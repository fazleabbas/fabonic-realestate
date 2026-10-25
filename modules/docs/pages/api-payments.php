<h1><i class="bi bi-braces me-2"></i><?php echo __('docs_api_payments', 'Payments API'); ?></h1>
<p><?php echo __('docs_apipay_intro', 'API reference for the Payments endpoint.'); ?></p>
<p><strong><?php echo __('docs_api_endpoint', 'Endpoint'); ?>:</strong> <code>/api/payments.php</code></p>

<h2><?php echo __('docs_apipay_list', 'List Payments'); ?></h2>
<pre><code>GET /api/payments.php?action=list&page=1&tenant_id=1&date_from=2025-01-01&date_to=2025-12-31</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('type', 'Type'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>action</code></td><td>string</td><td><code>list</code> (required)</td></tr>
        <tr><td><code>tenant_id</code></td><td>int</td><td>Filter by tenant</td></tr>
        <tr><td><code>property_id</code></td><td>int</td><td>Filter by property</td></tr>
        <tr><td><code>date_from</code></td><td>date</td><td>Start date (Y-m-d)</td></tr>
        <tr><td><code>date_to</code></td><td>date</td><td>End date (Y-m-d)</td></tr>
        <tr><td><code>method</code></td><td>string</td><td>cash | bank_transfer | mobile_money | cheque | gateway</td></tr>
    </tbody>
</table>

<h3><?php echo __('docs_api_response', 'Response'); ?></h3>
<pre><code>{
    "success": true,
    "data": [
        {
            "id": 1,
            "tenant_name": "John Doe",
            "unit_number": "A-101",
            "amount": 500000,
            "currency": "TZS",
            "payment_date": "2025-03-01",
            "payment_method": "mobile_money",
            "reference_number": "TXN123456",
            "period_from": "2025-03-01",
            "period_to": "2025-03-31",
            "status": "recorded"
        }
    ],
    "total": 120
}</code></pre>

<h2><?php echo __('docs_apipay_record', 'Record Payment'); ?></h2>
<pre><code>POST /api/payments.php
action=create&lease_id=1&tenant_id=1&amount=500000&payment_date=2025-03-01&payment_method=mobile_money&reference_number=TXN123456</code></pre>
<table class="table">
    <thead><tr><th><?php echo __('docs_api_param', 'Parameter'); ?></th><th><?php echo __('required', 'Required'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><code>lease_id</code></td><td>Yes</td><td>Active lease ID</td></tr>
        <tr><td><code>tenant_id</code></td><td>Yes</td><td>Tenant ID</td></tr>
        <tr><td><code>amount</code></td><td>Yes</td><td>Payment amount</td></tr>
        <tr><td><code>payment_date</code></td><td>Yes</td><td>Date of payment (Y-m-d)</td></tr>
        <tr><td><code>payment_method</code></td><td>Yes</td><td>cash | bank_transfer | mobile_money | cheque</td></tr>
        <tr><td><code>reference_number</code></td><td>No</td><td>Transaction reference</td></tr>
        <tr><td><code>notes</code></td><td>No</td><td>Additional notes</td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_apipay_receipt', 'Get Receipt'); ?></h2>
<pre><code>GET /api/payments.php?action=receipt&payment_id=1</code></pre>
<p><?php echo __('docs_apipay_receipt_desc', 'Returns the receipt data or triggers PDF download for the specified payment.'); ?></p>

<h2><?php echo __('docs_apipay_reverse', 'Reverse Payment'); ?></h2>
<pre><code>POST /api/payments.php
action=reverse&id=1&reason=Duplicate+entry</code></pre>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <?php echo __('docs_apipay_reverse_warn', 'Reversing a payment marks it as reversed and adjusts the tenant balance. The original record is preserved for audit. This action requires admin permission.'); ?>
</div>

<h2><?php echo __('docs_apipay_bulk', 'Bulk Payment'); ?></h2>
<pre><code>POST /api/payments.php
action=bulk_create&payments=[{"lease_id":1,"amount":500000,"payment_method":"cash"}, ...]</code></pre>
<p><?php echo __('docs_apipay_bulk_desc', 'Record multiple payments in a single request. The payments parameter is a JSON array of payment objects.'); ?></p>
