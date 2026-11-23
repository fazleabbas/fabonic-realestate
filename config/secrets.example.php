<?php
/**
 * Copy to config/secrets.php and fill in. NEVER commit config/secrets.php.
 *
 * On the server, deploy/make-secrets.php fills it from the keys in the live
 * scripts. A key left empty disables that web address (cron still works).
 */
define('RE_DEMO_RESET_KEY', '');
define('DEMO_DB_PASS', '');
define('DEMO_ADMIN_PASS', '');
define('RUN_TENANT_CRONS_KEY', '');
define('APPLY_TENANT_SQL_KEY', '');
define('REPAIR_KEY', '');
define('SEED_INSPECTION_TEMPLATES_KEY', '');
define('SEED_LEASE_TEMPLATES_KEY', '');
define('AUTO_PROVISION_KEY', '');
define('DRIP_CRON_KEY', '');
define('PROVISION_KEY', '');
define('TRIAL_CRON_KEY', '');
define('WA_CALLBACK_KEY', '');
define('TEST_MAIL_KEY', '');
define('TEST_CPANEL_KEY', '');
