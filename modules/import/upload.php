<?php
/**
 * FIS Real Estate — upload, preview and commit one import (part 40).
 *
 * GET              the panel explaining this import, its columns, the upload form
 * POST preview     read + validate the file, show every row; nothing is written
 * POST commit      re-validate (the database may have moved on) and write it all
 * GET  export=tok  the preview as CSV with Status and Problem columns, to fix and re-upload
 *
 * The parsed file waits in the system temp folder between preview and commit,
 * never inside the web root, because it holds tenants' names and money.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/import/engine.php';

if (!importCanUse()) {
    setAlert(__('imp_admins_only', 'Only admins can import data.'), 'danger');
    redirect('index.php');
}

$type = $_REQUEST['type'] ?? '';
$def  = importType($type);
if (!$def) redirect('modules/import/');
if (!importGoLiveDate()) {
    setAlert(__('imp_e_no_golive', 'Set the go-live date on the Data Import page first.'), 'warning');
    redirect('modules/import/');
}
if ($def['posts'] && importBlockedByLock()) {
    setAlert(__('imp_e_locked', 'Setup is finished and the opening date is closed. Reopen it in Ledger → Account Mapping to import opening figures.'), 'warning');
    redirect('modules/import/');
}
$pageTitle = $def['label'] . ' — ' . __('imp_title', 'Data Import');

function importStashPath($token) {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'fabonic_import_' . preg_replace('/[^a-z0-9_]/i', '', DB_NAME) . '_' . $token . '.json';
}

function importStashLoad($token, $type) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string) $token)) return null;
    $path = importStashPath($token);
    if (!is_file($path)) return null;
    $stash = json_decode((string) file_get_contents($path), true);
    if (!$stash || $stash['type'] !== $type || (int) $stash['user_id'] !== (int) getUserId()) return null;
    return $stash;
}

$errorMessage = null;
$validated = null;
$stash = null;
$token = null;

// ---- the preview as CSV, to fix and upload again ----
if (isset($_GET['export'])) {
    $stash = importStashLoad($_GET['export'], $type);
    if (!$stash) redirect('modules/import/upload.php?type=' . $type);
    $v = importValidate($type, $stash['parsed'], $stash['options']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_checked.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge($stash['parsed']['headers'], ['Status', 'Problem']));
    foreach ($stash['parsed']['rows'] as $i => $row) {
        $vr = $v['rows'][$i] ?? null;
        $problems = $vr ? array_map(function ($m) { return $m[1]; }, array_filter($vr['msgs'], function ($m) { return $m[0] !== 'info'; })) : [];
        fputcsv($out, array_merge($row['cells'], [$vr ? strtoupper($vr['status']) : '', implode(' | ', $problems)]));
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errorMessage = __('imp_e_upload', 'Choose a file to upload.');
        } else {
            $parsed = importReadFile($file['tmp_name'], $file['name']);
            if (!$parsed['ok']) {
                $errorMessage = $parsed['error'];
            } else {
                // Leftovers from abandoned previews: gone after a day.
                foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'fabonic_import_*.json') ?: [] as $old) {
                    if (filemtime($old) < time() - 86400) @unlink($old);
                }
                $token = bin2hex(random_bytes(16));
                $stash = [
                    'type' => $type, 'user_id' => getUserId(), 'file_name' => $file['name'],
                    'options' => ['update_existing' => !empty($_POST['update_existing'])],
                    'parsed' => $parsed,
                ];
                file_put_contents(importStashPath($token), json_encode($stash));
                @chmod(importStashPath($token), 0600);
            }
        }
    } elseif ($action === 'commit') {
        $token = $_POST['token'] ?? '';
        $stash = importStashLoad($token, $type);
        if (!$stash) {
            $errorMessage = __('imp_e_expired', 'This preview has expired. Upload the file again.');
        } elseif (empty($_POST['confirm'])) {
            $errorMessage = __('imp_e_confirm', 'Tick the box to confirm you have checked the preview.');
        } else {
            // Validate again: someone may have changed the data since the preview.
            $validated = importValidate($type, $stash['parsed'], $stash['options']);
            if ($validated['ok'] && empty($validated['counts']['error'])) {
                $result = importCommit($type, $validated, $stash['file_name']);
                if ($result['success']) {
                    @unlink(importStashPath($token));
                    setAlert(sprintf(__('imp_done', 'Import #%d done: %s'), $result['batch_id'], implode('; ', $validated['summary']['lines'])), 'success');
                    redirect('modules/import/#history');
                }
                $errorMessage = implode(' ', $result['errors']);
            } else {
                $errorMessage = __('imp_e_changed', 'Something changed since the preview and some rows are no longer valid. Nothing was imported. Check the rows below.');
            }
        }
    }
}

if ($stash && !$validated) $validated = importValidate($type, $stash['parsed'], $stash['options']);
$hasErrors = $validated && (!$validated['ok'] || !empty($validated['counts']['error']));
$goLive = importGoLiveDate();
$opening = importOpeningDate();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi <?php echo $def['icon']; ?> me-2"></i><?php echo htmlspecialchars($def['label']); ?></h4>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/import/"><?php _e('imp_title', 'Data Import'); ?></a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($def['label']); ?></li>
        </ol>
    </nav>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?php echo htmlspecialchars($errorMessage); ?></div>
<?php endif; ?>

<!-- What this import does, in four plain answers -->
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning-subtle"><i class="bi bi-info-circle me-1"></i> <?php _e('imp_what_happens', 'What this import does'); ?></div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <tr><th class="w-25 ps-3"><?php _e('imp_q_what', 'What it does'); ?></th><td><?php echo htmlspecialchars($def['what']); ?></td></tr>
            <tr><th class="ps-3"><?php _e('imp_q_where', 'Where it shows'); ?></th><td><?php echo htmlspecialchars($def['where']); ?></td></tr>
            <tr><th class="ps-3"><?php _e('imp_q_books', 'What it changes in your accounts'); ?></th><td><?php echo htmlspecialchars($def['books']); ?></td></tr>
            <tr><th class="ps-3"><?php _e('imp_q_not', 'What it does NOT do'); ?></th><td><?php echo htmlspecialchars($def['not']); ?></td></tr>
        </table>
    </div>
</div>

<?php if (!$validated || ($hasErrors && !$validated['rows'])): ?>
<!-- Upload -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-upload me-1"></i> <?php _e('imp_upload', 'Upload your file'); ?></div>
    <div class="card-body">
        <?php if ($validated && $validated['error']): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($validated['error']); ?>
                <?php if ($validated['unknown']): ?><div class="small mt-1"><?php echo sprintf(__('imp_unknown_cols', 'Columns not recognised (ignored): %s'), htmlspecialchars(implode(', ', $validated['unknown']))); ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <div class="col-12 col-md-6">
                <input type="file" name="file" class="form-control" accept=".csv,.xlsx" required>
                <div class="form-text"><?php echo sprintf(__('imp_upload_help', 'CSV or Excel (.xlsx), first sheet only, up to %d rows. Nothing is saved until you confirm the preview.'), IMPORT_MAX_ROWS); ?></div>
            </div>
            <?php if ($type === 'rent_roll'): ?>
            <div class="col-12 col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="update_existing" value="1" id="updExisting">
                    <label class="form-check-label small" for="updExisting"><?php _e('imp_update_existing', 'Update tenants already on file: fill in blank TIN and ID, replace phone and email'); ?></label>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i><?php _e('imp_check_file', 'Check the file'); ?></button>
                <a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>modules/import/template.php?type=<?php echo $type; ?>">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i><?php _e('imp_template', 'Template'); ?>
                </a>
                <a class="btn btn-link" title="<?php _e('imp_csv_title', 'Header row only, as CSV'); ?>"
                   href="<?php echo BASE_URL; ?>modules/import/template.php?type=<?php echo $type; ?>&format=csv">CSV</a>
            </div>
        </form>
    </div>
</div>

<!-- Columns -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-layout-three-columns me-1"></i> <?php _e('imp_columns', 'Columns'); ?></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th><?php _e('imp_column', 'Column'); ?></th><th><?php _e('imp_required', 'Required'); ?></th><th><?php _e('notes', 'Notes'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($def['columns'] as $col): ?>
                <tr>
                    <td><code><?php echo $col['key']; ?></code></td>
                    <td><?php echo $col['required'] ? '<span class="text-danger">*</span>' : ''; ?></td>
                    <td class="small"><?php echo htmlspecialchars($col['help']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted"><?php _e('imp_columns_help', 'Headers are matched by name: order, capitals and spaces do not matter. Records are matched by name and unit number, never by ID.'); ?></div>
</div>

<?php else: ?>
<!-- Preview -->
<?php
$counts = $validated['counts'];
$okRows = count($validated['rows']) - ($counts['error'] ?? 0);
$warnRows = count(array_filter($validated['rows'], function ($r) { return $r['status'] !== 'error' && in_array('warn', array_column($r['msgs'], 0), true); }));
?>
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-eye me-1"></i> <?php echo sprintf(__('imp_preview_of', 'Preview of %s'), htmlspecialchars($stash['file_name'])); ?></span>
        <span>
            <span class="badge bg-success"><?php echo sprintf(__('imp_n_ok', '%d ready'), $okRows); ?></span>
            <?php if ($warnRows): ?><span class="badge bg-warning text-dark"><?php echo sprintf(__('imp_n_warn', '%d to check'), $warnRows); ?></span><?php endif; ?>
            <?php if (!empty($counts['error'])): ?><span class="badge bg-danger"><?php echo sprintf(__('imp_n_err', '%d with errors'), $counts['error']); ?></span><?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary ms-2" href="?type=<?php echo $type; ?>&export=<?php echo $token; ?>"><i class="bi bi-download me-1"></i><?php _e('imp_download_checked', 'Download with problems marked'); ?></a>
        </span>
    </div>
    <?php if ($validated['unknown']): ?>
        <div class="alert alert-light border-0 border-bottom rounded-0 small mb-0"><?php echo sprintf(__('imp_unknown_cols', 'Columns not recognised (ignored): %s'), htmlspecialchars(implode(', ', $validated['unknown']))); ?></div>
    <?php endif; ?>
    <div class="table-responsive" style="max-height:60vh;">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light" style="position:sticky;top:0;">
                <tr>
                    <th><?php _e('imp_line', 'Line'); ?></th><th><?php _e('status', 'Status'); ?></th>
                    <?php foreach (importPreviewColumns($type) as $h): ?><th><?php echo htmlspecialchars($h); ?></th><?php endforeach; ?>
                    <th><?php _e('imp_problems', 'Problems and notes'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($validated['rows'] as $r):
                $isWarn = $r['status'] !== 'error' && in_array('warn', array_column($r['msgs'], 0), true);
            ?>
                <tr class="<?php echo $r['status'] === 'error' ? 'table-danger' : ($isWarn ? 'table-warning' : ''); ?>">
                    <td class="text-muted"><?php echo (int) $r['line']; ?></td>
                    <td><?php echo $r['status'] === 'error'
                        ? '<span class="badge bg-danger">' . __('imp_st_error', 'Error') . '</span>'
                        : ($isWarn ? '<span class="badge bg-warning text-dark">' . __('imp_st_warn', 'Check') . '</span>'
                                   : '<span class="badge bg-success">' . __('imp_st_new', 'New') . '</span>'); ?></td>
                    <?php foreach ($r['show'] as $cell): ?><td class="small"><?php echo htmlspecialchars((string) $cell); ?></td><?php endforeach; ?>
                    <td class="small">
                        <?php foreach ($r['msgs'] as $m): ?>
                            <div class="<?php echo $m[0] === 'error' ? 'text-danger' : ($m[0] === 'warn' ? 'text-warning-emphasis' : 'text-muted'); ?>">
                                <i class="bi <?php echo $m[0] === 'error' ? 'bi-x-circle' : ($m[0] === 'warn' ? 'bi-exclamation-triangle' : 'bi-info-circle'); ?> me-1"></i><?php echo htmlspecialchars($m[1]); ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($hasErrors): ?>
<div class="alert alert-danger">
    <i class="bi bi-x-octagon me-1"></i><?php echo sprintf(__('imp_fix_first', '%d row(s) have errors, so nothing can be imported yet. Fix them in your file — "Download with problems marked" lists each one — and upload it again.'), $counts['error'] ?? 0); ?>
</div>
<a class="btn btn-primary mb-4" href="?type=<?php echo $type; ?>"><i class="bi bi-upload me-1"></i><?php _e('imp_upload_again', 'Upload a corrected file'); ?></a>
<?php else: ?>
<!-- The confirmation: real figures, real consequences -->
<div class="card mb-4 border-danger">
    <div class="card-header bg-danger-subtle fw-semibold"><i class="bi bi-shield-exclamation me-1"></i> <?php _e('imp_confirm_title', 'Please confirm before importing'); ?></div>
    <div class="card-body">
        <p class="mb-2"><?php _e('imp_confirm_lead', 'This import will create:'); ?></p>
        <ul class="mb-3">
            <?php foreach ($validated['summary']['lines'] as $line): ?><li><strong><?php echo htmlspecialchars($line); ?></strong></li><?php endforeach; ?>
            <?php if ($def['posts'] && $validated['summary']['entries'] && ledgerAutoPost()): ?>
                <li><?php echo sprintf($type === 'trial_balance'
                        ? __('imp_confirm_entries_tb', '%d opening entry(ies) dated %s, waiting for a second approver in the Journal')
                        : __('imp_confirm_entries', '%d accounting entry(ies) dated %s'),
                        $validated['summary']['entries'], '<strong>' . formatDate($opening) . '</strong>'); ?></li>
            <?php endif; ?>
        </ul>
        <ul class="small mb-3">
            <?php if (in_array($type, ['arrears', 'advances'], true)): ?>
                <li><?php _e('imp_c_immediate', 'Arrears, tenant statements and reminders will change immediately.'); ?></li>
            <?php endif; ?>
            <?php if ($type === 'rent_roll' || $type === 'arrears'): ?>
                <li><?php echo sprintf(__('imp_c_billing', 'Automatic rent billing for these leases will start from %s. Anything owed before that date must be in the opening arrears import.'), '<strong>' . formatDate($goLive) . '</strong>'); ?></li>
            <?php endif; ?>
            <?php if ($type !== 'trial_balance'): ?>
                <li><?php _e('imp_c_no_messages', 'Tenants receive no messages from this import.'); ?></li>
            <?php endif; ?>
            <li><?php _e('imp_c_undo', 'You can undo this import only until a payment is recorded against it or someone edits its records.'); ?></li>
        </ul>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="impConfirm" required>
                <label class="form-check-label fw-semibold" for="impConfirm"><?php _e('imp_confirm_tick', 'I have checked the preview and understand these changes.'); ?></label>
            </div>
            <button class="btn btn-danger" id="impGo" disabled><i class="bi bi-check2-circle me-1"></i><?php _e('imp_import', 'Import'); ?></button>
            <a class="btn btn-outline-secondary" href="?type=<?php echo $type; ?>"><?php _e('cancel', 'Cancel'); ?></a>
        </form>
    </div>
</div>
<script>
document.getElementById('impConfirm').addEventListener('change', function () {
    document.getElementById('impGo').disabled = !this.checked;
});
document.getElementById('impGo').closest('form').addEventListener('submit', function () {
    document.getElementById('impGo').disabled = true;
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
