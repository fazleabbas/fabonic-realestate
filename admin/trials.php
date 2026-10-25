<?php
/**
 * FIS Real Estate — Central Admin: Trial Management
 * List all trial requests, filter by status, approve/reject, manual provision.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// Handle logout
if (isset($_GET['logout'])) {
    adminLogout();
    header('Location: login.php');
    exit;
}

requireAdminAuth();

$db = getAdminDb();
$pageTitle = 'Trial Requests';
$alert = null;

// ============================================
// Handle POST actions
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkAdminCsrf();

    $action = $_POST['action'] ?? '';
    $trialId = intval($_POST['trial_id'] ?? 0);

    if ($trialId > 0) {
        try {
            $stmt = $db->prepare("SELECT * FROM trial_requests WHERE id = ?");
            $stmt->execute([$trialId]);
            $trial = $stmt->fetch();

            if ($trial) {
                switch ($action) {
                    case 'approve':
                        // Mark as approved (provisioning happens via cron or manual trigger)
                        $stmt = $db->prepare("
                            UPDATE trial_requests
                            SET status = 'approved',
                                trial_start_date = CURDATE(),
                                trial_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY),
                                updated_at = NOW()
                            WHERE id = ? AND status = 'pending'
                        ");
                        $stmt->execute([TRIAL_DAYS, $trialId]);

                        if ($stmt->rowCount() > 0) {
                            $alert = ['type' => 'success', 'msg' => "Trial #{$trialId} approved for " . htmlspecialchars($trial['company_name']) . ". Provisioning can now proceed."];

                            // Send approval WhatsApp
                            $approvalMsg = "Habari " . $trial['full_name'] . "!\n\n"
                                . "Ombi lako la majaribio ya FIS Real Estate limekubaliwa!\n\n"
                                . "Akaunti yako inaandaliwa sasa. Utapokea taarifa ya kuingia hivi karibuni kupitia WhatsApp na email.\n\n"
                                . "Asante kwa kuchagua FIS Real Estate!\n\n"
                                . "\xE2\x80\x94 Timu ya FIS Real Estate";
                            sendWhatsApp($trial['phone'], $approvalMsg);
                        } else {
                            $alert = ['type' => 'warning', 'msg' => 'Trial was not in pending status or was already processed.'];
                        }
                        break;

                    case 'reject':
                        $notes = trim($_POST['reject_notes'] ?? '');
                        $stmt = $db->prepare("
                            UPDATE trial_requests
                            SET status = 'rejected', notes = CONCAT(COALESCE(notes,''), '\n[Rejected] ', ?), updated_at = NOW()
                            WHERE id = ? AND status = 'pending'
                        ");
                        $stmt->execute([$notes, $trialId]);

                        if ($stmt->rowCount() > 0) {
                            $alert = ['type' => 'danger', 'msg' => "Trial #{$trialId} rejected."];
                        }
                        break;

                    case 'provision':
                        // Manual provision trigger — call provisioning script
                        define('PROVISION_ALLOWED', true);
                        require_once __DIR__ . '/../api/provision-tenant.php';

                        ob_start();
                        $result = provisionTenant($trialId);
                        $output = ob_get_clean();

                        if ($result) {
                            $alert = ['type' => 'success', 'msg' => "Tenant provisioned successfully for trial #{$trialId}!"];
                        } else {
                            $alert = ['type' => 'danger', 'msg' => "Provisioning failed for trial #{$trialId}. Check logs."];
                        }
                        break;
                }
            }
        } catch (PDOException $e) {
            $alert = ['type' => 'danger', 'msg' => 'Database error: ' . $e->getMessage()];
        }
    }
}

// ============================================
// Filter & Fetch trials
// ============================================
$statusFilter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = "t.status = :status";
    $params['status'] = $statusFilter;
}

if ($search) {
    $where[] = "(t.full_name LIKE :search OR t.company_name LIKE :search2 OR t.phone LIKE :search3 OR t.email LIKE :search4)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM trial_requests t {$whereClause}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch
$sql = "
    SELECT t.*,
           s.id AS sub_id, s.status AS sub_status, s.tier AS sub_tier
    FROM trial_requests t
    LEFT JOIN subscriptions s ON s.trial_request_id = t.id
    {$whereClause}
    ORDER BY t.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$trials = $stmt->fetchAll();

// Status counts for filter badges
$statusCounts = [];
$countSql = $db->query("SELECT status, COUNT(*) as cnt FROM trial_requests GROUP BY status");
while ($row = $countSql->fetch()) {
    $statusCounts[$row['status']] = $row['cnt'];
}
$statusCounts['all'] = array_sum($statusCounts);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Trial Requests</h4>
    <div class="d-flex gap-2">
        <form class="d-flex gap-2" method="GET">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <div class="input-group input-group-sm" style="width:250px;">
                <input type="text" class="form-control" name="search" placeholder="Search name, phone, email..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<?php if ($alert): ?>
<div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show py-2" role="alert">
    <?php echo $alert['msg']; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Status Filter Tabs -->
<div class="d-flex gap-1 mb-3 flex-wrap">
    <?php
    $statuses = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'expired' => 'Expired'];
    foreach ($statuses as $sKey => $sLabel):
        $count = $statusCounts[$sKey] ?? 0;
        $isActive = ($statusFilter === $sKey);
    ?>
    <a href="?status=<?php echo $sKey; ?>&search=<?php echo urlencode($search); ?>"
       class="btn btn-sm <?php echo $isActive ? 'btn-dark' : 'btn-outline-secondary'; ?>">
        <?php echo $sLabel; ?>
        <span class="badge bg-<?php echo $isActive ? 'light text-dark' : 'secondary'; ?> ms-1"><?php echo $count; ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Trials Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Contact</th>
                    <th>Units</th>
                    <th>City</th>
                    <th>Status</th>
                    <th>Slug</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trials)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">No trial requests found.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($trials as $t): ?>
                <tr>
                    <td class="text-muted"><?php echo $t['id']; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($t['company_name'] ?: $t['full_name']); ?></strong>
                        <?php if ($t['company_name'] && $t['full_name']): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($t['full_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small>
                            <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($t['phone']); ?>
                            <?php if ($t['email']): ?>
                            <br><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($t['email']); ?>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td><?php echo $t['unit_count']; ?></td>
                    <td><small><?php echo htmlspecialchars($t['city'] ?? ''); ?></small></td>
                    <td>
                        <?php
                        $badgeMap = [
                            'pending' => 'adm-badge-pending',
                            'approved' => 'adm-badge-approved',
                            'rejected' => 'adm-badge-rejected',
                            'expired' => 'adm-badge-expired'
                        ];
                        $badge = $badgeMap[$t['status']] ?? 'adm-badge-expired';
                        ?>
                        <span class="adm-badge <?php echo $badge; ?>"><?php echo ucfirst($t['status']); ?></span>
                        <?php if ($t['sub_id']): ?>
                        <br><small class="text-muted">Sub: <?php echo $t['sub_status']; ?> (<?php echo $t['sub_tier']; ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?php echo htmlspecialchars($t['tenant_slug'] ?? '-'); ?></small></td>
                    <td><small><?php echo date('d M Y', strtotime($t['created_at'])); ?><br><?php echo date('H:i', strtotime($t['created_at'])); ?></small></td>
                    <td>
                        <?php if ($t['status'] === 'pending'): ?>
                        <div class="d-flex gap-1">
                            <!-- Approve -->
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Approve trial for <?php echo htmlspecialchars(addslashes($t['company_name'] ?: $t['full_name'])); ?>?');">
                                <?php echo adminCsrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="trial_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-success" title="Approve">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <!-- Reject -->
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Reject"
                                    onclick="rejectTrial(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['company_name'] ?: $t['full_name'])); ?>')">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <?php elseif ($t['status'] === 'approved' && !$t['sub_id']): ?>
                        <!-- Provision button (approved but not yet provisioned) -->
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Provision tenant for <?php echo htmlspecialchars(addslashes($t['company_name'] ?: $t['full_name'])); ?>? This creates the database, subdomain, and sends credentials.');">
                            <?php echo adminCsrfField(); ?>
                            <input type="hidden" name="action" value="provision">
                            <input type="hidden" name="trial_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-primary" title="Provision Tenant">
                                <i class="bi bi-gear me-1"></i>Provision
                            </button>
                        </form>
                        <?php elseif ($t['sub_id']): ?>
                        <span class="adm-badge adm-badge-provisioned"><i class="bi bi-check-circle me-1"></i>Provisioned</span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalRecords); ?> of <?php echo $totalRecords; ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">&laquo;</a></li>
                <?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Reject Modal (inline form) -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?php echo adminCsrfField(); ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="trial_id" id="rejectTrialId" value="">
                <div class="modal-header py-2">
                    <h6 class="modal-title">Reject Trial</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" style="font-size:0.85rem;">Reject trial for <strong id="rejectTrialName"></strong>?</p>
                    <textarea name="reject_notes" class="form-control form-control-sm" rows="2" placeholder="Reason (optional)"></textarea>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'JS'
<script>
function rejectTrial(id, name) {
    document.getElementById('rejectTrialId').value = id;
    document.getElementById('rejectTrialName').textContent = name;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
?>
