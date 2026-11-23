<h1><i class="bi bi-door-open me-2"></i><?php echo __('docs_units_guide', 'Units'); ?></h1>
<p><?php echo __('docs_units_intro', 'Units are the individual rentable spaces within a property — apartments, rooms, shops, offices, etc. Each unit has its own rent amount, status, and tenant assignment.'); ?></p>

<h2><?php echo __('docs_units_types', 'Unit Types'); ?></h2>
<table class="table">
    <thead><tr><th><?php echo __('type', 'Type'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td>Apartment</td><td><?php echo __('docs_unit_apartment', 'Self-contained apartment with kitchen and bathroom'); ?></td></tr>
        <tr><td>Room</td><td><?php echo __('docs_unit_room', 'Single room, may share facilities'); ?></td></tr>
        <tr><td>Shop</td><td><?php echo __('docs_unit_shop', 'Commercial retail space'); ?></td></tr>
        <tr><td>Office</td><td><?php echo __('docs_unit_office', 'Office space for businesses'); ?></td></tr>
        <tr><td>Warehouse</td><td><?php echo __('docs_unit_warehouse', 'Storage or warehouse space'); ?></td></tr>
        <tr><td>Plot</td><td><?php echo __('docs_unit_plot', 'Land plot for rent or lease'); ?></td></tr>
        <tr><td>House</td><td><?php echo __('docs_unit_house', 'Standalone house'); ?></td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_units_create', 'Creating a Unit'); ?></h2>
<ol>
    <li><?php echo __('docs_units_step1', 'Go to <strong>Units</strong> and click <strong>Add Unit</strong>'); ?></li>
    <li><?php echo __('docs_units_step2', 'Select the parent property'); ?></li>
    <li><?php echo __('docs_units_step3', 'Enter unit number, type, floor, size, bedrooms, bathrooms'); ?></li>
    <li><?php echo __('docs_units_step4', 'Set the rent amount, currency, and deposit amount'); ?></li>
    <li><?php echo __('docs_units_step5', 'Save the unit — it starts with <strong>Vacant</strong> status'); ?></li>
</ol>

<h2><?php echo __('docs_units_statuses', 'Unit Statuses'); ?></h2>
<ul>
    <li><span class="badge bg-success">Vacant</span> — <?php echo __('docs_unit_vacant', 'Available for rent'); ?></li>
    <li><span class="badge bg-primary">Occupied</span> — <?php echo __('docs_unit_occupied', 'Currently rented to a tenant'); ?></li>
    <li><span class="badge bg-warning text-dark">Maintenance</span> — <?php echo __('docs_unit_maintenance', 'Under repair, not available'); ?></li>
    <li><span class="badge bg-info text-dark">Reserved</span> — <?php echo __('docs_unit_reserved', 'Reserved for an incoming tenant'); ?></li>
</ul>

<h2><?php echo __('docs_units_photos', 'Unit Photos & Floor Plans'); ?></h2>
<p><?php echo __('docs_units_photos_desc', 'Upload photos of each unit including floor plans. Drag and drop multiple files to upload. Photos appear in the unit detail view and vacancy listings.'); ?></p>

<h2><?php echo __('docs_units_filters', 'Filtering & Bulk Actions'); ?></h2>
<p><?php echo __('docs_units_filters_desc', 'Filter units by property, type, status, or rent range. Select multiple units to change status in bulk or export data as CSV.'); ?></p>
