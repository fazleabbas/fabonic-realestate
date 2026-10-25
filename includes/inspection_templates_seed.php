<?php
/**
 * Fabonic Real Estate — Inspection Template Seed Data
 * ------------------------------------------------------------
 * Canonical source for the built-in inspection checklists every tenant starts
 * with. Used by:
 *   - api/seed-inspection-templates.php  (seeds existing tenants; also emits SQL)
 *   - sql/tenant_schema.sql              (INSERT block generated via --emit-sql)
 *
 * Shape matches what modules/inspections/templates.php writes:
 *   rooms_json = [ {"room": "<name>", "items": ["<item>", ...]}, ... ]
 * On inspection creation each item becomes one inspection_items row, rated
 * excellent / good / fair / poor / damaged / missing.
 *
 * LIMITS (enforced by the schema — the verifier checks these):
 *   inspection_templates.name  VARCHAR(100)
 *   inspection_items.room      VARCHAR(100)
 *   inspection_items.item_name VARCHAR(100)
 *
 * NOTE: 'Standard Residential' and 'Standard Commercial' keep the names used by
 * the older sql/part11_inspections.sql so they can never collide — a tenant that
 * already has those rows keeps its own, and only the missing templates are added.
 */

/** Items common to essentially every enclosed room. */
function itBaseRoom(): array
{
    return ['Walls', 'Floor', 'Ceiling', 'Doors', 'Door Locks', 'Windows', 'Window Locks',
            'Light Fixtures', 'Light Switches', 'Electrical Outlets'];
}

/** Standard wet-room items. */
function itBathroom(): array
{
    return ['Walls', 'Floor', 'Ceiling', 'Door', 'Toilet', 'Cistern / Flush', 'Wash Basin',
            'Taps / Faucets', 'Shower', 'Shower Head', 'Bathtub', 'Mirror', 'Towel Rail',
            'Drainage', 'Water Pressure', 'Ventilation / Extractor', 'Tiles & Grouting',
            'Visible Leaks'];
}

/** Standard kitchen items. */
function itKitchen(): array
{
    return ['Walls', 'Floor', 'Ceiling', 'Windows', 'Cabinets', 'Drawers', 'Countertop',
            'Sink', 'Taps / Faucets', 'Drainage', 'Cooker / Stove', 'Oven', 'Extractor Hood',
            'Refrigerator Space', 'Tiles / Splashback', 'Electrical Outlets', 'Light Fixtures',
            'Gas Connection', 'Visible Leaks'];
}

/**
 * The built-in inspection templates.
 *
 * @return array<int, array{name:string, is_default:int, rooms:array}>
 */
function inspectionTemplateSeedData(): array
{
    $templates = [];

    // ------------------------------------------------------------------
    // 1. Standard Residential (default)
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Standard Residential',
        'is_default' => 1,
        'rooms' => [
            ['room' => 'Entrance / Hallway', 'items' => [
                'Main Door', 'Door Lock & Keys', 'Doorbell', 'Walls', 'Floor', 'Ceiling',
                'Light Fixtures', 'Electrical Outlets', 'Intercom',
            ]],
            ['room' => 'Living Room', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Curtain Rods', 'Skirting Boards', 'TV Point',
                'Fan / Air Conditioner',
            ])],
            ['room' => 'Dining Area', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Skirting Boards',
            ])],
            ['room' => 'Kitchen', 'items' => itKitchen()],
            ['room' => 'Bedroom 1 (Master)', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Wardrobe Doors', 'Curtains / Blinds', 'Curtain Rods',
                'Fan / Air Conditioner',
            ])],
            ['room' => 'Bedroom 2', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Curtains / Blinds', 'Curtain Rods',
            ])],
            ['room' => 'Bedroom 3', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Curtains / Blinds', 'Curtain Rods',
            ])],
            ['room' => 'Bathroom / Toilet', 'items' => itBathroom()],
            ['room' => 'Laundry / Utility Area', 'items' => [
                'Walls', 'Floor', 'Washing Machine Point', 'Water Inlet', 'Drainage',
                'Drying Line', 'Ventilation',
            ]],
            ['room' => 'Balcony / Patio', 'items' => [
                'Floor', 'Railing', 'Door', 'Drainage', 'Light Fixture', 'Ceiling / Cover',
            ]],
            ['room' => 'Utilities & Services', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Distribution Board / Fuses',
                'Water Tank', 'Water Pump', 'Water Heater / Geyser', 'Gas Cylinder',
                'Smoke Detector', 'Fire Extinguisher',
            ]],
            ['room' => 'Exterior & Access', 'items' => [
                'External Walls', 'Roof / Gutters', 'Perimeter Fence', 'Gate', 'Parking Space',
                'Garden / Landscaping', 'Refuse Area', 'Security Lighting', 'Mailbox',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Remote / Access Cards', 'Manuals & Warranties',
                'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 2. Standard Commercial
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Standard Commercial',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Entrance & Frontage', 'items' => [
                'Main Door', 'Door Lock & Keys', 'Glazing / Shopfront', 'Shutter / Grille',
                'Signage Area', 'Ramp / Steps', 'External Lighting',
            ]],
            ['room' => 'Main Area', 'items' => array_merge(itBaseRoom(), [
                'Air Conditioning Units', 'Data / Network Points', 'Fire Exit',
                'Emergency Lighting', 'Suspended Ceiling Tiles',
            ])],
            ['room' => 'Back Office', 'items' => array_merge(itBaseRoom(), [
                'Air Conditioning', 'Data / Network Points',
            ])],
            ['room' => 'Storage / Stock Room', 'items' => [
                'Walls', 'Floor', 'Ceiling', 'Door & Lock', 'Shelving', 'Light Fixtures',
                'Ventilation',
            ]],
            ['room' => 'Washroom', 'items' => itBathroom()],
            ['room' => 'Kitchenette / Pantry', 'items' => [
                'Walls', 'Floor', 'Sink', 'Taps / Faucets', 'Drainage', 'Cabinets',
                'Countertop', 'Electrical Outlets',
            ]],
            ['room' => 'Utilities & Safety', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Distribution Board / Fuses',
                'Generator / Backup Supply', 'Fire Extinguishers', 'Fire Alarm',
                'Emergency Exit Signage', 'First Aid Kit', 'Security Alarm', 'CCTV',
            ]],
            ['room' => 'Exterior & Access', 'items' => [
                'External Walls', 'Roof / Gutters', 'Parking Space', 'Loading Area',
                'Refuse Area', 'Perimeter Security',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Remote / Access Cards', 'Statutory Certificates',
                'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 3. Single Room / Bedsitter
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Single Room / Bedsitter',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Room', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Curtain Rods', 'Wardrobe / Closet', 'Bed Frame',
                'Skirting Boards', 'Fan',
            ])],
            ['room' => 'Cooking Area', 'items' => [
                'Walls', 'Floor', 'Countertop', 'Sink', 'Taps / Faucets', 'Drainage',
                'Cooker Point', 'Gas Connection', 'Electrical Outlets', 'Ventilation',
            ]],
            ['room' => 'Bathroom / Toilet', 'items' => itBathroom()],
            ['room' => 'Shared Facilities', 'items' => [
                'Corridor', 'Staircase', 'Shared Toilet', 'Shared Bathroom', 'Water Point',
                'Drying Line', 'Refuse Area', 'Common Lighting', 'Courtyard',
            ]],
            ['room' => 'Utilities', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Sub-Meter',
                'Water Supply Pressure', 'Water Storage',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 4. Studio / Self-Contained Flat
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Studio / Self-Contained Flat',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Entrance', 'items' => [
                'Main Door', 'Door Lock & Keys', 'Intercom', 'Light Fixture',
            ]],
            ['room' => 'Living / Sleeping Area', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Curtain Rods', 'Wardrobe / Closet', 'Skirting Boards',
                'Fan / Air Conditioner', 'TV Point',
            ])],
            ['room' => 'Kitchenette', 'items' => itKitchen()],
            ['room' => 'Bathroom', 'items' => itBathroom()],
            ['room' => 'Balcony', 'items' => [
                'Floor', 'Railing', 'Door', 'Drainage', 'Light Fixture',
            ]],
            ['room' => 'Utilities', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Distribution Board / Fuses',
                'Water Heater / Geyser', 'Water Tank',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Access Cards', 'Cleanliness on Handover',
                'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 5. Standalone House / Villa
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Standalone House / Villa',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Entrance / Porch', 'items' => [
                'Main Door', 'Door Lock & Keys', 'Doorbell', 'Porch Floor', 'Porch Ceiling',
                'Light Fixtures', 'Security Screen',
            ]],
            ['room' => 'Living Room', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Curtain Rods', 'Skirting Boards', 'Fireplace',
                'Fan / Air Conditioner', 'TV Point',
            ])],
            ['room' => 'Dining Room', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Skirting Boards',
            ])],
            ['room' => 'Kitchen', 'items' => itKitchen()],
            ['room' => 'Pantry / Store', 'items' => [
                'Walls', 'Floor', 'Shelving', 'Door', 'Light Fixture', 'Ventilation',
            ]],
            ['room' => 'Master Bedroom', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Curtains / Blinds', 'Curtain Rods',
                'Fan / Air Conditioner', 'En-suite Access',
            ])],
            ['room' => 'Bedroom 2', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Curtains / Blinds',
            ])],
            ['room' => 'Bedroom 3', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Curtains / Blinds',
            ])],
            ['room' => 'Bedroom 4', 'items' => array_merge(itBaseRoom(), [
                'Wardrobe / Closet', 'Curtains / Blinds',
            ])],
            ['room' => 'Master Bathroom', 'items' => itBathroom()],
            ['room' => 'Guest Bathroom', 'items' => itBathroom()],
            ['room' => 'Laundry / Utility Room', 'items' => [
                'Walls', 'Floor', 'Washing Machine Point', 'Water Inlet', 'Drainage',
                'Drying Line', 'Ventilation', 'Ironing Area',
            ]],
            ['room' => 'Staircase & Landing', 'items' => [
                'Treads', 'Handrail', 'Balustrade', 'Walls', 'Lighting', 'Landing Floor',
            ]],
            ['room' => 'Garage / Carport', 'items' => [
                'Floor', 'Walls', 'Roof', 'Garage Door', 'Door Opener', 'Light Fixtures',
                'Electrical Outlets', 'Drainage',
            ]],
            ['room' => 'Servant Quarters / Outbuilding', 'items' => [
                'Walls', 'Floor', 'Roof', 'Door & Lock', 'Windows', 'Toilet', 'Shower',
                'Electricity', 'Water Supply',
            ]],
            ['room' => 'Garden & Grounds', 'items' => [
                'Lawn', 'Hedges / Shrubs', 'Trees', 'Paving / Driveway', 'Irrigation / Taps',
                'Outdoor Lighting', 'Swimming Pool', 'Pool Pump', 'Borehole / Well',
                'Septic Tank / Soakaway',
            ]],
            ['room' => 'Utilities & Services', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Distribution Board / Fuses',
                'Water Tank', 'Water Pump', 'Water Heater / Geyser', 'Solar Panels',
                'Generator', 'Gas Installation', 'Smoke Detectors', 'Fire Extinguisher',
            ]],
            ['room' => 'Exterior & Security', 'items' => [
                'External Walls', 'Paintwork', 'Roof / Gutters', 'Downpipes', 'Perimeter Wall',
                'Gate', 'Gate Motor', 'Security Lighting', 'CCTV', 'Alarm System',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Gate Remotes', 'Manuals & Warranties',
                'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 6. Furnished Unit — Inventory Check
    // ------------------------------------------------------------------
    $templates[] = [
        // ASCII-only name on purpose: the name is the seeder's match key, and a
        // non-ASCII character can survive a charset-mangling import as different
        // bytes, which would defeat the duplicate check.
        'name' => 'Furnished Unit - Inventory Check',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Living Room Furniture', 'items' => [
                'Sofa / Settee', 'Armchairs', 'Coffee Table', 'Side Tables', 'TV Unit',
                'Television', 'Remote Control', 'Bookshelf', 'Rug / Carpet', 'Wall Art',
                'Curtains', 'Lamps', 'Cushions',
            ]],
            ['room' => 'Dining Furniture', 'items' => [
                'Dining Table', 'Dining Chairs', 'Sideboard / Buffet', 'Table Mats',
            ]],
            ['room' => 'Kitchen Appliances', 'items' => [
                'Refrigerator', 'Freezer', 'Cooker / Stove', 'Oven', 'Microwave',
                'Extractor Hood', 'Kettle', 'Toaster', 'Blender', 'Water Dispenser',
                'Dishwasher',
            ]],
            ['room' => 'Kitchenware', 'items' => [
                'Plates', 'Bowls', 'Glasses', 'Mugs', 'Cutlery Set', 'Pots & Pans',
                'Cooking Utensils', 'Chopping Board', 'Storage Containers', 'Bin',
            ]],
            ['room' => 'Bedroom Furniture', 'items' => [
                'Bed Frame', 'Mattress', 'Mattress Protector', 'Bedside Tables', 'Wardrobe',
                'Chest of Drawers', 'Dressing Mirror', 'Bedside Lamps', 'Curtains',
            ]],
            ['room' => 'Linen & Bedding', 'items' => [
                'Bed Sheets', 'Duvet / Blanket', 'Duvet Cover', 'Pillows', 'Pillow Cases',
                'Bath Towels', 'Hand Towels', 'Bath Mat',
            ]],
            ['room' => 'Appliances & Electronics', 'items' => [
                'Air Conditioner', 'Ceiling Fan', 'Standing Fan', 'Washing Machine',
                'Iron', 'Ironing Board', 'Water Heater', 'Wi-Fi Router', 'Extension Cords',
            ]],
            ['room' => 'Bathroom Fittings', 'items' => [
                'Shower Curtain', 'Toilet Brush', 'Waste Bin', 'Mirror Cabinet',
                'Soap Dispenser',
            ]],
            ['room' => 'Outdoor Furniture', 'items' => [
                'Patio Table', 'Patio Chairs', 'Sun Loungers', 'Parasol', 'Drying Rack',
            ]],
            ['room' => 'Inventory Handover', 'items' => [
                'Inventory List Signed', 'Keys Issued (count)', 'Damages Noted',
                'Missing Items Noted', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 7. Shop / Retail Unit
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Shop / Retail Unit',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Shopfront', 'items' => [
                'Display Window', 'Glazing Condition', 'Entrance Door', 'Door Lock & Keys',
                'Roller Shutter', 'Shutter Motor', 'Signage Board', 'Awning', 'Step / Ramp',
                'External Lighting',
            ]],
            ['room' => 'Sales Floor', 'items' => array_merge(itBaseRoom(), [
                'Floor Covering', 'Display Shelving', 'Counter', 'Air Conditioning',
                'Suspended Ceiling Tiles', 'Emergency Lighting', 'Fire Exit',
            ])],
            ['room' => 'Fitting Room', 'items' => [
                'Walls', 'Floor', 'Mirror', 'Curtain / Door', 'Hooks', 'Lighting',
            ]],
            ['room' => 'Stock Room', 'items' => [
                'Walls', 'Floor', 'Ceiling', 'Door & Lock', 'Shelving', 'Lighting',
                'Ventilation', 'Pest Evidence',
            ]],
            ['room' => 'Staff Washroom', 'items' => itBathroom()],
            ['room' => 'Utilities & Safety', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Distribution Board / Fuses',
                'Fire Extinguishers', 'Fire Alarm', 'Emergency Exit Signage', 'Security Alarm',
                'CCTV', 'Roller Shutter Lock',
            ]],
            ['room' => 'Rear Access & Refuse', 'items' => [
                'Rear Door', 'Door Lock', 'Delivery Access', 'Refuse Storage', 'Drainage',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Shutter Keys', 'Alarm Codes Handed Over',
                'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 8. Office Space
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Office Space',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Reception', 'items' => array_merge(itBaseRoom(), [
                'Reception Counter', 'Waiting Seating', 'Air Conditioning', 'Intercom',
                'Signage',
            ])],
            ['room' => 'Open Plan Area', 'items' => array_merge(itBaseRoom(), [
                'Partitioning', 'Floor Covering / Carpet', 'Data / Network Points',
                'Air Conditioning', 'Suspended Ceiling Tiles', 'Blinds',
            ])],
            ['room' => 'Private Offices', 'items' => array_merge(itBaseRoom(), [
                'Partitioning', 'Blinds', 'Data / Network Points', 'Air Conditioning',
            ])],
            ['room' => 'Meeting Room', 'items' => array_merge(itBaseRoom(), [
                'Projector / Screen', 'Whiteboard', 'Data / Network Points',
                'Air Conditioning', 'Blinds',
            ])],
            ['room' => 'Server / Comms Room', 'items' => [
                'Walls', 'Floor', 'Door & Lock', 'Server Rack', 'Cabling', 'Cooling Unit',
                'UPS', 'Power Supply',
            ]],
            ['room' => 'Kitchenette / Pantry', 'items' => [
                'Walls', 'Floor', 'Cabinets', 'Countertop', 'Sink', 'Taps / Faucets',
                'Drainage', 'Refrigerator', 'Kettle', 'Microwave', 'Electrical Outlets',
            ]],
            ['room' => 'Washrooms', 'items' => itBathroom()],
            ['room' => 'Utilities & Safety', 'items' => [
                'Electricity Meter Reading', 'Water Meter Reading', 'Distribution Board / Fuses',
                'Generator / Backup Supply', 'Fire Extinguishers', 'Fire Alarm',
                'Smoke Detectors', 'Emergency Lighting', 'Emergency Exit Signage',
                'First Aid Kit', 'Access Control System', 'CCTV',
            ]],
            ['room' => 'Common Areas & Access', 'items' => [
                'Corridor', 'Staircase', 'Lift / Elevator', 'Parking Bays', 'Loading Bay',
                'Refuse Area',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Access Cards', 'Alarm Codes Handed Over',
                'Parking Bay Numbers', 'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 9. Warehouse / Godown
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Warehouse / Godown',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Structure', 'items' => [
                'External Walls', 'Internal Walls', 'Roof Sheets', 'Roof Leaks',
                'Roof Trusses', 'Gutters & Downpipes', 'Columns / Pillars', 'Skylights',
            ]],
            ['room' => 'Floor & Loading', 'items' => [
                'Floor Slab Condition', 'Floor Cracks', 'Floor Levelness', 'Loading Bay',
                'Dock Leveller', 'Ramp', 'Bollards', 'Line Markings', 'Gangway Clearance',
            ]],
            ['room' => 'Doors & Openings', 'items' => [
                'Roller Shutter Door', 'Shutter Motor', 'Personnel Door', 'Door Locks',
                'Emergency Exit Door', 'Windows / Louvres',
            ]],
            ['room' => 'Storage Area', 'items' => [
                'Racking / Shelving', 'Racking Fixings', 'Stacking Height Markings',
                'Pallet Condition', 'Pest Evidence', 'Damp / Water Ingress',
            ]],
            ['room' => 'Office / Admin Area', 'items' => array_merge(itBaseRoom(), [
                'Air Conditioning', 'Data / Network Points',
            ])],
            ['room' => 'Washroom & Welfare', 'items' => itBathroom()],
            ['room' => 'Electrical & Lighting', 'items' => [
                'Electricity Meter Reading', 'Distribution Board / Fuses', 'High Bay Lighting',
                'Emergency Lighting', 'Power Sockets', 'Three-Phase Supply', 'Earthing',
            ]],
            ['room' => 'Fire & Safety', 'items' => [
                'Fire Extinguishers', 'Fire Hose Reel', 'Sprinkler System', 'Fire Alarm',
                'Smoke Detectors', 'Emergency Exit Signage', 'Assembly Point Signage',
                'First Aid Kit', 'Hazardous Goods Signage',
            ]],
            ['room' => 'Yard & Security', 'items' => [
                'Yard Surface', 'Drainage', 'Perimeter Fence', 'Main Gate', 'Guard House',
                'Security Lighting', 'CCTV', 'Vehicle Turning Area', 'Weighbridge',
            ]],
            ['room' => 'Utilities', 'items' => [
                'Water Meter Reading', 'Water Supply', 'Water Tank', 'Septic / Sewer',
                'Generator', 'Compressed Air Line',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Shutter Keys', 'Gate Remotes',
                'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 10. Hostel Room / Student Accommodation
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Hostel Room / Student Accommodation',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Bed Space', 'items' => [
                'Bed Frame', 'Mattress', 'Mattress Condition', 'Pillow', 'Bedside Locker',
                'Study Desk', 'Study Chair', 'Reading Lamp', 'Wardrobe / Locker',
                'Locker Key', 'Notice Board',
            ]],
            ['room' => 'Room', 'items' => array_merge(itBaseRoom(), [
                'Curtains / Blinds', 'Fan', 'Skirting Boards', 'Room Number Plate',
            ])],
            ['room' => 'Shared Bathroom', 'items' => itBathroom()],
            ['room' => 'Shared Kitchen', 'items' => [
                'Walls', 'Floor', 'Cooker / Stove', 'Sink', 'Taps / Faucets', 'Drainage',
                'Countertop', 'Cabinets', 'Refrigerator', 'Ventilation', 'Waste Bin',
            ]],
            ['room' => 'Common Areas', 'items' => [
                'Corridor', 'Staircase', 'Handrail', 'Study / Common Room', 'Laundry Area',
                'Drying Line', 'Notice Board', 'Common Lighting', 'Refuse Area',
            ]],
            ['room' => 'Safety & Security', 'items' => [
                'Room Door Lock', 'Window Grilles', 'Fire Extinguisher', 'Smoke Detector',
                'Emergency Exit', 'Emergency Exit Signage', 'Assembly Point Signage',
                'First Aid Kit', 'Security Lighting', 'Warden Contact Displayed',
            ]],
            ['room' => 'Utilities', 'items' => [
                'Electricity Supply', 'Water Supply', 'Water Storage', 'Water Heater',
                'Internet / Wi-Fi',
            ]],
            ['room' => 'Handover', 'items' => [
                'Keys Issued (count)', 'Locker Key Issued', 'House Rules Signed',
                'Cleanliness on Handover', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 11. Plot / Land
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Plot / Land',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Boundaries', 'items' => [
                'Boundary Beacons Present', 'Beacon Condition', 'Fence Line', 'Fence Condition',
                'Gate', 'Gate Lock', 'Encroachment Evidence', 'Survey Plan Matches Site',
            ]],
            ['room' => 'Ground Condition', 'items' => [
                'Terrain / Slope', 'Soil Condition', 'Erosion Evidence', 'Waterlogging',
                'Rock Outcrops', 'Existing Excavations', 'Dumped Waste', 'Overgrowth',
            ]],
            ['room' => 'Vegetation', 'items' => [
                'Trees Present', 'Protected Trees', 'Grass / Bush Cover', 'Crops Planted',
            ]],
            ['room' => 'Existing Structures', 'items' => [
                'Temporary Structures', 'Permanent Structures', 'Foundations', 'Storage Shed',
                'Guard Post', 'Unauthorised Occupation',
            ]],
            ['room' => 'Access & Services', 'items' => [
                'Road Access', 'Access Road Condition', 'Electricity Line Nearby',
                'Water Connection', 'Borehole / Well', 'Drainage Channel', 'Sewer Line',
            ]],
            ['room' => 'Documentation', 'items' => [
                'Title / Ownership Document Sighted', 'Survey Plan Sighted',
                'Land Rent Receipts', 'Site Photographs Taken', 'GPS Coordinates Recorded',
            ]],
            ['room' => 'Handover', 'items' => [
                'Gate Keys Issued', 'Boundary Walk Completed', 'Overall Condition',
            ]],
        ],
    ];

    // ------------------------------------------------------------------
    // 12. Building Common Areas & Exterior
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Building Common Areas & Exterior',
        'is_default' => 0,
        'rooms' => [
            ['room' => 'Entrance & Lobby', 'items' => [
                'Main Entrance Door', 'Door Closer', 'Intercom Panel', 'Lobby Floor',
                'Lobby Walls', 'Lobby Ceiling', 'Lighting', 'Notice Board', 'Mailboxes',
                'Reception Desk',
            ]],
            ['room' => 'Corridors & Staircases', 'items' => [
                'Corridor Floor', 'Corridor Walls', 'Corridor Lighting', 'Stair Treads',
                'Handrails', 'Balustrades', 'Fire Doors', 'Emergency Lighting',
                'Exit Signage',
            ]],
            ['room' => 'Lifts', 'items' => [
                'Lift Car Condition', 'Lift Doors', 'Control Panel', 'Emergency Phone',
                'Inspection Certificate', 'Lift Lighting',
            ]],
            ['room' => 'Roof & Water Storage', 'items' => [
                'Roof Surface', 'Roof Leaks', 'Gutters', 'Downpipes', 'Water Tanks',
                'Tank Covers', 'Tank Cleanliness', 'Booster Pump', 'Lightning Arrestor',
                'Roof Access Door',
            ]],
            ['room' => 'Plant & Services', 'items' => [
                'Main Distribution Board', 'Sub-Meters', 'Generator', 'Generator Fuel Level',
                'Water Pump', 'Sump / Underground Tank', 'Solar Installation',
                'Sewer / Septic System', 'Grease Trap',
            ]],
            ['room' => 'Fire Safety', 'items' => [
                'Fire Extinguishers', 'Extinguisher Service Date', 'Fire Hose Reels',
                'Fire Alarm Panel', 'Smoke Detectors', 'Sprinklers', 'Emergency Exits',
                'Assembly Point Signage', 'Fire Escape Route Clear',
            ]],
            ['room' => 'Parking & Grounds', 'items' => [
                'Parking Surface', 'Bay Markings', 'Parking Lighting', 'Driveway',
                'Landscaping', 'Paving', 'Storm Drainage', 'Refuse Bin Area',
                'Refuse Collection',
            ]],
            ['room' => 'Security', 'items' => [
                'Perimeter Wall / Fence', 'Main Gate', 'Gate Motor', 'Guard House',
                'CCTV Cameras', 'CCTV Recording', 'Security Lighting', 'Access Control',
                'Visitor Log',
            ]],
            ['room' => 'External Fabric', 'items' => [
                'External Walls', 'Paintwork', 'Cracks', 'Damp Patches', 'Windows',
                'Balcony Railings', 'Signage', 'Boundary Wall Condition',
            ]],
        ],
    ];

    return $templates;
}
