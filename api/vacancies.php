<?php
/**
 * FIS Real Estate — Vacancy Listings & Inquiries API (Part 20)
 * Handles: vacancy listing CRUD, inquiry management, public endpoints.
 * Enterprise tier required + vacancy_advertising_enabled setting.
 * Public actions (public_listings, public_view, submit_inquiry) bypass auth.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

$action = $_REQUEST['action'] ?? '';

// Public actions — no auth required
$publicActions = ['public_listings', 'public_view', 'submit_inquiry'];
$isPublic = in_array($action, $publicActions);

if (!$isPublic) {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }
    if (!hasTier('enterprise')) {
        jsonResponse(['success' => false, 'message' => __('vac_tier_required', 'Enterprise tier required for vacancy advertising')], 403);
    }
    if (getSetting('vacancy_advertising_enabled', '0') !== '1') {
        jsonResponse(['success' => false, 'message' => __('vac_disabled', 'Vacancy advertising module is disabled')], 403);
    }
}

// For public actions, still check module is enabled
if ($isPublic && getSetting('vacancy_advertising_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => 'Vacancy listings are not available'], 403);
}

switch ($action) {

    // ============================
    // LISTINGS — LIST (admin)
    // ============================
    case 'list':
        if (!hasPermission('vacancies', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = "1=1" . propertyScopeSql('u.property_id');
        $params = [];

        if ($propertyId) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }
        if ($status) {
            $where .= " AND vl.status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $where .= " AND (vl.title LIKE :s1 OR vl.description LIKE :s2 OR u.unit_number LIKE :s3)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*) FROM vacancy_listings vl
            INNER JOIN units u ON vl.unit_id = u.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT vl.*, u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm,
                       p.name AS property_name, p.address AS property_address, p.city AS property_city,
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

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // LISTINGS — GET
    // ============================
    case 'get':
        if (!hasPermission('vacancies', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT vl.*, u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm, u.floor,
                   p.name AS property_name, p.address AS property_address, p.city AS property_city
            FROM vacancy_listings vl
            INNER JOIN units u ON vl.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            WHERE vl.id = ?
        ");
        $stmt->execute([$id]);
        $listing = $stmt->fetch();
        if (!$listing) {
            jsonResponse(['success' => false, 'message' => 'Listing not found'], 404);
        }
        if (!canAccessUnit($listing['unit_id'])) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        // Get unit photos
        $photos = $conn->prepare("SELECT file_path, caption FROM unit_photos WHERE unit_id = ? ORDER BY sort_order");
        $photos->execute([$listing['unit_id']]);
        $listing['unit_photos'] = $photos->fetchAll();

        jsonResponse(['success' => true, 'data' => $listing]);
        break;

    // ============================
    // LISTINGS — CREATE
    // ============================
    case 'create':
        if (!hasPermission('vacancies', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $rentAmount = (float)($_POST['rent_amount'] ?? 0);
        $depositAmount = isset($_POST['deposit_amount']) ? (float)$_POST['deposit_amount'] : null;
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $availableFrom = trim($_POST['available_from'] ?? '') ?: null;
        $features = trim($_POST['features'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? getSetting('vacancy_contact_phone', ''));
        $contactEmail = trim($_POST['contact_email'] ?? getSetting('vacancy_contact_email', ''));
        $contactName = trim($_POST['contact_name'] ?? getSetting('vacancy_company_name', getSetting('company_name', '')));

        if (!$unitId) jsonResponse(['success' => false, 'message' => __('vac_unit_required')]);
        if (empty($title)) jsonResponse(['success' => false, 'message' => __('vac_title_required')]);
        if ($rentAmount <= 0) jsonResponse(['success' => false, 'message' => __('vac_rent_required')]);
        if (!canAccessUnit($unitId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        // Check duplicate listing for same unit
        $check = $conn->prepare("SELECT id FROM vacancy_listings WHERE unit_id = ? AND status = 'active'");
        $check->execute([$unitId]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => __('vac_already_listed')]);
        }

        // Parse features
        $featuresJson = null;
        if ($features) {
            $featuresJson = json_encode(array_map('trim', explode(',', $features)));
        }

        // Get unit photos for listing
        $photosStmt = $conn->prepare("SELECT file_path FROM unit_photos WHERE unit_id = ? ORDER BY sort_order LIMIT 10");
        $photosStmt->execute([$unitId]);
        $unitPhotos = $photosStmt->fetchAll(PDO::FETCH_COLUMN);
        $photosJson = !empty($unitPhotos) ? json_encode($unitPhotos) : null;

        $stmt = $conn->prepare("
            INSERT INTO vacancy_listings (unit_id, title, description, rent_amount, deposit_amount, currency,
                                          available_from, features_json, photos_json, contact_phone, contact_email,
                                          contact_name, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $unitId, $title, $description, $rentAmount, $depositAmount, $currency,
            $availableFrom, $featuresJson, $photosJson, $contactPhone, $contactEmail, $contactName
        ]);
        $newId = (int)$conn->lastInsertId();

        logActivity('created', 'vacancy_listing', $newId, ['title' => $title, 'unit_id' => $unitId]);
        jsonResponse(['success' => true, 'message' => __('vac_created'), 'id' => $newId]);
        break;

    // ============================
    // LISTINGS — UPDATE
    // ============================
    case 'update':
        if (!hasPermission('vacancies', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Listing ID required']);

        $uStmt = $conn->prepare("SELECT unit_id FROM vacancy_listings WHERE id = ?");
        $uStmt->execute([$id]);
        $lUnitId = $uStmt->fetchColumn();
        if ($lUnitId === false) jsonResponse(['success' => false, 'message' => 'Listing not found'], 404);
        if (!canAccessUnit($lUnitId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $rentAmount = (float)($_POST['rent_amount'] ?? 0);
        $depositAmount = isset($_POST['deposit_amount']) ? (float)$_POST['deposit_amount'] : null;
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $availableFrom = trim($_POST['available_from'] ?? '') ?: null;
        $features = trim($_POST['features'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $contactName = trim($_POST['contact_name'] ?? '');

        if (empty($title)) jsonResponse(['success' => false, 'message' => __('vac_title_required')]);
        if ($rentAmount <= 0) jsonResponse(['success' => false, 'message' => __('vac_rent_required')]);

        $featuresJson = null;
        if ($features) {
            $featuresJson = json_encode(array_map('trim', explode(',', $features)));
        }

        $stmt = $conn->prepare("
            UPDATE vacancy_listings
            SET title = ?, description = ?, rent_amount = ?, deposit_amount = ?, currency = ?,
                available_from = ?, features_json = ?, contact_phone = ?, contact_email = ?, contact_name = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $title, $description, $rentAmount, $depositAmount, $currency,
            $availableFrom, $featuresJson, $contactPhone, $contactEmail, $contactName, $id
        ]);

        logActivity('updated', 'vacancy_listing', $id, ['title' => $title]);
        jsonResponse(['success' => true, 'message' => __('vac_updated')]);
        break;

    // ============================
    // LISTINGS — DELETE
    // ============================
    case 'delete':
        if (!hasPermission('vacancies', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Listing ID required']);

        $duStmt = $conn->prepare("SELECT unit_id FROM vacancy_listings WHERE id = ?");
        $duStmt->execute([$id]);
        $dUnitId = $duStmt->fetchColumn();
        if ($dUnitId === false) jsonResponse(['success' => false, 'message' => 'Listing not found'], 404);
        if (!canAccessUnit($dUnitId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        $conn->prepare("DELETE FROM vacancy_listings WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'vacancy_listing', $id);
        jsonResponse(['success' => true, 'message' => __('vac_deleted')]);
        break;

    // ============================
    // LISTINGS — TOGGLE STATUS
    // ============================
    case 'toggle_status':
        if (!hasPermission('vacancies', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $valid = ['active', 'inactive', 'rented'];
        if (!in_array($newStatus, $valid)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status']);
        }

        $tsStmt = $conn->prepare("SELECT unit_id FROM vacancy_listings WHERE id = ?");
        $tsStmt->execute([$id]);
        $tsUnitId = $tsStmt->fetchColumn();
        if ($tsUnitId === false) jsonResponse(['success' => false, 'message' => 'Listing not found'], 404);
        if (!canAccessUnit($tsUnitId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        $conn->prepare("UPDATE vacancy_listings SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
        logActivity('updated', 'vacancy_listing', $id, ['status' => $newStatus]);
        jsonResponse(['success' => true, 'message' => __('vac_status_updated')]);
        break;

    // ============================
    // INQUIRIES — LIST (all or per listing)
    // ============================
    case 'list_inquiries':
        if (!hasPermission('vacancies', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $listingId = (int)($_GET['listing_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = "1=1" . propertyScopeSql('u.property_id');
        $params = [];

        if ($listingId) {
            $where .= " AND vi.listing_id = :lid";
            $params['lid'] = $listingId;
        }
        if ($status) {
            $where .= " AND vi.status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $where .= " AND (vi.inquirer_name LIKE :s1 OR vi.inquirer_phone LIKE :s2 OR vi.inquirer_email LIKE :s3)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*) FROM vacancy_inquiries vi
            INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id
            INNER JOIN units u ON vl.unit_id = u.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT vi.*, vl.title AS listing_title, u.unit_number,
                       p.name AS property_name, vl.rent_amount, vl.currency,
                       usr.full_name AS followed_up_by_name
                FROM vacancy_inquiries vi
                INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id
                INNER JOIN units u ON vl.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                LEFT JOIN users usr ON vi.followed_up_by = usr.id
                WHERE $where
                ORDER BY vi.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // INQUIRIES — UPDATE STATUS
    // ============================
    case 'update_inquiry':
        if (!hasPermission('vacancies', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $followUpDate = trim($_POST['follow_up_date'] ?? '') ?: null;

        $valid = ['new', 'contacted', 'viewing_scheduled', 'application_received', 'rejected', 'converted'];
        if (!in_array($status, $valid)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status']);
        }

        $iuStmt = $conn->prepare("SELECT vl.unit_id FROM vacancy_inquiries vi INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id WHERE vi.id = ?");
        $iuStmt->execute([$id]);
        $iuUnitId = $iuStmt->fetchColumn();
        if ($iuUnitId === false) jsonResponse(['success' => false, 'message' => 'Inquiry not found'], 404);
        if (!canAccessUnit($iuUnitId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        $conn->prepare("
            UPDATE vacancy_inquiries
            SET status = ?, notes = ?, follow_up_date = ?, followed_up_by = ?
            WHERE id = ?
        ")->execute([$status, $notes, $followUpDate, getUserId(), $id]);

        logActivity('updated', 'vacancy_inquiry', $id, ['status' => $status]);
        jsonResponse(['success' => true, 'message' => __('vac_inquiry_updated')]);
        break;

    // ============================
    // INQUIRIES — DELETE
    // ============================
    case 'delete_inquiry':
        if (!hasPermission('vacancies', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);

        $diStmt = $conn->prepare("SELECT vl.unit_id FROM vacancy_inquiries vi INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id WHERE vi.id = ?");
        $diStmt->execute([$id]);
        $diUnitId = $diStmt->fetchColumn();
        if ($diUnitId === false) jsonResponse(['success' => false, 'message' => 'Inquiry not found'], 404);
        if (!canAccessUnit($diUnitId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        $conn->prepare("DELETE FROM vacancy_inquiries WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'vacancy_inquiry', $id);
        jsonResponse(['success' => true, 'message' => __('vac_inquiry_deleted')]);
        break;

    // ============================
    // STATS
    // ============================
    case 'stats':
        if (!hasPermission('vacancies', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $inqScope = " AND listing_id IN (SELECT id FROM vacancy_listings WHERE 1=1" . unitScopeSql('unit_id') . ")";
        $activeListings = (int)$conn->query("SELECT COUNT(*) FROM vacancy_listings WHERE status = 'active'" . unitScopeSql('unit_id'))->fetchColumn();
        $totalViews = (int)$conn->query("SELECT COALESCE(SUM(views_count), 0) FROM vacancy_listings WHERE 1=1" . unitScopeSql('unit_id'))->fetchColumn();
        $totalInquiries = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE 1=1" . $inqScope)->fetchColumn();
        $newInquiries = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'new'" . $inqScope)->fetchColumn();
        $converted = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'converted'" . $inqScope)->fetchColumn();

        jsonResponse(['success' => true, 'data' => [
            'active_listings' => $activeListings,
            'total_views' => $totalViews,
            'total_inquiries' => $totalInquiries,
            'new_inquiries' => $newInquiries,
            'converted' => $converted,
            'conversion_rate' => $totalInquiries > 0 ? round(($converted / $totalInquiries) * 100, 1) : 0
        ]]);
        break;

    // ============================
    // DROPDOWNS
    // ============================
    case 'dropdowns':
        $properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name")->fetchAll();
        // Only vacant units not already listed
        $units = $conn->query("
            SELECT u.id, u.unit_number, u.rent_amount, u.deposit_amount, u.currency, u.unit_type,
                   u.bedrooms, u.bathrooms, u.size_sqm, p.name AS property_name, u.property_id
            FROM units u
            INNER JOIN properties p ON u.property_id = p.id
            WHERE u.status = 'vacant'" . propertyScopeSql('u.property_id') . "
            AND u.id NOT IN (SELECT unit_id FROM vacancy_listings WHERE status = 'active')
            ORDER BY p.name, u.unit_number
        ")->fetchAll();

        jsonResponse(['success' => true, 'data' => [
            'properties' => $properties,
            'units' => $units
        ]]);
        break;

    // ============================
    // EXPORT INQUIRIES CSV
    // ============================
    case 'export_inquiries':
        if (!hasPermission('vacancies', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT vi.id, vl.title AS listing, u.unit_number, p.name AS property,
                   vi.inquirer_name, vi.inquirer_phone, vi.inquirer_email,
                   vi.message, vi.status, vi.notes, vi.follow_up_date,
                   usr.full_name AS followed_up_by, vi.created_at
            FROM vacancy_inquiries vi
            INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id
            INNER JOIN units u ON vl.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN users usr ON vi.followed_up_by = usr.id
            WHERE 1=1" . propertyScopeSql('u.property_id') . "
            ORDER BY vi.created_at DESC
        ");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inquiries_export_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Listing', 'Unit', 'Property', 'Name', 'Phone', 'Email', 'Message', 'Status', 'Notes', 'Follow-up Date', 'Followed Up By', 'Date']);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
        exit;

    // ============================
    // PUBLIC — LIST ACTIVE LISTINGS
    // ============================
    case 'public_listings':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $propertyType = trim($_GET['property_type'] ?? '');
        $city = trim($_GET['city'] ?? '');
        $minRent = (float)($_GET['min_rent'] ?? 0);
        $maxRent = (float)($_GET['max_rent'] ?? 0);
        $bedrooms = (int)($_GET['bedrooms'] ?? 0);

        $where = "vl.status = 'active'";
        $params = [];

        if ($propertyType) {
            $where .= " AND p.property_type = :ptype";
            $params['ptype'] = $propertyType;
        }
        if ($city) {
            $where .= " AND p.city LIKE :city";
            $params['city'] = "%$city%";
        }
        if ($minRent > 0) {
            $where .= " AND vl.rent_amount >= :minr";
            $params['minr'] = $minRent;
        }
        if ($maxRent > 0) {
            $where .= " AND vl.rent_amount <= :maxr";
            $params['maxr'] = $maxRent;
        }
        if ($bedrooms > 0) {
            $where .= " AND u.bedrooms >= :beds";
            $params['beds'] = $bedrooms;
        }

        $perPage = 12;
        $countStmt = $conn->prepare("
            SELECT COUNT(*) FROM vacancy_listings vl
            INNER JOIN units u ON vl.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page, $perPage);

        $sql = "SELECT vl.id, vl.title, vl.description, vl.rent_amount, vl.deposit_amount, vl.currency,
                       vl.available_from, vl.features_json, vl.photos_json, vl.contact_phone,
                       vl.contact_email, vl.contact_name, vl.views_count,
                       u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm,
                       p.name AS property_name, p.city, p.property_type
                FROM vacancy_listings vl
                INNER JOIN units u ON vl.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                WHERE $where
                ORDER BY vl.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // PUBLIC — VIEW SINGLE LISTING
    // ============================
    case 'public_view':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Listing ID required']);

        $stmt = $conn->prepare("
            SELECT vl.*, u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm, u.floor,
                   p.name AS property_name, p.city, p.property_type, p.address AS property_address
            FROM vacancy_listings vl
            INNER JOIN units u ON vl.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            WHERE vl.id = ? AND vl.status = 'active'
        ");
        $stmt->execute([$id]);
        $listing = $stmt->fetch();
        if (!$listing) {
            jsonResponse(['success' => false, 'message' => 'Listing not found or inactive'], 404);
        }

        // Increment views
        $conn->prepare("UPDATE vacancy_listings SET views_count = views_count + 1 WHERE id = ?")->execute([$id]);

        jsonResponse(['success' => true, 'data' => $listing]);
        break;

    // ============================
    // PUBLIC — SUBMIT INQUIRY
    // ============================
    case 'submit_inquiry':
        $listingId = (int)($_POST['listing_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$listingId) jsonResponse(['success' => false, 'message' => 'Listing ID required']);
        if (empty($name)) jsonResponse(['success' => false, 'message' => __('vac_name_required', 'Your name is required')]);
        if (empty($phone) && empty($email)) jsonResponse(['success' => false, 'message' => __('vac_contact_required', 'Phone or email is required')]);

        // Verify listing exists and is active
        $check = $conn->prepare("SELECT id FROM vacancy_listings WHERE id = ? AND status = 'active'");
        $check->execute([$listingId]);
        if (!$check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Listing not available']);
        }

        $conn->prepare("
            INSERT INTO vacancy_inquiries (listing_id, inquirer_name, inquirer_phone, inquirer_email, message, status)
            VALUES (?, ?, ?, ?, ?, 'new')
        ")->execute([$listingId, $name, $phone, $email, $message]);

        // Create notification for admin
        $conn->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, link)
            SELECT u.id, 'staff',
                   ?, ?,
                   ?
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'Admin' AND u.status = 'active'
        ")->execute([
            __('vac_new_inquiry_title', 'New Vacancy Inquiry'),
            $name . ' — ' . ($phone ?: $email),
            'modules/vacancies/inquiries.php'
        ]);

        jsonResponse(['success' => true, 'message' => __('vac_inquiry_submitted', 'Your inquiry has been submitted. We will contact you soon!')]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
