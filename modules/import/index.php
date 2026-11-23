<?php
/**
 * FIS Real Estate — Data Import: the setup page (part 40).
 * Go-live date, the import steps in order, every batch with its Undo, and
 * Finish setup. Admins only.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/import/engine.php';
$pageTitle = __('imp_title', 'Data Import');

if (!importCanUse()) {
    setAlert(__('imp_admins_only', 'Only admins can import data.'), 'danger');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'golive') {
        $d = trim($_POST['go_live_date'] ?? '');
        if (importCommittedCount() > 0) {
            setAlert(__('imp_golive_fixed', 'The go-live date cannot change while imports exist: every opening entry and billing date depends on it. Undo the imports first.'), 'danger');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            setAlert(__('imp_golive_invalid', 'Enter a valid go-live date.'), 'danger');
        } else {
            updateSetting('go_live_date', $d);
            logActivity('updated', 'settings', null, ['go_live_date' => $d]);
            setAlert(sprintf(__('imp_golive_saved', 'Go-live date set to %s. Opening figures will be dated %s.'),
                formatDate($d), formatDate(importOpeningDate())), 'success');
        }
    } elseif ($action === 'undo') {
        $result = importUndo((int) ($_POST['batch_id'] ?? 0));
        $result['success']
            ? setAlert(sprintf(__('imp_undone', 'Import #%d was undone.'), (int) $_POST['batch_id']), 'success')
            : setAlert(implode(' ', $result['errors']), 'danger');
    } elseif ($action === 'finish') {
        if (empty($_POST['confirm'])) {
            setAlert(__('imp_finish_tick', 'Tick the box to confirm before finishing setup.'), 'danger');
        } else {
            $result = importFinishSetup();
            $result['success']
                ? setAlert(sprintf(__('imp_finished', 'Setup finished. Everything dated on or before %s is now closed.'), formatDate(importOpeningDate())), 'success')
                : setAlert(implode(' ', $result['errors']), 'danger');
        }
    }
    redirect('modules/import/');
}

$goLive  = importGoLiveDate();
$opening = importOpeningDate();
$state   = importSetupState();
$locked  = importBlockedByLock();
$types   = importTypes();
$hasBatches = importCommittedCount() > 0;
$activeLeases = (int) $conn->query("SELECT COUNT(*) FROM leases WHERE status = 'active'")->fetchColumn();

$batches = $conn->query("SELECT b.*, u.full_name AS created_by_name, uu.full_name AS undone_by_name
                         FROM import_batches b
                         LEFT JOIN users u ON u.id = b.created_by
                         LEFT JOIN users uu ON uu.id = b.undone_by
                         ORDER BY b.id DESC")->fetchAll();
$doneByType = [];
foreach ($batches as $b) if ($b['status'] === 'committed') $doneByType[$b['type']] = ($doneByType[$b['type']] ?? 0) + 1;

$finishChecks = $state === 'setting_up' ? importFinishChecks() : [];
$finishBlocked = (bool) array_filter($finishChecks, function ($c) { return $c[0] === 'block'; });

$tableLabels = [
    'properties' => __('properties', 'Properties'), 'units' => __('units', 'Units'), 'tenants' => __('tenants', 'Tenants'),
    'leases' => __('leases', 'Leases'), 'deposits' => __('deposits', 'Deposits'), 'charges' => __('imp_charges', 'Charges'),
    'payments' => __('imp_advances_l', 'Advances'), 'journal_entries' => __('imp_entries', 'Accounting entries'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-box-arrow-in-down me-2"></i><?php _e('imp_title', 'Data Import'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/settings/"><?php _e('settings'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('imp_title', 'Data Import'); ?></li>
            </ol>
        </nav>
    </div>
    <div>
        <?php if ($state === 'finished'): ?>
            <span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>
                <?php echo sprintf(__('imp_state_finished', 'Setup finished on %s'), formatDate(substr(getSetting('setup_finished_at'), 0, 10))); ?></span>
        <?php elseif ($state === 'reopened'): ?>
            <span class="badge bg-warning text-dark fs-6"><i class="bi bi-unlock me-1"></i><?php _e('imp_state_reopened', 'Reopened'); ?></span>
        <?php else: ?>
            <span class="badge bg-info text-dark fs-6"><i class="bi bi-tools me-1"></i><?php _e('imp_state_setting_up', 'Setting up'); ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Always shown: what this page does to the client's data. -->
<div class="alert alert-warning d-flex gap-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
    <div>
        <strong><?php _e('imp_banner_title', 'Import your opening data.'); ?></strong>
        <?php _e('imp_banner', 'Use this page to load what already exists when you start using the system: your properties, units, tenants and leases, what tenants owe you today, the deposits you hold, and your account balances. Imports write real records. Amounts owed, deposits and balances go straight into arrears, tenant statements and your accounts. Work through the steps in order, check every preview carefully, and click Finish setup when your figures agree with your old records.'); ?>
    </div>
</div>

<!-- Step 0: go-live date -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-flag me-1"></i> <?php _e('imp_golive', 'Go-live date'); ?></div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            <?php _e('imp_golive_help', 'The first day this system keeps your books. Everything you import is dated the day before, and automatic rent billing starts on this day.'); ?>
        </p>
        <form method="post" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="golive">
            <div class="col-12 col-md-4">
                <input type="date" name="go_live_date" class="form-control" required
                       value="<?php echo htmlspecialchars($goLive ?? ''); ?>" <?php echo $hasBatches ? 'disabled' : ''; ?>>
            </div>
            <div class="col-12 col-md-8">
                <?php if ($hasBatches): ?>
                    <span class="small text-muted"><i class="bi bi-lock me-1"></i><?php _e('imp_golive_locked_help', 'Fixed while imports exist. Undo them to change it.'); ?></span>
                <?php else: ?>
                    <button class="btn btn-outline-primary"><i class="bi bi-check-lg me-1"></i><?php _e('save'); ?></button>
                <?php endif; ?>
                <?php if ($opening): ?>
                    <span class="small ms-2"><?php echo sprintf(__('imp_opening_dated', 'Opening figures are dated %s.'), '<strong>' . formatDate($opening) . '</strong>'); ?></span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- The steps, in order -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-list-ol me-1"></i> <?php _e('imp_steps', 'Import steps'); ?></div>
    <div class="list-group list-group-flush">
        <?php $n = 0; foreach ($types as $key => $t): $n++;
            $done = $doneByType[$key] ?? 0;
            $disabled = !$goLive || ($t['posts'] && $locked) || ($t['needs'] === 'lease' && !$activeLeases);
            $reason = !$goLive ? __('imp_need_golive', 'Set the go-live date first')
                    : (($t['posts'] && $locked) ? __('imp_need_unlock', 'Setup is finished')
                    : (($t['needs'] === 'lease' && !$activeLeases) ? __('imp_need_leases', 'Import the rent roll first') : ''));
        ?>
        <div class="list-group-item d-flex flex-wrap align-items-center gap-3 py-3">
            <span class="badge rounded-pill <?php echo $done ? 'bg-success' : 'bg-secondary'; ?>" style="width:2rem;">
                <?php echo $done ? '<i class="bi bi-check"></i>' : $n; ?>
            </span>
            <div class="flex-grow-1" style="min-width:220px;">
                <div class="fw-semibold"><i class="bi <?php echo $t['icon']; ?> me-1"></i><?php echo htmlspecialchars($t['label']); ?>
                    <?php if ($done): ?><span class="badge bg-success-subtle text-success ms-1"><?php echo sprintf(__('imp_n_done', '%d import(s)'), $done); ?></span><?php endif; ?>
                </div>
                <div class="small text-muted"><?php echo htmlspecialchars($t['what']); ?></div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo BASE_URL; ?>modules/import/template.php?type=<?php echo $key; ?>">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i><?php _e('imp_template', 'Template'); ?>
                </a>
                <a class="btn btn-sm btn-link px-1" title="<?php _e('imp_csv_title', 'Header row only, as CSV'); ?>"
                   href="<?php echo BASE_URL; ?>modules/import/template.php?type=<?php echo $key; ?>&format=csv">CSV</a>
                <?php if ($disabled): ?>
                    <button class="btn btn-sm btn-primary" disabled title="<?php echo htmlspecialchars($reason); ?>">
                        <i class="bi bi-upload me-1"></i><?php _e('imp_import', 'Import'); ?>
                    </button>
                <?php else: ?>
                    <a class="btn btn-sm btn-primary" href="<?php echo BASE_URL; ?>modules/import/upload.php?type=<?php echo $key; ?>">
                        <i class="bi bi-upload me-1"></i><?php _e('imp_import', 'Import'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($disabled && $reason): ?><div class="w-100 small text-muted ps-5"><?php echo htmlspecialchars($reason); ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- History -->
<div class="card mb-3" id="history">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> <?php _e('imp_history', 'Imports so far'); ?></div>
    <?php if (!$batches): ?>
        <div class="card-body text-muted small"><?php _e('imp_history_none', 'Nothing has been imported yet.'); ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th><th><?php _e('type', 'Type'); ?></th><th><?php _e('imp_h_what', 'What it created'); ?></th>
                    <th><?php _e('imp_h_by', 'By'); ?></th><th><?php _e('status', 'Status'); ?></th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b):
                $summary = json_decode($b['summary'] ?? '', true) ?: [];
                $blockers = $b['status'] === 'committed' ? importUndoBlockers($b['id']) : [];
                $items = $b['status'] === 'committed' ? importBatchItems($b['id']) : [];
                $created = [];
                foreach ($items as $table => $list) {
                    $c = count(array_filter($list, function ($i) { return $i['action'] === 'created'; }));
                    if ($c) $created[] = $c . ' ' . mb_strtolower($tableLabels[$table] ?? $table);
                }
                $undoText = sprintf(__('imp_undo_confirm', 'This will remove the %s this import created, and reverse its accounting entries. This cannot be undone.'),
                    implode(', ', $created) ?: '—');
            ?>
                <tr>
                    <td><?php echo (int) $b['id']; ?></td>
                    <td>
                        <?php echo htmlspecialchars($types[$b['type']]['label'] ?? $b['type']); ?>
                        <div class="small text-muted text-truncate" style="max-width:220px;"><?php echo htmlspecialchars($b['file_name'] ?? ''); ?></div>
                    </td>
                    <td class="small"><?php foreach ($summary['lines'] ?? [] as $line) echo htmlspecialchars($line) . '<br>'; ?></td>
                    <td class="small"><?php echo htmlspecialchars($b['created_by_name'] ?? ''); ?><br><span class="text-muted"><?php echo formatDateTime($b['created_at']); ?></span></td>
                    <td>
                        <?php if ($b['status'] === 'committed'): ?>
                            <span class="badge bg-success"><?php _e('imp_committed', 'Imported'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php _e('imp_status_undone', 'Undone'); ?></span>
                            <div class="small text-muted"><?php echo htmlspecialchars($b['undone_by_name'] ?? ''); ?> <?php echo formatDateTime($b['undone_at']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($b['status'] === 'committed'): ?>
                            <?php if ($blockers): ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled><?php _e('imp_undo', 'Undo'); ?></button>
                                <div class="small text-muted text-start mt-1" style="max-width:260px;">
                                    <?php foreach ($blockers as $why) echo '<div><i class="bi bi-info-circle me-1"></i>' . htmlspecialchars($why) . '</div>'; ?>
                                </div>
                            <?php else: ?>
                                <form method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($undoText), ENT_QUOTES); ?>);">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="undo">
                                    <input type="hidden" name="batch_id" value="<?php echo (int) $b['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise me-1"></i><?php _e('imp_undo', 'Undo'); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Finish setup -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lock me-1"></i> <?php _e('imp_finish', 'Finish setup'); ?></div>
    <div class="card-body">
        <?php if ($state === 'finished'): ?>
            <p class="mb-2"><?php echo sprintf(__('imp_finished_info', 'Your opening figures are final: nothing dated on or before %s can be posted, changed or imported.'), '<strong>' . formatDate(ledgerLockDate()) . '</strong>'); ?></p>
            <p class="small text-muted mb-0"><?php _e('imp_reopen_help', 'To correct them, an admin with approval rights can reopen the books in Ledger → Account Mapping by clearing or moving the "Closed up to" date. Reopening allows your opening balances to be changed, and reports for earlier periods may change. Lock again when you are done.'); ?>
                <a href="<?php echo BASE_URL; ?>modules/ledger/mappings.php"><?php _e('imp_go_mapping', 'Go to Account Mapping'); ?></a></p>
        <?php elseif ($state === 'reopened'): ?>
            <div class="alert alert-warning mb-0"><?php _e('imp_reopened_info', 'Setup was finished but the books have since been reopened, so opening figures can be changed and imported again. Close them again in Ledger → Account Mapping when you are done.'); ?></div>
        <?php else: ?>
            <p class="mb-2"><?php echo $opening
                ? sprintf(__('imp_finish_warning', 'After this, nobody can post, change or import anything dated on or before %s. Your opening figures become final. Only an admin can reopen them.'), '<strong>' . formatDate($opening) . '</strong>')
                : __('imp_f_no_golive', 'Set the go-live date first.'); ?></p>

            <?php if ($finishChecks): ?>
                <ul class="list-unstyled small mb-3">
                <?php foreach ($finishChecks as $c): ?>
                    <li class="<?php echo $c[0] === 'block' ? 'text-danger fw-semibold' : 'text-danger'; ?>">
                        <i class="bi <?php echo $c[0] === 'block' ? 'bi-x-circle' : 'bi-exclamation-circle'; ?> me-1"></i><?php echo htmlspecialchars($c[1]); ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php elseif ($opening): ?>
                <p class="small text-success"><i class="bi bi-check-circle me-1"></i><?php _e('imp_checks_ok', 'All checks pass: the ledger balances and receivables and deposits agree with your records.'); ?></p>
            <?php endif; ?>

            <?php if ($opening && !$finishBlocked): ?>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="finish">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="finishConfirm" required>
                    <label class="form-check-label" for="finishConfirm"><?php _e('imp_finish_tickbox', 'My opening figures agree with my old records. Close everything up to the day before go-live.'); ?></label>
                </div>
                <button class="btn btn-danger"><i class="bi bi-lock-fill me-1"></i><?php _e('imp_finish', 'Finish setup'); ?></button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
