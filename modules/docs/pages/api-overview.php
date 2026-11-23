<h1><i class="bi bi-code-slash me-2"></i><?php echo __('docs_api_overview', 'API Overview'); ?></h1>
<p><?php echo __('docs_api_intro', 'FIS Real Estate provides internal API endpoints for data operations. All endpoints use PHP session-based authentication and return JSON responses.'); ?></p>

<h2><?php echo __('docs_api_base', 'Base URL'); ?></h2>
<pre><code>https://your-tenant-url.fis.co.tz/api/</code></pre>

<h2><?php echo __('docs_api_auth', 'Authentication'); ?></h2>
<p><?php echo __('docs_api_auth_desc', 'API endpoints require an active PHP session. All requests must include:'); ?></p>
<ul>
    <li><?php echo __('docs_api_auth1', 'Valid session cookie (auto-managed by the browser)'); ?></li>
    <li><?php echo __('docs_api_auth2', 'CSRF token for POST requests (included via hidden form field or header)'); ?></li>
</ul>

<h3><?php echo __('docs_api_csrf', 'CSRF Token'); ?></h3>
<p><?php echo __('docs_api_csrf_desc', 'For POST requests, include the CSRF token:'); ?></p>
<pre><code>// In form data:
csrf_token = "<?php echo '<?php echo generateCSRF(); ?>'; ?>"

// Or as a hidden field:
<?php echo htmlspecialchars('<?php csrfField(); ?>'); ?></code></pre>

<h2><?php echo __('docs_api_format', 'Request Format'); ?></h2>
<p><?php echo __('docs_api_format_desc', 'All API endpoints use the <code>action</code> parameter dispatch pattern:'); ?></p>
<pre><code>// GET request
GET /api/properties.php?action=list&page=1&status=active

// POST request
POST /api/properties.php
Content-Type: application/x-www-form-urlencoded

action=create&name=My+Property&property_type=residential&address=123+Main+St</code></pre>

<h2><?php echo __('docs_api_response', 'Response Format'); ?></h2>
<p><?php echo __('docs_api_response_desc', 'All responses are JSON with a consistent structure:'); ?></p>
<pre><code>// Success response
{
    "success": true,
    "data": { ... },
    "message": "Property created successfully"
}

// Error response
{
    "success": false,
    "message": "Validation error: Name is required"
}

// List response (with pagination)
{
    "success": true,
    "data": [ ... ],
    "total": 45,
    "page": 1,
    "per_page": 20
}</code></pre>

<h2><?php echo __('docs_api_errors', 'Error Handling'); ?></h2>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_error_code', 'Scenario'); ?></th><th><?php echo __('docs_api_error_response', 'Response'); ?></th></tr></thead>
    <tbody>
        <tr><td><?php echo __('docs_api_err_auth', 'Not authenticated'); ?></td><td><code>{"success": false, "message": "Unauthorized"}</code></td></tr>
        <tr><td><?php echo __('docs_api_err_perm', 'No permission'); ?></td><td><code>{"success": false, "message": "Permission denied"}</code></td></tr>
        <tr><td><?php echo __('docs_api_err_valid', 'Validation failed'); ?></td><td><code>{"success": false, "message": "Field X is required"}</code></td></tr>
        <tr><td><?php echo __('docs_api_err_notfound', 'Not found'); ?></td><td><code>{"success": false, "message": "Record not found"}</code></td></tr>
        <tr><td><?php echo __('docs_api_err_csrf', 'CSRF mismatch'); ?></td><td><code>{"success": false, "message": "Invalid CSRF token"}</code></td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_api_endpoints', 'Available Endpoints'); ?></h2>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_endpoint', 'Endpoint'); ?></th><th><?php echo __('docs_api_module', 'Module'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>/api/properties.php</code></td><td><?php echo __('docs_properties_guide', 'Properties'); ?></td></tr>
        <tr><td><code>/api/units.php</code></td><td><?php echo __('docs_units_guide', 'Units'); ?></td></tr>
        <tr><td><code>/api/tenants.php</code></td><td><?php echo __('docs_tenants_guide', 'Tenants'); ?></td></tr>
        <tr><td><code>/api/leases.php</code></td><td><?php echo __('docs_leases_guide', 'Leases'); ?></td></tr>
        <tr><td><code>/api/payments.php</code></td><td><?php echo __('docs_payments_guide', 'Payments & Receipts'); ?></td></tr>
        <tr><td><code>/api/deposits.php</code></td><td><?php echo __('docs_deposits_guide', 'Deposits & Late Fees'); ?></td></tr>
        <tr><td><code>/api/inspections.php</code></td><td><?php echo __('docs_inspections_guide', 'Inspections'); ?></td></tr>
        <tr><td><code>/api/maintenance.php</code></td><td><?php echo __('docs_maintenance_guide', 'Maintenance'); ?></td></tr>
        <tr><td><code>/api/utility.php</code></td><td><?php echo __('docs_utilities_guide', 'Utility Billing'); ?></td></tr>
        <tr><td><code>/api/expenses.php</code></td><td><?php echo __('docs_expenses_guide', 'Expenses & Financial'); ?></td></tr>
        <tr><td><code>/api/reports.php</code></td><td><?php echo __('docs_reports_guide', 'Reports & Dashboard'); ?></td></tr>
        <tr><td><code>/api/settings.php</code></td><td><?php echo __('docs_settings_guide', 'Settings & Users'); ?></td></tr>
        <tr><td><code>/api/gateway.php</code></td><td><?php echo __('docs_gateway_guide', 'Payment Gateway'); ?></td></tr>
        <tr><td><code>/api/saas-admin.php</code></td><td><?php echo __('docs_api_saas', 'SaaS Admin'); ?></td></tr>
    </tbody>
</table>
