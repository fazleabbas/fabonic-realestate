<h1><i class="bi bi-braces me-2"></i><?php echo __('docs_api_properties', 'Properties API'); ?></h1>
<p><?php echo __('docs_apip_intro', 'API reference for the Properties endpoint.'); ?></p>
<p><strong><?php echo __('docs_api_endpoint', 'Endpoint'); ?>:</strong> <code>/api/properties.php</code></p>

<h2><?php echo __('docs_apip_list', 'List Properties'); ?></h2>
<pre><code>GET /api/properties.php?action=list&page=1&status=active&type=residential&search=downtown</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('type', 'Type'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>action</code></td><td>string</td><td><code>list</code> (required)</td></tr>
        <tr><td><code>page</code></td><td>int</td><td>Page number (default: 1)</td></tr>
        <tr><td><code>status</code></td><td>string</td><td><code>active</code> | <code>inactive</code></td></tr>
        <tr><td><code>type</code></td><td>string</td><td><code>residential</code> | <code>commercial</code> | <code>land</code> | <code>mixed</code></td></tr>
        <tr><td><code>search</code></td><td>string</td><td>Search by name, address, or city</td></tr>
    </tbody>
</table>
<h3><?php echo __('docs_api_response', 'Response'); ?></h3>
<pre><code>{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Sunset Apartments",
            "property_type": "residential",
            "address": "123 Main Street",
            "city": "Dar es Salaam",
            "total_units": 12,
            "status": "active",
            "created_at": "2025-01-15"
        }
    ],
    "total": 5,
    "page": 1,
    "per_page": 20
}</code></pre>

<h2><?php echo __('docs_apip_get', 'Get Property'); ?></h2>
<pre><code>GET /api/properties.php?action=get&id=1</code></pre>
<p><?php echo __('docs_apip_get_desc', 'Returns the full property record including settings and photo count.'); ?></p>

<h2><?php echo __('docs_apip_create', 'Create Property'); ?></h2>
<pre><code>POST /api/properties.php
action=create&name=Sunset+Apartments&property_type=residential&address=123+Main+St&city=Dar+es+Salaam</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('required', 'Required'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>name</code></td><td>Yes</td><td>Property name</td></tr>
        <tr><td><code>property_type</code></td><td>Yes</td><td>residential | commercial | land | mixed</td></tr>
        <tr><td><code>address</code></td><td>No</td><td>Street address</td></tr>
        <tr><td><code>city</code></td><td>No</td><td>City name</td></tr>
        <tr><td><code>region</code></td><td>No</td><td>Region/state</td></tr>
        <tr><td><code>description</code></td><td>No</td><td>Property description</td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_apip_update', 'Update Property'); ?></h2>
<pre><code>POST /api/properties.php
action=update&id=1&name=New+Name&city=Arusha</code></pre>

<h2><?php echo __('docs_apip_delete', 'Delete Property'); ?></h2>
<pre><code>POST /api/properties.php
action=delete&id=1</code></pre>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <?php echo __('docs_apip_delete_warn', 'Deleting a property will also remove all associated units, leases, and payments. This action cannot be undone.'); ?>
</div>

<h2><?php echo __('docs_apip_stats', 'Property Stats'); ?></h2>
<pre><code>GET /api/properties.php?action=stats&id=1</code></pre>
<p><?php echo __('docs_apip_stats_desc', 'Returns occupancy rate, revenue, overdue amounts, and unit count for a specific property.'); ?></p>
