<?php
/**
 * FIS Real Estate — Expenses List (Part 20.1)
 * Expense categories CRUD + expense recording with filters and modals.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/entity_documents.php';
$pageTitle = __('exp_expenses', 'Expenses');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('exp_tier_required', 'Enterprise tier or higher required for financial reporting.'), 'warning');
    redirect('index.php');
}
if (getSetting('financial_reports_enabled', '0') !== '1') {
    setAlert(__('exp_disabled', 'Financial reports module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('expenses', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filters
$filterProperty = (int)($_GET['property_id'] ?? 0);
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterSearch = trim($_GET['search'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Get dropdowns
$properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name")->fetchAll();
$categories = $conn->query("SELECT id, name FROM expense_categories ORDER BY name")->fetchAll();
$units = $conn->query("SELECT u.id, u.unit_number, u.property_id, p.name AS property_name FROM units u INNER JOIN properties p ON u.property_id = p.id ORDER BY p.name, u.unit_number")->fetchAll();

// Build query
$where = "e.status = 'active'" . propertyScopeSql('e.property_id');
$params = [];

if ($filterProperty) { $where .= " AND e.property_id = :pid"; $params['pid'] = $filterProperty; }
if ($filterCategory) { $where .= " AND e.category_id = :cid"; $params['cid'] = $filterCategory; }
if ($filterSearch) { $where .= " AND (e.description LIKE :s1 OR e.vendor_name LIKE :s2)"; $params['s1'] = "%$filterSearch%"; $params['s2'] = "%$filterSearch%"; }
if ($filterDateFrom) { $where .= " AND e.expense_date >= :df"; $params['df'] = $filterDateFrom; }
if ($filterDateTo) { $where .= " AND e.expense_date <= :dt"; $params['dt'] = $filterDateTo; }

$countStmt = $conn->prepare("SELECT COUNT(*) FROM expenses e WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pagination = paginate($total, $page);

$sql = "SELECT e.*, ec.name AS category_name, p.name AS property_name,
               u2.unit_number, usr.full_name AS recorded_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN properties p ON e.property_id = p.id
        LEFT JOIN units u2 ON e.unit_id = u2.id
        LEFT JOIN users usr ON e.recorded_by = usr.id
        WHERE $where
        ORDER BY e.expense_date DESC, e.id DESC
        LIMIT :offset, :limit";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
$stmt->execute();
$expenses = $stmt->fetchAll();

// Summary stats
$totalAmount = 0;
$thisMonthStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'active' AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())" . propertyScopeSql('property_id'));
$thisMonthStmt->execute();
$thisMonth = (float)$thisMonthStmt->fetchColumn();

$totalStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'active' AND YEAR(expense_date) = YEAR(CURDATE())" . propertyScopeSql('property_id'));
$totalStmt->execute();
$yearTotal = (float)$totalStmt->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-receipt-cutoff me-2"></i><?php _e('exp_expenses', 'Expenses'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"><?php _e('dashboard'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('exp_expenses', 'Expenses'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <a href="<?php echo BASE_URL; ?>api/expenses.php?action=export_expenses<?php echo $filterProperty ? '&property_id=' . $filterProperty : ''; ?><?php echo $filterDateFrom ? '&date_from=' . urlencode($filterDateFrom) : ''; ?><?php echo $filterDateTo ? '&date_to=' . urlencode($filterDateTo) : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i><?php _e('exp_export_csv', 'Export CSV'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/expenses/reports.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-bar-chart-line me-1"></i><?php _e('exp_reports', 'Financial Reports'); ?>
        </a>
        <?php if (hasPermission('expenses', 'create')): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal" onclick="resetExpenseForm()">
            <i class="bi bi-plus-circle me-1"></i><?php _e('exp_add', 'Add Expense'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('expenses.index'); ?>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $total; ?></div>
                <div class="stat-card-label"><?php _e('exp_total_records', 'Total Expenses'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-cash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($yearTotal); ?></div>
                <div class="stat-card-label"><?php _e('exp_year_total', 'This Year'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-calendar-month"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($thisMonth); ?></div>
                <div class="stat-card-label"><?php _e('exp_this_month', 'This Month'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-tags"></i></div>
            <div>
                <div class="stat-card-value"><?php echo count($categories); ?></div>
                <div class="stat-card-label"><?php _e('exp_categories', 'Categories'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="category_id" class="form-select form-select-sm">
                    <option value=""><?php _e('exp_all_categories', 'All Categories'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filterCategory == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
            </div>
            <div class="col-6 col-md-2">
                <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="<?php _e('search'); ?>...">
            </div>
            <div class="col-6 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/expenses/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Expenses Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($expenses)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-receipt-cutoff" style="font-size:2.5rem;"></i>
            <p class="mt-2"><?php _e('exp_no_expenses', 'No expenses found'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('date'); ?></th>
                        <th><?php _e('exp_description', 'Description'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('category'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('exp_vendor', 'Vendor'); ?></th>
                        <th class="text-end"><?php _e('amount'); ?></th>
                        <th class="text-center" style="width:80px;"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $exp): ?>
                    <tr>
                        <td><small><?php echo formatDate($exp['expense_date']); ?></small></td>
                        <td>
                            <span class="fw-semibold"><?php echo htmlspecialchars($exp['description'] ?: '—'); ?></span>
                            <?php if ($exp['receipt_path']): ?>
                            <i class="bi bi-paperclip text-muted ms-1" title="<?php _e('exp_receipt_attached', 'Receipt attached'); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <small><?php echo htmlspecialchars($exp['property_name'] ?? ''); ?></small>
                            <?php if ($exp['unit_number']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($exp['unit_number']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($exp['category_name'] ?? '—'); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($exp['vendor_name'] ?: '—'); ?></small></td>
                        <td class="text-end fw-semibold text-danger"><?php echo formatCurrency($exp['amount'], $exp['currency']); ?></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <?php if (hasPermission('expenses', 'edit')): ?>
                                <button class="btn btn-outline-primary btn-sm" onclick="editExpense(<?php echo $exp['id']; ?>)" title="<?php _e('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($exp['receipt_path']): ?>
                                <a href="<?php echo BASE_URL . 'uploads/' . $exp['receipt_path']; ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="<?php _e('exp_view_receipt', 'View Receipt'); ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php endif; ?>
                                <?php // Supplier invoices and receipts belong on the expense itself.
                                      echo entityDocsButton('expense', $exp['id'], __('doc_attach_invoice', 'Attach the invoice')); ?>
                                <?php if (hasPermission('expenses', 'delete')): ?>
                                <button class="btn btn-outline-danger btn-sm" onclick="voidExpense(<?php echo $exp['id']; ?>)" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo $pagination['total_records']; ?> <?php _e('records'); ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($pagination['has_prev']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&property_id=<?php echo $filterProperty; ?>&category_id=<?php echo $filterCategory; ?>&search=<?php echo urlencode($filterSearch); ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>">&laquo;</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($pagination['total_pages'], $page + 2); $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&property_id=<?php echo $filterProperty; ?>&category_id=<?php echo $filterCategory; ?>&search=<?php echo urlencode($filterSearch); ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($pagination['has_next']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&property_id=<?php echo $filterProperty; ?>&category_id=<?php echo $filterCategory; ?>&search=<?php echo urlencode($filterSearch); ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="expenseModalTitle"><?php _e('exp_add', 'Add Expense'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="expenseForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="id" id="exp_id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('property'); ?> <span class="text-danger">*</span></label>
                            <select name="property_id" id="exp_property_id" class="form-select" required onchange="filterUnits(this.value)">
                                <option value=""><?php _e('select'); ?>...</option>
                                <?php foreach ($properties as $prop): ?>
                                <option value="<?php echo $prop['id']; ?>"><?php echo htmlspecialchars($prop['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('unit'); ?></label>
                            <select name="unit_id" id="exp_unit_id" class="form-select">
                                <option value=""><?php _e('exp_property_wide', 'Property-wide (no specific unit)'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('category'); ?></label>
                            <select name="category_id" id="exp_category_id" class="form-select">
                                <option value=""><?php _e('exp_uncategorized', 'Uncategorized'); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('exp_date', 'Expense Date'); ?> <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" id="exp_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="exp_amount" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('exp_vendor', 'Vendor'); ?></label>
                            <input type="text" name="vendor_name" id="exp_vendor" class="form-control" placeholder="<?php _e('exp_vendor_placeholder', 'Vendor/Supplier name'); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php _e('description'); ?></label>
                            <textarea name="description" id="exp_description" class="form-control" rows="2" placeholder="<?php _e('exp_desc_placeholder', 'What was the expense for?'); ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('exp_receipt', 'Receipt'); ?></label>
                            <input type="file" name="receipt" id="exp_receipt" class="form-control form-control-sm" accept="image/*,.pdf">
                            <small class="text-muted"><?php _e('exp_receipt_hint', 'Upload receipt image or PDF'); ?></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" id="expenseSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i><?php _e('save'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Category Management Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php _e('exp_manage_categories', 'Manage Categories'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="newCatName" placeholder="<?php _e('exp_new_category', 'New category name'); ?>">
                    <button class="btn btn-primary" onclick="addCategory()"><i class="bi bi-plus"></i></button>
                </div>
                <div id="catList"></div>
            </div>
        </div>
    </div>
</div>

<script>
const allUnits = <?php echo json_encode($units); ?>;

function filterUnits(propertyId) {
    const sel = document.getElementById('exp_unit_id');
    sel.innerHTML = '<option value=""><?php echo __("exp_property_wide", "Property-wide"); ?></option>';
    allUnits.filter(u => u.property_id == propertyId).forEach(u => {
        sel.innerHTML += `<option value="${u.id}">${u.unit_number} (${u.property_name})</option>`;
    });
}

function resetExpenseForm() {
    document.getElementById('expenseModalTitle').textContent = '<?php echo __("exp_add", "Add Expense"); ?>';
    document.getElementById('expenseForm').reset();
    document.getElementById('exp_id').value = '';
    document.getElementById('exp_date').value = '<?php echo date("Y-m-d"); ?>';
}

function editExpense(id) {
    fetch(BASE_URL + 'api/expenses.php?action=get&id=' + id)
        .then(r => r.json())
        .then(res => {
            if (!res.success) return alert(res.message);
            const d = res.data;
            document.getElementById('expenseModalTitle').textContent = '<?php echo __("exp_edit", "Edit Expense"); ?>';
            document.getElementById('exp_id').value = d.id;
            document.getElementById('exp_property_id').value = d.property_id;
            filterUnits(d.property_id);
            setTimeout(() => { document.getElementById('exp_unit_id').value = d.unit_id || ''; }, 100);
            document.getElementById('exp_category_id').value = d.category_id || '';
            document.getElementById('exp_date').value = d.expense_date;
            document.getElementById('exp_amount').value = d.amount;
            document.getElementById('exp_vendor').value = d.vendor_name || '';
            document.getElementById('exp_description').value = d.description || '';
            new bootstrap.Modal(document.getElementById('expenseModal')).show();
        });
}

function voidExpense(id) {
    if (!confirm('<?php echo __("exp_void_confirm", "Void this expense?"); ?>')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch(BASE_URL + 'api/expenses.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { alert(res.message); if (res.success) location.reload(); });
}

document.getElementById('expenseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const isEdit = fd.get('id');
    fd.append('action', isEdit ? 'update' : 'create');

    const btn = document.getElementById('expenseSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    fetch(BASE_URL + 'api/expenses.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i><?php echo __("save"); ?>';
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('expenseModal')).hide();
                location.reload();
            } else {
                alert(res.message);
            }
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle me-1"></i><?php echo __("save"); ?>'; });
});

function addCategory() {
    const name = document.getElementById('newCatName').value.trim();
    if (!name) return;
    const fd = new FormData();
    fd.append('action', 'create_category');
    fd.append('name', name);
    fetch(BASE_URL + 'api/expenses.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { alert(res.message); if (res.success) location.reload(); });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
