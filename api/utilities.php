<?php
/**
 * Fabonic Real Estate — Utility Billing API
 * ------------------------------------------------------------
 * Utility types, meter readings and the charges generated from them.
 * Used by AJAX calls from modules/utilities/*.
 *
 * Settlement model: a charge is settled on the charge itself
 * (utility_charges.status = pending | paid | waived). The payments table is
 * rent-only and is deliberately not touched here.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
if (!hasTier('enterprise')) {
    jsonResponse(['success' => false, 'message' => __('util_tier_required', 'Enterprise tier required for utility billing')], 403);
}
if (getSetting('utility_billing_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => __('util_disabled', 'Utility billing module is disabled')], 403);
}

$action = $_REQUEST['action'] ?? '';

if (!function_exists('utilScopeDeny')) {
    function utilScopeDeny() {
        jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
    }
}

/**
 * Price a consumption figure against a utility type.
 * per_unit -> consumption x rate
 * flat     -> rate, regardless of consumption
 * tiered   -> walk the bands in tiered_rates_json: [{from, to, rate}, ...]
 *             Each band prices only the portion of consumption inside it, so
 *             50 units across "0-20 @ 100" and "20+ @ 150" costs
 *             20*100 + 30*150, not 50*150.
 */
function utilCalculateAmount(array $type, $consumption)
{
    $consumption = max(0, (float)$consumption);

    if ($type['rate_type'] === 'flat') {
        return round((float)$type['rate_amount'], 2);
    }

    if ($type['rate_type'] === 'tiered') {
        $bands = json_decode($type['tiered_rates_json'] ?? '[]', true);
        if (!is_array($bands) || !$bands) {
            // Misconfigured tiered type — fall back to the flat rate rather
            // than silently billing zero.
            return round($consumption * (float)$type['rate_amount'], 2);
        }
        usort($bands, function ($a, $b) {
            return (float)($a['from'] ?? 0) <=> (float)($b['from'] ?? 0);
        });

        $total = 0.0;
        foreach ($bands as $band) {
            $from = (float)($band['from'] ?? 0);
            $to   = ($band['to'] === '' || $band['to'] === null) ? INF : (float)$band['to'];
            $rate = (float)($band['rate'] ?? 0);

            if ($consumption <= $from) break;
            $inBand = min($consumption, $to) - $from;
            if ($inBand > 0) {
                $total += $inBand * $rate;
            }
        }
        return round($total, 2);
    }

    // per_unit
    return round($consumption * (float)$type['rate_amount'], 2);
}

switch ($action) {

    // ============================================================
    // UTILITY TYPES
    // ============================================================
    case 'types':
        $rows = $conn->query("SELECT * FROM utility_types ORDER BY status, name")->fetchAll();
        jsonResponse(['success' => true, 'data' => $rows]);
        break;

    case 'get_type':
        $stmt = $conn->prepare("SELECT * FROM utility_types WHERE id = ?");
        $stmt->execute([(int)($_REQUEST['id'] ?? 0)]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        jsonResponse(['success' => true, 'data' => $row]);
        break;

    case 'save_type':
        checkCSRF();
        if (!hasPermission('utilities', 'edit')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);

        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $uom      = trim($_POST['unit_of_measure'] ?? '');
        $rateType = $_POST['rate_type'] ?? 'per_unit';
        $rate     = (float)($_POST['rate_amount'] ?? 0);
        $status   = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '') jsonResponse(['success' => false, 'message' => __('util_name_required', 'Name is required')], 400);
        if (!in_array($rateType, ['per_unit', 'flat', 'tiered'], true)) $rateType = 'per_unit';

        // Tiered bands arrive as parallel arrays from the form.
        $tieredJson = null;
        if ($rateType === 'tiered') {
            $froms = $_POST['tier_from'] ?? [];
            $tos   = $_POST['tier_to'] ?? [];
            $rates = $_POST['tier_rate'] ?? [];
            $bands = [];
            foreach ((array)$froms as $i => $f) {
                if ($f === '' && ($rates[$i] ?? '') === '') continue;
                $bands[] = [
                    'from' => (float)$f,
                    'to'   => ($tos[$i] ?? '') === '' ? null : (float)$tos[$i],
                    'rate' => (float)($rates[$i] ?? 0),
                ];
            }
            if (!$bands) jsonResponse(['success' => false, 'message' => __('util_tiers_required', 'Add at least one rate band')], 400);
            $tieredJson = json_encode($bands, JSON_UNESCAPED_UNICODE);
        }

        try {
            if ($id) {
                $conn->prepare("
                    UPDATE utility_types
                    SET name = ?, unit_of_measure = ?, rate_type = ?, rate_amount = ?, tiered_rates_json = ?, status = ?
                    WHERE id = ?
                ")->execute([$name, $uom ?: null, $rateType, $rate, $tieredJson, $status, $id]);
                logActivity('updated', 'utility_type', $id, ['name' => $name]);
            } else {
                $conn->prepare("
                    INSERT INTO utility_types (name, unit_of_measure, rate_type, rate_amount, tiered_rates_json, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$name, $uom ?: null, $rateType, $rate, $tieredJson, $status]);
                $id = (int)$conn->lastInsertId();
                logActivity('created', 'utility_type', $id, ['name' => $name]);
            }
            jsonResponse(['success' => true, 'id' => $id, 'message' => __('util_type_saved', 'Utility type saved')]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_type':
        checkCSRF();
        if (!hasPermission('utilities', 'delete')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);
        $id = (int)($_POST['id'] ?? 0);
        // Deleting cascades to readings and charges — deactivate instead when in use.
        $used = $conn->prepare("SELECT COUNT(*) FROM utility_charges WHERE utility_type_id = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            jsonResponse([
                'success' => false,
                'message' => __('util_type_in_use', 'This utility type already has charges. Set it to Inactive instead of deleting it.'),
            ], 409);
        }
        $conn->prepare("DELETE FROM utility_types WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'utility_type', $id);
        jsonResponse(['success' => true, 'message' => __('util_type_deleted', 'Utility type deleted')]);
        break;

    // ============================================================
    // LOOKUPS
    // ============================================================
    case 'units_by_property':
        $pid = (int)($_REQUEST['property_id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT u.id, u.unit_number, u.unit_type, u.status
            FROM units u
            WHERE u.property_id = ?" . propertyScopeSql('u.property_id') . "
            ORDER BY u.unit_number
        ");
        $stmt->execute([$pid]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'previous_reading':
        // Last recorded reading for this unit + utility, so the form can
        // pre-fill it and compute consumption without the user doing sums.
        $unitId = (int)($_REQUEST['unit_id'] ?? 0);
        $typeId = (int)($_REQUEST['utility_type_id'] ?? 0);
        if (!canAccessUnit($unitId)) utilScopeDeny();
        $stmt = $conn->prepare("
            SELECT current_reading, reading_date
            FROM utility_readings
            WHERE unit_id = ? AND utility_type_id = ?
            ORDER BY reading_date DESC, id DESC LIMIT 1
        ");
        $stmt->execute([$unitId, $typeId]);
        $row = $stmt->fetch();
        jsonResponse([
            'success'  => true,
            'previous' => $row ? (float)$row['current_reading'] : 0,
            'as_of'    => $row['reading_date'] ?? null,
        ]);
        break;

    // ============================================================
    // READINGS
    // ============================================================
    case 'save_reading':
        checkCSRF();
        if (!hasPermission('utilities', 'create')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);

        $unitId  = (int)($_POST['unit_id'] ?? 0);
        $typeId  = (int)($_POST['utility_type_id'] ?? 0);
        $date    = trim($_POST['reading_date'] ?? date('Y-m-d'));
        $prev    = (float)($_POST['previous_reading'] ?? 0);
        $current = (float)($_POST['current_reading'] ?? 0);

        if (!$unitId || !$typeId) jsonResponse(['success' => false, 'message' => __('util_unit_type_required', 'Unit and utility type are required')], 400);
        if (!canAccessUnit($unitId)) utilScopeDeny();
        if ($current < $prev) {
            jsonResponse([
                'success' => false,
                'message' => __('util_reading_lower', 'The current reading is lower than the previous one. Check the meter, or correct the previous reading.'),
            ], 400);
        }

        $consumption = round($current - $prev, 2);
        try {
            $conn->prepare("
                INSERT INTO utility_readings (unit_id, utility_type_id, reading_date, previous_reading, current_reading, consumption, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$unitId, $typeId, $date, $prev, $current, $consumption, getUserId()]);
            $rid = (int)$conn->lastInsertId();
            logActivity('created', 'utility_reading', $rid, ['unit_id' => $unitId, 'consumption' => $consumption]);
            jsonResponse(['success' => true, 'id' => $rid, 'consumption' => $consumption, 'message' => __('util_reading_saved', 'Reading saved')]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_reading':
        checkCSRF();
        if (!hasPermission('utilities', 'delete')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);
        $id = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("SELECT unit_id FROM utility_readings WHERE id = ?");
        $s->execute([$id]);
        $uid = (int)$s->fetchColumn();
        if ($uid && !canAccessUnit($uid)) utilScopeDeny();
        $conn->prepare("DELETE FROM utility_readings WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'utility_reading', $id);
        jsonResponse(['success' => true, 'message' => __('util_reading_deleted', 'Reading deleted')]);
        break;

    // ============================================================
    // CHARGES
    // ============================================================
    case 'generate_charges':
        checkCSRF();
        if (!hasPermission('utilities', 'create')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);

        $month = (int)($_POST['period_month'] ?? date('n'));
        $year  = (int)($_POST['period_year'] ?? date('Y'));
        if ($month < 1 || $month > 12) jsonResponse(['success' => false, 'message' => 'Invalid month'], 400);

        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd   = date('Y-m-t', strtotime($periodStart));

        $types = $conn->query("SELECT * FROM utility_types WHERE status = 'active'")->fetchAll();
        if (!$types) jsonResponse(['success' => false, 'message' => __('util_no_types', 'No active utility types configured')], 400);

        $created = 0;
        $skipped = 0;

        try {
            $conn->beginTransaction();

            // Who occupies each unit this period — a charge needs a tenant.
            $leaseStmt = $conn->prepare("
                SELECT l.id AS lease_id, l.tenant_id, l.unit_id, l.currency
                FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                WHERE l.status = 'active' AND l.start_date <= ?" . propertyScopeSql('u.property_id') . "
            ");
            $leaseStmt->execute([$periodEnd]);
            $leases = $leaseStmt->fetchAll();

            $dupe = $conn->prepare("
                SELECT id FROM utility_charges
                WHERE unit_id = ? AND utility_type_id = ? AND period_month = ? AND period_year = ?
            ");
            $readingStmt = $conn->prepare("
                SELECT COALESCE(SUM(consumption), 0) FROM utility_readings
                WHERE unit_id = ? AND utility_type_id = ? AND reading_date BETWEEN ? AND ?
            ");
            $insert = $conn->prepare("
                INSERT INTO utility_charges
                    (tenant_id, lease_id, unit_id, utility_type_id, period_month, period_year, consumption, rate, amount, currency, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            foreach ($leases as $lease) {
                foreach ($types as $type) {
                    $dupe->execute([$lease['unit_id'], $type['id'], $month, $year]);
                    if ($dupe->fetch()) { $skipped++; continue; }

                    if ($type['rate_type'] === 'flat') {
                        $consumption = 0;
                    } else {
                        $readingStmt->execute([$lease['unit_id'], $type['id'], $periodStart, $periodEnd]);
                        $consumption = (float)$readingStmt->fetchColumn();
                        // Metered utility with no reading this period — nothing
                        // to bill, and guessing would be worse than skipping.
                        if ($consumption <= 0) { $skipped++; continue; }
                    }

                    $amount = utilCalculateAmount($type, $consumption);
                    $insert->execute([
                        $lease['tenant_id'], $lease['lease_id'], $lease['unit_id'], $type['id'],
                        $month, $year, $consumption, (float)$type['rate_amount'], $amount,
                        $lease['currency'] ?: DEFAULT_CURRENCY,
                    ]);
                    $created++;
                }
            }

            $conn->commit();
            logActivity('created', 'utility_charges', null, ['period' => "$year-$month", 'created' => $created]);
            jsonResponse([
                'success' => true,
                'created' => $created,
                'skipped' => $skipped,
                'message' => sprintf(__('util_generated', '%1$d charges generated, %2$d skipped.'), $created, $skipped),
            ]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    case 'set_charge_status':
        checkCSRF();
        if (!hasPermission('utilities', 'edit')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        if (!in_array($status, ['pending', 'paid', 'waived'], true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
        }
        $s = $conn->prepare("SELECT unit_id FROM utility_charges WHERE id = ?");
        $s->execute([$id]);
        $uid = (int)$s->fetchColumn();
        if (!$uid) jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        if (!canAccessUnit($uid)) utilScopeDeny();

        $conn->prepare("UPDATE utility_charges SET status = ? WHERE id = ?")->execute([$status, $id]);
        logActivity('updated', 'utility_charge', $id, ['status' => $status]);
        jsonResponse(['success' => true, 'message' => __('util_charge_updated', 'Charge updated')]);
        break;

    case 'delete_charge':
        checkCSRF();
        if (!hasPermission('utilities', 'delete')) jsonResponse(['success' => false, 'message' => __('no_permission')], 403);
        $id = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("SELECT unit_id FROM utility_charges WHERE id = ?");
        $s->execute([$id]);
        $uid = (int)$s->fetchColumn();
        if ($uid && !canAccessUnit($uid)) utilScopeDeny();
        $conn->prepare("DELETE FROM utility_charges WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'utility_charge', $id);
        jsonResponse(['success' => true, 'message' => __('util_charge_deleted', 'Charge deleted')]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
