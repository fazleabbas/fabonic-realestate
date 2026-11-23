<h1><i class="bi bi-phone me-2"></i><?php echo __('docs_gateway_guide', 'Payment Gateway'); ?></h1>
<p><span class="tier-badge tier-enterprise">Enterprise</span></p>
<p><?php echo __('docs_gw_intro', 'Integrate mobile money payment gateways to accept payments digitally. Supports Selcom, M-Pesa, Airtel Money, and Tigo Pesa.'); ?></p>

<h2><?php echo __('docs_gw_setup', 'Gateway Setup'); ?></h2>
<ol>
    <li><?php echo __('docs_gw_s1', 'Go to <strong>Settings</strong> and enable the Payment Gateway module'); ?></li>
    <li><?php echo __('docs_gw_s2', 'Navigate to gateway settings and select your provider'); ?></li>
    <li><?php echo __('docs_gw_s3', 'Enter API credentials: API key, secret, and merchant ID'); ?></li>
    <li><?php echo __('docs_gw_s4', 'Configure the callback URL for payment notifications'); ?></li>
    <li><?php echo __('docs_gw_s5', 'Test with sandbox mode before going live'); ?></li>
</ol>

<h2><?php echo __('docs_gw_supported', 'Supported Gateways'); ?></h2>
<table class="table">
    <thead><tr><th><?php echo __('docs_gw_gateway', 'Gateway'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td>Selcom</td><td><?php echo __('docs_gw_selcom', 'Aggregator supporting multiple mobile money wallets'); ?></td></tr>
        <tr><td>M-Pesa</td><td><?php echo __('docs_gw_mpesa', 'Vodacom M-Pesa mobile money'); ?></td></tr>
        <tr><td>Airtel Money</td><td><?php echo __('docs_gw_airtel', 'Airtel mobile money'); ?></td></tr>
        <tr><td>Tigo Pesa</td><td><?php echo __('docs_gw_tigo', 'Tigo mobile money (now MobiPay)'); ?></td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_gw_flow', 'Payment Flow'); ?></h2>
<ol>
    <li><?php echo __('docs_gw_f1', 'Tenant selects the amount and payment method'); ?></li>
    <li><?php echo __('docs_gw_f2', 'System sends USSD push to tenant\'s phone'); ?></li>
    <li><?php echo __('docs_gw_f3', 'Tenant confirms payment on their phone'); ?></li>
    <li><?php echo __('docs_gw_f4', 'Gateway sends callback to confirm the payment'); ?></li>
    <li><?php echo __('docs_gw_f5', 'System auto-records the payment and generates a receipt'); ?></li>
</ol>

<h2><?php echo __('docs_gw_reconciliation', 'Auto-Reconciliation'); ?></h2>
<p><?php echo __('docs_gw_reconciliation_desc', 'The system automatically matches gateway transactions to tenant payments. Any unmatched transactions appear in the manual reconciliation queue for admin review.'); ?></p>

<h2><?php echo __('docs_gw_log', 'Transaction Log'); ?></h2>
<p><?php echo __('docs_gw_log_desc', 'View all gateway transactions with their status (initiated, pending, completed, failed). Filter by date, gateway, or status. Failed transactions include error details for troubleshooting.'); ?></p>
