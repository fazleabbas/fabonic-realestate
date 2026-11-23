<?php
/**
 * FIS Real Estate — Sync API (Part 15.4)
 * Handles background sync of offline-queued operations.
 * Accepts queued items from the sync manager and dispatches them to the appropriate API.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // PROCESS SYNC QUEUE
    // ============================
    case 'process_queue':
        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            jsonResponse(['success' => false, 'message' => 'No items to sync']);
        }

        $results = [];
        foreach ($items as $item) {
            $queueId = $item['queue_id'] ?? null;
            $module = $item['module'] ?? '';
            $itemAction = $item['action'] ?? '';
            $data = $item['data'] ?? [];

            if (empty($module) || empty($itemAction)) {
                $results[] = [
                    'queue_id' => $queueId,
                    'success' => false,
                    'message' => 'Missing module or action'
                ];
                continue;
            }

            // Map module to API file
            $apiFile = __DIR__ . '/' . preg_replace('/[^a-z_-]/', '', $module) . '.php';
            if (!file_exists($apiFile)) {
                $results[] = [
                    'queue_id' => $queueId,
                    'success' => false,
                    'message' => 'Unknown module: ' . $module
                ];
                continue;
            }

            // Simulate the API call by setting up $_POST and $_REQUEST
            $savedPost = $_POST;
            $savedRequest = $_REQUEST;
            $savedAction = $_REQUEST['action'] ?? '';

            $_POST = $data;
            $_POST['action'] = $itemAction;
            $_REQUEST = array_merge($_REQUEST, $_POST);

            // Capture the JSON output
            ob_start();
            try {
                // We can't directly include because the API files call exit().
                // Instead, use a simulated internal API call via cURL to localhost
                $internalUrl = BASE_URL . 'api/' . preg_replace('/[^a-z_-]/', '', $module) . '.php';
                $ch = curl_init($internalUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query(array_merge($data, ['action' => $itemAction])),
                    CURLOPT_COOKIE => 'PHPSESSID=' . session_id(),
                    CURLOPT_TIMEOUT => 30,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $result = json_decode($response, true);
                if ($result === null) {
                    $results[] = [
                        'queue_id' => $queueId,
                        'success' => false,
                        'message' => 'Invalid response from module API'
                    ];
                } else {
                    $results[] = [
                        'queue_id' => $queueId,
                        'success' => $result['success'] ?? false,
                        'message' => $result['message'] ?? '',
                        'data' => $result['data'] ?? null
                    ];
                }
            } catch (Exception $e) {
                $results[] = [
                    'queue_id' => $queueId,
                    'success' => false,
                    'message' => 'Sync error: ' . $e->getMessage()
                ];
            }
            ob_end_clean();

            // Restore globals
            $_POST = $savedPost;
            $_REQUEST = $savedRequest;
        }

        logActivity('synced', 'offline_queue', null, [
            'total' => count($items),
            'success' => count(array_filter($results, function($r) { return $r['success']; }))
        ]);

        jsonResponse(['success' => true, 'results' => $results]);
        break;

    // ============================
    // GET SYNC STATUS
    // ============================
    case 'status':
        jsonResponse([
            'success' => true,
            'online' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'version' => APP_VERSION
        ]);
        break;

    // ============================
    // CACHE REFRESH — get fresh data for offline stores
    // ============================
    case 'cache_refresh':
        $module = trim($_GET['module'] ?? 'all');
        $result = [];

        if ($module === 'all' || $module === 'properties') {
            try {
                $stmt = $conn->query("SELECT id, name, property_type, status FROM properties WHERE status = 'active' ORDER BY name LIMIT 500");
                $result['properties'] = $stmt->fetchAll();
            } catch (PDOException $e) { $result['properties'] = []; }
        }

        if ($module === 'all' || $module === 'units') {
            try {
                $stmt = $conn->query("SELECT u.id, u.unit_number, u.unit_type, u.status, u.rent_amount, u.property_id, p.name AS property_name FROM units u LEFT JOIN properties p ON u.property_id = p.id ORDER BY p.name, u.unit_number LIMIT 1000");
                $result['units'] = $stmt->fetchAll();
            } catch (PDOException $e) { $result['units'] = []; }
        }

        if ($module === 'all' || $module === 'tenants') {
            try {
                $stmt = $conn->query("SELECT id, first_name, last_name, phone, status FROM tenants WHERE status = 'active' ORDER BY first_name, last_name LIMIT 500");
                $result['tenants'] = $stmt->fetchAll();
            } catch (PDOException $e) { $result['tenants'] = []; }
        }

        jsonResponse(['success' => true, 'data' => $result, 'cached_at' => date('Y-m-d H:i:s')]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
