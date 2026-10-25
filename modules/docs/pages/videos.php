<h1><i class="bi bi-play-circle me-2"></i><?php echo __('docs_video_tutorials', 'Video Tutorials'); ?></h1>
<p><?php echo __('docs_vid_intro', 'Watch step-by-step video tutorials to learn how to use FIS Real Estate effectively.'); ?></p>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <?php echo __('docs_vid_coming', 'Video tutorials are being produced. Placeholder embeds are shown below. Check back for new videos.'); ?>
</div>

<?php
// Video tutorial index — add YouTube/Vimeo IDs when available
$tutorials = [
    [
        'id' => 'getting-started-overview',
        'title' => __('docs_vid_t1_title', 'Getting Started — System Overview'),
        'description' => __('docs_vid_t1_desc', 'A complete walkthrough of the FIS Real Estate system, including login, navigation, and key features.'),
        'duration' => '8:00',
        'video_id' => '', // YouTube video ID — leave empty for placeholder
        'platform' => 'youtube',
        'category' => 'getting-started'
    ],
    [
        'id' => 'properties-units',
        'title' => __('docs_vid_t2_title', 'Adding Properties & Units'),
        'description' => __('docs_vid_t2_desc', 'Learn how to create properties, add units, set rent amounts, and upload photos.'),
        'duration' => '6:30',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'core'
    ],
    [
        'id' => 'tenants-leases',
        'title' => __('docs_vid_t3_title', 'Managing Tenants & Leases'),
        'description' => __('docs_vid_t3_desc', 'How to register tenants, create leases, set up renewal alerts, and handle terminations.'),
        'duration' => '7:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'core'
    ],
    [
        'id' => 'payments-receipts',
        'title' => __('docs_vid_t4_title', 'Recording Payments & Receipts'),
        'description' => __('docs_vid_t4_desc', 'Record payments, generate receipts, handle bulk payments, and manage payment reversals.'),
        'duration' => '5:30',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'core'
    ],
    [
        'id' => 'deposits-latefees',
        'title' => __('docs_vid_t5_title', 'Deposits & Late Fees'),
        'description' => __('docs_vid_t5_desc', 'Track security deposits, create deductions, process refunds, and configure late fee rules.'),
        'duration' => '5:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'core'
    ],
    [
        'id' => 'inspections',
        'title' => __('docs_vid_t6_title', 'Move-in/Move-out Inspections'),
        'description' => __('docs_vid_t6_desc', 'Conduct inspections with checklists, upload photos, and generate comparison reports.'),
        'duration' => '6:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'core'
    ],
    [
        'id' => 'reports-dashboard',
        'title' => __('docs_vid_t7_title', 'Dashboard & Reports'),
        'description' => __('docs_vid_t7_desc', 'Navigate the dashboard, generate financial reports, and export data as CSV.'),
        'duration' => '4:30',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'reports'
    ],
    [
        'id' => 'settings-users',
        'title' => __('docs_vid_t8_title', 'Settings, Users & Roles'),
        'description' => __('docs_vid_t8_desc', 'Configure system settings, manage users, create custom roles with granular permissions.'),
        'duration' => '5:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'admin'
    ],
    [
        'id' => 'maintenance',
        'title' => __('docs_vid_t9_title', 'Maintenance Management'),
        'description' => __('docs_vid_t9_desc', 'Create work orders, assign contractors, track costs, and manage maintenance workflows.'),
        'duration' => '5:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'enterprise'
    ],
    [
        'id' => 'tenant-portal',
        'title' => __('docs_vid_t10_title', 'Tenant Portal Walkthrough'),
        'description' => __('docs_vid_t10_desc', 'How tenants use the self-service portal to view balance, payments, and manage their profile.'),
        'duration' => '4:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'professional'
    ],
    [
        'id' => 'mobile-pwa',
        'title' => __('docs_vid_t11_title', 'PWA & Offline Mode'),
        'description' => __('docs_vid_t11_desc', 'Install the app on your device, work offline, and sync when back online.'),
        'duration' => '3:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'getting-started'
    ],
    [
        'id' => 'payment-gateway',
        'title' => __('docs_vid_t12_title', 'Payment Gateway Setup'),
        'description' => __('docs_vid_t12_desc', 'Configure mobile money gateways, test payments, and manage transaction reconciliation.'),
        'duration' => '6:00',
        'video_id' => '',
        'platform' => 'youtube',
        'category' => 'enterprise'
    ],
];

$categories = [
    'getting-started' => __('docs_vid_cat_gs', 'Getting Started'),
    'core' => __('docs_vid_cat_core', 'Core Modules'),
    'reports' => __('docs_vid_cat_reports', 'Reports'),
    'admin' => __('docs_vid_cat_admin', 'Administration'),
    'professional' => __('docs_vid_cat_pro', 'Professional Features'),
    'enterprise' => __('docs_vid_cat_ent', 'Enterprise Features'),
];

$lastCat = '';
foreach ($tutorials as $tutorial):
    if ($tutorial['category'] !== $lastCat):
        $lastCat = $tutorial['category'];
?>
<h2><?php echo $categories[$tutorial['category']] ?? $tutorial['category']; ?></h2>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-5">
                <?php if (!empty($tutorial['video_id'])): ?>
                    <?php if ($tutorial['platform'] === 'youtube'): ?>
                    <div class="ratio ratio-16x9">
                        <iframe src="https://www.youtube.com/embed/<?php echo htmlspecialchars($tutorial['video_id']); ?>"
                                title="<?php echo htmlspecialchars($tutorial['title']); ?>"
                                frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen loading="lazy"></iframe>
                    </div>
                    <?php elseif ($tutorial['platform'] === 'vimeo'): ?>
                    <div class="ratio ratio-16x9">
                        <iframe src="https://player.vimeo.com/video/<?php echo htmlspecialchars($tutorial['video_id']); ?>"
                                title="<?php echo htmlspecialchars($tutorial['title']); ?>"
                                frameborder="0" allow="autoplay; fullscreen; picture-in-picture"
                                allowfullscreen loading="lazy"></iframe>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Placeholder -->
                    <div class="ratio ratio-16x9">
                        <div class="d-flex align-items-center justify-content-center" style="background:linear-gradient(135deg,#e8f5ee,#f0f7f4);border-radius:8px;">
                            <div class="text-center">
                                <i class="bi bi-play-circle" style="font-size:2.5rem;color:var(--re-primary);opacity:0.5;"></i>
                                <br>
                                <small class="text-muted"><?php echo __('docs_vid_coming_soon', 'Coming Soon'); ?></small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                <h5 class="mb-1"><?php echo htmlspecialchars($tutorial['title']); ?></h5>
                <p class="text-muted mb-2" style="font-size:0.9rem;"><?php echo htmlspecialchars($tutorial['description']); ?></p>
                <small class="text-muted">
                    <i class="bi bi-clock me-1"></i><?php echo $tutorial['duration']; ?>
                    <?php if (!empty($tutorial['video_id'])): ?>
                    &nbsp;&middot;&nbsp;
                    <a href="https://<?php echo $tutorial['platform'] === 'youtube' ? 'youtube.com/watch?v=' : 'vimeo.com/'; ?><?php echo htmlspecialchars($tutorial['video_id']); ?>"
                       target="_blank" rel="noopener" class="text-decoration-none" style="color:var(--re-primary);">
                        <i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('docs_vid_watch_full', 'Watch Full Screen'); ?>
                    </a>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<?php endforeach; ?>

<div class="alert alert-success mt-4">
    <i class="bi bi-camera-video me-2"></i>
    <?php echo __('docs_vid_request', 'Need a tutorial on a specific topic? Contact our support team and we\'ll create one for you.'); ?>
</div>
