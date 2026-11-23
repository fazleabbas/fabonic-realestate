<?php
/**
 * FIS Real Estate — Journal (Part 30, Phase 1)
 * Entry list with status filter, and the pending queue for approvers.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger.php';
$pageTitle = __('led_journal', 'Journal');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$filterStatus = trim($_GET['status'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = RECORDS_PER_PAGE;

$where = ['1=1'];
$params = [];
if (in_array($filterStatus, ['pending', 'posted', 'rejected'], true)) {
    $where[] = 'e.status = :status';
    $params['status'] = $filterStatus;
}
if ($dateFrom !== '') { $where[] = 'e.entry_date >= :df'; $params['df'] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'e.entry_date <= :dt'; $params['dt'] = $dateTo; }
if ($search   !== '') {
    $where[] = '(e.entry_no LIKE :s OR e.memo LIKE :s)';
    $params['s'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) FROM journal_entries e WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT e.*, u.full_name AS created_by_name
    FROM journal_entries e
    LEFT JOIN users u ON u.id = e.created_by
    WHERE {$whereSql}
    ORDER BY e.entry_date DESC, e.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$entries = $stmt->fetchAll();

$pendingCount = ledgerPendingCount();
$statusColors = ['pending' => 'warning text-dark', 'posted' => 'success', 'rejected' => 'secondary'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-list-columns me-2"></i><?php _e('led_journal', 'Journal'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('led_journal', 'Journal'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($pendingCount > 0): ?>
        <a href="?status=pending" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-hourglass-split me-1"></i> <?php echo $pendingCount; ?> <?php _e('led_awaiting', 'awaiting approval'); ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('ledger', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/ledger/create.php" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> <?php _e('led_new_entry', 'New Entry'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('ledger.journal'); ?>

<?php ledgerNav('journal'); ?>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1"><?php _e('search'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php _e('led_search_entries', 'Entry no. or memo'); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('status'); ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses'); ?></option>
                    <option value="pending"  <?php echo $filterStatus === 'pending'  ? 'selected' : ''; ?>><?php _e('led_pending', 'Pending'); ?></option>
                    <option value="posted"   <?php echo $filterStatus === 'posted'   ? 'selected' : ''; ?>><?php _e('led_posted', 'Posted'); ?></option>
                    <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>><?php _e('led_rejected', 'Rejected'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('date_from', 'From'); ?></label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('date_to', 'To'); ?></label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/ledger/journal.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:120px;"><?php _e('led_entry_no', 'Entry'); ?></th>
                    <th style="width:110px;"><?php _e('date'); ?></th>
                    <th><?php _e('led_memo', 'Memo'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('led_created_by', 'Created by'); ?></th>
                    <th class="text-end" style="width:150px;"><?php _e('amount'); ?></th>
                    <th style="width:110px;"><?php _e('status'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$entries): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">
                    <i class="bi bi-journal-x d-block mb-2" style="font-size:2rem;opacity:.4;"></i>
                    <?php _e('led_no_entries', 'No journal entries yet'); ?>
                </td></tr>
                <?php endif; ?>
                <?php foreach ($entries as $e): ?>
                <tr style="cursor:pointer;" onclick="location.href='<?php echo BASE_URL; ?>modules/ledger/view.php?id=<?php echo (int)$e['id']; ?>'">
                    <td class="font-monospace small"><?php echo htmlspecialchars($e['entry_no']); ?></td>
                    <td class="small"><?php echo formatDate($e['entry_date']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($e['memo'] ?: '—'); ?>
                        <?php if ($e['source_type'] !== 'manual'): ?>
                        <span class="badge bg-light text-secondary border ms-1"><?php echo htmlspecialchars(str_replace('_', ' ', $e['source_type'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($e['reversed_by_entry_id'])): ?>
                        <span class="badge bg-light text-danger border ms-1"><?php _e('led_reversed', 'Reversed'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($e['created_by_name'] ?? '—'); ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($e['total_debit'], $e['currency'], 2); ?></td>
                    <td><span class="badge bg-<?php echo $statusColors[$e['status']] ?? 'secondary'; ?>"><?php echo ledgerStatusLabel($e['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo $total; ?> <?php _e('led_entries_lower', 'entries'); ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php
            $qs = $_GET;
            for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++):
                $qs['page'] = $p;
            ?>
            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>"><?php echo $p; ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
