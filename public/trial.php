<?php
/**
 * Fabonic Real Estate — Trial Request Form
 * Public page, no auth. CSRF protected.
 */
session_start();

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$pageTitle = 'Start Free Trial — Fabonic Real Estate';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --re-primary: #003b7a;
            --re-primary-dark: #002a57;
            --re-primary-light: #e9f1fb;
            --re-accent: #f5a623;
            --re-accent-dark: #d98e12;
        }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f4f6f9; margin: 0; }

        .trial-header {
            background: linear-gradient(135deg, #002a57 0%, #003b7a 100%);
            padding: 2rem 0;
            color: white;
            text-align: center;
        }
        .trial-header h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.25rem; }
        .trial-header p { opacity: 0.8; margin: 0; }
        .trial-header a { color: white; text-decoration: none; }
        .trial-header a:hover { color: var(--re-accent); }

        .trial-container { max-width: 680px; margin: -2rem auto 3rem; padding: 0 1rem; position: relative; z-index: 1; }

        .trial-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 2rem;
        }
        .trial-card .form-label { font-weight: 600; font-size: 0.9rem; color: #333; }
        .trial-card .form-control, .trial-card .form-select {
            border-radius: 0.5rem;
            border: 1.5px solid #dee2e6;
            padding: 0.55rem 0.75rem;
            font-size: 0.9rem;
        }
        .trial-card .form-control:focus, .trial-card .form-select:focus {
            border-color: var(--re-primary);
            box-shadow: 0 0 0 0.2rem rgba(26,107,74,0.15);
        }
        .slug-preview {
            font-size: 0.82rem;
            margin-top: 0.35rem;
            min-height: 1.3rem;
        }
        .slug-preview.available { color: #16a34a; }
        .slug-preview.unavailable { color: #dc2626; }
        .slug-preview.checking { color: #888; }

        .btn-trial {
            background: var(--re-primary);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: background 0.2s;
        }
        .btn-trial:hover { background: var(--re-primary-dark); color: white; }
        .btn-trial:disabled { background: #999; cursor: not-allowed; }

        .trial-benefits {
            background: var(--re-primary-light);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .trial-benefits h6 { font-weight: 700; color: var(--re-primary-dark); margin-bottom: 0.5rem; font-size: 0.9rem; }
        .trial-benefits ul { list-style: none; padding: 0; margin: 0; }
        .trial-benefits li { padding: 0.2rem 0; font-size: 0.85rem; color: #555; }
        .trial-benefits li i { color: var(--re-primary); margin-right: 0.4rem; }

        .trial-success {
            text-align: center;
            padding: 2rem;
        }
        .trial-success-icon {
            width: 80px; height: 80px;
            background: var(--re-primary-light);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .trial-success-icon i { font-size: 2.5rem; color: var(--re-primary); }

        .trial-footer { text-align: center; padding: 1rem; color: #999; font-size: 0.8rem; }
        .trial-footer a { color: #888; text-decoration: none; }

        /* Honeypot */
        .hp-field { position: absolute; left: -9999px; opacity: 0; height: 0; }
    </style>
</head>
<body>

<!-- Header -->
<div class="trial-header">
    <div class="container">
        <a href="index.php" style="display:inline-flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem;background:#fff;padding:0.4rem 0.75rem;border-radius:8px;">
            <img src="assets/logo.png" alt="Fabonic Real Estate" style="height:26px;">
        </a>
        <h1>Start Your 14-Day Free Trial</h1>
        <p>Professional tier included free. No credit card required.</p>
    </div>
</div>

<div class="trial-container">
    <div class="trial-card">
        <!-- Benefits reminder -->
        <div class="trial-benefits">
            <h6><i class="bi bi-gift me-1"></i> What's Included in Your Free Trial:</h6>
            <ul>
                <li><i class="bi bi-check-circle-fill"></i> Full Professional tier access (normally TZS 14,999/unit/month)</li>
                <li><i class="bi bi-check-circle-fill"></i> Properties, tenants, leases, payments, receipts</li>
                <li><i class="bi bi-check-circle-fill"></i> Tenant portal &amp; automated WhatsApp reminders</li>
                <li><i class="bi bi-check-circle-fill"></i> 14 days free, no obligations</li>
            </ul>
        </div>

        <!-- Form -->
        <form id="trialForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <!-- Honeypot -->
            <div class="hp-field">
                <label for="website">Website</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="row g-3">
                <!-- Company Name -->
                <div class="col-12">
                    <label class="form-label">Company / Property Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="company_name" id="companyName" placeholder="e.g. Mwangaza Properties" required maxlength="200" autocomplete="organization">
                </div>

                <!-- Owner Name -->
                <div class="col-md-6">
                    <label class="form-label">Your Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name" id="fullName" placeholder="e.g. John Mwakijembe" required maxlength="150" autocomplete="name">
                </div>

                <!-- Phone -->
                <div class="col-md-6">
                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" name="phone" id="phone" placeholder="e.g. 0786 110 021" required maxlength="20" autocomplete="tel">
                </div>

                <!-- Email -->
                <div class="col-12">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" name="email" id="email" placeholder="e.g. john@example.com" maxlength="150" autocomplete="email">
                    <div class="form-text">Optional but recommended &mdash; we'll send your login credentials here.</div>
                </div>

                <!-- Preferred Slug -->
                <div class="col-md-6">
                    <label class="form-label">Preferred URL Slug</label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="preferred_slug" id="preferredSlug" placeholder="mycompany" maxlength="20" pattern="[a-z0-9]+" autocomplete="off">
                        <span class="input-group-text" style="font-size:0.82rem;background:#f8f9fa;">.real.co.tz</span>
                    </div>
                    <div class="slug-preview" id="slugStatus"></div>
                </div>

                <!-- Unit Count -->
                <div class="col-md-6">
                    <label class="form-label">Number of Units <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="unit_count" id="unitCount" value="10" min="1" max="10000" required>
                    <div class="form-text">Rooms, shops, apartments, etc. you manage.</div>
                </div>

                <!-- City -->
                <div class="col-md-6">
                    <label class="form-label">City</label>
                    <select class="form-select" name="city" id="city">
                        <option value="">Select city...</option>
                        <option value="Dar es Salaam">Dar es Salaam</option>
                        <option value="Arusha">Arusha</option>
                        <option value="Mwanza">Mwanza</option>
                        <option value="Dodoma">Dodoma</option>
                        <option value="Mbeya">Mbeya</option>
                        <option value="Morogoro">Morogoro</option>
                        <option value="Tanga">Tanga</option>
                        <option value="Zanzibar">Zanzibar</option>
                        <option value="Iringa">Iringa</option>
                        <option value="Kigoma">Kigoma</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <!-- Referral Source -->
                <div class="col-md-6">
                    <label class="form-label">How did you hear about us?</label>
                    <select class="form-select" name="referral_source" id="referralSource">
                        <option value="">Select...</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="google">Google Search</option>
                        <option value="social_media">Social Media</option>
                        <option value="friend">Friend / Colleague</option>
                        <option value="sales_agent">Sales Agent</option>
                        <option value="fis_lite">Already using FIS Lite</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <!-- Submit -->
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-trial" id="submitBtn">
                        <i class="bi bi-rocket-takeoff me-1"></i> Request Free Trial
                    </button>
                </div>

                <div class="col-12">
                    <p class="text-center text-muted mb-0" style="font-size:0.82rem;">
                        By submitting, you agree to receive WhatsApp messages about your trial. You can opt out anytime.
                    </p>
                </div>
            </div>
        </form>

        <!-- Success Message (hidden by default) -->
        <div class="trial-success d-none" id="successMessage">
            <div class="trial-success-icon">
                <i class="bi bi-check-lg"></i>
            </div>
            <h3 style="color:var(--re-primary-dark);font-weight:700;">Ombi Lako Limepokelewa!</h3>
            <h5 class="text-muted mb-3">Your Request Has Been Received!</h5>
            <p class="text-muted">Timu yetu itakagua ombi lako na kukuandalia akaunti ndani ya masaa 24. Utapokea taarifa kupitia WhatsApp na email.</p>
            <div style="background:#f8f9fa;border-radius:0.75rem;padding:1.25rem;text-align:left;max-width:400px;margin:1.5rem auto 0;">
                <h6 style="font-weight:700;margin-bottom:0.75rem;">Hatua Zinazofuata:</h6>
                <ol style="padding-left:1.25rem;margin:0;color:#555;font-size:0.9rem;">
                    <li class="mb-1">We review your request (within 24 hours)</li>
                    <li class="mb-1">Your account is set up automatically</li>
                    <li class="mb-1">Login details sent via WhatsApp &amp; email</li>
                    <li>Start managing your properties!</li>
                </ol>
            </div>
            <a href="index.php" class="btn btn-trial mt-4" style="max-width:300px;">
                <i class="bi bi-arrow-left me-1"></i> Back to Home
            </a>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="trial-footer">
    <a href="index.php">&larr; Back to Fabonic Real Estate</a> &nbsp;|&nbsp;
    <a href="https://real.co.tz">real.co.tz</a>
    <br>&copy; <?php echo date('Y'); ?> Fabonic. All rights reserved.
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    const form = document.getElementById('trialForm');
    const submitBtn = document.getElementById('submitBtn');
    const successMsg = document.getElementById('successMessage');
    const slugInput = document.getElementById('preferredSlug');
    const slugStatus = document.getElementById('slugStatus');
    const companyInput = document.getElementById('companyName');
    let slugTimer = null;
    let slugAvailable = null;

    // Auto-generate slug from company name
    companyInput.addEventListener('input', function() {
        if (!slugInput.value && this.value.length >= 3) {
            const auto = this.value.toLowerCase().replace(/[^a-z0-9]/g, '').substring(0, 20);
            slugInput.value = auto;
            checkSlug(auto);
        }
    });

    // Slug validation on input
    slugInput.addEventListener('input', function() {
        let val = this.value.toLowerCase().replace(/[^a-z0-9]/g, '');
        this.value = val;
        clearTimeout(slugTimer);
        if (val.length >= 3) {
            slugStatus.textContent = 'Checking availability...';
            slugStatus.className = 'slug-preview checking';
            slugTimer = setTimeout(() => checkSlug(val), 400);
        } else if (val.length > 0) {
            slugStatus.textContent = 'Must be at least 3 characters';
            slugStatus.className = 'slug-preview unavailable';
            slugAvailable = false;
        } else {
            slugStatus.textContent = '';
            slugAvailable = null;
        }
    });

    function checkSlug(slug) {
        fetch('../api/check-slug.php?slug=' + encodeURIComponent(slug))
            .then(r => r.json())
            .then(data => {
                if (slugInput.value.toLowerCase().replace(/[^a-z0-9]/g, '') !== data.slug) return;
                slugAvailable = data.available;
                slugStatus.textContent = data.message;
                slugStatus.className = 'slug-preview ' + (data.available ? 'available' : 'unavailable');
            })
            .catch(() => {
                slugStatus.textContent = 'Could not check availability';
                slugStatus.className = 'slug-preview unavailable';
                slugAvailable = null;
            });
    }

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Basic validation
        const companyName = form.company_name.value.trim();
        const fullName = form.full_name.value.trim();
        const phone = form.phone.value.trim();
        const unitCount = parseInt(form.unit_count.value) || 0;

        if (!companyName || !fullName || !phone || unitCount < 1) {
            alert('Please fill in all required fields.');
            return;
        }

        if (phone.replace(/[^0-9]/g, '').length < 9) {
            alert('Please enter a valid phone number.');
            return;
        }

        if (slugAvailable === false) {
            alert('The chosen URL slug is not available. Please choose another one.');
            slugInput.focus();
            return;
        }

        // Honeypot check
        if (form.website.value) return;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...';

        const formData = new FormData(form);

        fetch('../api/send-trial-request.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                form.classList.add('d-none');
                document.querySelector('.trial-benefits').classList.add('d-none');
                successMsg.classList.remove('d-none');
            } else {
                alert(data.message || 'Something went wrong. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i> Request Free Trial';
            }
        })
        .catch(() => {
            alert('Network error. Please check your connection and try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i> Request Free Trial';
        });
    });
})();
</script>
</body>
</html>
