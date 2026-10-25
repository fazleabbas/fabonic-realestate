<?php
/**
 * FIS Real Estate — Authenticated File Server
 *
 * Serves files that must NOT be readable straight off disk (tenant ID copies,
 * documents). Those directories are blocked by their own .htaccess; every read
 * goes through here so the session, permission and property-scope checks that
 * guard the rest of the app also guard the files.
 *
 * The path is always looked up from the database by record id — it is never
 * taken from the request — so there is no traversal surface.
 *
 *   api/file.php?type=document&id=12          inline (preview)
 *   api/file.php?type=document&id=12&dl=1     forced download
 *   api/file.php?type=tenant_id&id=5          inline (preview)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

/** Plain-text failure — this endpoint feeds <img>/<iframe>, so never JSON. */
function fileFail($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
}

if (!isLoggedIn()) {
    fileFail(401, 'Unauthorized');
}

$type = $_GET['type'] ?? '';
$id   = (int) ($_GET['id'] ?? 0);
$asDownload = !empty($_GET['dl']);

if ($id <= 0) {
    fileFail(400, 'Bad request');
}

$relPath = null;
$downloadName = null;

switch ($type) {

    // ------------------------------------------------------------
    // Document (Documents module)
    // ------------------------------------------------------------
    case 'document':
        if (!hasPermission('documents', 'view')) {
            fileFail(403, 'Forbidden');
        }

        $stmt = $conn->prepare("
            SELECT d.file_path, d.file_name, d.entity_type, d.entity_id
            FROM documents d
            WHERE d.id = :id AND d.status <> 'deleted'
        ");
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            fileFail(404, 'Not found');
        }

        // Property scoping — a document linked to an entity the user may not
        // reach must not be readable either.
        if (!documentInScope($conn, $doc['entity_type'], (int) $doc['entity_id'])) {
            fileFail(403, 'Forbidden');
        }

        $relPath = $doc['file_path'];
        $downloadName = $doc['file_name'];
        break;

    // ------------------------------------------------------------
    // Tenant ID copy (national ID / passport)
    // ------------------------------------------------------------
    case 'tenant_id':
        if (!hasPermission('tenants', 'view')) {
            fileFail(403, 'Forbidden');
        }

        $stmt = $conn->prepare("
            SELECT id_copy_path, first_name, last_name
            FROM tenants
            WHERE id = :id" . tenantScopeSql('id')
        );
        $stmt->execute(['id' => $id]);
        $tenant = $stmt->fetch();
        if (!$tenant || empty($tenant['id_copy_path'])) {
            fileFail(404, 'Not found');
        }

        $relPath = $tenant['id_copy_path'];
        $downloadName = 'ID-' . preg_replace('/[^A-Za-z0-9._-]/', '_',
            $tenant['first_name'] . '-' . $tenant['last_name']) . '.'
            . strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        break;

    default:
        fileFail(400, 'Bad request');
}

// ------------------------------------------------------------
// Resolve and confirm the file really sits inside uploads/
// ------------------------------------------------------------
$fullPath = realpath(UPLOAD_PATH . $relPath);
$uploadRoot = realpath(UPLOAD_PATH);

if ($fullPath === false || $uploadRoot === false
    || strncmp($fullPath, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot) + 1) !== 0) {
    fileFail(404, 'Not found');
}
if (!is_file($fullPath) || !is_readable($fullPath)) {
    fileFail(404, 'Not found');
}

// ------------------------------------------------------------
// Content type — derived from the extension whitelist, never from stored or
// submitted data, so a mislabelled row cannot make the browser render markup.
// ------------------------------------------------------------
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$safeTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];
$contentType = $safeTypes[$ext] ?? 'application/octet-stream';

// Anything we cannot render safely is only ever handed over as a download.
if ($contentType === 'application/octet-stream') {
    $asDownload = true;
}

if ($downloadName === null || $downloadName === '') {
    $downloadName = basename($fullPath);
}
// Strip quotes/control chars so the filename cannot break out of the header.
$downloadName = preg_replace('/[^\x20-\x7E]/', '', $downloadName);
$downloadName = str_replace(['"', '\\'], '', $downloadName);

logActivity('viewed', $type === 'document' ? 'document' : 'tenant', $id, [
    'file_served' => true,
    'mode' => $asDownload ? 'download' : 'inline',
]);

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . ($asDownload ? 'attachment' : 'inline')
    . '; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
// Neutralise anything script-like that slipped past the upload whitelist.
header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'none'; sandbox");
header('Cache-Control: private, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($fullPath);
exit;
