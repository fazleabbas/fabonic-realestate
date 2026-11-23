-- ============================================================
-- FIS Real Estate — Part 11: Move-in/Move-out Inspections
-- Run against tenant DB to add default inspection template.
-- Tables already exist in tenant_schema.sql (inspections,
-- inspection_items, inspection_templates).
-- ============================================================
--
-- SUPERSEDED — DO NOT RUN. Kept for history only.
--
-- The built-in inspection templates now live in
-- includes/inspection_templates_seed.php, are baked into
-- sql/tenant_schema.sql for new tenants, and are installed into existing
-- tenants by api/seed-inspection-templates.php (which skips by name and is
-- safe to re-run).
--
-- Running THIS file again would duplicate rows: the ON DUPLICATE KEY UPDATE
-- below never fires, because inspection_templates has no unique index on
-- `name` — so each run inserts another copy.
-- ============================================================

-- Insert a default inspection template with common rooms/items
INSERT INTO `inspection_templates` (`name`, `rooms_json`, `is_default`) VALUES
('Standard Residential', '[
    {"room": "Living Room", "items": ["Walls", "Floor", "Ceiling", "Windows", "Doors", "Light Fixtures", "Electrical Outlets", "Curtain Rods"]},
    {"room": "Kitchen", "items": ["Walls", "Floor", "Ceiling", "Cabinets", "Countertop", "Sink", "Faucet", "Stove/Cooker", "Electrical Outlets", "Windows"]},
    {"room": "Bedroom 1", "items": ["Walls", "Floor", "Ceiling", "Windows", "Doors", "Light Fixtures", "Closet/Wardrobe", "Electrical Outlets"]},
    {"room": "Bedroom 2", "items": ["Walls", "Floor", "Ceiling", "Windows", "Doors", "Light Fixtures", "Closet/Wardrobe", "Electrical Outlets"]},
    {"room": "Bathroom", "items": ["Walls", "Floor", "Ceiling", "Toilet", "Sink", "Shower/Bathtub", "Faucets", "Mirror", "Towel Rack", "Ventilation"]},
    {"room": "Exterior/Entrance", "items": ["Main Door", "Door Lock", "Doorbell", "Mailbox", "Balcony/Patio", "Parking Space"]}
]', 1)
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Insert a commercial template
INSERT INTO `inspection_templates` (`name`, `rooms_json`, `is_default`) VALUES
('Standard Commercial', '[
    {"room": "Main Area", "items": ["Walls", "Floor", "Ceiling", "Windows", "Doors", "Light Fixtures", "Electrical Outlets", "AC Units"]},
    {"room": "Washroom", "items": ["Walls", "Floor", "Toilet", "Sink", "Faucets", "Mirror", "Ventilation"]},
    {"room": "Storage", "items": ["Walls", "Floor", "Door", "Shelving", "Light Fixtures"]},
    {"room": "Exterior/Entrance", "items": ["Main Door", "Door Lock", "Signage Area", "Parking Space", "Security Features"]}
]', 0)
ON DUPLICATE KEY UPDATE `name` = `name`;
