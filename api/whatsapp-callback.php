<?php
/**
 * FIS Real Estate — WhatsApp Delivery Status Callback
 *
 * Receives delivery status updates from wa.fis.co.tz API.
 * Updates drip_messages_re.delivery_status and drip_message_queue status.
 *
 * Expected POST payload (JSON):
 * {
 *   "message_id": "xxx",
 *   "phone": "255...",
 *   "status": "delivered" | "read" | "failed" | "sent",
 *   "timestamp": "2026-02-27T10:00:00Z",
 *   "error": "optional error message"
 * }
 *
 * Webhook URL to register: https://realestate.fis.co.tz/api/whatsapp-callback.php?key=<WA_CALLBACK_KEY>
 */

// Access control via shared key
require_once __DIR__ . '/../includes/secrets.php';
if (!appSecretMatches('WA_CALLBACK_KEY', $_GET['key'] ?? null)) {
    http_response_code(403);
    die(json_encode(['error' => 'Access denied']));
}

header('Content-Type: application/json');

// Read JSON body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data || empty($data['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/subscription.php';

$central = getCentralDb();
if (!$central) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$phone = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
$status = $data['status'] ?? '';
$messageId = $data['message_id'] ?? '';
$errorMsg = $data['error'] ?? null;
$timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
$callbackJson = $rawBody;

$updated = 0;

try {
    // Strategy 1: Match by message_id in queue response_data
    // The queue stores the API response which may contain the message_id
    if ($messageId) {
        // Update queue entries that have this message_id in response_data
        $stmt = $central->prepare("
            SELECT id, message_id FROM drip_message_queue
            WHERE response_data LIKE :pattern
            AND status = 'sent'
            ORDER BY id DESC LIMIT 5
        ");
        $stmt->execute(['pattern' => '%' . $messageId . '%']);
        $queueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($queueRows as $qr) {
            if ($qr['message_id']) {
                $deliveryStatus = $status;
                $deliveredAt = in_array($status, ['delivered', 'read']) ? $timestamp : null;

                $central->prepare("
                    UPDATE drip_messages_re
                    SET delivery_status = ?,
                        delivered_at = COALESCE(?, delivered_at),
                        callback_data = ?,
                        status = CASE
                            WHEN ? IN ('delivered', 'read') THEN 'delivered'
                            WHEN ? = 'failed' THEN 'failed'
                            ELSE status
                        END
                    WHERE id = ?
                ")->execute([$deliveryStatus, $deliveredAt, $callbackJson, $status, $status, $qr['message_id']]);
                $updated++;
            }
        }
    }

    // Strategy 2: Match by phone number on recently sent messages
    if ($updated === 0 && $phone) {
        $stmt = $central->prepare("
            SELECT m.id, m.campaign_id
            FROM drip_messages_re m
            JOIN drip_campaigns_re c ON c.id = m.campaign_id
            WHERE c.phone = :phone
            AND m.status = 'sent'
            AND m.sent_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
            ORDER BY m.sent_at DESC
            LIMIT 1
        ");
        $stmt->execute(['phone' => $phone]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($match) {
            $deliveredAt = in_array($status, ['delivered', 'read']) ? $timestamp : null;

            $central->prepare("
                UPDATE drip_messages_re
                SET delivery_status = ?,
                    delivered_at = COALESCE(?, delivered_at),
                    callback_data = ?,
                    status = CASE
                        WHEN ? IN ('delivered', 'read') THEN 'delivered'
                        WHEN ? = 'failed' THEN 'failed'
                        ELSE status
                    END
                WHERE id = ?
            ")->execute([$status, $deliveredAt, $callbackJson, $status, $status, $match['id']]);
            $updated++;
        }
    }

    // Log the callback
    error_log("[WA Callback] phone={$phone} status={$status} message_id={$messageId} updated={$updated}");

    echo json_encode(['success' => true, 'updated' => $updated]);

} catch (PDOException $e) {
    error_log("WhatsApp callback error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}
