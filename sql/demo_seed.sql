-- ============================================================
-- Fabonic Real Estate — DEMO tenant sample data
-- Loaded into the demo.real.co.tz database after tenant_schema.sql.
-- Re-run nightly by api/reset-demo.php (which TRUNCATEs first).
-- Fixed primary keys so foreign keys line up on every reset.
-- Dates are relative to the reset run via CURDATE()/INTERVAL.
-- NOTE: the `demo` admin user is (re)created by reset-demo.php using
-- password_hash() so we never hardcode a bcrypt hash here.
-- ============================================================

-- ---------- Properties ----------
INSERT INTO `properties`
    (`id`, `name`, `property_type`, `address`, `city`, `region`, `country`, `description`, `total_units`, `status`) VALUES
    (1, 'Mwangaza Apartments', 'residential', 'Plot 45, Mikocheni B', 'Dar es Salaam', 'Dar es Salaam', 'Tanzania', 'Modern 2-storey apartment block near the main road.', 6, 'active'),
    (2, 'Uhuru Business Plaza', 'commercial', 'Uhuru Street, Kariakoo', 'Dar es Salaam', 'Dar es Salaam', 'Tanzania', 'Ground-floor shops and upper-floor offices.', 4, 'active');

-- ---------- Units ----------
INSERT INTO `units`
    (`id`, `property_id`, `unit_number`, `unit_label`, `unit_type`, `floor`, `size_sqm`, `bedrooms`, `bathrooms`, `rent_amount`, `currency`, `deposit_amount`, `status`, `description`) VALUES
    (1, 1, 'A1', 'Ground Floor Left',  'apartment', 'Ground', 68.00, 2, 1, 450000, 'TZS', 900000, 'occupied', 'Two-bedroom apartment with balcony.'),
    (2, 1, 'A2', 'Ground Floor Right', 'apartment', 'Ground', 68.00, 2, 1, 450000, 'TZS', 900000, 'occupied', 'Two-bedroom apartment.'),
    (3, 1, 'B1', 'First Floor Left',   'apartment', 'First',  72.00, 3, 2, 600000, 'TZS', 1200000, 'occupied', 'Three-bedroom corner apartment.'),
    (4, 1, 'B2', 'First Floor Right',  'apartment', 'First',  72.00, 3, 2, 600000, 'TZS', 1200000, 'vacant',   'Three-bedroom apartment, newly painted.'),
    (5, 1, 'C1', 'Studio',             'room',      'First',  32.00, 1, 1, 250000, 'TZS', 250000, 'occupied', 'Self-contained studio.'),
    (6, 1, 'C2', 'Studio',             'room',      'First',  32.00, 1, 1, 250000, 'TZS', 250000, 'maintenance', 'Studio undergoing plumbing repair.'),
    (7, 2, 'S1', 'Corner Shop',        'shop',      'Ground', 40.00, NULL, 1, 800000, 'TZS', 1600000, 'occupied', 'High-visibility corner retail shop.'),
    (8, 2, 'S2', 'Shop',               'shop',      'Ground', 35.00, NULL, 1, 700000, 'TZS', 1400000, 'vacant',   'Retail shop facing the street.'),
    (9, 2, 'O1', 'Office Suite 1',     'office',    'First',  55.00, NULL, 1, 950000, 'TZS', 1900000, 'occupied', 'Open-plan office suite.'),
    (10, 2, 'O2','Office Suite 2',     'office',    'First',  55.00, NULL, 1, 950000, 'TZS', 1900000, 'reserved', 'Office suite reserved, awaiting deposit.');

-- ---------- Tenants ----------
INSERT INTO `tenants`
    (`id`, `first_name`, `last_name`, `phone`, `email`, `id_type`, `id_number`, `occupation`, `status`, `move_in_date`, `portal_enabled`) VALUES
    (1, 'Amina',  'Juma',      '255712000001', 'amina.juma@example.com',  'national_id', '19850101-00001-00001-01', 'Teacher',       'active', DATE_SUB(CURDATE(), INTERVAL 8 MONTH), 1),
    (2, 'Baraka', 'Mushi',     '255712000002', 'baraka.mushi@example.com','national_id', '19880202-00002-00002-02', 'Accountant',    'active', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), 0),
    (3, 'Neema',  'Kimaro',    '255712000003', 'neema.kimaro@example.com','voter_id',    'VTR-000003',              'Nurse',         'active', DATE_SUB(CURDATE(), INTERVAL 5 MONTH), 1),
    (4, 'Joseph', 'Nyerere',   '255712000004', 'joseph.n@example.com',    'national_id', '19790303-00004-00004-03', 'Shop Owner',    'active', DATE_SUB(CURDATE(), INTERVAL 12 MONTH), 0),
    (5, 'Fatuma', 'Salehe',    '255712000005', 'fatuma.s@example.com',    'passport',    'TZ0000005',               'Entrepreneur',  'active', DATE_SUB(CURDATE(), INTERVAL 3 MONTH), 1);

-- ---------- Leases (tenant -> unit) ----------
INSERT INTO `leases`
    (`id`, `unit_id`, `tenant_id`, `start_date`, `end_date`, `rent_amount`, `currency`, `deposit_amount`, `payment_frequency`, `payment_due_day`, `status`) VALUES
    (1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 8 MONTH),  DATE_ADD(CURDATE(), INTERVAL 4 MONTH), 450000, 'TZS', 900000,  'monthly', 5, 'active'),
    (2, 2, 2, DATE_SUB(CURDATE(), INTERVAL 6 MONTH),  DATE_ADD(CURDATE(), INTERVAL 6 MONTH), 450000, 'TZS', 900000,  'monthly', 1, 'active'),
    (3, 3, 3, DATE_SUB(CURDATE(), INTERVAL 5 MONTH),  DATE_ADD(CURDATE(), INTERVAL 7 MONTH), 600000, 'TZS', 1200000, 'monthly', 1, 'active'),
    (4, 5, 5, DATE_SUB(CURDATE(), INTERVAL 3 MONTH),  DATE_ADD(CURDATE(), INTERVAL 9 MONTH), 250000, 'TZS', 250000,  'monthly', 10, 'active'),
    (5, 7, 4, DATE_SUB(CURDATE(), INTERVAL 12 MONTH), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 800000, 'TZS', 1600000, 'monthly', 1, 'active'),
    (6, 9, 4, DATE_SUB(CURDATE(), INTERVAL 10 MONTH), DATE_ADD(CURDATE(), INTERVAL 2 MONTH), 950000, 'TZS', 1900000, 'monthly', 1, 'active');

-- ---------- Payments (recorded_by = 1, the demo admin) ----------
INSERT INTO `payments`
    (`lease_id`, `tenant_id`, `unit_id`, `amount`, `currency`, `payment_date`, `payment_method`, `reference_number`, `period_from`, `period_to`, `notes`, `recorded_by`, `status`) VALUES
    (1, 1, 1, 450000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 2 MONTH), 'mobile_money', 'MPESA-AX1001', DATE_SUB(CURDATE(), INTERVAL 2 MONTH), DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'Rent - two months ago', 1, 'recorded'),
    (1, 1, 1, 450000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'mobile_money', 'MPESA-AX1002', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), CURDATE(), 'Rent - last month', 1, 'recorded'),
    (2, 2, 2, 450000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'bank_transfer', 'CRDB-778812', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), CURDATE(), 'Rent - last month', 1, 'recorded'),
    (3, 3, 3, 600000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'cash', 'RCPT-000045', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), CURDATE(), 'Rent - last month', 1, 'recorded'),
    (4, 5, 5, 250000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'mobile_money', 'TIGO-9921', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), CURDATE(), 'Studio rent', 1, 'recorded'),
    (5, 4, 7, 800000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'bank_transfer', 'NMB-4432', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), CURDATE(), 'Shop rent', 1, 'recorded'),
    (6, 4, 9, 950000, 'TZS', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 'bank_transfer', 'NMB-4433', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), CURDATE(), 'Office rent', 1, 'recorded'),
    (1, 1, 1, 450000, 'TZS', CURDATE(), 'mobile_money', 'MPESA-AX1003', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 'Rent - current month', 1, 'recorded'),
    (3, 3, 3, 600000, 'TZS', CURDATE(), 'cash', 'RCPT-000051', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 'Rent - current month', 1, 'recorded');
