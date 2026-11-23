<?php
/**
 * Fabonic Real Estate Management System — Public Landing Page
 * real.co.tz — self-registration + auto-provisioning marketing site.
 * Public page, no auth. CSRF token issued for the embedded trial form.
 */
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fabonic Real Estate Management System — Property Management for Tanzania</title>
    <meta name="description" content="Manage properties, tenants, leases, and rent collection online. Register in minutes, get your own subdomain, and start your 14-day free trial. No credit card required.">
    <link rel="icon" type="image/png" href="/public/assets/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #003b7a;          /* Fabonic navy */
            --brand-dark: #002a57;
            --brand-light: #e9f1fb;
            --accent: #f5a623;         /* warm gold CTA */
            --accent-dark: #d98e12;
            --ink: #1a2233;
            --muted: #5a6473;
            --line: #e7ebf0;
            --bg-soft: #f6f8fb;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--ink);
            margin: 0;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4 { font-weight: 800; letter-spacing: -0.02em; line-height: 1.2; }
        section { scroll-margin-top: 80px; }
        a { text-decoration: none; }
        .text-muted-2 { color: var(--muted); }

        /* ---------- Buttons ---------- */
        .btn-brand {
            background: var(--brand); color: #fff; border: none;
            padding: 0.75rem 1.6rem; border-radius: 10px; font-weight: 600;
            transition: transform .15s ease, background .15s ease;
        }
        .btn-brand:hover { background: var(--brand-dark); color: #fff; transform: translateY(-1px); }
        .btn-accent {
            background: var(--accent); color: #3a2a00; border: none;
            padding: 0.75rem 1.6rem; border-radius: 10px; font-weight: 700;
            transition: transform .15s ease, background .15s ease;
        }
        .btn-accent:hover { background: var(--accent-dark); color: #3a2a00; transform: translateY(-1px); }
        .btn-ghost {
            background: transparent; color: var(--brand); border: 1.5px solid var(--brand);
            padding: 0.72rem 1.5rem; border-radius: 10px; font-weight: 600;
        }
        .btn-ghost:hover { background: var(--brand); color: #fff; }

        /* ---------- Navbar ---------- */
        .nav-wrap {
            position: sticky; top: 0; z-index: 1000; width: 100%;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
            box-shadow: 0 2px 10px rgba(0,42,87,.06);
        }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0; position: relative; min-height: 52px; }
        .nav-logo img { height: 34px; }
        .nav-links { display: flex; align-items: center; gap: 1.75rem; }
        .nav-links a.link { color: var(--ink); font-weight: 500; font-size: 0.95rem; }
        .nav-links a.link:hover { color: var(--brand); }
        .nav-toggle { display: none; background: none; border: none; color: var(--brand); font-size: 1.7rem; line-height: 1; cursor: pointer; padding: .1rem .3rem; }
        @media (max-width: 900px) {
            .nav-toggle { display: block; }
            .nav-links {
                position: absolute; top: calc(100% + 1px); left: 0; right: 0;
                flex-direction: column; align-items: stretch; gap: 0;
                background: #fff; border-bottom: 1px solid var(--line);
                box-shadow: 0 14px 30px rgba(0,42,87,.10);
                padding: .4rem 0; display: none;
            }
            .nav-links.open { display: flex; }
            .nav-links a.link { padding: .8rem 1.25rem; border-bottom: 1px solid var(--bg-soft); }
            .nav-links .btn-accent { margin: .6rem 1.25rem .3rem; text-align: center; }
        }

        /* ---------- Hero ---------- */
        .hero {
            background:
                radial-gradient(900px 420px at 85% -10%, rgba(0,59,122,.10), transparent),
                linear-gradient(180deg, #fff 0%, var(--bg-soft) 100%);
            padding: 4.5rem 0 3.5rem;
        }
        .hero h1 { font-size: clamp(2.1rem, 5vw, 3.4rem); }
        .hero .lead { font-size: 1.2rem; color: var(--muted); max-width: 560px; }
        .pill {
            display: inline-flex; align-items: center; gap: .45rem;
            background: var(--brand-light); color: var(--brand);
            padding: .4rem .9rem; border-radius: 50px; font-weight: 600; font-size: .85rem;
            margin-bottom: 1.1rem;
        }
        .hero-card {
            background: #fff; border: 1px solid var(--line); border-radius: 18px;
            box-shadow: 0 24px 60px rgba(0,42,87,.12); padding: 1.25rem; overflow: hidden;
        }
        .browser-bar { display:flex; align-items:center; gap:.4rem; padding:.35rem .2rem .75rem; }
        .browser-bar .dot { width:11px; height:11px; border-radius:50%; }
        .browser-url {
            flex:1; margin-left:.5rem; background:var(--bg-soft); border-radius:7px;
            font-size:.78rem; color:var(--muted); padding:.3rem .7rem; text-align:center;
        }
        .stat-row { display:flex; gap:1.5rem; flex-wrap:wrap; margin-top:2rem; }
        .stat b { font-size:1.6rem; color:var(--brand); display:block; }
        .stat span { font-size:.85rem; color:var(--muted); }

        /* ---------- Sections ---------- */
        .section { padding: 4.5rem 0; }
        .section-soft { background: var(--bg-soft); }
        .eyebrow { color: var(--accent-dark); font-weight: 700; text-transform: uppercase; letter-spacing:.08em; font-size:.8rem; }
        .section h2 { font-size: clamp(1.7rem, 3.5vw, 2.4rem); margin: .4rem 0 .6rem; }
        .section .sub { color: var(--muted); font-size: 1.08rem; max-width: 640px; margin: 0 auto; }

        /* ---------- Steps ---------- */
        .step {
            background:#fff; border:1px solid var(--line); border-radius:16px; padding:1.75rem; height:100%;
            position:relative; transition: box-shadow .2s, transform .2s;
        }
        .step:hover { box-shadow:0 14px 34px rgba(0,42,87,.08); transform:translateY(-3px); }
        .step-num {
            width:44px; height:44px; border-radius:12px; background:var(--brand); color:#fff;
            display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.15rem; margin-bottom:1rem;
        }
        .step h4 { font-size:1.15rem; margin-bottom:.4rem; }
        .step p { color:var(--muted); font-size:.95rem; margin:0; }

        /* ---------- Features ---------- */
        .feature { padding:1.5rem; border-radius:14px; height:100%; transition: background .2s; }
        .feature:hover { background:#fff; box-shadow:0 10px 28px rgba(0,42,87,.06); }
        .feature-ico {
            width:48px; height:48px; border-radius:12px; background:var(--brand-light); color:var(--brand);
            display:flex; align-items:center; justify-content:center; font-size:1.35rem; margin-bottom:.9rem;
        }
        .feature h5 { font-size:1.05rem; font-weight:700; margin-bottom:.3rem; }
        .feature p { color:var(--muted); font-size:.92rem; margin:0; }

        /* ---------- Pricing ---------- */
        .price-card { background:#fff; border:1px solid var(--line); border-radius:18px; padding:2.25rem; height:100%; }
        .price-card.featured { border:2px solid var(--brand); box-shadow:0 20px 44px rgba(0,42,87,.12); position:relative; }
        .price-card.featured .ribbon {
            position:absolute; top:-13px; left:50%; transform:translateX(-50%);
            background:var(--accent); color:#3a2a00; font-weight:700; font-size:.78rem;
            padding:.3rem 1rem; border-radius:50px;
        }
        .price-amt { font-size:2.6rem; font-weight:800; color:var(--brand); }
        .price-card ul { list-style:none; padding:0; margin:1.25rem 0 0; }
        .price-card li { padding:.4rem 0; color:var(--ink); font-size:.95rem; }
        .price-card li i { color:#16a34a; margin-right:.5rem; }

        /* ---------- Demo band ---------- */
        .demo-band {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color:#fff; border-radius:22px; padding:3rem; text-align:center;
        }
        .demo-band h2 { color:#fff; font-size:1.9rem; }
        .demo-band p { color:rgba(255,255,255,.82); max-width:520px; margin:.5rem auto 1.6rem; }

        /* ---------- Signup form ---------- */
        .signup-card { background:#fff; border:1px solid var(--line); border-radius:20px; box-shadow:0 20px 50px rgba(0,42,87,.10); padding:2.25rem; }
        .form-label { font-weight:600; font-size:.9rem; color:var(--ink); }
        .form-control, .form-select { border:1.5px solid var(--line); border-radius:10px; padding:.6rem .8rem; font-size:.95rem; }
        .form-control:focus, .form-select:focus { border-color:var(--brand); box-shadow:0 0 0 .2rem rgba(0,59,122,.14); }
        .input-group-text { background:var(--bg-soft); border:1.5px solid var(--line); font-size:.85rem; color:var(--muted); }
        .slug-preview { font-size:.82rem; margin-top:.35rem; min-height:1.2rem; }
        .slug-preview.available { color:#16a34a; }
        .slug-preview.unavailable { color:#dc2626; }
        .slug-preview.checking { color:#888; }
        .hp-field { position:absolute; left:-9999px; opacity:0; height:0; }
        .trust-line { font-size:.85rem; color:var(--muted); }
        .success-box { text-align:center; padding:1.5rem .5rem; }
        .success-ico { width:76px;height:76px;background:#e9f9ef;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem; }
        .success-ico i { font-size:2.4rem; color:#16a34a; }

        /* ---------- Footer ---------- */
        .footer { background:var(--ink); color:#c7cdd6; padding:3rem 0 1.5rem; }
        .footer a { color:#c7cdd6; }
        .footer a:hover { color:#fff; }
        .footer .logo-inv { height:30px; filter: brightness(0) invert(1); opacity:.9; }
        .footer h6 { color:#fff; font-weight:700; font-size:.95rem; margin-bottom:.8rem; }
        .footer-bottom { border-top:1px solid rgba(255,255,255,.1); margin-top:2rem; padding-top:1.25rem; font-size:.85rem; color:#8a93a1; }
    </style>
</head>
<body>

<!-- ===================== NAV ===================== -->
<div class="nav-wrap">
    <div class="container">
        <div class="nav-inner">
            <a href="#top" class="nav-logo"><img src="/public/assets/logo.png" alt="Fabonic Real Estate"></a>
            <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false"><i class="bi bi-list"></i></button>
            <div class="nav-links" id="navLinks">
                <a href="#how" class="link">How it works</a>
                <a href="#features" class="link">Features</a>
                <a href="#pricing" class="link">Pricing</a>
                <a href="#demo" class="link">Live Demo</a>
                <a href="/public/login.php" class="link"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
                <a href="#signup" class="btn-accent" style="padding:.55rem 1.1rem;font-size:.92rem;">Start Free Trial</a>
            </div>
        </div>
    </div>
</div>

<a id="top"></a>

<!-- ===================== HERO ===================== -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="pill"><i class="bi bi-lightning-charge-fill"></i> Set up in minutes — no installation</span>
                <h1>Run your property business <span style="color:var(--brand)">online</span>, from one dashboard.</h1>
                <p class="lead mt-3">Fabonic Real Estate Management System helps landlords and property managers track units, tenants, leases, and rent — with automatic WhatsApp reminders and receipts. Register below, pick your subdomain, and your system is auto-provisioned instantly.</p>
                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a href="#signup" class="btn-accent"><i class="bi bi-rocket-takeoff me-2"></i>Start 14-Day Free Trial</a>
                    <a href="#demo" class="btn-ghost"><i class="bi bi-play-circle me-2"></i>Try the Live Demo</a>
                </div>
                <div class="stat-row">
                    <div class="stat"><b>14 days</b><span>Free trial, no card</span></div>
                    <div class="stat"><b>~2 min</b><span>Auto setup time</span></div>
                    <div class="stat"><b>Your own</b><span>subdomain &amp; login</span></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="hero-card">
                    <div class="browser-bar">
                        <span class="dot" style="background:#ff5f57"></span>
                        <span class="dot" style="background:#febc2e"></span>
                        <span class="dot" style="background:#28c840"></span>
                        <span class="browser-url"><i class="bi bi-lock-fill me-1"></i>yourcompany.real.co.tz</span>
                    </div>
                    <img src="https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=1200&q=70"
                         alt="Property dashboard preview" style="width:100%;border-radius:12px;display:block;"
                         onerror="this.style.display='none'">
                    <div class="row g-2 mt-1">
                        <div class="col-4"><div style="background:var(--brand-light);border-radius:10px;padding:.7rem;text-align:center;"><b style="color:var(--brand);font-size:1.2rem;">128</b><div style="font-size:.72rem;color:var(--muted)">Units</div></div></div>
                        <div class="col-4"><div style="background:#e9f9ef;border-radius:10px;padding:.7rem;text-align:center;"><b style="color:#16a34a;font-size:1.2rem;">94%</b><div style="font-size:.72rem;color:var(--muted)">Occupancy</div></div></div>
                        <div class="col-4"><div style="background:#fff4e0;border-radius:10px;padding:.7rem;text-align:center;"><b style="color:var(--accent-dark);font-size:1.2rem;">TZS 8.4M</b><div style="font-size:.72rem;color:var(--muted)">Collected</div></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== HOW IT WORKS ===================== -->
<section class="section" id="how">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow">Get started</div>
            <h2>Three steps to your own system</h2>
            <p class="sub">No downloads, no servers to manage. Fill one form and we do the rest — automatically.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="step">
                    <div class="step-num">1</div>
                    <h4>Fill the registration form</h4>
                    <p>Tell us your company name, how many units you manage, and pick your subdomain (e.g. <strong>acme.real.co.tz</strong>). Takes under a minute.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-num">2</div>
                    <h4>We auto-provision instantly</h4>
                    <p>Your subdomain, database, and secure admin account are created automatically — a private system just for your business, isolated from everyone else.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-num">3</div>
                    <h4>Get your login by email</h4>
                    <p>You receive an email &amp; WhatsApp: <em>"Your setup is ready"</em> — with your link, username, and password. Log in and start managing.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== FEATURES ===================== -->
<section class="section section-soft" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow">Everything included</div>
            <h2>Built for property management in Tanzania</h2>
            <p class="sub">All the tools you need to manage properties, collect rent, and keep tenants happy — in one place.</p>
        </div>
        <div class="row g-3">
            <?php
            $features = [
                ['bi-buildings', 'Properties &amp; Units', 'Organise buildings, apartments, shops, offices and plots with per-unit details and status.'],
                ['bi-people', 'Tenant Management', 'Full tenant records, documents, and a self-service tenant portal.'],
                ['bi-file-earmark-text', 'Leases &amp; Contracts', 'Create leases, track start/end dates, renewals and deposits automatically.'],
                ['bi-cash-coin', 'Rent &amp; Payments', 'Record payments, generate receipts, and see who has paid at a glance.'],
                ['bi-whatsapp', 'WhatsApp Reminders', 'Automated rent reminders and confirmations sent straight to tenants.'],
                ['bi-tools', 'Maintenance Requests', 'Log and track repairs from request to resolution with caretaker assignments.'],
                ['bi-graph-up-arrow', 'Reports &amp; Insights', 'Occupancy, arrears, income and expense reports with clear dashboards.'],
                ['bi-shield-lock', 'Roles &amp; Permissions', 'Give managers, accountants and caretakers exactly the access they need.'],
                ['bi-phone', 'Works on Mobile', 'Installable web app — manage your properties from your phone, anywhere.'],
            ];
            foreach ($features as $f): ?>
            <div class="col-md-6 col-lg-4">
                <div class="feature">
                    <div class="feature-ico"><i class="bi <?php echo $f[0]; ?>"></i></div>
                    <h5><?php echo $f[1]; ?></h5>
                    <p><?php echo $f[2]; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== PRICING ===================== -->
<section class="section" id="pricing">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow">Simple pricing</div>
            <h2>Pay only for the units you manage</h2>
            <p class="sub">Start with a 14-day free trial on the Professional plan. After that, transparent per-unit monthly pricing. No hidden fees.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-lg-4 col-md-6">
                <div class="price-card">
                    <h4>Basic</h4>
                    <p class="text-muted-2 mb-3" style="font-size:.9rem;">For small landlords getting started.</p>
                    <div class="price-amt">TZS 9,999<span style="font-size:1rem;color:var(--muted);font-weight:500;">/unit/mo</span></div>
                    <ul>
                        <li><i class="bi bi-check-circle-fill"></i> Properties, units &amp; tenants</li>
                        <li><i class="bi bi-check-circle-fill"></i> Leases &amp; rent tracking</li>
                        <li><i class="bi bi-check-circle-fill"></i> Receipts &amp; basic reports</li>
                        <li><i class="bi bi-check-circle-fill"></i> Email support</li>
                    </ul>
                    <a href="#signup" class="btn-ghost w-100 mt-4 d-block text-center">Start Free Trial</a>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="price-card featured">
                    <span class="ribbon">MOST POPULAR</span>
                    <h4>Professional</h4>
                    <p class="text-muted-2 mb-3" style="font-size:.9rem;">Everything you need to run at scale.</p>
                    <div class="price-amt">TZS 14,999<span style="font-size:1rem;color:var(--muted);font-weight:500;">/unit/mo</span></div>
                    <ul>
                        <li><i class="bi bi-check-circle-fill"></i> Everything in Basic</li>
                        <li><i class="bi bi-check-circle-fill"></i> WhatsApp reminders</li>
                        <li><i class="bi bi-check-circle-fill"></i> Tenant portal</li>
                        <li><i class="bi bi-check-circle-fill"></i> Advanced reports</li>
                    </ul>
                    <a href="#signup" class="btn-brand w-100 mt-4 d-block text-center">Start Free Trial</a>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="price-card">
                    <h4>Enterprise</h4>
                    <p class="text-muted-2 mb-3" style="font-size:.9rem;">For large portfolios &amp; agencies.</p>
                    <div class="price-amt">TZS 19,999<span style="font-size:1rem;color:var(--muted);font-weight:500;">/unit/mo</span></div>
                    <ul>
                        <li><i class="bi bi-check-circle-fill"></i> Everything in Professional</li>
                        <li><i class="bi bi-check-circle-fill"></i> Maintenance tracking</li>
                        <li><i class="bi bi-check-circle-fill"></i> Utility billing (water &amp; electricity)</li>
                        <li><i class="bi bi-check-circle-fill"></i> Priority support</li>
                        <li><i class="bi bi-check-circle-fill"></i> Custom roles &amp; workflows</li>
                        <li><i class="bi bi-check-circle-fill"></i> Onboarding assistance</li>
                    </ul>
                    <a href="#signup" class="btn-ghost w-100 mt-4 d-block text-center">Start Free Trial</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== DEMO ===================== -->
<section class="section section-soft" id="demo">
    <div class="container">
        <div class="demo-band">
            <div class="pill" style="background:rgba(255,255,255,.15);color:#fff;"><i class="bi bi-eye"></i> No sign-up needed</div>
            <h2>Take it for a spin — live demo</h2>
            <p>Explore a fully-loaded sample account with properties, tenants, leases and payments already set up. See exactly how it works before you register.</p>
            <a href="https://demo.real.co.tz" target="_blank" rel="noopener" class="btn-accent"><i class="bi bi-box-arrow-up-right me-2"></i>Open Live Demo</a>
            <p class="mt-3 mb-0" style="font-size:.82rem;color:rgba(255,255,255,.65);">demo.real.co.tz &middot; login: <strong>demo</strong> / <strong>demo1234</strong> &middot; resets daily</p>
        </div>
    </div>
</section>

<!-- ===================== SIGNUP ===================== -->
<section class="section" id="signup">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <div class="eyebrow">Start free</div>
                <h2>Create your account</h2>
                <p class="text-muted-2" style="font-size:1.05rem;">Fill the form and your system is provisioned automatically. Your login details arrive by email and WhatsApp within minutes.</p>
                <ul class="list-unstyled mt-4">
                    <li class="mb-2"><i class="bi bi-check-circle-fill me-2" style="color:#16a34a;"></i> Full Professional tier free for 14 days</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill me-2" style="color:#16a34a;"></i> Your own private subdomain &amp; database</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill me-2" style="color:#16a34a;"></i> No credit card, cancel anytime</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill me-2" style="color:#16a34a;"></i> Set up automatically — no technical skills needed</li>
                </ul>
            </div>
            <div class="col-lg-7">
                <div class="signup-card">
                    <form id="trialForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="hp-field">
                            <label for="website">Website</label>
                            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Company / Property Business Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="company_name" id="companyName" placeholder="e.g. Mwangaza Properties" required maxlength="200" autocomplete="organization">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Your Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" id="fullName" placeholder="e.g. John Mwakijembe" required maxlength="150" autocomplete="name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="phone" id="phone" placeholder="e.g. 0786 110 021" required maxlength="20" autocomplete="tel">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" id="email" placeholder="e.g. john@example.com" maxlength="150" autocomplete="email">
                                <div class="form-text">We'll send your login link, username &amp; password here.</div>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label">Choose Your Subdomain <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="preferred_slug" id="preferredSlug" placeholder="mycompany" maxlength="20" pattern="[a-z0-9]+" autocomplete="off" required>
                                    <span class="input-group-text">.real.co.tz</span>
                                </div>
                                <div class="slug-preview" id="slugStatus"></div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Number of Units <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="unit_count" id="unitCount" value="10" min="1" max="10000" required>
                                <div class="form-text">Rooms, shops, apartments, etc.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">City</label>
                                <select class="form-select" name="city" id="city">
                                    <option value="">Select city...</option>
                                    <option>Dar es Salaam</option><option>Arusha</option><option>Mwanza</option>
                                    <option>Dodoma</option><option>Mbeya</option><option>Morogoro</option>
                                    <option>Tanga</option><option>Zanzibar</option><option>Iringa</option>
                                    <option>Kigoma</option><option>Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">How did you hear about us?</label>
                                <select class="form-select" name="referral_source" id="referralSource">
                                    <option value="">Select...</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="google">Google Search</option>
                                    <option value="social_media">Social Media</option>
                                    <option value="friend">Friend / Colleague</option>
                                    <option value="sales_agent">Sales Agent</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="col-12 mt-2">
                                <button type="submit" class="btn-brand w-100" id="submitBtn" style="padding:.85rem;font-size:1.05rem;">
                                    <i class="bi bi-rocket-takeoff me-2"></i>Create My System — Start Free Trial
                                </button>
                            </div>
                            <div class="col-12">
                                <p class="text-center trust-line mb-0">
                                    <i class="bi bi-shield-check me-1"></i>By submitting you agree to receive WhatsApp/email about your trial. Opt out anytime.
                                </p>
                            </div>
                        </div>
                    </form>

                    <!-- Success -->
                    <div class="success-box d-none" id="successMessage">
                        <div class="success-ico"><i class="bi bi-check-lg"></i></div>
                        <h3 style="color:var(--brand);">You're all set! 🎉</h3>
                        <p class="text-muted-2">Your system is being provisioned right now. Within a few minutes you'll receive your login link, username, and password by <strong>email and WhatsApp</strong>.</p>
                        <div style="background:var(--bg-soft);border-radius:12px;padding:1.25rem;text-align:left;max-width:420px;margin:1.25rem auto 0;">
                            <h6 style="font-weight:700;margin-bottom:.6rem;">What happens next:</h6>
                            <ol style="padding-left:1.2rem;margin:0;color:var(--muted);font-size:.92rem;">
                                <li class="mb-1">Your subdomain &amp; database are created automatically</li>
                                <li class="mb-1">An admin account is set up for you</li>
                                <li class="mb-1">Login details are sent to you</li>
                                <li>Log in and start managing your properties!</li>
                            </ol>
                        </div>
                        <a href="#top" class="btn-ghost mt-4 d-inline-block">Back to top</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== FOOTER ===================== -->
<footer class="footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <img src="/public/assets/logo.png" alt="Fabonic" class="logo-inv mb-3">
                <p style="font-size:.92rem;max-width:320px;">Fabonic Real Estate Management System — online property management for landlords and agencies across Tanzania.</p>
            </div>
            <div class="col-lg-2 col-6">
                <h6>Product</h6>
                <div class="d-flex flex-column gap-2" style="font-size:.9rem;">
                    <a href="#how">How it works</a>
                    <a href="#features">Features</a>
                    <a href="#pricing">Pricing</a>
                    <a href="#demo">Live Demo</a>
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <h6>Account</h6>
                <div class="d-flex flex-column gap-2" style="font-size:.9rem;">
                    <a href="#signup">Start Free Trial</a>
                    <a href="/public/login.php">Login</a>
                </div>
            </div>
            <div class="col-lg-4">
                <h6>Contact</h6>
                <div class="d-flex flex-column gap-2" style="font-size:.9rem;">
                    <a href="https://wa.me/255786110021"><i class="bi bi-whatsapp me-2"></i>+255 786 110 021</a>
                    <a href="mailto:info@real.co.tz"><i class="bi bi-envelope me-2"></i>info@real.co.tz</a>
                    <a href="https://real.co.tz"><i class="bi bi-globe me-2"></i>real.co.tz</a>
                </div>
            </div>
        </div>
        <div class="footer-bottom d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?php echo $year; ?> Fabonic. All rights reserved.</span>
            <span>Powered by <strong style="color:#fff;">Fabonic</strong> — it's about IT, Web &amp; Apps</span>
        </div>
    </div>
</footer>

<!-- Floating WhatsApp -->
<a href="https://wa.me/255786110021" target="_blank" rel="noopener"
   style="position:fixed;bottom:22px;right:22px;width:56px;height:56px;background:#25d366;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(37,211,102,.45);z-index:999;">
    <i class="bi bi-whatsapp" style="color:#fff;font-size:1.7rem;"></i>
</a>

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

    // Auto-suggest subdomain from company name
    companyInput.addEventListener('input', function() {
        if (!slugInput.dataset.touched && this.value.length >= 3) {
            const auto = this.value.toLowerCase().replace(/[^a-z0-9]/g, '').substring(0, 20);
            slugInput.value = auto;
            if (auto.length >= 3) checkSlug(auto);
        }
    });

    slugInput.addEventListener('input', function() {
        this.dataset.touched = '1';
        let val = this.value.toLowerCase().replace(/[^a-z0-9]/g, '');
        this.value = val;
        clearTimeout(slugTimer);
        if (val.length >= 3) {
            slugStatus.textContent = 'Checking availability…';
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
        fetch('/api/check-slug.php?slug=' + encodeURIComponent(slug))
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

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const companyName = form.company_name.value.trim();
        const fullName = form.full_name.value.trim();
        const phone = form.phone.value.trim();
        const slug = slugInput.value.trim();
        const unitCount = parseInt(form.unit_count.value) || 0;

        if (!companyName || !fullName || !phone || unitCount < 1) {
            alert('Please fill in all required fields.'); return;
        }
        if (slug.length < 3) {
            alert('Please choose a subdomain (at least 3 letters/numbers).'); slugInput.focus(); return;
        }
        if (phone.replace(/[^0-9]/g, '').length < 9) {
            alert('Please enter a valid phone number.'); return;
        }
        if (slugAvailable === false) {
            alert('That subdomain is not available. Please choose another one.'); slugInput.focus(); return;
        }
        if (form.website.value) return; // honeypot

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating your system…';

        fetch('/api/send-trial-request.php', { method: 'POST', body: new FormData(form) })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    form.classList.add('d-none');
                    successMsg.classList.remove('d-none');
                    successMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    alert(data.message || 'Something went wrong. Please try again.');
                    resetBtn();
                }
            })
            .catch(() => { alert('Network error. Please check your connection and try again.'); resetBtn(); });
    });

    function resetBtn() {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-rocket-takeoff me-2"></i>Create My System — Start Free Trial';
    }

    // Mobile nav toggle
    const navToggle = document.getElementById('navToggle');
    const navLinks = document.getElementById('navLinks');
    navToggle.addEventListener('click', function() {
        const open = navLinks.classList.toggle('open');
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        navToggle.innerHTML = open ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
    });
    function closeMenu() {
        navLinks.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.innerHTML = '<i class="bi bi-list"></i>';
    }

    // Smooth scroll for in-page anchors (and close the mobile menu on click)
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function(e) {
            const id = this.getAttribute('href');
            if (id.length > 1) {
                const el = document.querySelector(id);
                if (el) { e.preventDefault(); el.scrollIntoView({ behavior: 'smooth' }); }
            }
            closeMenu();
        });
    });
    // Close the menu when tapping the Login link too
    navLinks.querySelectorAll('a:not([href^="#"])').forEach(a => a.addEventListener('click', closeMenu));
})();
</script>
</body>
</html>
