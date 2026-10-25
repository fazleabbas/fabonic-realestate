<?php
/**
 * FIS Real Estate — Session keep-alive ping
 * Touching this endpoint refreshes $_SESSION['last_activity'].
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'ts' => time()]);
