-- ============================================================
-- FIS Real Estate — Part 4: Drip Campaign Schema Updates
-- Run against central DB: fiscotz_propmanage
-- ============================================================

USE `fiscotz_propmanage`;

-- ============================================================
-- Drip Campaigns: add language, skip_weekends, approval_date
-- ============================================================
ALTER TABLE `drip_campaigns_re`
    ADD COLUMN IF NOT EXISTS `language` ENUM('sw','en') NOT NULL DEFAULT 'sw' AFTER `name`,
    ADD COLUMN IF NOT EXISTS `skip_weekends` TINYINT(1) NOT NULL DEFAULT 0 AFTER `language`,
    ADD COLUMN IF NOT EXISTS `approval_date` DATE DEFAULT NULL AFTER `skip_weekends`;

-- ============================================================
-- Drip Messages: add retry_count, last_error, delivered_at, callback_data
-- ============================================================
ALTER TABLE `drip_messages_re`
    ADD COLUMN IF NOT EXISTS `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `delivery_status`,
    ADD COLUMN IF NOT EXISTS `last_error` TEXT DEFAULT NULL AFTER `retry_count`,
    ADD COLUMN IF NOT EXISTS `delivered_at` DATETIME DEFAULT NULL AFTER `last_error`,
    ADD COLUMN IF NOT EXISTS `callback_data` TEXT DEFAULT NULL AFTER `delivered_at`;

-- ============================================================
-- Drip Message Queue (rate-limited outbound queue)
-- ============================================================
CREATE TABLE IF NOT EXISTS `drip_message_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT UNSIGNED DEFAULT NULL,
    `message_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK to drip_messages_re.id',
    `phone` VARCHAR(20) NOT NULL,
    `message` TEXT NOT NULL,
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1=highest, 10=lowest',
    `status` ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
    `last_attempt_at` DATETIME DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `error` TEXT DEFAULT NULL,
    `response_data` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`, `created_at`),
    INDEX `idx_campaign` (`campaign_id`),
    INDEX `idx_message` (`message_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Drip Templates (admin-editable message templates)
-- ============================================================
CREATE TABLE IF NOT EXISTS `drip_templates_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `step_number` INT UNSIGNED NOT NULL,
    `language` ENUM('sw','en') NOT NULL DEFAULT 'sw',
    `subject` VARCHAR(200) DEFAULT NULL COMMENT 'Short label for admin reference',
    `message_body` TEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_step_lang` (`step_number`, `language`)
) ENGINE=InnoDB;

-- ============================================================
-- Insert default Swahili templates (matching existing buildDripMessage)
-- ============================================================
INSERT INTO `drip_templates_re` (`step_number`, `language`, `subject`, `message_body`) VALUES
(1, 'sw', 'Karibu - Day 0',
'Habari {name}! 🏠\n\nKaribu FIS Real Estate — mfumo wa kisasa wa kusimamia mali zako.\n\nAkaunti yako ya majaribio imeanzishwa kwa siku 14 (hadi {trial_end_date}).\n\nIngia hapa: {login_url}\n\nKatika siku zijazo, tutakuonyesha jinsi FIS Real Estate inavyoweza kukusaidia:\n✅ Kusimamia mali na vyumba vyako\n✅ Kufuatilia wapangaji na mikataba\n✅ Kukusanya kodi kwa urahisi\n✅ Kupata ripoti za kifedha\n\nKama una swali lolote, jibu ujumbe huu!\n\n— Timu ya FIS Real Estate'),

(2, 'sw', 'Kuanza - Day 1',
'Habari {name}! 👋\n\nJe, umeshaingia kwenye akaunti yako? Hapa kuna hatua 3 za kuanza:\n\n1️⃣ Ongeza mali yako ya kwanza (jengo/nyumba)\n2️⃣ Ongeza vyumba/nafasi za kupangisha\n3️⃣ Sajili wapangaji wako\n\nInachukua dakika 5 tu! ⏱️\n\nIngia sasa: {login_url}\n\n— Timu ya FIS'),

(3, 'sw', 'Mikataba & Kodi - Day 3',
'Habari {name}! 📋\n\nJe, unajua FIS Real Estate inaweza kukutengenezea mikataba ya upangaji (PDF) kwa sekunde chache?\n\n✅ Chagua template ya mkataba\n✅ Jaza maelezo ya mpangaji\n✅ Mfumo unaunda PDF moja kwa moja\n✅ Tuma kwa mpangaji kupitia WhatsApp au email\n\nPia, kila mpangaji anapolipa kodi, risiti inatengenezwa moja kwa moja! 🧾\n\nJaribu sasa: {login_url}\n\n— Timu ya FIS'),

(4, 'sw', 'Vikumbusho - Day 5',
'Habari {name}! ⏰\n\nWapangaji wako wanacheleweshwa kulipa kodi? FIS Real Estate inaweza kusaidia:\n\n📱 Tuma vikumbusho vya kodi kupitia WhatsApp kabla ya tarehe ya malipo\n📱 Tuma taarifa ya ucheleweshaji kiotomatiki\n📱 Fuatilia malipo yote kwa urahisi\n\nHakuna tena kufuatilia wapangaji kwa simu!\n\nAngalia jinsi inavyofanya kazi: {login_url}\n\n— Timu ya FIS'),

(5, 'sw', 'Nusu ya Majaribio - Day 7',
'Habari {name}! 📊\n\nUmefika nusu ya majaribio yako! Umeshajaribu vitu hivi?\n\n☐ Kuongeza mali na vyumba\n☐ Kusajili wapangaji\n☐ Kutengeneza mkataba wa PDF\n☐ Kurekodi malipo ya kodi\n☐ Kuangalia dashibodi ya takwimu\n\nKama hujafanya bado, ingia sasa na jaribu — bure kabisa hadi {trial_end_date}!\n\n{login_url}\n\nUna swali? Jibu ujumbe huu! 💬\n\n— Timu ya FIS'),

(6, 'sw', 'Vipengele vya Pro - Day 9',
'Habari {name}! 🌟\n\nUkijua kuwa na FIS Real Estate Professional, unaweza:\n\n🏠 Wapangaji wako wanaingia wenyewe kuangalia bakaa na risiti\n📱 Vikumbusho vya kodi vinatumwa kiotomatiki\n🧾 Risiti zinatumwa WhatsApp moja kwa moja\n\nNa Enterprise unapata:\n⚡ Bili za umeme na maji kiotomatiki\n🔧 Mfumo wa matengenezo\n📈 Ripoti za faida na hasara\n\nUnaweza kuanzia 1,000 TZS tu kwa chumba kwa mwezi!\n\nAngalia bei zote: {demo_url}/#pricing\n\n— Timu ya FIS'),

(7, 'sw', 'Ushuhuda - Day 11',
'Habari {name}! 🗣️\n\nWatumiaji wetu wanasema nini:\n\n"Zamani nilikuwa natumia daftari kufuatilia wapangaji 50. Sasa FIS Real Estate inafanya kazi yote — kodi, risiti, vikumbusho. Nimeokoa masaa mengi!"\n— Mpangishaji, Dar es Salaam\n\n"Mfumo wa mikataba ya PDF ni wa kushangaza. Wapangaji wangu wanapokea mkataba wa kitaalamu ndani ya dakika."\n— Kampuni ya Mali, Arusha\n\nJaribu mwenyewe: {login_url}\n\nSiku 3 tu zimebaki za majaribio! ⏳\n\n— Timu ya FIS'),

(8, 'sw', 'Haraka - Day 13',
'Habari {name}! ⚠️\n\nMajaribio yako ya FIS Real Estate yanaisha KESHO ({trial_end_date})!\n\nUsipoteze data na mipangilio yako — endelea na usajili:\n\n💰 Basic: 1,000 TZS/chumba/mwezi — Vipengele vyote vya msingi\n💰 Professional: 1,800 TZS/chumba/mwezi — + Portal ya wapangaji + Vikumbusho\n💰 Enterprise: 2,500 TZS/chumba/mwezi — Vipengele vyote!\n\nKwa vyumba {units}: kuanzia {units} x 1,000 = {basic_total} TZS/mwezi tu!\n\nLipa sasa na uendelee: {login_url}/modules/subscription/subscribe.php\n\n— Timu ya FIS'),

(9, 'sw', 'Muda Umeisha - Day 14',
'Habari {name}! 🔒\n\nMajaribio yako ya FIS Real Estate yameisha leo.\n\nData yako BADO iko salama kwa siku 30 — lakini huwezi kufikia mfumo hadi ulipe.\n\nLipa sasa na uendelee kusimamia mali zako kwa urahisi:\n{login_url}/modules/subscription/subscribe.php\n\nKama una swali kuhusu bei au unahitaji msaada, jibu ujumbe huu.\n\nTunaweza kukusaidia! 🤝\n\n— Timu ya FIS'),

(10, 'sw', 'Ufuatiliaji - Day 17',
'Habari {name}! 👋\n\nBado tunasubiri kurudi kwako! Akaunti yako ya FIS Real Estate bado ipo.\n\nJe, kuna kitu kilikuzuia? Tuambie:\n\n1️⃣ Bei ni kubwa sana\n2️⃣ Sijapata muda wa kujaribu vizuri\n3️⃣ Ninahitaji vipengele vingine\n4️⃣ Ninatumia mfumo mwingine\n\nJibu na nambari au maelezo yako — tutakusaidia! 💬\n\n— Timu ya FIS'),

(11, 'sw', 'Mwisho - Day 21',
'Habari {name}!\n\nHuu ni ujumbe wetu wa mwisho kuhusu majaribio yako ya FIS Real Estate.\n\nTukumbuke: Data yako itahifadhiwa kwa siku 30 tangu majaribio yameisha. Baada ya hapo, itafutwa.\n\nUkitaka kuendelea: {login_url}/modules/subscription/subscribe.php\n\nUkibadilisha mawazo yako baadaye, unaweza kuanza upya wakati wowote kwenye: {demo_url}\n\nAsante kwa kujaribu FIS Real Estate! 🙏\n\n— Timu ya FIS')
ON DUPLICATE KEY UPDATE `message_body` = VALUES(`message_body`), `subject` = VALUES(`subject`);

-- ============================================================
-- Insert English templates
-- ============================================================
INSERT INTO `drip_templates_re` (`step_number`, `language`, `subject`, `message_body`) VALUES
(1, 'en', 'Welcome - Day 0',
'Hello {name}! 🏠\n\nWelcome to FIS Real Estate — the modern property management system.\n\nYour 14-day trial account is ready (expires {trial_end_date}).\n\nLogin here: {login_url}\n\nOver the coming days, we''ll show you how FIS Real Estate can help you:\n✅ Manage your properties and units\n✅ Track tenants and leases\n✅ Collect rent effortlessly\n✅ Get financial reports\n\nReply to this message if you have any questions!\n\n— FIS Real Estate Team'),

(2, 'en', 'Getting Started - Day 1',
'Hello {name}! 👋\n\nHave you logged into your account yet? Here are 3 steps to get started:\n\n1️⃣ Add your first property (building/house)\n2️⃣ Add units/rooms for rent\n3️⃣ Register your tenants\n\nIt only takes 5 minutes! ⏱️\n\nLogin now: {login_url}\n\n— FIS Team'),

(3, 'en', 'Leases & Rent - Day 3',
'Hello {name}! 📋\n\nDid you know FIS Real Estate can generate lease agreements (PDF) in seconds?\n\n✅ Choose a lease template\n✅ Fill in tenant details\n✅ System generates PDF automatically\n✅ Send to tenant via WhatsApp or email\n\nPlus, every time a tenant pays rent, a receipt is generated automatically! 🧾\n\nTry it now: {login_url}\n\n— FIS Team'),

(4, 'en', 'Reminders & Automation - Day 5',
'Hello {name}! ⏰\n\nTenants paying rent late? FIS Real Estate can help:\n\n📱 Send rent reminders via WhatsApp before the due date\n📱 Auto-send overdue notices\n📱 Track all payments easily\n\nNo more chasing tenants by phone!\n\nSee how it works: {login_url}\n\n— FIS Team'),

(5, 'en', 'Mid-Trial Check-in - Day 7',
'Hello {name}! 📊\n\nYou''re halfway through your trial! Have you tried these features?\n\n☐ Adding properties and units\n☐ Registering tenants\n☐ Generating lease PDFs\n☐ Recording rent payments\n☐ Viewing the analytics dashboard\n\nIf not, log in now and try — completely free until {trial_end_date}!\n\n{login_url}\n\nQuestions? Reply to this message! 💬\n\n— FIS Team'),

(6, 'en', 'Professional Features - Day 9',
'Hello {name}! 🌟\n\nWith FIS Real Estate Professional, you can:\n\n🏠 Tenants log in themselves to check balance and receipts\n📱 Rent reminders sent automatically\n🧾 Receipts sent via WhatsApp instantly\n\nWith Enterprise you get:\n⚡ Automatic utility billing (water & electricity)\n🔧 Maintenance management system\n📈 Profit & loss reports\n\nStarting from just 1,000 TZS per unit per month!\n\nSee all pricing: {demo_url}/#pricing\n\n— FIS Team'),

(7, 'en', 'Testimonials - Day 11',
'Hello {name}! 🗣️\n\nHere''s what our users say:\n\n"I used to track 50 tenants in a notebook. Now FIS Real Estate does everything — rent, receipts, reminders. Saved me so many hours!"\n— Landlord, Dar es Salaam\n\n"The PDF lease system is amazing. My tenants receive professional agreements in minutes."\n— Property Company, Arusha\n\nTry it yourself: {login_url}\n\nOnly 3 days left in your trial! ⏳\n\n— FIS Team'),

(8, 'en', 'Urgency - Day 13',
'Hello {name}! ⚠️\n\nYour FIS Real Estate trial expires TOMORROW ({trial_end_date})!\n\nDon''t lose your data and settings — subscribe now:\n\n💰 Basic: 1,000 TZS/unit/month — All core features\n💰 Professional: 1,800 TZS/unit/month — + Tenant portal + Reminders\n💰 Enterprise: 2,500 TZS/unit/month — All features!\n\nFor {units} units: starting at {units} x 1,000 = {basic_total} TZS/month!\n\nSubscribe now: {login_url}/modules/subscription/subscribe.php\n\n— FIS Team'),

(9, 'en', 'Trial Expired - Day 14',
'Hello {name}! 🔒\n\nYour FIS Real Estate trial has ended today.\n\nYour data is STILL safe for 30 days — but you can''t access the system until you pay.\n\nPay now and continue managing your properties effortlessly:\n{login_url}/modules/subscription/subscribe.php\n\nIf you have questions about pricing or need help, reply to this message.\n\nWe''re here to help! 🤝\n\n— FIS Team'),

(10, 'en', 'Follow Up - Day 17',
'Hello {name}! 👋\n\nWe''re still waiting to hear back from you! Your FIS Real Estate account is still there.\n\nWhat held you back? Tell us:\n\n1️⃣ Price is too high\n2️⃣ Didn''t have time to try it properly\n3️⃣ Need different features\n4️⃣ Using another system\n\nReply with a number or your feedback — we''ll help! 💬\n\n— FIS Team'),

(11, 'en', 'Final Follow Up - Day 21',
'Hello {name}!\n\nThis is our last message about your FIS Real Estate trial.\n\nRemember: Your data will be kept for 30 days after the trial ended. After that, it will be deleted.\n\nTo continue: {login_url}/modules/subscription/subscribe.php\n\nIf you change your mind later, you can start fresh anytime at: {demo_url}\n\nThank you for trying FIS Real Estate! 🙏\n\n— FIS Team')
ON DUPLICATE KEY UPDATE `message_body` = VALUES(`message_body`), `subject` = VALUES(`subject`);

-- ============================================================
-- System settings for drip configuration
-- ============================================================
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('drip_default_language', 'sw', 'Default language for drip campaigns (sw/en)'),
('drip_skip_weekends', '0', 'Skip weekend days for drip sending (1=yes, 0=no)'),
('drip_send_hour', '9', 'Hour of day (EAT) to send drip messages (0-23)'),
('drip_max_retries', '3', 'Max retry attempts for failed drip messages'),
('drip_rate_limit', '30', 'Max WhatsApp messages per minute'),
('drip_enabled', '1', 'Enable/disable drip campaigns globally')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
