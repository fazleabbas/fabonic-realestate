<h1><i class="bi bi-question-circle me-2"></i><?php echo __('docs_faq', 'FAQ'); ?></h1>
<p><?php echo __('docs_faq_intro', 'Frequently asked questions about FIS Real Estate.'); ?></p>

<div class="accordion" id="faqAccordion">

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1"><?php echo __('docs_faq_q1', 'How do I start using the system?'); ?></button></h2>
        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a1', 'After logging in, add your first property, create units within it, register tenants, create leases, and start recording payments. Follow the Getting Started guide for step-by-step instructions.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2"><?php echo __('docs_faq_q2', 'Can I use the system offline?'); ?></button></h2>
        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a2', 'Yes! FIS Real Estate is a Progressive Web App (PWA). Install it on your device and continue working offline. Your changes will sync automatically when you reconnect to the internet.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3"><?php echo __('docs_faq_q3', 'How does the subscription billing work?'); ?></button></h2>
        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo sprintf(__('docs_faq_a3', 'You are billed monthly based on the number of units you manage multiplied by your tier rate. Basic is %1$s TZS/unit/month, Professional is %2$s, and Enterprise is %3$s. Discounts may apply.'), number_format(TIER_BASIC_RATE), number_format(TIER_PROFESSIONAL_RATE), number_format(TIER_ENTERPRISE_RATE)); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4"><?php echo __('docs_faq_q4', 'How do I upgrade my subscription tier?'); ?></button></h2>
        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a4', 'Contact the FIS Real Estate support team via WhatsApp or email to request a tier upgrade. Your subscription will be updated and you\'ll gain access to the new features immediately.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5"><?php echo __('docs_faq_q5', 'Can tenants log in and view their balance?'); ?></button></h2>
        <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a5', 'Yes, with the Professional tier or above. The Tenant Portal allows tenants to log in, view their balance, payment history, and lease details. You can enable/disable portal access per tenant.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6"><?php echo __('docs_faq_q6', 'How are late fees calculated?'); ?></button></h2>
        <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a6', 'Late fees are calculated automatically based on your configuration. You set the fee type (percentage or fixed), grace period, and maximum cap. Fees are applied when rent is not paid by the due date plus the grace period.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq7"><?php echo __('docs_faq_q7', 'Can I manage multiple properties?'); ?></button></h2>
        <div id="faq7" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a7', 'Yes, you can manage unlimited properties at no extra cost. Each property can have its own settings, units, and tenants. The dashboard aggregates data across all your properties.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq8"><?php echo __('docs_faq_q8', 'What languages are supported?'); ?></button></h2>
        <div id="faq8" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a8', 'The system supports English, Swahili (Kiswahili), and Arabic (with right-to-left layout). Switch languages anytime using the globe icon in the top bar.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq9"><?php echo __('docs_faq_q9', 'How do I export my data?'); ?></button></h2>
        <div id="faq9" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a9', 'Most list views and reports have an Export/CSV button. Click it to download the data as a CSV file that opens in Excel or Google Sheets. Admins can also create full database backups from the Backup page.'); ?></div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq10"><?php echo __('docs_faq_q10', 'How do I get support?'); ?></button></h2>
        <div id="faq10" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body"><?php echo __('docs_faq_a10', 'Contact our support team via WhatsApp or email. Enterprise tier subscribers get priority support with faster response times.'); ?></div>
        </div>
    </div>

</div>
