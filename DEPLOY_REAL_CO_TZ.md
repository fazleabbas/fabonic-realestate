# Deploying Fabonic Real Estate to real.co.tz

This is the production go-live checklist for the SaaS self-registration +
auto-provisioning system on **real.co.tz** (cPanel).

Flow recap: visitor fills the form on **real.co.tz** → row lands in the central
`trial_requests` table → the auto-provision cron picks it up → a new
**`<slug>.real.co.tz`** subdomain + database + admin user are created
automatically → the customer gets an email + WhatsApp with their login.

---

## 0. What changed in the code (already done)

| Area | Change |
|------|--------|
| Landing page | New clean `public/index.php` (Fabonic branded, embedded signup form, live demo link). Old one kept as `public/index.fis.bak.php`. |
| Login helper | New `public/login.php` — subdomain picker that forwards to `<slug>.real.co.tz`. |
| Subdomains | Dropped the `re_` prefix → tenants are `acme.real.co.tz`. DB names still get an internal `re_` prefix to avoid clashes. |
| Branding | "FIS Real Estate" → **Fabonic Real Estate**, `fis.co.tz` → `real.co.tz`, navy `#003b7a` theme. |
| Demo | `sql/demo_seed.sql` + `api/reset-demo.php` (nightly reset). |

---

## 1. DNS (registrar / Cloudflare)

Point these at the server IP:

| Record | Type | Value |
|--------|------|-------|
| `real.co.tz` | A | server IP |
| `www.real.co.tz` | CNAME | `real.co.tz` |
| `app.real.co.tz` | A | server IP (operator / SaaS admin panel) |
| `demo.real.co.tz` | A | server IP |
| **`*.real.co.tz`** | A | server IP  ← **wildcard, required** so new tenant subdomains resolve without touching DNS again |

> If using Cloudflare, set the wildcard record to **DNS only (grey cloud)** unless
> you have a wildcard/Advanced Certificate — otherwise tenant HTTPS will break.

**SSL:** enable **AutoSSL** in cPanel (or a Let's Encrypt **wildcard** cert for
`*.real.co.tz`) so every tenant subdomain is HTTPS.

---

## 2. Server / cPanel layout

Under the cPanel account (assumed user `fiscotz` — adjust everywhere if different):

```
/home/fiscotz/
├── real.co.tz/                → docroot for real.co.tz  (put public/ contents here: the LANDING PAGE)
├── app.real.co.tz/            → the operator master app (this whole repo)  [BASE_URL target]
├── re_tenant_template/        → a clean copy of the tenant app (what each new tenant is cloned from)
└── public_html/re_tenants/    → one folder per provisioned tenant (auto-created)
```

- **Landing page:** make `real.co.tz`'s document root serve `public/` (so
  `https://real.co.tz/` shows `index.php`). The form posts to `../api/...`, so the
  `api/`, `config/`, `includes/`, `sql/` folders must sit one level above `public/`
  (i.e. deploy the whole repo and point the docroot at its `public/` subfolder).
- **Template:** `re_tenant_template/` is the app a tenant gets. Keep it in sync with
  releases. Ensure `sql/tenant_schema.sql` exists at the path in `RE_SCHEMA_PATH`.

---

## 3. Databases

1. Create/confirm the **central** DB (default name `fiscotz_propmanage`) and import:
   ```
   sql/central_schema.sql
   ```
2. Create a central DB **user** with privileges on it. Then in
   **`api/provision-tenant.php`** uncomment and set:
   ```php
   define('CENTRAL_DB_USER_P', 'fiscotz_reuser');
   define('CENTRAL_DB_PASS_P', '••••••');
   ```
   (and set the same in `config/database.php` `DB_USER`/`DB_PASS` for the master app).

---

## 4. Master config — `config/database.php` (on app.real.co.tz)

Set these for production:

```php
define('DB_USER', 'fiscotz_reuser');
define('DB_PASS', '••••••');
define('DB_NAME', 'fiscotz_propmanage');       // master reads central directly
define('BASE_URL', 'https://app.real.co.tz/');
define('IS_SAAS_MASTER', true);                // operator panel visible on the master ONLY

// cPanel API (Security > Manage API Tokens in cPanel)
define('CPANEL_HOST', 'your-server-hostname');  // e.g. server1.yourhost.com
define('CPANEL_USER', 'fiscotz');
define('CPANEL_API_TOKEN', '••••••');
define('CPANEL_DOMAIN', 'real.co.tz');          // already set

define('RE_TEMPLATE_PATH', '/home/fiscotz/re_tenant_template');
define('RE_TENANTS_PATH', '/home/fiscotz/public_html/re_tenants');
define('RE_SCHEMA_PATH', '/home/fiscotz/app.real.co.tz/sql/tenant_schema.sql');
```

Also turn off error display in production (`error_reporting(0); ini_set('display_errors', 0);`).

---

## 5. Rotate the secret keys

These are currently hardcoded query-string keys — **change them** before go-live:

| File | Constant / literal | Used by |
|------|--------------------|---------|
| `api/provision-tenant.php` | `RE_PROVISION_KEY_2026` | manual provision trigger |
| `api/auto-provision-cron.php` | `RE_CRON_KEY_2026` | provisioning cron |
| `api/reset-demo.php` | `RE_DEMO_RESET_KEY_2026` | demo reset |

---

## 6. Cron jobs (cPanel → Cron Jobs)

```cron
# Auto-provision new signups — every 5 minutes
*/5 * * * * /usr/bin/php /home/fiscotz/app.real.co.tz/api/auto-provision-cron.php >/dev/null 2>&1

# Reset the public demo every night at 02:00
0 2 * * * /usr/bin/php /home/fiscotz/app.real.co.tz/api/reset-demo.php >/dev/null 2>&1
```

---

## 7. Email deliverability

The system uses PHP `mail()`. On cPanel that works, but to avoid spam folders:

- Add an **SPF** record for real.co.tz including the server.
- Enable **DKIM** for real.co.tz (cPanel → Email Deliverability).
- Create the mailboxes/aliases used as senders: `noreply@real.co.tz`,
  `info@real.co.tz`.
- *(Optional, more reliable)* swap `mail()` for PHPMailer + SMTP. Ask me and I'll wire it.

---

## 8. Set up the DEMO tenant (`demo.real.co.tz`)

One-time provisioning of the shared demo:

1. **Subdomain:** cPanel → Subdomains → create `demo` under `real.co.tz`,
   docroot `public_html/re_tenants/demo`.
2. **Files:** copy the template into it:
   `cp -rf /home/fiscotz/re_tenant_template/* /home/fiscotz/public_html/re_tenants/demo/`
3. **Database:** create DB `fiscotz_re_demo` + user (e.g. `fiscotz_redemo`), grant ALL.
   Import `sql/tenant_schema.sql` into it.
4. **Tenant config:** edit `public_html/re_tenants/demo/config/database.php` with the
   demo DB creds and `BASE_URL='https://demo.real.co.tz/'`, `DEMO_MODE=true`.
5. **Reset script creds:** edit `api/reset-demo.php` and set:
   ```php
   define('DEMO_DB_NAME', 'fiscotz_re_demo');
   define('DEMO_DB_USER', 'fiscotz_redemo');
   define('DEMO_DB_PASS', '••••••');
   ```
6. **Seed it:** run once — `php /home/fiscotz/app.real.co.tz/api/reset-demo.php`
   This loads sample properties/units/tenants/leases/payments and creates the login:
   **`demo` / `demo1234`** (matches what the landing page advertises).
7. The nightly cron (step 6 above) keeps it clean.

> The demo login shown on the landing page is `demo / demo1234`. If you change
> `DEMO_ADMIN_PASS` in `reset-demo.php`, update the text in
> `public/index.php` (the `#demo` section) to match.

---

## 9. Smoke test (end to end)

1. Visit `https://real.co.tz/` — landing loads, logo shows, demo button works.
2. `https://demo.real.co.tz/` — log in with `demo / demo1234`, data is present.
3. Fill the signup form with a test company + subdomain `testco`, 5 units.
4. Within ~5 min: `https://testco.real.co.tz/` is live; check the credentials email.
5. Log in to `testco.real.co.tz`, confirm the admin account + 14-day trial.
6. `admin/trials.php` on the master shows the request as provisioned.

---

## Notes / things to revisit

- **DB-name length:** cPanel limits DB names. `fiscotz_re_<slug>` with a 20-char slug
  can get long; the provisioner truncates the DB **user** to `fiscotz_re<6 chars>`.
  If your cPanel user name is long, shorten the slug cap in
  `api/provision-tenant.php` / `api/check-slug.php`.
- **No migration runner:** new tenants get `sql/tenant_schema.sql` only. Apply any
  `sql/partNN_*.sql` migrations to the template and to existing tenants by hand.
- **WhatsApp** still uses `wa.fis.co.tz` (unchanged infra). Update `WA_API_URL` if it moves.
- Old FIS landing preserved at `public/index.fis.bak.php` — delete once happy.
