<?php
/**
 * FIS Real Estate — Vacancy Listings Management (Part 20.4)
 * List, create, edit vacancy listings.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('vac_vacancies', 'Vacancy Listings');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('vac_tier_required', 'Enterprise tier or higher required for vacancy advertising.'), 'warning');
    redirect('index.php');
}
if (getSetting('vacancy_advertising_enabled', '0') !== '1') {
    setAlert(__('vac_disabled', 'Vacancy advertising module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('vacancies', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filters
$filterProperty = (int)($_GET['property_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Properties dropdown
$properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name")->fetchAll();

// Build query
$where = "1=1" . propertyScopeSql('u.property_id');
$params = [];
if ($filterProperty) { $where .= " AND u.property_id = :pid"; $params['pid'] = $filterProperty; }
if ($filterStatus) { $where .= " AND vl.status = :status"; $params['status'] = $filterStatus; }
if ($filterSearch) { $where .= " AND (vl.title LIKE :s1 OR u.unit_number LIKE :s2)"; $params['s1'] = "%$filterSearch%"; $params['s2'] = "%$filterSearch%"; }

$countStmt = $conn->prepare("SELECT COUNT(*) FROM vacancy_listings vl INNER JOIN units u ON vl.unit_id = u.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pagination = paginate($total, $page);

$sql = "SELECT vl.*, u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm,
               p.name AS property_name, p.city,
               (SELECT COUNT(*) FROM vacancy_inquiries vi WHERE vi.listing_id = vl.id) AS inquiry_count,
               (SELECT COUNT(*) FROM vacancy_inquiries vi WHERE vi.listing_id = vl.id AND vi.status = 'new') AS new_inquiry_count
        FROM vacancy_listings vl
        INNER JOIN units u ON vl.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE $where
        ORDER BY vl.created_at DESC
        LIMIT :offset, :limit";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
$stmt->execute();
$listings = $stmt->fetchAll();

// Stats
$activeListings = (int)$conn->query("SELECT COUNT(*) FROM vacancy_listings WHERE status = 'active'" . unitScopeSql('unit_id'))->fetchColumn();
$totalViews = (int)$conn->query("SELECT COALESCE(SUM(views_count), 0) FROM vacancy_listings WHERE 1=1" . unitScopeSql('unit_id'))->fetchColumn();
$totalInquiries = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE listing_id IN (SELECT id FROM vacancy_listings WHERE 1=1" . unitScopeSql('unit_id') . ")")->fetchColumn();
$newInquiries = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'new' AND listing_id IN (SELECT id FROM vacancy_listings WHERE 1=1" . unitScopeSql('unit_id') . ")")->fetchColumn();

$statusColors = ['active' => 'success', 'inactive' => 'secondary', 'rented' => 'primary'];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-megaphone me-2"></i><?php _e('vac_vacancies', 'Vacancy Listings'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"><?php _e('dashboard'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('vac_vacancies', 'Vacancy Listings'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <a href="<?php echo BASE_URL; ?>modules/vacancies/inquiries.php" class="btn btn-sm btn-outline-info">
            <i class="bi bi-chat-dots me-1"></i><?php _e('vac_inquiries', 'Inquiries'); ?>
            <?php if ($newInquiries > 0): ?><span class="badge bg-danger"><?php echo $newInquiries; ?></span><?php endif; ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/vacancies/public.php" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-globe me-1"></i><?php _e('vac_public_page', 'Public Page'); ?>
        </a>
        <?php if (hasPermission('vacancies', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/vacancies/create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-circle me-1"></i><?php _e('vac_add', 'Add Listing'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('vacancies.index'); ?>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-megaphone"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $activeListings; ?></div>
                <div class="stat-card-label"><?php _e('vac_active_listings', 'Active Listings'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-eye"></i></div>
            <div>
                <div class="stat-card-value"><?php echo number_format($totalViews); ?></div>
                <div class="stat-card-label"><?php _e('vac_total_views', 'Total Views'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-chat-dots"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalInquiries; ?></div>
                <div class="stat-card-label"><?php _e('vac_total_inquiries', 'Total Inquiries'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-bell"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $newInquiries; ?></div>
                <div class="stat-card-label"><?php _e('vac_new_inquiries', 'New Inquiries'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                    <option value="rented" <?php echo $filterStatus === 'rented' ? 'selected' : ''; ?>><?php _e('vac_rented', 'Rented'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="<?php _e('search'); ?>...">
            </div>
            <div class="col-6 col-md-4 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/vacancies/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Listings Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($listings)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-megaphone" style="font-size:2.5rem;"></i>
            <p class="mt-2"><?php _e('vac_no_listings', 'No vacancy listings found'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('vac_listing', 'Listing'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                        <th class="text-end"><?php _e('exp_rent', 'Rent'); ?></th>
                        <th class="text-center"><?php _e('vac_inquiries', 'Inquiries'); ?></th>
                        <th class="text-center d-none d-md-table-cell"><?php _e('vac_views', 'Views'); ?></th>
                        <th class="text-center"><?php _e('status'); ?></th>
                        <th class="text-center" style="width:100px;"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                    <tr>
                        <td>
                            <a href="<?php echo BASE_URL; ?>modules/vacancies/view.php?id=<?php echo $listing['id']; ?>" class="fw-semibold text-decoration-none">
                                <?php echo htmlspecialchars($listing['title']); ?>
                            </a>
                            <br><small class="text-muted"><?php echo htmlspecialchars($listing['unit_number']); ?> — <?php echo ucfirst($listing['unit_type']); ?>
                            <?php if ($listing['bedrooms']): ?> | <?php echo $listing['bedrooms']; ?> BR<?php endif; ?>
                            </small>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <small><?php echo htmlspecialchars($listing['property_name']); ?></small>
                            <?php if ($listing['city']): ?><br><small class="text-muted"><?php echo htmlspecialchars($listing['city']); ?></small><?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?php echo formatCurrency($listing['rent_amount'], $listing['currency']); ?></td>
                        <td class="text-center">
                            <?php echo (int)$listing['inquiry_count']; ?>
                            <?php if ($listing['new_inquiry_count'] > 0): ?>
                            <span class="badge bg-danger" style="font-size:0.6rem;"><?php echo $listing['new_inquiry_count']; ?> new</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center d-none d-md-table-cell"><?php echo number_format($listing['views_count']); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $statusColors[$listing['status']] ?? 'secondary'; ?>"><?php echo ucfirst($listing['status']); ?></span>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="<?php echo BASE_URL; ?>modules/vacancies/view.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline-primary btn-sm" title="<?php _e('view'); ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (hasPermission('vacancies', 'edit')): ?>
                                <a href="<?php echo BASE_URL; ?>modules/vacancies/create.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline-secondary btn-sm" title="<?php _e('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (hasPermission('vacancies', 'delete')): ?>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteListing(<?php echo $listing['id']; ?>)" title="<?php _e('delete'); ?>">
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
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&property_id=<?php echo $filterProperty; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>">&laquo;</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($pagination['total_pages'], $page + 2); $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&property_id=<?php echo $filterProperty; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($pagination['has_next']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&property_id=<?php echo $filterProperty; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<script>
function deleteListing(id) {
    if (!confirm('<?php echo __("vac_delete_confirm", "Delete this listing? All inquiries will also be deleted."); ?>')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch(BASE_URL + 'api/vacancies.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { alert(res.message); if (res.success) location.reload(); });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
