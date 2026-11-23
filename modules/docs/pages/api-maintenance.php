<h1><i class="bi bi-braces me-2"></i><?php echo __('docs_api_maintenance', 'Maintenance API'); ?></h1>
<p><span class="tier-badge tier-enterprise">Enterprise</span></p>
<p><?php echo __('docs_apim_intro', 'API reference for the Maintenance endpoint.'); ?></p>
<p><strong><?php echo __('docs_api_endpoint', 'Endpoint'); ?>:</strong> <code>/api/maintenance.php</code></p>

<h2><?php echo __('docs_apim_list', 'List Requests'); ?></h2>
<pre><code>GET /api/maintenance.php?action=list&page=1&status=reported&priority=high</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('type', 'Type'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>action</code></td><td>string</td><td><code>list</code> (required)</td></tr>
        <tr><td><code>status</code></td><td>string</td><td>reported | assigned | in_progress | completed | closed</td></tr>
        <tr><td><code>priority</code></td><td>string</td><td>low | medium | high | urgent</td></tr>
        <tr><td><code>property_id</code></td><td>int</td><td>Filter by property</td></tr>
        <tr><td><code>category_id</code></td><td>int</td><td>Filter by category</td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_apim_create', 'Create Request'); ?></h2>
<pre><code>POST /api/maintenance.php
action=create&unit_id=1&category_id=2&priority=high&title=Broken+pipe&description=Water+leaking+in+bathroom</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('required', 'Required'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>unit_id</code></td><td>Yes</td><td>Unit where issue is located</td></tr>
        <tr><td><code>category_id</code></td><td>Yes</td><td>Maintenance category ID</td></tr>
        <tr><td><code>priority</code></td><td>Yes</td><td>low | medium | high | urgent</td></tr>
        <tr><td><code>title</code></td><td>Yes</td><td>Short title for the request</td></tr>
        <tr><td><code>description</code></td><td>Yes</td><td>Detailed description</td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_apim_assign', 'Assign Contractor'); ?></h2>
<pre><code>POST /api/maintenance.php
action=assign&request_id=1&contractor_id=3&estimated_cost=150000&estimated_completion=2025-04-15</code></pre>

<h2><?php echo __('docs_apim_status', 'Update Status'); ?></h2>
<pre><code>POST /api/maintenance.php
action=update_status&id=1&status=completed&actual_cost=120000&notes=Pipe+replaced</code></pre>

<h2><?php echo __('docs_apim_contractors', 'List Contractors'); ?></h2>
<pre><code>GET /api/maintenance.php?action=contractors</code></pre>
<p><?php echo __('docs_apim_contractors_desc', 'Returns all registered contractors with their specialties and contact information.'); ?></p>

<h2><?php echo __('docs_apim_reports', 'Maintenance Reports'); ?></h2>
<pre><code>GET /api/maintenance.php?action=reports&type=cost_summary&date_from=2025-01-01&date_to=2025-12-31</code></pre>
<p><?php echo __('docs_apim_reports_desc', 'Available report types: <code>cost_summary</code>, <code>by_category</code>, <code>by_contractor</code>, <code>response_time</code>.'); ?></p>
