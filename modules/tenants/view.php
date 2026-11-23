<?php
/**
 * FIS Real Estate — View Tenant (7.3 + 7.4 + 7.5)
 * Tenant detail page with:
 * - Info bar + stat cards
 * - Status change with move_out_date
 * - ID copy upload
 * - Active lease / unit assignment (7.3)
 * - History tabs: leases, payments, inspections (7.5)
 * - Activity log
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('tenants', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Tenant not found', 'danger');
    redirect('modules/tenants/');
}

// Fetch tenant
$stmt = $conn->prepare("SELECT * FROM tenants WHERE id = :id");
$stmt->execute(['id' => $id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    setAlert('Tenant not found', 'danger');
    redirect('modules/tenants/');
}

// Property-based access scoping guard
if (!canAccessTenant($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/tenants/');
}

$pageTitle = $tenant['first_name'] . ' ' . $tenant['last_name'];

// Active leases for this tenant (7.3)
$leasesStmt = $conn->prepare("
    SELECT l.*, u.unit_number, p.name AS property_name, u.id AS unit_id_val, p.id AS property_id_val
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    WHERE l.tenant_id = :tid AND l.status = 'active'
    ORDER BY l.created_at DESC
");
$leasesStmt->execute(['tid' => $id]);
$activeLeases = $leasesStmt->fetchAll();

// All leases (7.5)
$allLeasesStmt = $conn->prepare("
    SELECT l.*, u.unit_number, p.name AS property_name
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    WHERE l.tenant_id = :tid
    ORDER BY l.created_at DESC
    LIMIT 50
");
$allLeasesStmt->execute(['tid' => $id]);
$allLeases = $allLeasesStmt->fetchAll();

// Payment history (7.5)
$paymentsStmt = $conn->prepare("
    SELECT py.*, u.unit_number, p.name AS property_name
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    WHERE py.tenant_id = :tid
    ORDER BY py.payment_date DESC
    LIMIT 50
");
$paymentsStmt->execute(['tid' => $id]);
$payments = $paymentsStmt->fetchAll();

// Inspection history (7.5)
$inspectionsStmt = $conn->prepare("
    SELECT i.*, u.unit_number, p.name AS property_name
    FROM inspections i
    INNER JOIN units u ON i.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    WHERE i.tenant_id = :tid
    ORDER BY i.inspection_date DESC
    LIMIT 50
");
$inspectionsStmt->execute(['tid' => $id]);
$inspections = $inspectionsStmt->fetchAll();

// Documents linked to this tenant (only when the Documents module is available)
$docsModuleActive = hasTier('professional') && getSetting('documents_enabled', '0') === '1' && hasPermission('documents', 'view');
$tenantDocs = [];
if ($docsModuleActive) {
    try {
        $docsStmt = $conn->prepare("
            SELECT d.id, d.title, d.file_name, d.file_size, d.created_at, d.is_confidential,
                   dc.name AS category_name, dc.color AS category_color
            FROM documents d
            LEFT JOIN document_categories dc ON d.category_id = dc.id
            WHERE d.entity_type = 'tenant' AND d.entity_id = :tid AND d.status = 'active'
            ORDER BY d.created_at DESC
            LIMIT 100
        ");
        $docsStmt->execute(['tid' => $id]);
        $tenantDocs = $docsStmt->fetchAll();
    } catch (PDOException $e) {
        // Documents module enabled but its tables aren't installed yet — never break the tenant page.
        $docsModuleActive = false;
    }
}

// Total payments amount
$totalPaid = 0;
foreach ($payments as $py) {
    if ($py['status'] === 'recorded') {
        $totalPaid += (float)$py['amount'];
    }
}

// Activity log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.entity_type = 'tenant'
      AND al.entity_id = :tid
    ORDER BY al.created_at DESC
    LIMIT 10
");
$historyStmt->execute(['tid' => $id]);
$activityHistory = $historyStmt->fetchAll();

$statusColors = ['active' => 'success', 'notice_given' => 'warning', 'moved_out' => 'secondary'];
$statusLabels = [
    'active' => __('active', 'Active'),
    'notice_given' => __('notice_given', 'Notice Given'),
    'moved_out' => __('moved_out', 'Moved Out'),
];

$idTypeLabels = [
    'national_id' => __('national_id', 'National ID'),
    'passport' => __('passport', 'Passport'),
    'driving_license' => __('driving_license', 'Driving License'),
    'voter_id' => __('voter_id', 'Voter ID'),
];

$leaseStatusColors = ['active' => 'success', 'expired' => 'secondary', 'terminated' => 'danger', 'renewed' => 'info'];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-person me-2"></i><?php echo htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/tenants/"><?php _e('tenants'); ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasPermission('tenants', 'edit')): ?>
        <a href="<?php echo BASE_URL; ?>modules/tenants/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('tenants.view'); ?>

<!-- Tenant Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $statusColors[$tenant['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$tenant['status']] ?? ucfirst($tenant['status']); ?>
            </span>
            <span><i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($tenant['phone']); ?></span>
            <?php if ($tenant['email']): ?>
            <span><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($tenant['email']); ?></span>
            <?php endif; ?>
            <?php if ($tenant['id_type']): ?>
            <span><i class="bi bi-card-text me-1"></i> <?php echo $idTypeLabels[$tenant['id_type']] ?? $tenant['id_type']; ?>: <?php echo htmlspecialchars($tenant['id_number'] ?? ''); ?></span>
            <?php endif; ?>
            <?php if (!empty($tenant['tin'])): ?>
            <span><i class="bi bi-receipt me-1"></i> <?php _e('tin', 'TIN'); ?>: <?php echo htmlspecialchars($tenant['tin']); ?></span>
            <?php endif; ?>
            <?php if ($tenant['move_in_date']): ?>
            <span><i class="bi bi-calendar-check me-1"></i> <?php _e('move_in', 'Moved in'); ?>: <?php echo formatDate($tenant['move_in_date']); ?></span>
            <?php endif; ?>
            <?php if ($tenant['move_out_date']): ?>
            <span><i class="bi bi-calendar-x me-1"></i> <?php _e('move_out', 'Moved out'); ?>: <?php echo formatDate($tenant['move_out_date']); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo count($activeLeases); ?></div>
                <div class="stat-card-label"><?php _e('active_leases', 'Active Leases'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;">
                <i class="bi bi-receipt"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo count($allLeases); ?></div>
                <div class="stat-card-label"><?php _e('total_leases', 'Total Leases'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dcfce7;color:#166534;">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($totalPaid); ?></div>
                <div class="stat-card-label"><?php _e('total_payments', 'Total Payments'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;">
                <i class="bi bi-clipboard-check"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo count($inspections); ?></div>
                <div class="stat-card-label"><?php _e('inspections'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Row -->
<div class="row g-3 mb-4">
    <!-- Status Change (7.4) -->
    <?php if (hasPermission('tenants', 'edit')): ?>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-arrow-repeat me-1"></i> <?php _e('change_status', 'Change Status'); ?>
            </div>
            <div class="card-body">
                <form id="statusChangeForm">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('new_status', 'New Status'); ?></label>
                        <select name="new_status" class="form-select" id="newStatus" onchange="toggleMoveOutDate()">
                            <?php foreach ($statusLabels as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $tenant['status'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="moveOutDateRow" style="<?php echo $tenant['status'] === 'moved_out' ? '' : 'display:none;'; ?>">
                        <label class="form-label fw-semibold"><?php _e('move_out_date', 'Move-out Date'); ?></label>
                        <input type="date" name="move_out_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('reason_note', 'Reason / Note'); ?></label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="<?php _e('reason_placeholder', 'Optional reason for status change'); ?>"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100" id="statusBtn">
                        <i class="bi bi-arrow-repeat me-1"></i> <?php _e('update_status', 'Update Status'); ?>
                    </button>
                </form>
                <div id="statusResult" class="mt-2"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Current Unit Assignment (7.3) -->
    <div class="col-12 col-lg-<?php echo hasPermission('tenants', 'edit') ? '4' : '6'; ?>">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-door-open me-1"></i> <?php _e('current_assignment', 'Current Assignment'); ?>
            </div>
            <div class="card-body">
                <?php if (!empty($activeLeases)): ?>
                <?php foreach ($activeLeases as $al): ?>
                <div class="mb-3 pb-2 <?php echo count($activeLeases) > 1 ? 'border-bottom' : ''; ?>">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:100px;"><?php _e('unit', 'Unit'); ?></td>
                            <td class="fw-semibold">
                                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $al['unit_id_val']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($al['unit_number']); ?>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?php _e('property'); ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $al['property_id_val']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($al['property_name']); ?>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?php _e('lease_period', 'Period'); ?></td>
                            <td><?php echo formatDate($al['start_date']); ?> — <?php echo formatDate($al['end_date']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?php _e('rent', 'Rent'); ?></td>
                            <td class="fw-semibold"><?php echo formatCurrency($al['rent_amount'], $al['currency']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?php _e('frequency', 'Frequency'); ?></td>
                            <td><?php echo paymentFrequencyLabel($al['payment_frequency']); ?></td>
                        </tr>
                    </table>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-door-closed" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_active_assignment', 'No active unit assignment'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ID Copy & Screening (7.2) -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-card-image me-1"></i> <?php _e('id_document', 'ID Document'); ?>
            </div>
            <div class="card-body">
                <div id="idCopySection">
                    <?php if ($tenant['id_copy_path']): ?>
                    <div class="text-center mb-2">
                        <?php
                        $ext = strtolower(pathinfo($tenant['id_copy_path'], PATHINFO_EXTENSION));
                        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                        ?>
                        <?php if ($isImage): ?>
                        <img src="<?php echo BASE_URL; ?>api/file.php?type=tenant_id&amp;id=<?php echo (int)$id; ?>"
                             alt="ID Copy" style="max-width:100%;max-height:200px;border-radius:8px;cursor:pointer;"
                             onclick="window.open(this.src, '_blank')">
                        <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>api/file.php?type=tenant_id&amp;id=<?php echo (int)$id; ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-file-earmark-pdf me-1"></i> <?php _e('view_id_copy', 'View ID Document'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php if (hasPermission('tenants', 'edit')): ?>
                    <button class="btn btn-outline-danger btn-sm w-100" onclick="deleteIdCopy()">
                        <i class="bi bi-trash me-1"></i> <?php _e('remove_id_copy', 'Remove ID Copy'); ?>
                    </button>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-card-image" style="font-size:2rem;"></i>
                        <p class="mt-2 mb-0"><?php _e('no_id_copy', 'No ID copy uploaded'); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (hasPermission('tenants', 'edit')): ?>
                <hr>
                <form id="idUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_id_copy">
                    <input type="hidden" name="tenant_id" value="<?php echo $id; ?>">
                    <div class="mb-2">
                        <label class="form-label fw-semibold small"><?php _e('upload_id_copy', 'Upload ID Copy'); ?></label>
                        <input type="file" class="form-control form-control-sm" name="id_copy" accept="image/*,.pdf" id="idCopyFile">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100" id="uploadBtn" disabled>
                        <i class="bi bi-upload me-1"></i> <?php _e('upload', 'Upload'); ?>
                    </button>
                </form>
                <div id="uploadResult" class="mt-2"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Details Section: Employment, Emergency, References -->
<div class="row g-3 mb-4">
    <!-- Personal Details -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-briefcase me-1"></i> <?php _e('employment', 'Employment'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted"><?php _e('occupation', 'Occupation'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['occupation'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('employer', 'Employer'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['employer'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('employer_phone', 'Employer Phone'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['employer_phone'] ?? '-'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Emergency Contact -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('emergency_contact', 'Emergency Contact'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted"><?php _e('name'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['emergency_contact_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('phone'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['emergency_contact_phone'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('relationship', 'Relationship'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['emergency_contact_relation'] ?? '-'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Next of Kin -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-people me-1"></i> <?php _e('next_of_kin', 'Next of Kin'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted"><?php _e('name'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['next_of_kin_name'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('nok_relationship', 'Relationship'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['next_of_kin_relationship'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('phone'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['next_of_kin_phone'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('nok_address', 'Address'); ?></td>
                        <td><?php echo htmlspecialchars($tenant['next_of_kin_address'] ?: '-'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- References -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person-check me-1"></i> <?php _e('references', 'References'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted"><?php _e('reference', 'Ref'); ?> 1</td>
                        <td>
                            <?php echo htmlspecialchars($tenant['reference1_name'] ?? '-'); ?>
                            <?php if ($tenant['reference1_phone']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($tenant['reference1_phone']); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('reference', 'Ref'); ?> 2</td>
                        <td>
                            <?php echo htmlspecialchars($tenant['reference2_name'] ?? '-'); ?>
                            <?php if ($tenant['reference2_phone']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($tenant['reference2_phone']); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Screening Notes -->
<?php if ($tenant['screening_notes']): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clipboard-check me-1"></i> <?php _e('screening_notes', 'Screening Notes'); ?></div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(htmlspecialchars($tenant['screening_notes'])); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- History Tabs (7.5) -->
<div class="card mb-4">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabLeases" type="button">
                    <i class="bi bi-file-earmark-text me-1"></i> <?php _e('leases'); ?> (<?php echo count($allLeases); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPayments" type="button">
                    <i class="bi bi-cash-coin me-1"></i> <?php _e('payments'); ?> (<?php echo count($payments); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabInspections" type="button">
                    <i class="bi bi-clipboard-check me-1"></i> <?php _e('inspections'); ?> (<?php echo count($inspections); ?>)
                </button>
            </li>
            <?php if ($docsModuleActive): ?>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDocuments" type="button">
                    <i class="bi bi-folder2-open me-1"></i> <?php _e('doc_documents', 'Documents'); ?> (<?php echo count($tenantDocs); ?>)
                </button>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabActivity" type="button">
                    <i class="bi bi-clock-history me-1"></i> <?php _e('activity', 'Activity'); ?>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <!-- Leases Tab -->
            <div class="tab-pane fade show active" id="tabLeases">
                <?php if (empty($allLeases)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-file-earmark-x" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_leases', 'No lease records found'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('unit', 'Unit'); ?></th>
                                <th><?php _e('property'); ?></th>
                                <th><?php _e('period'); ?></th>
                                <th class="text-end"><?php _e('rent'); ?></th>
                                <th><?php _e('frequency', 'Frequency'); ?></th>
                                <th><?php _e('status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allLeases as $lease): ?>
                            <tr>
                                <td><a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $lease['unit_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($lease['unit_number']); ?></a></td>
                                <td><?php echo htmlspecialchars($lease['property_name']); ?></td>
                                <td><small><?php echo formatDate($lease['start_date']); ?> — <?php echo formatDate($lease['end_date']); ?></small></td>
                                <td class="text-end"><?php echo formatCurrency($lease['rent_amount'], $lease['currency']); ?></td>
                                <td><?php echo paymentFrequencyLabel($lease['payment_frequency']); ?></td>
                                <td><span class="badge bg-<?php echo $leaseStatusColors[$lease['status']] ?? 'secondary'; ?>"><?php echo ucfirst($lease['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payments Tab -->
            <div class="tab-pane fade" id="tabPayments">
                <?php if (empty($payments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cash-coin" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_payments_found', 'No payment records found'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('date'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                                <th><?php _e('method', 'Method'); ?></th>
                                <th><?php _e('unit', 'Unit'); ?></th>
                                <th><?php _e('period'); ?></th>
                                <th><?php _e('reference', 'Ref'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $py): ?>
                            <tr>
                                <td><?php echo formatDate($py['payment_date']); ?></td>
                                <td class="text-end fw-semibold"><?php echo formatCurrency($py['amount'], $py['currency']); ?></td>
                                <td><small><?php echo ucfirst(str_replace('_', ' ', $py['payment_method'])); ?></small></td>
                                <td><small><?php echo htmlspecialchars($py['unit_number']); ?></small></td>
                                <td><small><?php echo $py['period_from'] ? formatDate($py['period_from']) . ' - ' . formatDate($py['period_to']) : '-'; ?></small></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($py['reference_number'] ?? '-'); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Inspections Tab -->
            <div class="tab-pane fade" id="tabInspections">
                <?php if (empty($inspections)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-clipboard-check" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_inspections', 'No inspection records found'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('type'); ?></th>
                                <th><?php _e('unit', 'Unit'); ?></th>
                                <th><?php _e('property'); ?></th>
                                <th><?php _e('inspector', 'Inspector'); ?></th>
                                <th><?php _e('status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inspections as $insp): ?>
                            <tr>
                                <td><?php echo formatDate($insp['inspection_date']); ?></td>
                                <td><span class="badge bg-light text-dark"><?php echo ucfirst(str_replace('_', ' ', $insp['inspection_type'])); ?></span></td>
                                <td><?php echo htmlspecialchars($insp['unit_number']); ?></td>
                                <td><?php echo htmlspecialchars($insp['property_name']); ?></td>
                                <td><small><?php echo htmlspecialchars($insp['inspector_name'] ?? '-'); ?></small></td>
                                <td><span class="badge bg-<?php echo $insp['status'] === 'completed' ? 'success' : ($insp['status'] === 'in_progress' ? 'warning' : 'info'); ?>"><?php echo ucfirst(str_replace('_', ' ', $insp['status'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Documents Tab -->
            <?php if ($docsModuleActive): ?>
            <div class="tab-pane fade" id="tabDocuments">
                <div class="d-flex justify-content-end mb-3">
                    <?php if (hasPermission('documents', 'create')): ?>
                    <a href="<?php echo BASE_URL; ?>modules/documents/upload.php?entity_type=tenant&entity_id=<?php echo $id; ?>"
                       class="btn btn-sm btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i> <?php _e('doc_upload_now', 'Upload Document'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php if (empty($tenantDocs)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-folder2-open" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('doc_no_documents', 'No documents linked to this tenant'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('doc_title', 'Title'); ?></th>
                                <th><?php _e('doc_category', 'Category'); ?></th>
                                <th class="text-end"><?php _e('doc_size', 'Size'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th class="text-end"><?php _e('actions', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tenantDocs as $doc):
                                $bytes = (int)$doc['file_size'];
                                if ($bytes >= 1048576) { $sizeFmt = round($bytes / 1048576, 1) . ' MB'; }
                                elseif ($bytes >= 1024) { $sizeFmt = round($bytes / 1024, 1) . ' KB'; }
                                else { $sizeFmt = $bytes . ' B'; }
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>modules/documents/view.php?id=<?php echo $doc['id']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($doc['title']); ?>
                                    </a>
                                    <?php if ((int)$doc['is_confidential'] === 1): ?>
                                    <i class="bi bi-lock-fill text-warning ms-1" title="<?php _e('doc_confidential', 'Confidential'); ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($doc['category_name']): ?>
                                    <span class="badge" style="background:<?php echo htmlspecialchars($doc['category_color'] ?: '#6c757d'); ?>;">
                                        <?php echo htmlspecialchars($doc['category_name']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><small><?php echo $sizeFmt; ?></small></td>
                                <td><small><?php echo formatDate($doc['created_at']); ?></small></td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>api/documents.php?action=download&id=<?php echo $doc['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary py-0 px-1" title="<?php _e('download', 'Download'); ?>">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Activity Tab -->
            <div class="tab-pane fade" id="tabActivity">
                <?php if (empty($activityHistory)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-clock" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_history', 'No activity history'); ?></p>
                </div>
                <?php else: ?>
                <div class="timeline-simple">
                    <?php foreach ($activityHistory as $entry):
                        $details = json_decode($entry['details'] ?? '{}', true) ?: [];
                    ?>
                    <div class="d-flex gap-2 mb-3 pb-2 border-bottom">
                        <div>
                            <i class="bi bi-circle-fill text-primary" style="font-size:0.5rem;margin-top:6px;"></i>
                        </div>
                        <div class="flex-grow-1">
                            <small class="text-muted"><?php echo formatDateTime($entry['created_at']); ?></small>
                            <?php if (!empty($details['status_change'])): ?>
                            <br><small class="fw-semibold"><?php echo htmlspecialchars($details['status_change']); ?></small>
                            <?php if (!empty($details['reason'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($details['reason']); ?></small>
                            <?php endif; ?>
                            <?php elseif (!empty($details['id_copy_uploaded'])): ?>
                            <br><small><?php _e('id_copy_uploaded', 'ID copy uploaded'); ?></small>
                            <?php else: ?>
                            <br><small><?php echo ucfirst($entry['action']); ?></small>
                            <?php endif; ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/tenants.php';

// Toggle move-out date field based on status
function toggleMoveOutDate() {
    const status = document.getElementById('newStatus').value;
    const row = document.getElementById('moveOutDateRow');
    if (row) {
        row.style.display = status === 'moved_out' ? '' : 'none';
    }
}

// Status change form
const statusForm = document.getElementById('statusChangeForm');
if (statusForm) {
    statusForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('statusBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("updating", "Updating..."); ?>';

        const formData = new FormData(statusForm);

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                const result = document.getElementById('statusResult');
                if (data.success) {
                    result.innerHTML = '<div class="alert alert-success alert-sm py-1 px-2 mb-0"><small>' + data.message + '</small></div>';
                    setTimeout(() => location.reload(), 800);
                } else {
                    result.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0"><small>' + data.message + '</small></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> <?php echo __("update_status", "Update Status"); ?>';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> <?php echo __("update_status", "Update Status"); ?>';
                alert('Request failed');
            });
    });
}

// ID copy upload
const idFile = document.getElementById('idCopyFile');
const uploadBtn = document.getElementById('uploadBtn');
if (idFile) {
    idFile.addEventListener('change', function() {
        uploadBtn.disabled = !this.files.length;
    });
}

const idUploadForm = document.getElementById('idUploadForm');
if (idUploadForm) {
    idUploadForm.addEventListener('submit', function(e) {
        e.preventDefault();

        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("uploading", "Uploading..."); ?>';

        const formData = new FormData(idUploadForm);

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                const result = document.getElementById('uploadResult');
                if (data.success) {
                    result.innerHTML = '<div class="alert alert-success alert-sm py-1 px-2 mb-0"><small>' + data.message + '</small></div>';
                    setTimeout(() => location.reload(), 800);
                } else {
                    result.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0"><small>' + data.message + '</small></div>';
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="bi bi-upload me-1"></i> <?php echo __("upload", "Upload"); ?>';
                }
            })
            .catch(() => {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="bi bi-upload me-1"></i> <?php echo __("upload", "Upload"); ?>';
                alert('Upload failed');
            });
    });
}

// Delete ID copy
function deleteIdCopy() {
    if (!confirm('<?php echo __("delete_id_copy_confirm", "Remove the ID copy?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_id_copy');
    formData.append('csrf_token', csrfToken);
    formData.append('tenant_id', <?php echo $id; ?>);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Request failed'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
