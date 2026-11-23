<h1><i class="bi bi-braces me-2"></i><?php echo __('docs_api_tenants', 'Tenants API'); ?></h1>
<p><?php echo __('docs_apit_intro', 'API reference for the Tenants endpoint.'); ?></p>
<p><strong><?php echo __('docs_api_endpoint', 'Endpoint'); ?>:</strong> <code>/api/tenants.php</code></p>

<h2><?php echo __('docs_apit_list', 'List Tenants'); ?></h2>
<pre><code>GET /api/tenants.php?action=list&page=1&status=active&search=john</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('type', 'Type'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>action</code></td><td>string</td><td><code>list</code> (required)</td></tr>
        <tr><td><code>page</code></td><td>int</td><td>Page number</td></tr>
        <tr><td><code>status</code></td><td>string</td><td><code>active</code> | <code>notice_given</code> | <code>moved_out</code></td></tr>
        <tr><td><code>property_id</code></td><td>int</td><td>Filter by property</td></tr>
        <tr><td><code>search</code></td><td>string</td><td>Search by name, phone, or email</td></tr>
    </tbody>
</table>

<h3><?php echo __('docs_api_response', 'Response'); ?></h3>
<pre><code>{
    "success": true,
    "data": [
        {
            "id": 1,
            "first_name": "John",
            "last_name": "Doe",
            "phone": "+255712345678",
            "email": "john@example.com",
            "id_type": "national_id",
            "status": "active",
            "unit_number": "A-101",
            "property_name": "Sunset Apartments"
        }
    ],
    "total": 35,
    "page": 1
}</code></pre>

<h2><?php echo __('docs_apit_get', 'Get Tenant'); ?></h2>
<pre><code>GET /api/tenants.php?action=get&id=1</code></pre>
<p><?php echo __('docs_apit_get_desc', 'Returns full tenant profile including employment, references, emergency contacts, and portal status.'); ?></p>

<h2><?php echo __('docs_apit_create', 'Create Tenant'); ?></h2>
<pre><code>POST /api/tenants.php
action=create&first_name=John&last_name=Doe&phone=+255712345678&email=john@example.com&id_type=national_id&id_number=12345678</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('required', 'Required'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>first_name</code></td><td>Yes</td><td>Tenant first name</td></tr>
        <tr><td><code>last_name</code></td><td>Yes</td><td>Tenant last name</td></tr>
        <tr><td><code>phone</code></td><td>Yes</td><td>Phone number</td></tr>
        <tr><td><code>email</code></td><td>No</td><td>Email address</td></tr>
        <tr><td><code>id_type</code></td><td>No</td><td>national_id | passport | driving_license | voter_id</td></tr>
        <tr><td><code>id_number</code></td><td>No</td><td>ID document number</td></tr>
        <tr><td><code>occupation</code></td><td>No</td><td>Tenant occupation</td></tr>
        <tr><td><code>employer</code></td><td>No</td><td>Employer name</td></tr>
        <tr><td><code>screening_notes</code></td><td>No</td><td>Background notes</td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_apit_update', 'Update Tenant'); ?></h2>
<pre><code>POST /api/tenants.php
action=update&id=1&phone=+255712345679&status=notice_given</code></pre>

<h2><?php echo __('docs_apit_history', 'Tenant History'); ?></h2>
<pre><code>GET /api/tenants.php?action=history&id=1</code></pre>
<p><?php echo __('docs_apit_history_desc', 'Returns all leases, payments, and inspections associated with this tenant.'); ?></p>
