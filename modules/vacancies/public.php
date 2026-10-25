<?php
/**
 * FIS Real Estate — Public Vacancy Listings Page (Part 20.4)
 * Standalone page (no auth required). Shows active listings with inquiry form.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if module is enabled
if (getSetting('vacancy_advertising_enabled', '0') !== '1') {
    echo '<!DOCTYPE html><html><body><h2>Vacancy listings are not available.</h2></body></html>';
    exit;
}

$companyName = getSetting('vacancy_company_name', getSetting('company_name', APP_NAME));
$viewListingId = (int)($_GET['listing'] ?? 0);

// If viewing a specific listing
$singleListing = null;
if ($viewListingId) {
    $stmt = $conn->prepare("
        SELECT vl.*, u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm, u.floor,
               p.name AS property_name, p.city, p.property_type, p.address AS property_address
        FROM vacancy_listings vl
        INNER JOIN units u ON vl.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE vl.id = ? AND vl.status = 'active'
    ");
    $stmt->execute([$viewListingId]);
    $singleListing = $stmt->fetch();
    if ($singleListing) {
        $conn->prepare("UPDATE vacancy_listings SET views_count = views_count + 1 WHERE id = ?")->execute([$viewListingId]);
    }
}

// Get all active listings for list view
$listings = [];
if (!$viewListingId) {
    $stmt = $conn->query("
        SELECT vl.id, vl.title, vl.description, vl.rent_amount, vl.deposit_amount, vl.currency,
               vl.available_from, vl.features_json, vl.photos_json, vl.contact_phone,
               u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm,
               p.name AS property_name, p.city, p.property_type
        FROM vacancy_listings vl
        INNER JOIN units u ON vl.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE vl.status = 'active'
        ORDER BY vl.created_at DESC
    ");
    $listings = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $singleListing ? htmlspecialchars($singleListing['title']) : __('vac_available_units', 'Available Units'); ?> — <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --re-primary: #1a6b4a; --re-accent: #e8a838; }
        body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .re-pub-header { background: linear-gradient(135deg, var(--re-primary), #0d503a); color: #fff; padding: 2rem 0; }
        .re-pub-header h1 { font-size: 1.8rem; font-weight: 700; }
        .listing-card { border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden; transition: box-shadow 0.2s; background: #fff; }
        .listing-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .listing-card .price { font-size: 1.3rem; font-weight: 700; color: var(--re-primary); }
        .listing-card .meta { font-size: 0.85rem; color: #6c757d; }
        .listing-card .features-row .badge { font-size: 0.75rem; font-weight: 400; }
        .unit-detail-hero { background: linear-gradient(135deg, #e8f5ee, #dbeafe); padding: 2rem; border-radius: 12px; }
        .inquiry-form { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 1.5rem; }
        footer { background: var(--re-primary); color: rgba(255,255,255,0.7); padding: 1rem 0; font-size: 0.85rem; }
    </style>
</head>
<body>

<!-- Header -->
<div class="re-pub-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-building" style="font-size:1.5rem;"></i>
            </div>
            <div>
                <h1 class="mb-0"><?php echo htmlspecialchars($companyName); ?></h1>
                <p class="mb-0 opacity-75"><?php _e('vac_available_units', 'Available Units for Rent'); ?></p>
            </div>
        </div>
    </div>
</div>

<div class="container py-4">

<?php if ($singleListing): ?>
    <!-- Single Listing View -->
    <a href="<?php echo BASE_URL; ?>modules/vacancies/public.php" class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-arrow-left me-1"></i><?php _e('vac_back_listings', 'Back to Listings'); ?>
    </a>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="unit-detail-hero mb-3">
                <h2><?php echo htmlspecialchars($singleListing['title']); ?></h2>
                <p class="text-muted mb-2">
                    <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($singleListing['property_name']); ?>
                    <?php if ($singleListing['city']): ?>, <?php echo htmlspecialchars($singleListing['city']); ?><?php endif; ?>
                </p>
                <div class="d-flex gap-3 flex-wrap mb-3">
                    <span class="fs-4 fw-bold text-success"><?php echo formatCurrency($singleListing['rent_amount'], $singleListing['currency']); ?><small class="fs-6 text-muted">/<?php _e('month'); ?></small></span>
                    <?php if ($singleListing['deposit_amount']): ?>
                    <span class="text-muted align-self-end"><?php _e('vac_deposit', 'Deposit'); ?>: <?php echo formatCurrency($singleListing['deposit_amount'], $singleListing['currency']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-3 flex-wrap">
                    <span><i class="bi bi-house me-1"></i><?php echo ucfirst($singleListing['unit_type']); ?></span>
                    <?php if ($singleListing['bedrooms']): ?><span><i class="bi bi-door-open me-1"></i><?php echo $singleListing['bedrooms']; ?> <?php _e('bedrooms'); ?></span><?php endif; ?>
                    <?php if ($singleListing['bathrooms']): ?><span><i class="bi bi-droplet me-1"></i><?php echo $singleListing['bathrooms']; ?> <?php _e('bathrooms'); ?></span><?php endif; ?>
                    <?php if ($singleListing['size_sqm']): ?><span><i class="bi bi-arrows-angle-expand me-1"></i><?php echo $singleListing['size_sqm']; ?> sqm</span><?php endif; ?>
                    <?php if ($singleListing['available_from']): ?><span><i class="bi bi-calendar me-1"></i><?php _e('vac_from', 'From'); ?> <?php echo formatDate($singleListing['available_from']); ?></span><?php endif; ?>
                </div>
            </div>

            <?php if ($singleListing['description']): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-text-paragraph me-1"></i> <?php _e('description'); ?></div>
                <div class="card-body"><p class="mb-0"><?php echo nl2br(htmlspecialchars($singleListing['description'])); ?></p></div>
            </div>
            <?php endif; ?>

            <?php
            $features = $singleListing['features_json'] ? json_decode($singleListing['features_json'], true) : [];
            if (!empty($features)):
            ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-star me-1"></i> <?php _e('vac_features', 'Features & Amenities'); ?></div>
                <div class="card-body">
                    <?php foreach ($features as $f): ?>
                    <span class="badge bg-light text-dark border me-1 mb-1"><i class="bi bi-check2-circle text-success me-1"></i><?php echo htmlspecialchars($f); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-lg-5">
            <!-- Inquiry Form -->
            <div class="inquiry-form">
                <h5 class="mb-3"><i class="bi bi-chat-dots me-1"></i> <?php _e('vac_interested', 'Interested? Send us a message'); ?></h5>
                <form id="inquiryForm">
                    <input type="hidden" name="listing_id" value="<?php echo $singleListing['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label"><?php _e('vac_your_name', 'Your Name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php _e('vac_your_phone', 'Phone Number'); ?></label>
                        <input type="tel" name="phone" class="form-control" placeholder="+255...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php _e('email'); ?></label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php _e('message'); ?></label>
                        <textarea name="message" class="form-control" rows="3" placeholder="<?php _e('vac_message_placeholder', 'I am interested in this unit. When can I schedule a viewing?'); ?>"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="inquiryBtn">
                        <i class="bi bi-send me-1"></i><?php _e('vac_send_inquiry', 'Send Inquiry'); ?>
                    </button>
                </form>
                <div id="inquiryResult" class="mt-3 d-none"></div>
            </div>

            <?php if ($singleListing['contact_phone'] || $singleListing['contact_email']): ?>
            <div class="card mt-3">
                <div class="card-body">
                    <h6><i class="bi bi-person-lines-fill me-1"></i> <?php _e('vac_contact_directly', 'Contact Directly'); ?></h6>
                    <?php if ($singleListing['contact_name']): ?><p class="mb-1"><strong><?php echo htmlspecialchars($singleListing['contact_name']); ?></strong></p><?php endif; ?>
                    <?php if ($singleListing['contact_phone']): ?><p class="mb-1"><i class="bi bi-telephone me-1"></i> <a href="tel:<?php echo $singleListing['contact_phone']; ?>"><?php echo htmlspecialchars($singleListing['contact_phone']); ?></a></p><?php endif; ?>
                    <?php if ($singleListing['contact_email']): ?><p class="mb-0"><i class="bi bi-envelope me-1"></i> <a href="mailto:<?php echo $singleListing['contact_email']; ?>"><?php echo htmlspecialchars($singleListing['contact_email']); ?></a></p><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- Listings Grid -->
    <?php if (empty($listings)): ?>
    <div class="text-center py-5">
        <i class="bi bi-house" style="font-size:3rem;color:#ccc;"></i>
        <h4 class="mt-3 text-muted"><?php _e('vac_no_available', 'No units available at this time'); ?></h4>
        <p class="text-muted"><?php _e('vac_check_later', 'Please check back later for new listings.'); ?></p>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($listings as $listing):
            $features = $listing['features_json'] ? json_decode($listing['features_json'], true) : [];
        ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="listing-card h-100">
                <div class="p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-light text-dark border"><?php echo ucfirst($listing['unit_type']); ?></span>
                        <span class="price"><?php echo formatCurrency($listing['rent_amount'], $listing['currency']); ?><small class="text-muted fw-normal" style="font-size:0.7rem;">/mo</small></span>
                    </div>
                    <h6 class="mb-1">
                        <a href="?listing=<?php echo $listing['id']; ?>" class="text-decoration-none text-dark">
                            <?php echo htmlspecialchars($listing['title']); ?>
                        </a>
                    </h6>
                    <p class="meta mb-2">
                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($listing['property_name']); ?>
                        <?php if ($listing['city']): ?>, <?php echo htmlspecialchars($listing['city']); ?><?php endif; ?>
                    </p>
                    <div class="d-flex gap-2 flex-wrap mb-2 meta">
                        <?php if ($listing['bedrooms']): ?><span><i class="bi bi-door-open"></i> <?php echo $listing['bedrooms']; ?> BR</span><?php endif; ?>
                        <?php if ($listing['bathrooms']): ?><span><i class="bi bi-droplet"></i> <?php echo $listing['bathrooms']; ?> BA</span><?php endif; ?>
                        <?php if ($listing['size_sqm']): ?><span><i class="bi bi-arrows-angle-expand"></i> <?php echo $listing['size_sqm']; ?> sqm</span><?php endif; ?>
                    </div>
                    <?php if (!empty($features)): ?>
                    <div class="features-row">
                        <?php foreach (array_slice($features, 0, 3) as $f): ?>
                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($f); ?></span>
                        <?php endforeach; ?>
                        <?php if (count($features) > 3): ?>
                        <span class="badge bg-light text-muted">+<?php echo count($features) - 3; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="?listing=<?php echo $listing['id']; ?>" class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-eye me-1"></i><?php _e('vac_view_details', 'View Details'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

</div>

<!-- Footer -->
<footer class="mt-4">
    <div class="container text-center">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?> — Powered by FIS Real Estate
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($singleListing): ?>
<script>
document.getElementById('inquiryForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('inquiryBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';

    const fd = new FormData(this);
    fd.append('action', 'submit_inquiry');

    fetch('<?php echo BASE_URL; ?>api/vacancies.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i><?php echo __("vac_send_inquiry", "Send Inquiry"); ?>';
            const result = document.getElementById('inquiryResult');
            result.classList.remove('d-none');
            if (res.success) {
                result.className = 'mt-3 alert alert-success';
                result.textContent = res.message;
                document.getElementById('inquiryForm').reset();
            } else {
                result.className = 'mt-3 alert alert-danger';
                result.textContent = res.message;
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Send';
        });
});
</script>
<?php endif; ?>
</body>
</html>
