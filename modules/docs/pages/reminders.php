<h1><i class="bi bi-bell me-2"></i><?php echo __('docs_reminders_guide', 'Automated Reminders'); ?></h1>
<p><span class="tier-badge tier-professional">Professional</span></p>
<p><?php echo __('docs_rem_intro', 'Set up automated reminders for rent due dates, overdue payments, and lease renewals. Deliver via WhatsApp, SMS, or email.'); ?></p>

<h2><?php echo __('docs_rem_templates', 'Reminder Templates'); ?></h2>
<p><?php echo __('docs_rem_templates_desc', 'Create message templates with placeholders:'); ?></p>
<pre><code>Hello {tenant_name}, your rent of {amount} is due on {due_date}.
Please pay to avoid a late fee. Thank you!</code></pre>
<p><?php echo __('docs_rem_placeholders', 'Available placeholders: <code>{tenant_name}</code>, <code>{amount}</code>, <code>{due_date}</code>, <code>{unit_number}</code>, <code>{property_name}</code>, <code>{balance}</code>'); ?></p>

<h2><?php echo __('docs_rem_schedule', 'Scheduling Rules'); ?></h2>
<ul>
    <li><?php echo __('docs_rem_before', '<strong>Before due date</strong> — Send X days before rent is due (e.g., 5 days, 3 days, 1 day)'); ?></li>
    <li><?php echo __('docs_rem_on_due', '<strong>On due date</strong> — Send reminder on the day rent is due'); ?></li>
    <li><?php echo __('docs_rem_overdue', '<strong>Overdue</strong> — Send X days after due date if payment not received'); ?></li>
</ul>

<h2><?php echo __('docs_rem_channels', 'Delivery Channels'); ?></h2>
<ul>
    <li><i class="bi bi-whatsapp text-success me-1"></i> <strong>WhatsApp</strong> — <?php echo __('docs_rem_wa', 'Via wa.fis.co.tz API'); ?></li>
    <li><i class="bi bi-envelope me-1"></i> <strong>Email</strong> — <?php echo __('docs_rem_email', 'HTML email templates'); ?></li>
</ul>

<h2><?php echo __('docs_rem_auto_receipt', 'Auto-Send Receipts'); ?></h2>
<p><?php echo __('docs_rem_auto_receipt_desc', 'When a payment is recorded, the system can automatically generate a receipt and send it to the tenant via WhatsApp. Enable this in the reminder settings.'); ?></p>

<h2><?php echo __('docs_rem_log', 'Delivery Log'); ?></h2>
<p><?php echo __('docs_rem_log_desc', 'Track all sent reminders and receipts. View delivery status (sent, delivered, failed) and retry failed messages. Export the log as CSV for review.'); ?></p>
