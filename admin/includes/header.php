<?php
/**
 * FIS Real Estate — Central Admin Header
 */
$adminUser = getAdminUser();
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Admin Panel'; ?> — FIS Real Estate Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --adm-primary: #1a6b4a;
            --adm-primary-dark: #134f37;
            --adm-accent: #e8a838;
            --adm-sidebar-bg: #0f3d2d;
        }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f4f6f9;
            margin: 0;
            font-size: 0.9rem;
        }
        .adm-topbar {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .adm-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-weight: 700;
            color: var(--adm-primary-dark);
            font-size: 1rem;
        }
        .adm-brand-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--adm-primary), var(--adm-accent));
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .adm-brand-icon i { color: white; font-size: 0.9rem; }
        .adm-brand small { display: block; font-size: 0.65rem; font-weight: 400; color: #888; }
        .adm-nav { display: flex; align-items: center; gap: 0.25rem; }
        .adm-nav a {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 0.85rem;
            border-radius: 0.5rem;
            text-decoration: none;
            color: #555;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.15s;
        }
        .adm-nav a:hover { background: #f0f0f0; color: var(--adm-primary); }
        .adm-nav a.active { background: var(--adm-primary); color: white; }
        .adm-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
        .adm-user-info { display: flex; align-items: center; gap: 0.75rem; }
        .adm-user-info .adm-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--adm-primary);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 0.8rem;
        }
        .adm-badge { padding: 0.2rem 0.5rem; border-radius: 50px; font-size: 0.72rem; font-weight: 600; }
        .adm-badge-pending { background: #fef3c7; color: #92400e; }
        .adm-badge-approved { background: #dcfce7; color: #166534; }
        .adm-badge-rejected { background: #fee2e2; color: #991b1b; }
        .adm-badge-expired { background: #f3f4f6; color: #6b7280; }
        .adm-badge-provisioned { background: #dbeafe; color: #1e40af; }

        .adm-stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .adm-stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }
        .adm-stat-value { font-size: 1.5rem; font-weight: 800; color: #222; }
        .adm-stat-label { font-size: 0.8rem; color: #888; }

        .table th { font-weight: 600; font-size: 0.82rem; text-transform: uppercase; color: #666; letter-spacing: 0.03em; }
        .table td { vertical-align: middle; font-size: 0.88rem; }

        @media (max-width: 767px) {
            .adm-nav { overflow-x: auto; }
            .adm-content { padding: 1rem; }
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="adm-topbar">
    <div class="d-flex align-items-center gap-3">
        <a href="index.php" class="adm-brand">
            <div class="adm-brand-icon"><i class="bi bi-building"></i></div>
            <div>FIS RE Admin<small>Central Panel</small></div>
        </a>
        <nav class="adm-nav ms-3">
            <a href="index.php" class="<?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="trials.php" class="<?php echo $currentPage === 'trials' ? 'active' : ''; ?>">
                <i class="bi bi-clock-history"></i> Trials
            </a>
            <a href="drip.php" class="<?php echo $currentPage === 'drip' ? 'active' : ''; ?>">
                <i class="bi bi-send"></i> Drip
            </a>
            <a href="drip-analytics.php" class="<?php echo $currentPage === 'drip-analytics' ? 'active' : ''; ?>">
                <i class="bi bi-graph-up"></i> Analytics
            </a>
            <a href="drip-templates.php" class="<?php echo $currentPage === 'drip-templates' ? 'active' : ''; ?>">
                <i class="bi bi-file-text"></i> Templates
            </a>
        </nav>
    </div>
    <div class="adm-user-info">
        <div class="adm-avatar"><?php echo strtoupper(substr($adminUser['full_name'] ?? 'A', 0, 1)); ?></div>
        <span class="d-none d-md-inline" style="font-size:0.85rem;"><?php echo htmlspecialchars($adminUser['full_name'] ?? ''); ?></span>
        <a href="?logout=1" class="btn btn-sm btn-outline-secondary" style="font-size:0.8rem;"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</div>

<!-- Content -->
<div class="adm-content">
