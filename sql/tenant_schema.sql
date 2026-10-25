-- ============================================================
-- FIS Real Estate — Tenant Database Schema Template
-- This SQL creates all tables for a single tenant instance.
-- Run against each newly provisioned tenant DB.
-- ============================================================

-- The seeded lease templates contain UTF-8 punctuation (em dashes, curly
-- apostrophes). The provisioner imports this file by piping it to the `mysql`
-- client, which applies ITS default charset unless told otherwise — on some
-- systems latin1, which mangles those characters. Declaring it here fixes every
-- import path (mysql CLI, PDO, phpMyAdmin) from inside the file itself.
SET NAMES utf8mb4;

-- ============================================================
-- SYSTEM TABLES
-- ============================================================

-- Users (staff: admin, managers, accountants, caretakers)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `can_approve` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'May approve ledger entries (part 30)',
    `language` ENUM('en','sw','ar','hi','ur','gu') NOT NULL DEFAULT 'en',
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `last_login` DATETIME DEFAULT NULL,
    `password_reset_token` VARCHAR(100) DEFAULT NULL,
    `password_reset_expires` DATETIME DEFAULT NULL,
    `remember_token` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_username` (`username`),
    INDEX `idx_role` (`role_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB;

-- Roles
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `permissions` JSON DEFAULT NULL COMMENT 'JSON object of module:action pairs',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Protect system roles from deletion',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_name` (`name`)
) ENGINE=InnoDB;

-- Default roles
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`) VALUES
(1, 'Admin', 'Full system access', '{"all": true}', 1),
(2, 'Manager', 'Property manager with broad access', '{"properties": ["view","create","edit"], "units": ["view","create","edit"], "tenants": ["view","create","edit"], "leases": ["view","create","edit"], "payments": ["view","create","edit"], "deposits": ["view","create","edit"], "inspections": ["view","create","edit"], "utilities": ["view","create","edit"], "reports": ["view"], "settings": ["view"]}', 1),
(3, 'Accountant', 'Financial operations access', '{"payments": ["view","create","edit"], "deposits": ["view","create","edit"], "utilities": ["view"], "reports": ["view"], "tenants": ["view"], "leases": ["view"], "units": ["view"], "properties": ["view"]}', 1),
(4, 'Caretaker', 'On-site property management', '{"properties": ["view"], "units": ["view"], "tenants": ["view"], "inspections": ["view","create","edit"], "maintenance": ["view","create","edit"], "utilities": ["view","create"]}', 1);

-- ============================================================
-- PROPERTY & UNIT TABLES
-- ============================================================

-- Properties
CREATE TABLE IF NOT EXISTS `properties` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `property_type` ENUM('residential','commercial','land','mixed') NOT NULL DEFAULT 'residential',
    `address` TEXT DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `region` VARCHAR(100) DEFAULT NULL,
    `country` VARCHAR(50) NOT NULL DEFAULT 'Tanzania',
    `description` TEXT DEFAULT NULL,
    `total_units` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type` (`property_type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_city` (`city`)
) ENGINE=InnoDB;

-- User <-> Property access scoping (was sql/part24_user_properties.sql — never
-- folded in). Enforcement is gated by the property_scoping_enabled setting.
CREATE TABLE IF NOT EXISTS `user_properties` (
    `user_id` INT UNSIGNED NOT NULL,
    `property_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `property_id`),
    INDEX `idx_up_property` (`property_id`),
    CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_up_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Property Photos
CREATE TABLE IF NOT EXISTS `property_photos` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `caption` VARCHAR(255) DEFAULT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_property` (`property_id`),
    CONSTRAINT `fk_propphoto_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Property Settings (per-property overrides)
CREATE TABLE IF NOT EXISTS `property_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT UNSIGNED NOT NULL,
    `rent_cycle` ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    `late_fee_type` ENUM('percentage','fixed','none') NOT NULL DEFAULT 'none',
    `late_fee_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `grace_period_days` INT UNSIGNED NOT NULL DEFAULT 5,
    `max_late_fee` DECIMAL(12,2) DEFAULT NULL,
    `deposit_months` DECIMAL(3,1) NOT NULL DEFAULT 1.0 COMMENT 'Number of months for security deposit',
    `payment_instructions` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_property` (`property_id`),
    CONSTRAINT `fk_propsettings_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Units
CREATE TABLE IF NOT EXISTS `units` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT UNSIGNED NOT NULL,
    `unit_number` VARCHAR(50) NOT NULL,
    `unit_label` VARCHAR(100) DEFAULT NULL COMMENT 'Optional friendly name',
    `unit_type` ENUM('apartment','room','shop','office','warehouse','plot','house','flat','hostel','godown') NOT NULL DEFAULT 'apartment',
    `floor` VARCHAR(20) DEFAULT NULL,
    `size_sqm` DECIMAL(10,2) DEFAULT NULL,
    `bedrooms` TINYINT UNSIGNED DEFAULT NULL,
    `bathrooms` TINYINT UNSIGNED DEFAULT NULL,
    `rent_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `deposit_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('vacant','occupied','maintenance','reserved') NOT NULL DEFAULT 'vacant',
    `description` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_property` (`property_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type` (`unit_type`),
    UNIQUE INDEX `idx_property_unit` (`property_id`, `unit_number`),
    CONSTRAINT `fk_unit_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Unit Photos
CREATE TABLE IF NOT EXISTS `unit_photos` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `caption` VARCHAR(255) DEFAULT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_unit` (`unit_id`),
    CONSTRAINT `fk_unitphoto_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TENANT (RENTER) TABLES
-- ============================================================

-- Tenants (renters/occupants)
CREATE TABLE IF NOT EXISTS `tenants` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `id_type` ENUM('national_id','passport','driving_license','voter_id') DEFAULT NULL,
    `id_number` VARCHAR(50) DEFAULT NULL,
    `id_copy_path` VARCHAR(500) DEFAULT NULL,
    `occupation` VARCHAR(100) DEFAULT NULL,
    `employer` VARCHAR(200) DEFAULT NULL,
    `employer_phone` VARCHAR(20) DEFAULT NULL,
    `emergency_contact_name` VARCHAR(150) DEFAULT NULL,
    `emergency_contact_phone` VARCHAR(20) DEFAULT NULL,
    `emergency_contact_relation` VARCHAR(50) DEFAULT NULL,
    -- Tax + next of kin (part 27). Next of kin is the relative declared on the
    -- lease and is deliberately separate from the emergency contact above.
    `tin` VARCHAR(20) DEFAULT NULL COMMENT 'Taxpayer Identification Number, stored NNN-NNN-NNN when 9 digits',
    `next_of_kin_name` VARCHAR(150) DEFAULT NULL,
    `next_of_kin_relationship` VARCHAR(50) DEFAULT NULL,
    `next_of_kin_phone` VARCHAR(20) DEFAULT NULL,
    `next_of_kin_address` VARCHAR(255) DEFAULT NULL,
    `reference1_name` VARCHAR(150) DEFAULT NULL,
    `reference1_phone` VARCHAR(20) DEFAULT NULL,
    `reference2_name` VARCHAR(150) DEFAULT NULL,
    `reference2_phone` VARCHAR(20) DEFAULT NULL,
    `screening_notes` TEXT DEFAULT NULL,
    `status` ENUM('active','notice_given','moved_out') NOT NULL DEFAULT 'active',
    `move_in_date` DATE DEFAULT NULL,
    `move_out_date` DATE DEFAULT NULL,
    `portal_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `portal_password_hash` VARCHAR(255) DEFAULT NULL,
    -- Tenant-portal login/reset state. These were only ever added by the
    -- sql/part16_tenant_portal.sql migration, which was never folded in here —
    -- so every tenant provisioned from this file 500'd on portal login.
    `portal_reset_token` VARCHAR(100) DEFAULT NULL,
    `portal_reset_expires` DATETIME DEFAULT NULL,
    `portal_last_login` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_phone` (`phone`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB;

-- ============================================================
-- LEASE TABLES
-- ============================================================

-- Leases
CREATE TABLE IF NOT EXISTS `leases` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `rent_amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `deposit_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `deposit_currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `payment_frequency` ENUM('monthly','quarterly','yearly','half_yearly') NOT NULL DEFAULT 'monthly',
    `payment_due_day` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Day of month (1-28)',
    `billed_through` DATE DEFAULT NULL COMMENT 'Rent billed up to and including this date (part 39)',
    `terms` TEXT DEFAULT NULL COMMENT 'Additional lease terms',
    `renewal_alert_days` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Days before expiry to alert',
    `status` ENUM('active','expired','terminated','renewed') NOT NULL DEFAULT 'active',
    `document_path` VARCHAR(500) DEFAULT NULL COMMENT 'Generated lease PDF path',
    `previous_lease_id` INT UNSIGNED DEFAULT NULL COMMENT 'For renewal chain',
    `terminated_reason` TEXT DEFAULT NULL,
    `terminated_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_unit` (`unit_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_end_date` (`end_date`),
    CONSTRAINT `fk_lease_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lease_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Lease Templates
CREATE TABLE IF NOT EXISTS `lease_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `content` LONGTEXT NOT NULL COMMENT 'HTML with {{placeholders}}',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Built-in lease templates (generated by api/seed-lease-templates.php --emit-sql)
-- Edit includes/lease_templates_seed.php and regenerate; do not hand-edit.
INSERT INTO `lease_templates` (`name`, `content`, `is_default`) VALUES
('Standard Residential Lease Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">RESIDENTIAL LEASE AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE PREMISES</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Premises are let for residential use only, together with the fixtures, fittings and appliances listed in the inventory signed at handover.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. TERM</h3>
<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. UTILITIES AND OUTGOINGS</h3>
<p>Unless stated otherwise in the Additional Terms, the Tenant pays for electricity, water, gas, refuse collection, internet and telephone consumed at the Premises, and keeps all such accounts current.</p>
<p>The Landlord pays land rates, statutory property taxes and building insurance.</p>
<p>The Tenant produces evidence that all utility accounts are settled before vacating.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. USE OF THE PREMISES</h3>
<p>The Premises are used as a private residence by the Tenant and the occupants declared to the Landlord in writing. Any change in the number of occupants requires the Landlord\'s prior written consent.</p>
<p>The Tenant will not carry on any trade or business at the Premises, will not do anything that is unlawful, a nuisance, or that invalidates the building insurance, and will observe any house rules notified in writing.</p>
<p>Pets are not kept at the Premises without the Landlord\'s prior written consent.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. MAINTENANCE AND REPAIRS</h3>
<p><strong>The Landlord</strong> keeps the structure, roof, exterior walls, and the main water, electrical, drainage and sanitary installations in good repair, and attends to reported faults within a reasonable time.</p>
<p><strong>The Tenant</strong> keeps the interior clean and in good order, replaces consumables such as light bulbs, and promptly reports any defect, leak or breakage in writing.</p>
<p>The Tenant is responsible for the cost of repairing damage caused by the Tenant, the Tenant\'s household, or the Tenant\'s visitors.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. ALTERATIONS</h3>
<p>The Tenant makes no structural alteration, no change to locks, and no permanent fixture or installation without the Landlord\'s prior written consent.</p>
<p>Approved alterations become part of the Premises at the end of the Term unless the parties agree otherwise in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. LANDLORD\'S RIGHT OF ENTRY</h3>
<p>The Landlord, or a person authorised by the Landlord, may enter the Premises to inspect, to carry out repairs, or to show the Premises to a prospective tenant or buyer, after giving the Tenant at least twenty-four (24) hours\' notice and at a reasonable hour.</p>
<p>In a genuine emergency the Landlord may enter without notice.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. SUBLETTING AND ASSIGNMENT</h3>
<p>The Tenant does not sublet, assign, or part with possession of the Premises, in whole or in part, without the Landlord\'s prior written consent.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. INSURANCE AND LIABILITY</h3>
<p>The Landlord insures the building. The Landlord does not insure the Tenant\'s belongings, and the Tenant is advised to arrange their own contents cover.</p>
<p>The Landlord is not liable for loss or damage to the Tenant\'s property except where it results from the Landlord\'s own negligence.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">13. TERMINATION AND NOTICE</h3>
<p>Either party may end this Agreement at or after the end of the Term by giving the other at least thirty (30) days\' written notice.</p>
<p>On vacating, the Tenant returns the Premises clean and in the same condition as at handover, fair wear and tear excepted, and returns all keys, remotes and access cards.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">14. DEFAULT</h3>
<p>The Landlord may treat the Tenant as in default if rent stays unpaid for fourteen (14) days after its due date, if the Tenant breaches any clause of this Agreement and fails to remedy it within fourteen (14) days of written notice, or if the Tenant abandons the Premises.</p>
<p>On default the Landlord may pursue any remedy available in law, including recovery of arrears and possession of the Premises.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">15. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">16. GOVERNING LAW AND DISPUTES</h3>
<p>This Agreement is governed by the laws in force where the Premises are situated.</p>
<p>The parties will first attempt to resolve any dispute by good-faith discussion. Failing that, the dispute may be referred to the competent tribunal or court having jurisdiction over the Premises.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">17. ENTIRE AGREEMENT</h3>
<p>This document is the entire agreement between the parties and replaces any earlier understanding, whether spoken or written. Any change must be in writing and signed by both parties.</p>
<p>If any clause is found unenforceable, the remaining clauses stay in full force.</p>
<p>Each party confirms they have read and understood this Agreement, and have signed it freely.</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
1),
('Simple One-Page Tenancy Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">TENANCY AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES AND PREMISES</h3>
<p><strong>Landlord:</strong> {{company_name}} &nbsp;|&nbsp; {{company_phone}}</p>
<p><strong>Tenant:</strong> {{tenant_name}} &nbsp;|&nbsp; {{tenant_phone}} &nbsp;|&nbsp; {{tenant_id_type}} {{tenant_id_number}}</p>
<p><strong>Premises:</strong> Unit {{unit_number}} ({{unit_type}}), {{property_name}}, {{property_address}}, {{property_city}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. TERM AND RENT</h3>
<p>The tenancy runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p>
<p>Rent is <strong>{{rent_amount}}</strong> {{payment_frequency}}, paid in advance on or before day <strong>{{payment_due_day}}</strong> of each period.</p>
<p>Security deposit: <strong>{{deposit_amount}}</strong>, refundable within thirty (30) days of vacating, less any unpaid rent, unpaid utilities or damage beyond fair wear and tear.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. WHAT THE TENANT AGREES</h3>
<p>Pay rent on time and pay for electricity, water and refuse collection used at the Premises.</p>
<p>Use the Premises as a private home, keep it clean, and cause no nuisance to neighbours.</p>
<p>Report faults promptly, make no alterations without written consent, and do not sublet.</p>
<p>Return the Premises and all keys in good condition at the end of the tenancy.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. WHAT THE LANDLORD AGREES</h3>
<p>Give the Tenant quiet enjoyment of the Premises for the Term.</p>
<p>Keep the structure and the main water, electrical and sanitary installations in good repair.</p>
<p>Give at least twenty-four (24) hours\' notice before entering, except in an emergency.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. ENDING THE TENANCY</h3>
<p>Either party may end this tenancy by giving thirty (30) days\' written notice.</p>
<p>If rent stays unpaid for fourteen (14) days after its due date, the Landlord may take steps to recover the arrears and possession of the Premises.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Single Room / Bedsitter Tenancy Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">ROOM TENANCY AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE ROOM</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Tenant rents the room described above ("the Room") and has shared use of the common areas of the building — which may include the corridor, courtyard, water points, washing lines and shared sanitary facilities — together with the other occupants.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. TERM</h3>
<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. WATER AND ELECTRICITY</h3>
<p>Where the Room has its own meter, the Tenant pays for their own consumption.</p>
<p>Where supply is shared, the Tenant pays the share notified by the Landlord in writing, and the Landlord makes the bills available for inspection on request.</p>
<p>The Tenant uses water and electricity reasonably and does not tamper with any meter or connection.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. HOUSE RULES</h3>
<p>Only the occupants declared to the Landlord may reside in the Room. Overnight guests staying more than three (3) consecutive nights require the Landlord\'s consent.</p>
<p>Common areas are kept clean and clear. Cooking is done only where the Landlord permits it.</p>
<p>Quiet hours apply between 10:00 pm and 6:00 am. Loud music, disorderly conduct and any unlawful activity are grounds for termination.</p>
<p>Refuse is placed only in the bins provided. Nothing is stored in corridors or stairways.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. CARE OF THE ROOM</h3>
<p>The Tenant keeps the Room clean and reports leaks, electrical faults or breakages promptly.</p>
<p>The Tenant makes no alteration, drills no holes and changes no lock without written consent. The Landlord repairs the structure and the shared installations.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. ACCESS</h3>
<p>The Landlord may enter the Room to inspect or repair after twenty-four (24) hours\' notice, and without notice in an emergency such as fire, flood or a burst pipe.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. SECURITY AND BELONGINGS</h3>
<p>The Tenant is responsible for locking the Room and for their own belongings. The Landlord does not insure the Tenant\'s property and is not liable for theft from the Room or the common areas except where caused by the Landlord\'s negligence.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. ENDING THE TENANCY</h3>
<p>Either party may end this Agreement by giving thirty (30) days\' written notice.</p>
<p>The Landlord may end it immediately for non-payment of rent persisting fourteen (14) days beyond its due date, or for serious or repeated breach of the House Rules.</p>
<p>The Tenant hands back the Room clean and empty, with all keys returned.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">13. ENTIRE AGREEMENT</h3>
<p>This document is the entire agreement between the parties and replaces any earlier understanding, whether spoken or written. Any change must be in writing and signed by both parties.</p>
<p>If any clause is found unenforceable, the remaining clauses stay in full force.</p>
<p>Each party confirms they have read and understood this Agreement, and have signed it freely.</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Furnished & Short-Stay Rental Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">FURNISHED / SHORT-STAY RENTAL AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Owner:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Guest:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE PREMISES</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Premises are let fully furnished, with the furniture, appliances, linen, kitchenware and equipment set out in the signed inventory, which forms part of this Agreement.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. STAY PERIOD</h3>
<p>The stay runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p>
<p>Check-in is from 2:00 pm on the start date and check-out is by 11:00 am on the end date, unless agreed otherwise in writing. Late check-out without agreement is charged at one additional night.</p>
<p>This is a short-stay licence to occupy furnished accommodation. It does not create a long-term or statutory tenancy, and it ends on the date shown above without further notice.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. CHARGES</h3>
<p>The rate is <strong>{{rent_amount}}</strong> per {{payment_frequency}} period, payable in advance.</p>
<p>Unless stated otherwise, the rate includes water, electricity, internet and routine housekeeping.</p>
<p>Extension of the stay is subject to availability and is confirmed in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>A refundable deposit of <strong>{{deposit_amount}}</strong> is held against damage, breakage, missing items and excessive cleaning.</p>
<p>It is refunded within seven (7) days after check-out, once the inventory has been checked, less any deductions itemised in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. OCCUPANCY</h3>
<p>Only the guests declared at booking may occupy the Premises. The maximum number of occupants may not be exceeded.</p>
<p>Parties, events and commercial filming are not permitted. The Premises may not be re-let, sub-let or listed on any platform by the Guest.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. CARE OF THE PREMISES AND INVENTORY</h3>
<p>The Guest keeps the Premises and its contents in good order and reports any damage or fault immediately.</p>
<p>The Guest is responsible for the cost of items broken, damaged or missing at check-out, and for any specialist cleaning needed beyond normal use.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. HOUSE RULES</h3>
<p>No smoking indoors. Quiet hours apply between 10:00 pm and 7:00 am. Pets only with prior written consent.</p>
<p>The Guest observes any rules of the building or estate, including security, parking and access arrangements.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. ACCESS AND SERVICING</h3>
<p>The Owner or housekeeping staff may enter at reasonable times for cleaning, servicing or repair, giving as much notice as is practical, and without notice in an emergency.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. CANCELLATION AND EARLY DEPARTURE</h3>
<p>Cancellation terms are as notified at the time of booking. Where the Guest departs before the end date, charges for the booked period remain payable unless the Owner agrees otherwise in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. LIABILITY</h3>
<p>The Guest is responsible for their own belongings. The Owner does not insure them and is not liable for their loss except where caused by the Owner\'s negligence.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Owner Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Guest Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Commercial Shop / Retail Lease Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">COMMERCIAL SHOP / RETAIL LEASE AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE PREMISES</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Premises are let for retail trading, together with the right to use the common areas of the building in accordance with the rules notified by the Landlord.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. TERM</h3>
<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>
<p>Rent is exclusive of any value added tax or similar levy, which the Tenant pays in addition where it applies.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. PERMITTED USE</h3>
<p>The Premises are used only for the trade the Tenant declared to the Landlord in writing, and for no other purpose without prior written consent.</p>
<p>The Tenant obtains and keeps current every licence, permit and registration that the trade requires, and produces copies to the Landlord on request.</p>
<p>The Tenant does not carry on any auction, illegal trade or activity that is a nuisance to other occupiers, or that invalidates the building insurance.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. SERVICE CHARGE AND OUTGOINGS</h3>
<p>The Tenant pays electricity, water and telephone consumed at the Premises, and refuse removal for its own trade waste.</p>
<p>Where the building has a service charge covering security, common-area cleaning, lighting and maintenance, the Tenant pays the proportionate share notified in writing, and the Landlord provides a statement of the charge on request.</p>
<p>The Landlord pays land rates and statutory property taxes on the building.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. FIT-OUT, SIGNAGE AND ALTERATIONS</h3>
<p>The Tenant makes no structural alteration or shopfront change without the Landlord\'s prior written consent and, where required, the approval of the relevant authority.</p>
<p>Signage is displayed only in the position and to the standard the Landlord approves.</p>
<p>At the end of the Term the Tenant removes its trade fittings and signage, and makes good any damage caused by their removal.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. TRADING HOURS AND PRESENTATION</h3>
<p>The Tenant keeps the Premises open for trade during the hours reasonably notified by the Landlord for the building, and keeps the shopfront clean and properly presented.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. REPAIRS AND CONDITION</h3>
<p>The Landlord keeps the structure, roof and main services in good repair.</p>
<p>The Tenant keeps the interior, shopfront, glazing and its own installations in good repair, and returns the Premises in that condition at the end of the Term, fair wear and tear excepted.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. INSURANCE AND INDEMNITY</h3>
<p>The Landlord insures the building. The Tenant insures its own stock, fittings and equipment, and maintains public liability cover appropriate to its trade.</p>
<p>The Tenant indemnifies the Landlord against claims arising from the Tenant\'s use of the Premises, except where caused by the Landlord\'s negligence.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. ASSIGNMENT AND SUBLETTING</h3>
<p>The Tenant does not assign, sublet, or share possession of the Premises without the Landlord\'s prior written consent, which is not unreasonably withheld for a financially sound assignee in the same trade.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">13. TERMINATION AND DEFAULT</h3>
<p>Either party may end this Lease at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Landlord may terminate and re-enter where rent stays unpaid for twenty-one (21) days after its due date, where the Tenant breaches a clause and fails to remedy it within thirty (30) days of written notice, or where the Tenant becomes insolvent or ceases trading.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">14. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">15. GOVERNING LAW AND DISPUTES</h3>
<p>This Agreement is governed by the laws in force where the Premises are situated.</p>
<p>The parties will first attempt to resolve any dispute by good-faith discussion. Failing that, the dispute may be referred to the competent tribunal or court having jurisdiction over the Premises.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">16. ENTIRE AGREEMENT</h3>
<p>This document is the entire agreement between the parties and replaces any earlier understanding, whether spoken or written. Any change must be in writing and signed by both parties.</p>
<p>If any clause is found unenforceable, the remaining clauses stay in full force.</p>
<p>Each party confirms they have read and understood this Agreement, and have signed it freely.</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Office Space Lease Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">OFFICE SPACE LEASE AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE PREMISES</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Premises are let as office accommodation, together with the right to use the building\'s entrances, lifts, staircases, washrooms and other common areas.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. TERM</h3>
<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>
<p>Rent is exclusive of any value added tax or similar levy, which the Tenant pays in addition where it applies.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. PERMITTED USE</h3>
<p>The Premises are used only as business offices for the Tenant\'s declared activity, during normal business hours and at such other times as the building access rules allow.</p>
<p>The Tenant does not use the Premises for residential purposes, for storage of goods for sale, or for any activity that is a nuisance to other occupiers.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. SERVICE CHARGE AND UTILITIES</h3>
<p>The Tenant pays for electricity, water, telephone and internet consumed at the Premises, unless these are included in the service charge.</p>
<p>The Tenant pays the proportionate service charge for security, reception, common-area cleaning, lighting, lifts, generator fuel and grounds maintenance, as notified in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. ACCESS, SECURITY AND PARKING</h3>
<p>Access cards, keys and remotes issued to the Tenant remain the Landlord\'s property and are returned at the end of the Term. Lost items are replaced at the Tenant\'s cost.</p>
<p>The Tenant observes the building\'s security and visitor procedures. Parking bays, where allocated, are used only by the Tenant and are not sublet.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. FIT-OUT AND ALTERATIONS</h3>
<p>Partitioning, cabling, air-conditioning and any other installation require the Landlord\'s prior written consent and are carried out to the standard the Landlord approves.</p>
<p>At the end of the Term the Tenant removes its own installations and reinstates the Premises, unless the Landlord agrees in writing that they may remain.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. REPAIRS AND CONDITION</h3>
<p>The Landlord maintains the structure, common areas, lifts and main building services.</p>
<p>The Tenant keeps the interior of the Premises, including its own fittings and equipment, in good repair and reports faults promptly.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. INSURANCE AND INDEMNITY</h3>
<p>The Landlord insures the building. The Tenant insures its own equipment, records and contents, and maintains public liability cover.</p>
<p>The Tenant indemnifies the Landlord against claims arising from the Tenant\'s use of the Premises, except where caused by the Landlord\'s negligence.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. ASSIGNMENT AND SUBLETTING</h3>
<p>The Tenant does not assign, sublet or share occupation without the Landlord\'s prior written consent, save that the Tenant may allow occupation by a company within the same group on written notice to the Landlord.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">13. TERMINATION AND DEFAULT</h3>
<p>Either party may end this Lease at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Landlord may terminate where rent stays unpaid for twenty-one (21) days after its due date, or where the Tenant breaches a clause and fails to remedy it within thirty (30) days of written notice.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">14. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">15. GOVERNING LAW AND DISPUTES</h3>
<p>This Agreement is governed by the laws in force where the Premises are situated.</p>
<p>The parties will first attempt to resolve any dispute by good-faith discussion. Failing that, the dispute may be referred to the competent tribunal or court having jurisdiction over the Premises.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">16. ENTIRE AGREEMENT</h3>
<p>This document is the entire agreement between the parties and replaces any earlier understanding, whether spoken or written. Any change must be in writing and signed by both parties.</p>
<p>If any clause is found unenforceable, the remaining clauses stay in full force.</p>
<p>Each party confirms they have read and understood this Agreement, and have signed it freely.</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Warehouse / Godown Storage Lease Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">WAREHOUSE / GODOWN LEASE AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE PREMISES</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Premises are let for the storage and handling of goods, together with the right to use the yard and access roads for loading and unloading in accordance with the Landlord\'s directions.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. TERM</h3>
<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>
<p>Rent is exclusive of any value added tax or similar levy, which the Tenant pays in addition where it applies.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. PERMITTED GOODS AND USE</h3>
<p>The Premises are used only for storing and handling lawful goods of the type the Tenant declared to the Landlord in writing.</p>
<p>The Tenant does <strong>not</strong> store explosives, fuel, compressed gas, corrosive or radioactive material, or any hazardous or perishable goods, without the Landlord\'s prior written consent and the approval of the relevant authority.</p>
<p>No manufacturing, workshop activity, cooking or residential occupation takes place at the Premises. No person sleeps at the Premises except a security guard approved in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. LOADING LIMITS AND BUILDING CARE</h3>
<p>The Tenant observes the floor loading limits, stacking heights and rack fixing rules notified by the Landlord, and keeps gangways and fire exits clear at all times.</p>
<p>The Tenant does not overload the floor, roof or walls, and does not fix anything to the structure without written consent.</p>
<p>Forklifts and handling equipment are operated only by trained personnel, and the Tenant makes good any damage caused to floors, doors, walls or bollards.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. FIRE SAFETY AND COMPLIANCE</h3>
<p>The Tenant keeps firefighting equipment serviced and accessible, complies with all fire, health, safety and environmental requirements applying to its goods and operations, and permits inspection by the relevant authority.</p>
<p>The Tenant holds every licence and permit its operations require and produces copies to the Landlord on request.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. ACCESS HOURS AND SECURITY</h3>
<p>Access is available during the hours notified by the Landlord. Access outside those hours requires prior arrangement.</p>
<p>The Tenant is responsible for securing the Premises and its own goods, and observes the estate\'s gate, vehicle and visitor procedures.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. UTILITIES AND OUTGOINGS</h3>
<p>The Tenant pays for electricity and water consumed at the Premises and for the removal of its own waste.</p>
<p>Where the estate levies a service charge for security, road maintenance, drainage or common lighting, the Tenant pays the proportionate share notified in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. REPAIRS AND CONDITION</h3>
<p>The Landlord keeps the structure, roof, external walls and main services in good repair.</p>
<p>The Tenant keeps the interior, doors, shutters and its own installations in good repair, and returns the Premises swept clean and empty of all goods and waste at the end of the Term.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. INSURANCE AND LIABILITY</h3>
<p>The Landlord insures the building. <strong>The Landlord does not insure the Tenant\'s goods.</strong> The Tenant insures its own goods, equipment and liability to third parties for their full value.</p>
<p>Goods are stored at the Tenant\'s sole risk. The Landlord is not liable for loss of or damage to goods, including by theft, fire, water, pest or vermin, except where caused by the Landlord\'s negligence.</p>
<p>The Tenant indemnifies the Landlord against claims arising from the Tenant\'s goods or operations.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">13. ASSIGNMENT AND SUBLETTING</h3>
<p>The Tenant does not assign, sublet, or allow any third party to store goods at the Premises without the Landlord\'s prior written consent.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">14. TERMINATION AND DEFAULT</h3>
<p>Either party may end this Lease at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Landlord may terminate where rent stays unpaid for twenty-one (21) days after its due date, or where prohibited goods are stored at the Premises.</p>
<p>Goods left at the Premises more than fourteen (14) days after the Term ends may be removed and stored at the Tenant\'s cost, and dealt with as the law allows.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">15. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">16. GOVERNING LAW AND DISPUTES</h3>
<p>This Agreement is governed by the laws in force where the Premises are situated.</p>
<p>The parties will first attempt to resolve any dispute by good-faith discussion. Failing that, the dispute may be referred to the competent tribunal or court having jurisdiction over the Premises.</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Hostel / Student Accommodation Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">HOSTEL / STUDENT ACCOMMODATION AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Hostel Management:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Resident:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE ACCOMMODATION</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The Resident is allocated the bed space and room shown above. Management may reallocate the Resident to comparable accommodation where this is reasonably necessary, on written notice.</p>
<p>The Resident has shared use of the common facilities — which may include study areas, kitchen, laundry, bathrooms and grounds — with the other residents.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. PERIOD OF RESIDENCE</h3>
<p>Residence runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>, covering the academic period stated by Management.</p>
<p>This Agreement is personal to the Resident. It grants a licence to occupy a bed space and does not create a tenancy of any particular room.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. FEES</h3>
<p>The accommodation fee is <strong>{{rent_amount}}</strong> per {{payment_frequency}} period, payable in advance on or before day <strong>{{payment_due_day}}</strong>.</p>
<p>Fees are payable whether or not the Resident is in occupation during the period, including during short vacations falling within the Term.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. CAUTION DEPOSIT</h3>
<p>A refundable caution deposit of <strong>{{deposit_amount}}</strong> is held against damage, loss of keys, unpaid fees and breach of the Hostel Rules.</p>
<p>It is refunded within thirty (30) days after the Resident checks out and returns all keys, less any deductions itemised in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. HOSTEL RULES</h3>
<p>Quiet and study hours apply as posted. Noise that disturbs other residents is not permitted at any time.</p>
<p>Visitors are received only in the designated areas and during visiting hours. Overnight guests are not permitted.</p>
<p>Alcohol, illegal drugs, weapons, cooking appliances in bedrooms, open flames and smoking indoors are prohibited.</p>
<p>Residents do not swap rooms or bed spaces, and do not admit any person who is not a registered resident.</p>
<p>Management may post additional rules, which form part of this Agreement once notified.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. CARE OF PROPERTY</h3>
<p>The Resident keeps the room and shared facilities clean, reports faults promptly, and does not remove or alter any furniture or fitting.</p>
<p>Damage to the room, its furniture or common property is charged to the Resident responsible, or shared among the residents of the room where responsibility cannot be established.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. HEALTH, SAFETY AND ACCESS</h3>
<p>The Resident complies with all fire and safety instructions and takes part in evacuation drills.</p>
<p>Management may enter any room for inspection, cleaning, repair, or where there is reasonable concern for safety or a breach of the Rules, giving notice where it is practical to do so.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. BELONGINGS</h3>
<p>The Resident is responsible for their own belongings and for securing them. Management does not insure them and is not liable for loss or theft except where caused by its own negligence.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. DISCIPLINE AND TERMINATION</h3>
<p>Management may issue a warning, impose a charge, or terminate this Agreement and require the Resident to vacate where the Resident seriously or repeatedly breaches the Hostel Rules, fails to pay fees, or behaves in a way that endangers or harasses others.</p>
<p>The Resident may end this Agreement on thirty (30) days\' written notice; fees already due remain payable.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. EMERGENCY CONTACT AND GUARDIAN</h3>
<p>The Resident provides an emergency contact and, where the Resident is under the age of majority, a parent or guardian countersigns this Agreement.</p>
<p>Management may contact that person in a medical emergency or in a serious disciplinary matter.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Hostel Management Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Resident Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Plot / Land Lease Agreement',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">PLOT / LAND LEASE AGREEMENT</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Lessor:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Lessee:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE LAND</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>
<p>The land let under this Agreement ("the Land") is identified above and is delineated in the sketch or survey plan attached to and forming part of this Agreement.</p>
<p>The Lessee accepts the Land in its present condition, having inspected it and satisfied itself as to its boundaries, access and suitability.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. TERM</h3>
<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. PERMITTED USE</h3>
<p>The Land is used only for the purpose the Lessee declared to the Lessor in writing, and for no other purpose without prior written consent.</p>
<p>The Lessee does not use the Land for any unlawful purpose, for dumping of waste, for quarrying or extraction of soil or sand, or for any activity that damages the Land or is a nuisance to neighbouring occupiers.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. STRUCTURES AND IMPROVEMENTS</h3>
<p>The Lessee erects no permanent structure on the Land without the Lessor\'s prior written consent and the approval of the relevant planning authority.</p>
<p>Temporary structures approved by the Lessor are removed by the Lessee at the end of the Term, and the Land restored to its former condition.</p>
<p>Any permanent improvement remaining at the end of the Term becomes the property of the Lessor without compensation, unless the parties agree otherwise in writing.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. BOUNDARIES AND FENCING</h3>
<p>The Lessee does not move, remove or interfere with any boundary mark, beacon or fence.</p>
<p>Where the Lessee fences the Land, the fence is erected on the correct boundary and maintained in good condition throughout the Term.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. RATES, TAXES AND OUTGOINGS</h3>
<p>The Lessor pays land rent payable to the authority and any statutory land taxes on the title.</p>
<p>The Lessee pays for water, electricity and any service connected for its own use, and for the removal of its own waste.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">10. CARE OF THE LAND</h3>
<p>The Lessee keeps the Land clean, free of refuse and free of overgrowth, and takes reasonable steps to prevent erosion, contamination and damage to any tree the Lessor has asked to be preserved.</p>
<p>The Lessee makes good any contamination or damage it causes.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">11. TITLE AND QUIET ENJOYMENT</h3>
<p>The Lessor confirms it is entitled to let the Land and will give the Lessee quiet enjoyment of it for the Term, provided the Lessee observes this Agreement.</p>
<p>This Agreement creates a lease only. It transfers no ownership, and it gives the Lessee no right to sell, charge or subdivide the Land.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">12. ASSIGNMENT AND SUBLETTING</h3>
<p>The Lessee does not assign, sublet or part with possession of the Land, in whole or in part, without the Lessor\'s prior written consent.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">13. TERMINATION AND DEFAULT</h3>
<p>Either party may end this Agreement at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Lessor may terminate where rent stays unpaid for thirty (30) days after its due date, or where the Lessee breaches a clause and fails to remedy it within thirty (30) days of written notice.</p>
<p>On termination the Lessee vacates the Land, removes its property, and leaves the Land clean and clear.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">14. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">15. GOVERNING LAW AND DISPUTES</h3>
<p>This Agreement is governed by the laws in force where the Premises are situated.</p>
<p>The parties will first attempt to resolve any dispute by good-faith discussion. Failing that, the dispute may be referred to the competent tribunal or court having jurisdiction over the Premises.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">16. ENTIRE AGREEMENT</h3>
<p>This document is the entire agreement between the parties and replaces any earlier understanding, whether spoken or written. Any change must be in writing and signed by both parties.</p>
<p>If any clause is found unenforceable, the remaining clauses stay in full force.</p>
<p>Each party confirms they have read and understood this Agreement, and have signed it freely.</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Lessor Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Lessee Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0),
('Lease Renewal & Extension Addendum',
'<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">LEASE RENEWAL &amp; EXTENSION ADDENDUM</h2>
    <hr style="border:2px solid #1a6b4a;">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>
<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. THE PREMISES</h3>
<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. PURPOSE OF THIS ADDENDUM</h3>
<p>This Addendum is made on <strong>{{date_today}}</strong> and renews and extends the existing lease between the parties for the Premises described above ("the Original Lease").</p>
<p>It is read together with the Original Lease and forms part of it.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENEWED TERM</h3>
<p>The Original Lease is extended for a further term running from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. REVISED RENT</h3>
<p>With effect from <strong>{{start_date}}</strong>, rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>All other provisions of the Original Lease relating to payment of rent, receipts and late fees continue to apply.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. SECURITY DEPOSIT</h3>
<p>The security deposit held under the Original Lease is <strong>{{deposit_amount}}</strong>, and is carried forward and held on the same terms for the renewed term.</p>
<p>Where the revised rent requires an increased deposit, the Tenant pays the difference on or before the start of the renewed term.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">7. CONDITION OF THE PREMISES</h3>
<p>The Tenant confirms the Premises are in good condition and that any outstanding repair items have been notified to the Landlord in writing before signing this Addendum.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">8. ALL OTHER TERMS UNCHANGED</h3>
<p>Except as varied by this Addendum, every term and condition of the Original Lease remains in full force and continues to bind the parties.</p>
<p>Where this Addendum conflicts with the Original Lease, this Addendum prevails.</p>

<h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">9. ADDITIONAL TERMS</h3>
<p>{{terms}}</p>

<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Landlord Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>Tenant Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>
',
0);


-- ============================================================
-- PAYMENT & FINANCIAL TABLES
-- ============================================================

-- Payments (rent payments from tenants)
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lease_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `payment_date` DATE NOT NULL,
    `payment_method` ENUM('cash','bank_transfer','mobile_money','cheque','gateway') NOT NULL DEFAULT 'cash',
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `period_from` DATE DEFAULT NULL COMMENT 'Rent period start',
    `period_to` DATE DEFAULT NULL COMMENT 'Rent period end',
    `notes` TEXT DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `ledger_account_id` INT UNSIGNED DEFAULT NULL COMMENT 'Cash box or bank account chosen on receipt (part 38)',
    `status` ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_unit` (`unit_id`),
    INDEX `idx_lease` (`lease_id`),
    INDEX `idx_date` (`payment_date`),
    CONSTRAINT `fk_payment_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Receipts
CREATE TABLE IF NOT EXISTS `receipts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED NOT NULL,
    `receipt_number` VARCHAR(50) NOT NULL,
    `pdf_path` VARCHAR(500) DEFAULT NULL,
    `sent_via` ENUM('none','email','whatsapp','both') NOT NULL DEFAULT 'none',
    `sent_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_receipt_number` (`receipt_number`),
    INDEX `idx_payment` (`payment_id`),
    CONSTRAINT `fk_receipt_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Security Deposits
CREATE TABLE IF NOT EXISTS `deposits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lease_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `paid_date` DATE DEFAULT NULL,
    `payment_method` ENUM('cash','bank_transfer','mobile_money','cheque') DEFAULT NULL,
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `ledger_account_id` INT UNSIGNED DEFAULT NULL COMMENT 'Cash box or bank account chosen on receipt (part 38)',
    `status` ENUM('held','partially_refunded','refunded','forfeited') NOT NULL DEFAULT 'held',
    `refund_amount` DECIMAL(12,2) DEFAULT NULL,
    `refund_date` DATE DEFAULT NULL,
    `refund_method` VARCHAR(50) DEFAULT NULL,
    `refund_reference` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_lease` (`lease_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_deposit_lease` FOREIGN KEY (`lease_id`) REFERENCES `leases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_deposit_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_deposit_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Deposit Deductions
CREATE TABLE IF NOT EXISTS `deposit_deductions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `deposit_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `inspection_item_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_deposit` (`deposit_id`),
    CONSTRAINT `fk_deduction_deposit` FOREIGN KEY (`deposit_id`) REFERENCES `deposits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Late Fees
CREATE TABLE IF NOT EXISTS `late_fees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lease_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `fee_date` DATE NOT NULL,
    `period_reference` VARCHAR(20) DEFAULT NULL COMMENT 'e.g. 2026-03',
    `status` ENUM('pending','paid','waived') NOT NULL DEFAULT 'pending',
    `waived_by` INT UNSIGNED DEFAULT NULL,
    `waived_reason` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lease` (`lease_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_latefee_lease` FOREIGN KEY (`lease_id`) REFERENCES `leases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_latefee_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- INSPECTION TABLES
-- ============================================================

-- Inspections
CREATE TABLE IF NOT EXISTS `inspections` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `lease_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED DEFAULT NULL,
    `inspection_type` ENUM('move_in','move_out','routine') NOT NULL,
    `inspection_date` DATE NOT NULL,
    `inspector_name` VARCHAR(150) DEFAULT NULL,
    `status` ENUM('scheduled','in_progress','completed') NOT NULL DEFAULT 'scheduled',
    `overall_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_unit` (`unit_id`),
    INDEX `idx_type` (`inspection_type`),
    CONSTRAINT `fk_insp_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Inspection Items
CREATE TABLE IF NOT EXISTS `inspection_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `inspection_id` INT UNSIGNED NOT NULL,
    `room` VARCHAR(100) NOT NULL,
    `item_name` VARCHAR(100) NOT NULL,
    `condition` ENUM('excellent','good','fair','poor','damaged','missing') NOT NULL DEFAULT 'good',
    `photo_path` VARCHAR(500) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inspection` (`inspection_id`),
    CONSTRAINT `fk_inspitem_insp` FOREIGN KEY (`inspection_id`) REFERENCES `inspections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Inspection Templates
CREATE TABLE IF NOT EXISTS `inspection_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `rooms_json` JSON NOT NULL COMMENT 'Array of {room, items[]}',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Built-in inspection templates (generated by api/seed-inspection-templates.php --emit-sql)
-- Edit includes/inspection_templates_seed.php and regenerate; do not hand-edit.
INSERT INTO `inspection_templates` (`name`, `rooms_json`, `is_default`) VALUES
('Standard Residential',
'[{"room":"Entrance \\/ Hallway","items":["Main Door","Door Lock & Keys","Doorbell","Walls","Floor","Ceiling","Light Fixtures","Electrical Outlets","Intercom"]},{"room":"Living Room","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Curtain Rods","Skirting Boards","TV Point","Fan \\/ Air Conditioner"]},{"room":"Dining Area","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Skirting Boards"]},{"room":"Kitchen","items":["Walls","Floor","Ceiling","Windows","Cabinets","Drawers","Countertop","Sink","Taps \\/ Faucets","Drainage","Cooker \\/ Stove","Oven","Extractor Hood","Refrigerator Space","Tiles \\/ Splashback","Electrical Outlets","Light Fixtures","Gas Connection","Visible Leaks"]},{"room":"Bedroom 1 (Master)","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Wardrobe Doors","Curtains \\/ Blinds","Curtain Rods","Fan \\/ Air Conditioner"]},{"room":"Bedroom 2","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Curtains \\/ Blinds","Curtain Rods"]},{"room":"Bedroom 3","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Curtains \\/ Blinds","Curtain Rods"]},{"room":"Bathroom \\/ Toilet","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Laundry \\/ Utility Area","items":["Walls","Floor","Washing Machine Point","Water Inlet","Drainage","Drying Line","Ventilation"]},{"room":"Balcony \\/ Patio","items":["Floor","Railing","Door","Drainage","Light Fixture","Ceiling \\/ Cover"]},{"room":"Utilities & Services","items":["Electricity Meter Reading","Water Meter Reading","Distribution Board \\/ Fuses","Water Tank","Water Pump","Water Heater \\/ Geyser","Gas Cylinder","Smoke Detector","Fire Extinguisher"]},{"room":"Exterior & Access","items":["External Walls","Roof \\/ Gutters","Perimeter Fence","Gate","Parking Space","Garden \\/ Landscaping","Refuse Area","Security Lighting","Mailbox"]},{"room":"Handover","items":["Keys Issued (count)","Remote \\/ Access Cards","Manuals & Warranties","Cleanliness on Handover","Overall Condition"]}]',
1),
('Standard Commercial',
'[{"room":"Entrance & Frontage","items":["Main Door","Door Lock & Keys","Glazing \\/ Shopfront","Shutter \\/ Grille","Signage Area","Ramp \\/ Steps","External Lighting"]},{"room":"Main Area","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Air Conditioning Units","Data \\/ Network Points","Fire Exit","Emergency Lighting","Suspended Ceiling Tiles"]},{"room":"Back Office","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Air Conditioning","Data \\/ Network Points"]},{"room":"Storage \\/ Stock Room","items":["Walls","Floor","Ceiling","Door & Lock","Shelving","Light Fixtures","Ventilation"]},{"room":"Washroom","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Kitchenette \\/ Pantry","items":["Walls","Floor","Sink","Taps \\/ Faucets","Drainage","Cabinets","Countertop","Electrical Outlets"]},{"room":"Utilities & Safety","items":["Electricity Meter Reading","Water Meter Reading","Distribution Board \\/ Fuses","Generator \\/ Backup Supply","Fire Extinguishers","Fire Alarm","Emergency Exit Signage","First Aid Kit","Security Alarm","CCTV"]},{"room":"Exterior & Access","items":["External Walls","Roof \\/ Gutters","Parking Space","Loading Area","Refuse Area","Perimeter Security"]},{"room":"Handover","items":["Keys Issued (count)","Remote \\/ Access Cards","Statutory Certificates","Cleanliness on Handover","Overall Condition"]}]',
0),
('Single Room / Bedsitter',
'[{"room":"Room","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Curtain Rods","Wardrobe \\/ Closet","Bed Frame","Skirting Boards","Fan"]},{"room":"Cooking Area","items":["Walls","Floor","Countertop","Sink","Taps \\/ Faucets","Drainage","Cooker Point","Gas Connection","Electrical Outlets","Ventilation"]},{"room":"Bathroom \\/ Toilet","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Shared Facilities","items":["Corridor","Staircase","Shared Toilet","Shared Bathroom","Water Point","Drying Line","Refuse Area","Common Lighting","Courtyard"]},{"room":"Utilities","items":["Electricity Meter Reading","Water Meter Reading","Sub-Meter","Water Supply Pressure","Water Storage"]},{"room":"Handover","items":["Keys Issued (count)","Cleanliness on Handover","Overall Condition"]}]',
0),
('Studio / Self-Contained Flat',
'[{"room":"Entrance","items":["Main Door","Door Lock & Keys","Intercom","Light Fixture"]},{"room":"Living \\/ Sleeping Area","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Curtain Rods","Wardrobe \\/ Closet","Skirting Boards","Fan \\/ Air Conditioner","TV Point"]},{"room":"Kitchenette","items":["Walls","Floor","Ceiling","Windows","Cabinets","Drawers","Countertop","Sink","Taps \\/ Faucets","Drainage","Cooker \\/ Stove","Oven","Extractor Hood","Refrigerator Space","Tiles \\/ Splashback","Electrical Outlets","Light Fixtures","Gas Connection","Visible Leaks"]},{"room":"Bathroom","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Balcony","items":["Floor","Railing","Door","Drainage","Light Fixture"]},{"room":"Utilities","items":["Electricity Meter Reading","Water Meter Reading","Distribution Board \\/ Fuses","Water Heater \\/ Geyser","Water Tank"]},{"room":"Handover","items":["Keys Issued (count)","Access Cards","Cleanliness on Handover","Overall Condition"]}]',
0),
('Standalone House / Villa',
'[{"room":"Entrance \\/ Porch","items":["Main Door","Door Lock & Keys","Doorbell","Porch Floor","Porch Ceiling","Light Fixtures","Security Screen"]},{"room":"Living Room","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Curtain Rods","Skirting Boards","Fireplace","Fan \\/ Air Conditioner","TV Point"]},{"room":"Dining Room","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Skirting Boards"]},{"room":"Kitchen","items":["Walls","Floor","Ceiling","Windows","Cabinets","Drawers","Countertop","Sink","Taps \\/ Faucets","Drainage","Cooker \\/ Stove","Oven","Extractor Hood","Refrigerator Space","Tiles \\/ Splashback","Electrical Outlets","Light Fixtures","Gas Connection","Visible Leaks"]},{"room":"Pantry \\/ Store","items":["Walls","Floor","Shelving","Door","Light Fixture","Ventilation"]},{"room":"Master Bedroom","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Curtains \\/ Blinds","Curtain Rods","Fan \\/ Air Conditioner","En-suite Access"]},{"room":"Bedroom 2","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Curtains \\/ Blinds"]},{"room":"Bedroom 3","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Curtains \\/ Blinds"]},{"room":"Bedroom 4","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Wardrobe \\/ Closet","Curtains \\/ Blinds"]},{"room":"Master Bathroom","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Guest Bathroom","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Laundry \\/ Utility Room","items":["Walls","Floor","Washing Machine Point","Water Inlet","Drainage","Drying Line","Ventilation","Ironing Area"]},{"room":"Staircase & Landing","items":["Treads","Handrail","Balustrade","Walls","Lighting","Landing Floor"]},{"room":"Garage \\/ Carport","items":["Floor","Walls","Roof","Garage Door","Door Opener","Light Fixtures","Electrical Outlets","Drainage"]},{"room":"Servant Quarters \\/ Outbuilding","items":["Walls","Floor","Roof","Door & Lock","Windows","Toilet","Shower","Electricity","Water Supply"]},{"room":"Garden & Grounds","items":["Lawn","Hedges \\/ Shrubs","Trees","Paving \\/ Driveway","Irrigation \\/ Taps","Outdoor Lighting","Swimming Pool","Pool Pump","Borehole \\/ Well","Septic Tank \\/ Soakaway"]},{"room":"Utilities & Services","items":["Electricity Meter Reading","Water Meter Reading","Distribution Board \\/ Fuses","Water Tank","Water Pump","Water Heater \\/ Geyser","Solar Panels","Generator","Gas Installation","Smoke Detectors","Fire Extinguisher"]},{"room":"Exterior & Security","items":["External Walls","Paintwork","Roof \\/ Gutters","Downpipes","Perimeter Wall","Gate","Gate Motor","Security Lighting","CCTV","Alarm System"]},{"room":"Handover","items":["Keys Issued (count)","Gate Remotes","Manuals & Warranties","Cleanliness on Handover","Overall Condition"]}]',
0),
('Furnished Unit - Inventory Check',
'[{"room":"Living Room Furniture","items":["Sofa \\/ Settee","Armchairs","Coffee Table","Side Tables","TV Unit","Television","Remote Control","Bookshelf","Rug \\/ Carpet","Wall Art","Curtains","Lamps","Cushions"]},{"room":"Dining Furniture","items":["Dining Table","Dining Chairs","Sideboard \\/ Buffet","Table Mats"]},{"room":"Kitchen Appliances","items":["Refrigerator","Freezer","Cooker \\/ Stove","Oven","Microwave","Extractor Hood","Kettle","Toaster","Blender","Water Dispenser","Dishwasher"]},{"room":"Kitchenware","items":["Plates","Bowls","Glasses","Mugs","Cutlery Set","Pots & Pans","Cooking Utensils","Chopping Board","Storage Containers","Bin"]},{"room":"Bedroom Furniture","items":["Bed Frame","Mattress","Mattress Protector","Bedside Tables","Wardrobe","Chest of Drawers","Dressing Mirror","Bedside Lamps","Curtains"]},{"room":"Linen & Bedding","items":["Bed Sheets","Duvet \\/ Blanket","Duvet Cover","Pillows","Pillow Cases","Bath Towels","Hand Towels","Bath Mat"]},{"room":"Appliances & Electronics","items":["Air Conditioner","Ceiling Fan","Standing Fan","Washing Machine","Iron","Ironing Board","Water Heater","Wi-Fi Router","Extension Cords"]},{"room":"Bathroom Fittings","items":["Shower Curtain","Toilet Brush","Waste Bin","Mirror Cabinet","Soap Dispenser"]},{"room":"Outdoor Furniture","items":["Patio Table","Patio Chairs","Sun Loungers","Parasol","Drying Rack"]},{"room":"Inventory Handover","items":["Inventory List Signed","Keys Issued (count)","Damages Noted","Missing Items Noted","Overall Condition"]}]',
0),
('Shop / Retail Unit',
'[{"room":"Shopfront","items":["Display Window","Glazing Condition","Entrance Door","Door Lock & Keys","Roller Shutter","Shutter Motor","Signage Board","Awning","Step \\/ Ramp","External Lighting"]},{"room":"Sales Floor","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Floor Covering","Display Shelving","Counter","Air Conditioning","Suspended Ceiling Tiles","Emergency Lighting","Fire Exit"]},{"room":"Fitting Room","items":["Walls","Floor","Mirror","Curtain \\/ Door","Hooks","Lighting"]},{"room":"Stock Room","items":["Walls","Floor","Ceiling","Door & Lock","Shelving","Lighting","Ventilation","Pest Evidence"]},{"room":"Staff Washroom","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Utilities & Safety","items":["Electricity Meter Reading","Water Meter Reading","Distribution Board \\/ Fuses","Fire Extinguishers","Fire Alarm","Emergency Exit Signage","Security Alarm","CCTV","Roller Shutter Lock"]},{"room":"Rear Access & Refuse","items":["Rear Door","Door Lock","Delivery Access","Refuse Storage","Drainage"]},{"room":"Handover","items":["Keys Issued (count)","Shutter Keys","Alarm Codes Handed Over","Cleanliness on Handover","Overall Condition"]}]',
0),
('Office Space',
'[{"room":"Reception","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Reception Counter","Waiting Seating","Air Conditioning","Intercom","Signage"]},{"room":"Open Plan Area","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Partitioning","Floor Covering \\/ Carpet","Data \\/ Network Points","Air Conditioning","Suspended Ceiling Tiles","Blinds"]},{"room":"Private Offices","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Partitioning","Blinds","Data \\/ Network Points","Air Conditioning"]},{"room":"Meeting Room","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Projector \\/ Screen","Whiteboard","Data \\/ Network Points","Air Conditioning","Blinds"]},{"room":"Server \\/ Comms Room","items":["Walls","Floor","Door & Lock","Server Rack","Cabling","Cooling Unit","UPS","Power Supply"]},{"room":"Kitchenette \\/ Pantry","items":["Walls","Floor","Cabinets","Countertop","Sink","Taps \\/ Faucets","Drainage","Refrigerator","Kettle","Microwave","Electrical Outlets"]},{"room":"Washrooms","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Utilities & Safety","items":["Electricity Meter Reading","Water Meter Reading","Distribution Board \\/ Fuses","Generator \\/ Backup Supply","Fire Extinguishers","Fire Alarm","Smoke Detectors","Emergency Lighting","Emergency Exit Signage","First Aid Kit","Access Control System","CCTV"]},{"room":"Common Areas & Access","items":["Corridor","Staircase","Lift \\/ Elevator","Parking Bays","Loading Bay","Refuse Area"]},{"room":"Handover","items":["Keys Issued (count)","Access Cards","Alarm Codes Handed Over","Parking Bay Numbers","Cleanliness on Handover","Overall Condition"]}]',
0),
('Warehouse / Godown',
'[{"room":"Structure","items":["External Walls","Internal Walls","Roof Sheets","Roof Leaks","Roof Trusses","Gutters & Downpipes","Columns \\/ Pillars","Skylights"]},{"room":"Floor & Loading","items":["Floor Slab Condition","Floor Cracks","Floor Levelness","Loading Bay","Dock Leveller","Ramp","Bollards","Line Markings","Gangway Clearance"]},{"room":"Doors & Openings","items":["Roller Shutter Door","Shutter Motor","Personnel Door","Door Locks","Emergency Exit Door","Windows \\/ Louvres"]},{"room":"Storage Area","items":["Racking \\/ Shelving","Racking Fixings","Stacking Height Markings","Pallet Condition","Pest Evidence","Damp \\/ Water Ingress"]},{"room":"Office \\/ Admin Area","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Air Conditioning","Data \\/ Network Points"]},{"room":"Washroom & Welfare","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Electrical & Lighting","items":["Electricity Meter Reading","Distribution Board \\/ Fuses","High Bay Lighting","Emergency Lighting","Power Sockets","Three-Phase Supply","Earthing"]},{"room":"Fire & Safety","items":["Fire Extinguishers","Fire Hose Reel","Sprinkler System","Fire Alarm","Smoke Detectors","Emergency Exit Signage","Assembly Point Signage","First Aid Kit","Hazardous Goods Signage"]},{"room":"Yard & Security","items":["Yard Surface","Drainage","Perimeter Fence","Main Gate","Guard House","Security Lighting","CCTV","Vehicle Turning Area","Weighbridge"]},{"room":"Utilities","items":["Water Meter Reading","Water Supply","Water Tank","Septic \\/ Sewer","Generator","Compressed Air Line"]},{"room":"Handover","items":["Keys Issued (count)","Shutter Keys","Gate Remotes","Cleanliness on Handover","Overall Condition"]}]',
0),
('Hostel Room / Student Accommodation',
'[{"room":"Bed Space","items":["Bed Frame","Mattress","Mattress Condition","Pillow","Bedside Locker","Study Desk","Study Chair","Reading Lamp","Wardrobe \\/ Locker","Locker Key","Notice Board"]},{"room":"Room","items":["Walls","Floor","Ceiling","Doors","Door Locks","Windows","Window Locks","Light Fixtures","Light Switches","Electrical Outlets","Curtains \\/ Blinds","Fan","Skirting Boards","Room Number Plate"]},{"room":"Shared Bathroom","items":["Walls","Floor","Ceiling","Door","Toilet","Cistern \\/ Flush","Wash Basin","Taps \\/ Faucets","Shower","Shower Head","Bathtub","Mirror","Towel Rail","Drainage","Water Pressure","Ventilation \\/ Extractor","Tiles & Grouting","Visible Leaks"]},{"room":"Shared Kitchen","items":["Walls","Floor","Cooker \\/ Stove","Sink","Taps \\/ Faucets","Drainage","Countertop","Cabinets","Refrigerator","Ventilation","Waste Bin"]},{"room":"Common Areas","items":["Corridor","Staircase","Handrail","Study \\/ Common Room","Laundry Area","Drying Line","Notice Board","Common Lighting","Refuse Area"]},{"room":"Safety & Security","items":["Room Door Lock","Window Grilles","Fire Extinguisher","Smoke Detector","Emergency Exit","Emergency Exit Signage","Assembly Point Signage","First Aid Kit","Security Lighting","Warden Contact Displayed"]},{"room":"Utilities","items":["Electricity Supply","Water Supply","Water Storage","Water Heater","Internet \\/ Wi-Fi"]},{"room":"Handover","items":["Keys Issued (count)","Locker Key Issued","House Rules Signed","Cleanliness on Handover","Overall Condition"]}]',
0),
('Plot / Land',
'[{"room":"Boundaries","items":["Boundary Beacons Present","Beacon Condition","Fence Line","Fence Condition","Gate","Gate Lock","Encroachment Evidence","Survey Plan Matches Site"]},{"room":"Ground Condition","items":["Terrain \\/ Slope","Soil Condition","Erosion Evidence","Waterlogging","Rock Outcrops","Existing Excavations","Dumped Waste","Overgrowth"]},{"room":"Vegetation","items":["Trees Present","Protected Trees","Grass \\/ Bush Cover","Crops Planted"]},{"room":"Existing Structures","items":["Temporary Structures","Permanent Structures","Foundations","Storage Shed","Guard Post","Unauthorised Occupation"]},{"room":"Access & Services","items":["Road Access","Access Road Condition","Electricity Line Nearby","Water Connection","Borehole \\/ Well","Drainage Channel","Sewer Line"]},{"room":"Documentation","items":["Title \\/ Ownership Document Sighted","Survey Plan Sighted","Land Rent Receipts","Site Photographs Taken","GPS Coordinates Recorded"]},{"room":"Handover","items":["Gate Keys Issued","Boundary Walk Completed","Overall Condition"]}]',
0),
('Building Common Areas & Exterior',
'[{"room":"Entrance & Lobby","items":["Main Entrance Door","Door Closer","Intercom Panel","Lobby Floor","Lobby Walls","Lobby Ceiling","Lighting","Notice Board","Mailboxes","Reception Desk"]},{"room":"Corridors & Staircases","items":["Corridor Floor","Corridor Walls","Corridor Lighting","Stair Treads","Handrails","Balustrades","Fire Doors","Emergency Lighting","Exit Signage"]},{"room":"Lifts","items":["Lift Car Condition","Lift Doors","Control Panel","Emergency Phone","Inspection Certificate","Lift Lighting"]},{"room":"Roof & Water Storage","items":["Roof Surface","Roof Leaks","Gutters","Downpipes","Water Tanks","Tank Covers","Tank Cleanliness","Booster Pump","Lightning Arrestor","Roof Access Door"]},{"room":"Plant & Services","items":["Main Distribution Board","Sub-Meters","Generator","Generator Fuel Level","Water Pump","Sump \\/ Underground Tank","Solar Installation","Sewer \\/ Septic System","Grease Trap"]},{"room":"Fire Safety","items":["Fire Extinguishers","Extinguisher Service Date","Fire Hose Reels","Fire Alarm Panel","Smoke Detectors","Sprinklers","Emergency Exits","Assembly Point Signage","Fire Escape Route Clear"]},{"room":"Parking & Grounds","items":["Parking Surface","Bay Markings","Parking Lighting","Driveway","Landscaping","Paving","Storm Drainage","Refuse Bin Area","Refuse Collection"]},{"room":"Security","items":["Perimeter Wall \\/ Fence","Main Gate","Gate Motor","Guard House","CCTV Cameras","CCTV Recording","Security Lighting","Access Control","Visitor Log"]},{"room":"External Fabric","items":["External Walls","Paintwork","Cracks","Damp Patches","Windows","Balcony Railings","Signage","Boundary Wall Condition"]}]',
0);


-- ============================================================
-- ADVANCED MODULE TABLES (created for all, activated by tier)
-- ============================================================

-- Utility Types
CREATE TABLE IF NOT EXISTS `utility_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `unit_of_measure` VARCHAR(30) DEFAULT NULL COMMENT 'kWh, liters, units, flat',
    `rate_type` ENUM('per_unit','flat','tiered') NOT NULL DEFAULT 'per_unit',
    `rate_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tiered_rates_json` JSON DEFAULT NULL COMMENT '[{from, to, rate}]',
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Starter utility types (rates are 0 - each landlord sets their own tariff)
INSERT INTO `utility_types` (`name`, `unit_of_measure`, `rate_type`, `rate_amount`, `status`) VALUES
('Water', 'litres', 'per_unit', 0.00, 'active'),
('Electricity', 'kWh', 'per_unit', 0.00, 'active'),
('Garbage Collection', 'month', 'flat', 0.00, 'active');


-- Utility Readings
CREATE TABLE IF NOT EXISTS `utility_readings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `utility_type_id` INT UNSIGNED NOT NULL,
    `reading_date` DATE NOT NULL,
    `previous_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `current_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `consumption` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_unit` (`unit_id`),
    INDEX `idx_utility` (`utility_type_id`),
    CONSTRAINT `fk_reading_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reading_utility` FOREIGN KEY (`utility_type_id`) REFERENCES `utility_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Utility Charges
CREATE TABLE IF NOT EXISTS `utility_charges` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED NOT NULL,
    `lease_id` INT UNSIGNED DEFAULT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `utility_type_id` INT UNSIGNED NOT NULL,
    `period_month` TINYINT UNSIGNED NOT NULL,
    `period_year` SMALLINT UNSIGNED NOT NULL,
    `consumption` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `status` ENUM('pending','paid','waived') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_unit` (`unit_id`),
    CONSTRAINT `fk_charge_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_charge_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_charge_utility` FOREIGN KEY (`utility_type_id`) REFERENCES `utility_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Maintenance Categories
CREATE TABLE IF NOT EXISTS `maintenance_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `icon` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `maintenance_categories` (`name`) VALUES
('Plumbing'), ('Electrical'), ('Structural'), ('Appliance'),
('Pest Control'), ('Cleaning'), ('Other')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Maintenance Requests
CREATE TABLE IF NOT EXISTS `maintenance_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('reported','acknowledged','assigned','in_progress','completed','closed','cancelled') NOT NULL DEFAULT 'reported',
    `reported_by_type` ENUM('tenant','staff') NOT NULL DEFAULT 'staff',
    `reported_by_id` INT UNSIGNED DEFAULT NULL,
    `completed_date` TIMESTAMP NULL DEFAULT NULL,
    `completion_notes` TEXT DEFAULT NULL,
    `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_unit` (`unit_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    CONSTRAINT `fk_maint_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_maint_category` FOREIGN KEY (`category_id`) REFERENCES `maintenance_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Maintenance Photos
CREATE TABLE IF NOT EXISTS `maintenance_photos` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `caption` VARCHAR(255) DEFAULT NULL,
    `photo_type` ENUM('before','after') NOT NULL DEFAULT 'before',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_request` (`request_id`),
    CONSTRAINT `fk_maintphoto_request` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Contractors
CREATE TABLE IF NOT EXISTS `contractors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `company_name` VARCHAR(200) DEFAULT NULL,
    `specialty` VARCHAR(100) DEFAULT NULL,
    `hourly_rate` DECIMAL(12,2) DEFAULT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `rating` DECIMAL(2,1) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Maintenance Assignments
CREATE TABLE IF NOT EXISTS `maintenance_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT UNSIGNED NOT NULL,
    `contractor_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED DEFAULT NULL,
    `assigned_date` DATE NOT NULL,
    `estimated_completion` DATE DEFAULT NULL,
    `actual_completion` DATE DEFAULT NULL,
    `estimated_cost` DECIMAL(12,2) DEFAULT NULL,
    `actual_cost` DECIMAL(12,2) DEFAULT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('assigned','accepted','in_progress','completed') NOT NULL DEFAULT 'assigned',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_request` (`request_id`),
    INDEX `idx_contractor` (`contractor_id`),
    CONSTRAINT `fk_assign_request` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assign_contractor` FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Maintenance Time Logs (was sql/part12_maintenance.sql — never folded in, so
-- modules/maintenance/view.php 500'd on every request detail page)
CREATE TABLE IF NOT EXISTS `maintenance_time_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT UNSIGNED NOT NULL,
    `log_date` DATE NOT NULL,
    `hours_worked` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `description` VARCHAR(255) DEFAULT NULL,
    `logged_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_assignment` (`assignment_id`),
    CONSTRAINT `fk_timelog_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `maintenance_assignments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Maintenance Notifications (Part 19)
CREATE TABLE IF NOT EXISTS `maintenance_notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT UNSIGNED NOT NULL,
    `recipient_type` ENUM('staff','tenant','contractor') NOT NULL DEFAULT 'staff',
    `recipient_id` INT UNSIGNED DEFAULT NULL,
    `recipient_name` VARCHAR(100) DEFAULT NULL,
    `channel` ENUM('system','whatsapp','email') NOT NULL DEFAULT 'system',
    `event_type` VARCHAR(50) NOT NULL,
    `message` TEXT DEFAULT NULL,
    `status` ENUM('sent','pending','failed') NOT NULL DEFAULT 'sent',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_request` (`request_id`),
    INDEX `idx_recipient` (`recipient_type`, `recipient_id`),
    CONSTRAINT `fk_maintnoti_request` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Expense Categories
CREATE TABLE IF NOT EXISTS `expense_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `expense_categories` (`name`) VALUES
('Repairs'), ('Taxes'), ('Insurance'), ('Staff'),
('Management Fee'), ('Utilities'), ('Other')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Expenses
CREATE TABLE IF NOT EXISTS `expenses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `expense_date` DATE NOT NULL,
    `vendor_name` VARCHAR(200) DEFAULT NULL,
    `receipt_path` VARCHAR(500) DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `maintenance_request_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('active','voided') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_property` (`property_id`),
    INDEX `idx_date` (`expense_date`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_expense_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Reminder Templates
CREATE TABLE IF NOT EXISTS `reminder_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `trigger_type` ENUM('before_due','on_due','overdue') NOT NULL,
    `days_offset` INT NOT NULL DEFAULT 0 COMMENT 'Positive = before due, negative = after due',
    `repeat_interval_days` INT UNSIGNED DEFAULT 0 COMMENT '0=no repeat, N=repeat every N days for overdue',
    `channel` ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
    `message_template` TEXT NOT NULL COMMENT 'Placeholders: {tenant_name}, {amount}, {due_date}, etc.',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default reminder templates (was sql/part17_reminders.sql — never folded in)
INSERT INTO `reminder_templates` (`name`, `trigger_type`, `days_offset`, `repeat_interval_days`, `channel`, `message_template`, `is_active`) VALUES
('3-Day Reminder', 'before_due', 3, 0, 'whatsapp',
 'Hello {tenant_name}, this is a friendly reminder that your rent of {currency} {amount} for unit {unit_number} at {property_name} is due on {due_date}. Please ensure timely payment. Thank you! - {company_name}', 1),
('1-Day Reminder', 'before_due', 1, 0, 'whatsapp',
 'Hi {tenant_name}, your rent of {currency} {amount} for {unit_number} is due tomorrow ({due_date}). Please make your payment to avoid late fees. - {company_name}', 1),
('Due Date Notice', 'on_due', 0, 0, 'whatsapp',
 'Dear {tenant_name}, your rent of {currency} {amount} for unit {unit_number} at {property_name} is due today. Please complete your payment. Thank you! - {company_name}', 1),
('Overdue Notice', 'overdue', 3, 7, 'whatsapp',
 'Dear {tenant_name}, your rent of {currency} {amount} for unit {unit_number} at {property_name} is now overdue. Your outstanding balance is {currency} {balance}. Please make payment immediately to avoid additional late fees. - {company_name}', 1);


-- Reminder Schedule
CREATE TABLE IF NOT EXISTS `reminder_schedule` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lease_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `template_id` INT UNSIGNED NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `period_reference` VARCHAR(20) DEFAULT NULL COMMENT 'e.g. 2026-03',
    `actual_sent_date` DATETIME DEFAULT NULL,
    `channel` ENUM('whatsapp','sms','email') NOT NULL,
    `message_body` TEXT DEFAULT NULL COMMENT 'Resolved message text that was sent',
    `recipient_phone` VARCHAR(20) DEFAULT NULL,
    `recipient_email` VARCHAR(150) DEFAULT NULL,
    `status` ENUM('scheduled','sent','delivered','failed','cancelled') NOT NULL DEFAULT 'scheduled',
    `error_message` TEXT DEFAULT NULL,
    `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_scheduled` (`scheduled_date`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_reminder_lease` FOREIGN KEY (`lease_id`) REFERENCES `leases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reminder_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reminder_template` FOREIGN KEY (`template_id`) REFERENCES `reminder_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Receipt Messages — tracks WhatsApp/SMS/email receipt auto-sends
-- (was sql/part17_reminders.sql — never folded in)
CREATE TABLE IF NOT EXISTS `receipt_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED NOT NULL,
    `receipt_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
    `recipient_phone` VARCHAR(20) DEFAULT NULL,
    `recipient_email` VARCHAR(150) DEFAULT NULL,
    `message_body` TEXT DEFAULT NULL,
    `status` ENUM('pending','sent','delivered','failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `sent_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payment` (`payment_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_rcptmsg_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rcptmsg_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Vacancy Listings
CREATE TABLE IF NOT EXISTS `vacancy_listings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `rent_amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `available_from` DATE DEFAULT NULL,
    `features_json` JSON DEFAULT NULL,
    `photos_json` JSON DEFAULT NULL,
    `contact_phone` VARCHAR(20) DEFAULT NULL,
    `contact_email` VARCHAR(150) DEFAULT NULL,
    `contact_name` VARCHAR(150) DEFAULT NULL,
    `deposit_amount` DECIMAL(12,2) DEFAULT NULL,
    `status` ENUM('active','inactive','rented') NOT NULL DEFAULT 'active',
    `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_unit` (`unit_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_listing_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Vacancy Inquiries
CREATE TABLE IF NOT EXISTS `vacancy_inquiries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `listing_id` INT UNSIGNED NOT NULL,
    `inquirer_name` VARCHAR(150) NOT NULL,
    `inquirer_phone` VARCHAR(20) DEFAULT NULL,
    `inquirer_email` VARCHAR(150) DEFAULT NULL,
    `message` TEXT DEFAULT NULL,
    `status` ENUM('new','contacted','viewing_scheduled','application_received','rejected','converted') NOT NULL DEFAULT 'new',
    `notes` TEXT DEFAULT NULL,
    `follow_up_date` DATE DEFAULT NULL,
    `followed_up_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_listing` (`listing_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_inquiry_listing` FOREIGN KEY (`listing_id`) REFERENCES `vacancy_listings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gateway Config
CREATE TABLE IF NOT EXISTS `gateway_config` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `gateway_name` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `api_key` VARCHAR(255) DEFAULT NULL,
    `api_secret` VARCHAR(255) DEFAULT NULL,
    `merchant_id` VARCHAR(100) DEFAULT NULL,
    `callback_url` VARCHAR(500) DEFAULT NULL,
    `config_json` JSON DEFAULT NULL COMMENT 'Extra gateway-specific config (shortcode, business_number, etc.)',
    `logo_icon` VARCHAR(50) DEFAULT NULL,
    `environment` ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_gateway` (`gateway_name`)
) ENGINE=InnoDB;

-- Default gateway configurations
INSERT INTO `gateway_config` (`gateway_name`, `display_name`, `description`, `environment`, `is_active`, `logo_icon`) VALUES
('selcom', 'Selcom', 'Selcom payment gateway — supports M-Pesa, Airtel Money, Tigo Pesa, Halotel', 'sandbox', 0, 'bi-phone'),
('mpesa', 'Vodacom M-Pesa', 'Vodacom M-Pesa C2B collection (direct integration)', 'sandbox', 0, 'bi-phone-vibrate'),
('airtel_money', 'Airtel Money', 'Airtel Money collection API', 'sandbox', 0, 'bi-telephone-outbound'),
('tigo_pesa', 'Tigo Pesa', 'Tigo Pesa / MiX by Yas collection', 'sandbox', 0, 'bi-telephone-forward')
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`), `description` = VALUES(`description`), `logo_icon` = VALUES(`logo_icon`);

-- Gateway Transactions
CREATE TABLE IF NOT EXISTS `gateway_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED DEFAULT NULL,
    `lease_id` INT UNSIGNED DEFAULT NULL,
    `unit_id` INT UNSIGNED DEFAULT NULL,
    `gateway_name` VARCHAR(50) NOT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `external_reference` VARCHAR(100) DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `phone_number` VARCHAR(20) DEFAULT NULL,
    `status` ENUM('initiated','pending','completed','failed','cancelled') NOT NULL DEFAULT 'initiated',
    `callback_data` JSON DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `reconciled` TINYINT(1) NOT NULL DEFAULT 0,
    `reconciled_at` DATETIME DEFAULT NULL,
    `reconciled_by` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_payment` (`payment_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_gateway_name` (`gateway_name`),
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_reconciled` (`reconciled`)
) ENGINE=InnoDB;

-- ============================================================
-- SYSTEM TABLES (all tiers)
-- ============================================================

-- Settings (tenant-level key-value)
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_group` ENUM('general','payments','notifications','late_fees','modules','display') NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_key` (`setting_key`)
) ENGINE=InnoDB;

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('company_name', '', 'general', 'Company/landlord name'),
('company_phone', '', 'general', 'Company phone'),
('company_email', '', 'general', 'Company email'),
('company_address', '', 'general', 'Company address'),
('company_logo', '', 'general', 'Path to company logo'),
('default_currency', 'TZS', 'general', 'Default currency'),
('supported_currencies_json', '["TZS","USD","KES","UGX"]', 'general', 'Supported currencies'),
('exchange_rates_json', '{"USD":2500,"KES":18,"UGX":0.68}', 'general', 'Exchange rates (TZS per 1 unit of foreign currency)'),
('language', 'en', 'general', 'Default language'),
('timezone', 'Africa/Dar_es_Salaam', 'general', 'Timezone'),
('late_fee_enabled', '0', 'late_fees', 'Enable late fee auto-calculation'),
('late_fee_type', 'percentage', 'late_fees', 'Late fee type: percentage or fixed'),
('late_fee_value', '5', 'late_fees', 'Late fee value'),
('grace_period_days', '5', 'late_fees', 'Grace period before late fee applies'),
('max_late_fee', '0', 'late_fees', 'Maximum late fee (0=unlimited)'),
('payment_methods_json', '["cash","bank_transfer","mobile_money"]', 'payments', 'Enabled payment methods'),
('notification_channels_json', '["email"]', 'notifications', 'Enabled channels'),
('receipt_prefix', 'RCT-', 'payments', 'Receipt number prefix'),
('receipt_next_number', '1', 'payments', 'Next receipt sequential number'),
('tenant_portal_enabled', '0', 'modules', 'Enable tenant self-service portal'),
('utility_billing_enabled', '0', 'modules', 'Enable utility billing module'),
('maintenance_enabled', '0', 'modules', 'Enable maintenance management'),
('reminders_enabled', '0', 'modules', 'Enable automated rent reminders'),
('documents_enabled', '0', 'modules', 'Enable document management module'),
('storage_quota_mb', '500', 'modules', 'Storage quota in MB for documents'),
('financial_reports_enabled', '0', 'modules', 'Enable financial reporting'),
('vacancy_advertising_enabled', '0', 'modules', 'Enable vacancy listing module'),
('vacancy_contact_phone', '', 'modules', 'Default contact phone for vacancy listings'),
('vacancy_contact_email', '', 'modules', 'Default contact email for vacancy listings'),
('vacancy_company_name', '', 'modules', 'Company name shown on public vacancy page'),
('payment_gateway_enabled', '0', 'modules', 'Enable payment gateway integration'),
('gateway_callback_secret', '', 'payments', 'Secret key for validating gateway callbacks'),
('gateway_auto_reconcile', '1', 'payments', 'Auto-reconcile completed gateway transactions to payments'),
('gateway_auto_receipt', '1', 'payments', 'Auto-generate receipt after successful gateway payment'),
('session_timeout_minutes', '30', 'general', 'Session idle timeout in minutes (0 = no timeout)'),
('maintenance_notify_on_status', '1', 'notifications', 'Notify stakeholders on maintenance status changes'),
('maintenance_notify_on_assign', '1', 'notifications', 'Notify contractor when assigned to maintenance request'),
('maintenance_auto_close_days', '7', 'modules', 'Auto-close completed requests after X days (0 = disabled)')
,
-- From sql/part17_reminders.sql / part24_user_properties.sql (never folded in)
('receipt_auto_send', '1', 'notifications', 'Auto-send receipt via WhatsApp after payment recording'),
('reminder_wa_enabled', '1', 'notifications', 'Enable WhatsApp for reminders'),
('reminder_sms_enabled', '0', 'notifications', 'Enable SMS for reminders'),
('reminder_email_enabled', '1', 'notifications', 'Enable email for reminders'),
('cron_key', '', 'general', 'Secret key for cron job HTTP access'),
('property_scoping_enabled', '0', 'general', 'Restrict non-admin users to their assigned properties'),
-- SMS gateway (Sprint Marketing & Technology API v3.0)
('sms_api_url', '', 'notifications', 'SMS gateway base URL, e.g. https://sms.example.com'),
('sms_api_id', '', 'notifications', 'SMS API ID'),
('sms_api_password', '', 'notifications', 'SMS API password'),
('sms_sender_id', '', 'notifications', 'Registered SMS sender ID'),
('sms_type', 'T', 'notifications', 'T = transactional, P = promotional'),
('sms_country_code', '255', 'notifications', 'Country code prefixed to local numbers'),
('sms_callback_url', '', 'notifications', 'Optional delivery-report callback URL'),
('reminder_max_overdue_nags', '4', 'notifications', 'Max repeated overdue reminders per period')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Activity Log (audit trail)
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `user_type` ENUM('staff','tenant') NOT NULL DEFAULT 'staff',
    `action` VARCHAR(50) NOT NULL COMMENT 'created, updated, deleted, viewed, exported, login, logout',
    `entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'property, unit, tenant, lease, payment, etc.',
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `details` JSON DEFAULT NULL COMMENT 'Old/new values',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

-- Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `user_type` ENUM('staff','tenant') NOT NULL DEFAULT 'staff',
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT DEFAULT NULL,
    `link` VARCHAR(500) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`, `user_type`),
    INDEX `idx_read` (`is_read`)
) ENGINE=InnoDB;

-- Document Categories (Part 18)
CREATE TABLE IF NOT EXISTS `document_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `icon` VARCHAR(50) NOT NULL DEFAULT 'bi-folder',
    `color` VARCHAR(20) NOT NULL DEFAULT '#6c757d',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_name` (`name`)
) ENGINE=InnoDB;

-- Documents (Part 18)
CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL COMMENT 'Original file name',
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Size in bytes',
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `entity_type` ENUM('general','property','unit','tenant','lease') NOT NULL DEFAULT 'general',
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `tags` JSON DEFAULT NULL COMMENT 'Array of tag strings',
    `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
    `expiry_date` DATE DEFAULT NULL,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_uploaded_by` (`uploaded_by`),
    INDEX `idx_expiry` (`expiry_date`),
    CONSTRAINT `fk_doc_category` FOREIGN KEY (`category_id`) REFERENCES `document_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Default document categories
INSERT INTO `document_categories` (`name`, `description`, `icon`, `color`, `sort_order`, `is_system`) VALUES
('Lease Agreements', 'Signed lease contracts and amendments', 'bi-file-earmark-text', '#0d6efd', 1, 1),
('ID Documents', 'Tenant identification copies', 'bi-person-badge', '#6f42c1', 2, 1),
('Contracts', 'Vendor and service contracts', 'bi-file-earmark-ruled', '#198754', 3, 1),
('Financial Records', 'Invoices, statements, tax documents', 'bi-cash-stack', '#e8a838', 4, 1),
('Insurance', 'Insurance policies and claims', 'bi-shield-check', '#dc3545', 5, 1),
('Photos & Media', 'Property photos, videos, floor plans', 'bi-image', '#20c997', 6, 1),
('Receipts', 'Payment receipts and proofs', 'bi-receipt', '#fd7e14', 7, 1),
('Correspondence', 'Letters, notices, emails', 'bi-envelope', '#0dcaf0', 8, 1),
('Other', 'Miscellaneous documents', 'bi-folder', '#6c757d', 9, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- File Uploads (generic attachment tracking)
CREATE TABLE IF NOT EXISTS `file_uploads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_size` INT UNSIGNED DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Default admin user (password: admin123)
-- ============================================================
INSERT INTO `users` (`username`, `full_name`, `email`, `password_hash`, `role_id`, `status`) VALUES
('admin', 'Administrator', 'admin@example.com', '$2y$10$P9X6QJAG5ynjRGziLcvtKeahumRNvLNQm0zAGsArV9qQVjYGkKLJy', 1, 'active')
ON DUPLICATE KEY UPDATE `username` = `username`;
