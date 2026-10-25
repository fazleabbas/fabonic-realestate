<?php
/**
 * FIS Real Estate — Ledger API (Part 30, Phase 1)
 * Accounts, journal entries, approval, reversal, mappings.
 *
 * Every write goes through includes/ledger.php, which owns the accounting
 * rules. This file only handles permission, input and response.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/ledger.php';
require_once __DIR__ . '/../includes/charges.php';
require_once __DIR__ . '/../includes/ledger_reports.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // ACCOUNTS
    // ============================
    case 'list_accounts':
        if (!hasPermission('ledger', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        $accounts = ledgerAccounts([
            'type' => trim($_GET['type'] ?? ''),
            'currency' => trim($_GET['currency'] ?? ''),
            'search' => trim($_GET['search'] ?? ''),
            'include_archived' => !empty($_GET['include_archived']),
        ]);
        foreach ($accounts as &$a) {
            $a['balance'] = ledgerAccountBalance($a['id']);
        }
        unset($a);
        jsonResponse(['success' => true, 'data' => $accounts]);
        break;

    case 'save_account':
        if (!hasPermission('ledger', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $code     = trim($_POST['code'] ?? '');
        $type     = trim($_POST['type'] ?? '');
        $subtype  = trim($_POST['subtype'] ?? '');
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $desc     = trim($_POST['description'] ?? '');

        $errors = [];
        if ($name === '') $errors[] = __('led_err_name', 'Account name is required');
        if (!array_key_exists($type, ledgerAccountTypes())) $errors[] = __('led_err_type', 'Invalid account type');
        if (!in_array($currency, array_keys(getSupportedCurrencies()), true)) {
            $errors[] = __('led_err_currency_invalid', 'Invalid currency');
        }
        if ($errors) jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 400);

        try {
            if ($id) {
                $existing = ledgerAccount($id);
                if (!$existing) jsonResponse(['success' => false, 'message' => 'Account not found'], 404);

                // A system account keeps its type, currency and key — automatic
                // postings depend on them. Only the label may change.
                if ((int) $existing['is_system'] === 1) {
                    $stmt = $conn->prepare('UPDATE accounts SET name = :n, code = :c, description = :d WHERE id = :id');
                    $stmt->execute(['n' => $name, 'c' => $code ?: null, 'd' => $desc ?: null, 'id' => $id]);
                } else {
                    // Changing currency under posted lines would break the
                    // single-currency rule retrospectively.
                    $used = $conn->prepare('SELECT COUNT(*) FROM journal_lines WHERE account_id = ?');
                    $used->execute([$id]);
                    $hasLines = (int) $used->fetchColumn() > 0;

                    if ($hasLines && ($existing['currency'] !== $currency || $existing['type'] !== $type)) {
                        jsonResponse(['success' => false, 'message' => __('led_err_locked',
                            'This account already has entries, so its type and currency cannot change')], 400);
                    }
                    $stmt = $conn->prepare('UPDATE accounts SET name=:n, code=:c, type=:t, subtype=:st, currency=:cur, description=:d WHERE id=:id');
                    $stmt->execute(['n'=>$name,'c'=>$code ?: null,'t'=>$type,'st'=>$subtype ?: null,'cur'=>$currency,'d'=>$desc ?: null,'id'=>$id]);
                }
                logActivity('updated', 'account', $id, ['name' => $name]);
                jsonResponse(['success' => true, 'message' => __('led_account_saved', 'Account saved'), 'id' => $id]);
            }

            $stmt = $conn->prepare('
                INSERT INTO accounts (code, name, type, subtype, currency, description, created_by)
                VALUES (:c, :n, :t, :st, :cur, :d, :by)
            ');
            $stmt->execute([
                'c' => $code ?: null, 'n' => $name, 't' => $type, 'st' => $subtype ?: null,
                'cur' => $currency, 'd' => $desc ?: null, 'by' => getUserId(),
            ]);
            $newId = (int) $conn->lastInsertId();
            logActivity('created', 'account', $newId, ['name' => $name, 'type' => $type]);
            jsonResponse(['success' => true, 'message' => __('led_account_created', 'Account created'), 'id' => $newId]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    case 'archive_account':
        if (!hasPermission('ledger', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();
        $id = (int) ($_POST['id'] ?? 0);
        $account = ledgerAccount($id);
        if (!$account) jsonResponse(['success' => false, 'message' => 'Account not found'], 404);
        if ((int) $account['is_system'] === 1) {
            jsonResponse(['success' => false, 'message' => __('led_err_system_archive',
                'System accounts cannot be archived — automatic postings depend on them')], 400);
        }

        // Archiving hides an account from new entries; history is never touched.
        $target = $account['status'] === 'active' ? 'archived' : 'active';
        $conn->prepare('UPDATE accounts SET status = ? WHERE id = ?')->execute([$target, $id]);
        logActivity('updated', 'account', $id, ['status' => $target]);
        jsonResponse(['success' => true, 'message' => $target === 'archived'
            ? __('led_account_archived', 'Account archived')
            : __('led_account_restored', 'Account restored')]);
        break;

    // ============================
    // JOURNAL ENTRIES
    // ============================
    case 'create_entry':
        if (!hasPermission('ledger', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $lines = [];
        $accountIds = $_POST['line_account'] ?? [];
        foreach ($accountIds as $i => $accountId) {
            $lines[] = [
                'account_id'  => (int) $accountId,
                'debit'       => (float) str_replace(',', '', $_POST['line_debit'][$i] ?? 0),
                'credit'      => (float) str_replace(',', '', $_POST['line_credit'][$i] ?? 0),
                'property_id' => (int) ($_POST['line_property'][$i] ?? 0) ?: null,
                'memo'        => trim($_POST['line_memo'][$i] ?? ''),
            ];
        }

        $result = ledgerCreateEntry([
            'entry_date'  => trim($_POST['entry_date'] ?? date('Y-m-d')),
            'currency'    => trim($_POST['currency'] ?? DEFAULT_CURRENCY),
            'memo'        => trim($_POST['memo'] ?? ''),
            'source_type' => in_array($_POST['source_type'] ?? '', ['manual', 'opening_balance'], true)
                                ? $_POST['source_type'] : 'manual',
        ], $lines);

        if (!$result['success']) {
            jsonResponse(['success' => false, 'message' => implode(' ', $result['errors'])], 400);
        }
        jsonResponse([
            'success' => true,
            'id' => $result['id'],
            'message' => __('led_entry_created', 'Entry submitted for approval'),
        ]);
        break;

    case 'approve_entry':
        if (!ledgerCanApprove()) {
            jsonResponse(['success' => false, 'message' => __('led_no_approve_right', 'You do not have approval rights')], 403);
        }
        checkCSRF();
        $result = ledgerApproveEntry((int) ($_POST['id'] ?? 0));
        jsonResponse($result['success']
            ? ['success' => true, 'message' => __('led_entry_approved', 'Entry posted')]
            : ['success' => false, 'message' => implode(' ', $result['errors'])],
            $result['success'] ? 200 : 400);
        break;

    case 'reject_entry':
        if (!ledgerCanApprove()) {
            jsonResponse(['success' => false, 'message' => __('led_no_approve_right', 'You do not have approval rights')], 403);
        }
        checkCSRF();
        $result = ledgerRejectEntry((int) ($_POST['id'] ?? 0), trim($_POST['reason'] ?? ''));
        jsonResponse($result['success']
            ? ['success' => true, 'message' => __('led_entry_rejected', 'Entry rejected')]
            : ['success' => false, 'message' => implode(' ', $result['errors'])],
            $result['success'] ? 200 : 400);
        break;

    case 'reverse_entry':
        if (!hasPermission('ledger', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();
        $result = ledgerReverseEntry(
            (int) ($_POST['id'] ?? 0),
            trim($_POST['date'] ?? '') ?: null,
            trim($_POST['memo'] ?? '') ?: null
        );
        jsonResponse($result['success']
            ? ['success' => true, 'id' => $result['id'], 'message' => __('led_reversal_created', 'Reversing entry submitted for approval')]
            : ['success' => false, 'message' => implode(' ', $result['errors'])],
            $result['success'] ? 200 : 400);
        break;

    // ============================
    // MAPPINGS
    // ============================
    case 'save_mappings':
        if (!hasPermission('ledger', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $map = $_POST['mapping'] ?? [];
        $stmt = $conn->prepare('UPDATE account_mappings SET account_id = :aid WHERE system_key = :k');
        $saved = 0;
        foreach ($map as $key => $accountId) {
            $accountId = (int) $accountId;
            if (!$accountId) {
                // A mapping must always point somewhere, or phase 2 postings
                // would fail at run time with nowhere to land.
                jsonResponse(['success' => false, 'message' => __('led_err_mapping_empty',
                    'Every event must be mapped to an account')], 400);
            }
            if (!ledgerAccount($accountId)) {
                jsonResponse(['success' => false, 'message' => 'Unknown account in mapping'], 400);
            }
            $stmt->execute(['aid' => $accountId, 'k' => $key]);
            $saved++;
        }
        logActivity('updated', 'account_mapping', null, ['count' => $saved]);
        jsonResponse(['success' => true, 'message' => __('led_mappings_saved', 'Account mapping saved')]);
        break;

    // ============================
    // CHARGES
    // ============================
    case 'generate_charges':
        if (!hasPermission('ledger', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();
        $r = generateRentCharges();
        jsonResponse([
            'success' => true,
            'message' => sprintf(
                __('chg_gen_result', '%d charge(s) raised, %d already present, %d failed.'),
                $r['created'], $r['skipped'], $r['failed']
            ),
        ] + $r);
        break;

    // ============================
    // REMITTANCE
    // ============================
    case 'create_remittance':
        if (!hasPermission('ledger', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $floatAccount = ledgerAccount((int) ($_POST['float_account_id'] ?? 0));
        // A collector may only remit their own float; an approver may act for anyone.
        if ($floatAccount && (int) $floatAccount['user_id'] !== (int) getUserId() && !ledgerCanApprove()) {
            jsonResponse(['success' => false, 'message' => __('flt_err_not_yours', 'That is not your float')], 403);
        }

        $r = remittanceCreate([
            'float_account_id' => (int) ($_POST['float_account_id'] ?? 0),
            'bank_account_id'  => (int) ($_POST['bank_account_id'] ?? 0),
            'amount'           => (float) str_replace(',', '', $_POST['amount'] ?? 0),
            'remitted_date'    => trim($_POST['remitted_date'] ?? date('Y-m-d')),
            'bank_reference'   => trim($_POST['bank_reference'] ?? ''),
            'notes'            => trim($_POST['notes'] ?? ''),
        ]);
        jsonResponse($r['success']
            ? ['success' => true, 'id' => $r['id'], 'message' => __('flt_created', 'Remittance submitted for approval')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'approve_remittance':
        checkCSRF();
        $r = remittanceApprove((int) ($_POST['id'] ?? 0));
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('flt_approved', 'Remittance approved — the float has been reduced')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'reject_remittance':
        checkCSRF();
        $r = remittanceReject((int) ($_POST['id'] ?? 0), trim($_POST['reason'] ?? ''));
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('flt_rejected', 'Remittance rejected — the float is unchanged')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    // ============================
    // PAYABLES (phase 3)
    // ============================
    case 'pay_expense':
        checkCSRF();
        $r = payExpense(
            (int) ($_POST['id'] ?? 0),
            (int) ($_POST['account_id'] ?? 0),
            trim($_POST['paid_date'] ?? '') ?: null
        );
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('pay_paid', 'Expense paid and posted')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'request_payout':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();
        $r = depositPayoutRequest(
            (int) ($_POST['id'] ?? 0),
            trim($_POST['payout_type'] ?? ''),
            (float) str_replace(',', '', $_POST['amount'] ?? 0),
            (int) ($_POST['from_account_id'] ?? 0) ?: null,
            trim($_POST['notes'] ?? '') ?: null
        );
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('dep_requested', 'Submitted for approval')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'approve_payout':
        checkCSRF();
        $r = depositPayoutApprove((int) ($_POST['id'] ?? 0));
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('dep_approved', 'Approved and posted')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'reject_payout':
        checkCSRF();
        $r = depositPayoutReject((int) ($_POST['id'] ?? 0), trim($_POST['reason'] ?? ''));
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('dep_rejected', 'Rejected — nothing was moved')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'save_category_accounts':
        if (!hasPermission('ledger', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();
        $stmt = $conn->prepare('UPDATE expense_categories SET account_id = :aid WHERE id = :id');
        foreach (($_POST['category'] ?? []) as $catId => $accountId) {
            // Blank is allowed: that category falls back to General Expenses.
            $stmt->execute(['aid' => (int) $accountId ?: null, 'id' => (int) $catId]);
        }
        jsonResponse(['success' => true, 'message' => __('pay_cats_saved', 'Expense categories saved')]);
        break;

    case 'save_lock_date':
        if (!ledgerCanApprove()) {
            jsonResponse(['success' => false, 'message' => __('led_no_approve_right', 'You do not have approval rights')], 403);
        }
        checkCSRF();
        $lock = trim($_POST['lock_date'] ?? '');
        if ($lock !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lock)) {
            jsonResponse(['success' => false, 'message' => __('rep_err_lock_date', 'Enter a valid date, or clear it to reopen the books')], 400);
        }
        updateSetting('ledger_lock_date', $lock);
        logActivity('updated', 'settings', null, ['ledger_lock_date' => $lock ?: '(open)']);
        jsonResponse(['success' => true, 'message' => $lock
            ? sprintf(__('rep_locked_msg', 'Books closed up to %s'), formatDate($lock))
            : __('rep_unlocked_msg', 'Books reopened')]);
        break;

    // Cash-handling policy: who may sign off their own work, and which payment
    // methods put money in someone's hands (part 38).
    case 'save_cash_policy':
        if (!ledgerCanApprove()) {
            jsonResponse(['success' => false, 'message' => __('led_no_approve_right', 'You do not have approval rights')], 403);
        }
        checkCSRF();

        $selfApprove = !empty($_POST['allow_self_approval']) ? '1' : '0';

        // Only methods the app actually offers, so a typo cannot quietly send
        // every receipt down the wrong path.
        $valid  = ['cash', 'cheque', 'bank_transfer', 'mobile_money'];
        $chosen = array_values(array_intersect($valid, (array) ($_POST['float_methods'] ?? [])));
        if (!$chosen) {
            jsonResponse(['success' => false, 'message' =>
                __('led_err_no_cash_method', 'Choose at least one method, or nobody can ever hold cash')], 400);
        }

        updateSetting('allow_self_approval', $selfApprove);
        updateSetting('float_payment_methods', implode(',', $chosen));
        logActivity('updated', 'settings', null, [
            'allow_self_approval'   => $selfApprove,
            'float_payment_methods' => implode(',', $chosen),
        ]);
        jsonResponse(['success' => true, 'message' => __('saved', 'Saved')]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
