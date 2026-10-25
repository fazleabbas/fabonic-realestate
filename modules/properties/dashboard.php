<?php
/**
 * FIS Real Estate — Property Dashboard (5.3)
 * Redirects to view.php which contains the dashboard widgets.
 * This file exists for URL compatibility if anyone links to /properties/dashboard.php?id=X
 */
$id = (int)($_GET['id'] ?? 0);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($id) {
    redirect('modules/properties/view.php?id=' . $id);
} else {
    redirect('modules/properties/');
}
