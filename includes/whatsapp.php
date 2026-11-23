<?php
/**
 * Fabonic Real Estate — WhatsApp Integration Helpers
 * Sends messages via wa.fis.co.tz API
 * Part 4: Enhanced with DB templates, rate limiting, queue support
 */

/**
 * Format phone number for WhatsApp (Tanzania format)
 */
function formatPhoneWA($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (substr($clean, 0, 1) === '0') {
        $clean = '255' . substr($clean, 1);
    }
    if (strlen($clean) === 9) {
        $clean = '255' . $clean;
    }
    return $clean;
}

/**
 * Send a plain WhatsApp message via wa.fis.co.tz
 */
function sendWhatsApp($phone, $message) {
    $phone = formatPhoneWA($phone);

    $payload = json_encode([
        'phone' => $phone,
        'message' => $message
    ]);

    $ch = curl_init(WA_API_URL . '/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("WhatsApp send error: {$error}");
        return ['success' => false, 'error' => $error];
    }

    $decoded = json_decode($response, true);

    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'response' => $response,
        'message_id' => $decoded['id'] ?? ($decoded['message_id'] ?? null)
    ];
}

/**
 * Send WhatsApp drip message #1 (welcome/confirmation)
 * Called immediately after trial request submission
 */
function sendDripMessage1($phone, $name, $company, $unitCount) {
    $trialEndDate = date('d/m/Y', strtotime('+' . TRIAL_DAYS . ' days'));
    $demoUrl = 'https://demo.real.co.tz';

    $message = "Habari {$name}! \xF0\x9F\x8F\xA0\n\n"
        . "Asante kwa kuomba majaribio ya Fabonic Real Estate!\n\n"
        . "Tumepokea ombi lako kwa {$company} (vyumba {$unitCount}).\n\n"
        . "Timu yetu itakagua ombi lako na kukuandalia akaunti yako ndani ya masaa 24.\n\n"
        . "Utapokea:\n"
        . "\xE2\x9C\x85 Link ya kuingia\n"
        . "\xE2\x9C\x85 Username na password\n"
        . "\xE2\x9C\x85 Majaribio ya bure siku 14\n\n"
        . "Ukiwa na swali, jibu ujumbe huu!\n\n"
        . "Tembelea: {$demoUrl}\n\n"
        . "\xE2\x80\x94 Timu ya Fabonic Real Estate";

    return sendWhatsApp($phone, $message);
}

// ============================================================
// DB-backed Template System (Part 4)
// ============================================================

/**
 * Get drip message template from DB (drip_templates_re).
 * Falls back to hardcoded buildDripMessage() if DB template not found.
 *
 * @param int $stepNumber Step 1-11
 * @param string $language 'sw' or 'en'
 * @param array $placeholders Associative array of {name}, {login_url}, etc.
 * @return string|null The message with placeholders replaced, or null
 */
function getDripTemplate($stepNumber, $language, $placeholders = []) {
    $central = getCentralDb();
    if (!$central) return null;

    try {
        $stmt = $central->prepare("
            SELECT message_body FROM drip_templates_re
            WHERE step_number = ? AND language = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$stepNumber, $language]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['message_body'])) {
            $body = $row['message_body'];
            // Convert literal \n to newlines
            $body = str_replace('\\n', "\n", $body);
            // Replace placeholders
            foreach ($placeholders as $key => $val) {
                $body = str_replace('{' . $key . '}', $val, $body);
            }
            return $body;
        }
    } catch (PDOException $e) {
        error_log("getDripTemplate error: " . $e->getMessage());
    }

    return null;
}

/**
 * Build drip message by step number with placeholders replaced.
 * Tries DB template first (by language), falls back to hardcoded Swahili.
 *
 * @param int $stepNumber
 * @param string $name
 * @param string $company
 * @param int $unitCount
 * @param string $loginUrl
 * @param string $trialEndDate
 * @param string $language 'sw' or 'en'
 * @return string|null
 */
function buildDripMessage($stepNumber, $name, $company, $unitCount, $loginUrl = '', $trialEndDate = '', $language = 'sw') {
    $demoUrl = 'https://demo.real.co.tz';
    $basicTotal = number_format($unitCount * TIER_BASIC_RATE);

    // Try DB template first
    $placeholders = [
        'name' => $name,
        'company' => $company,
        'units' => $unitCount,
        'unit_count' => $unitCount,
        'login_url' => $loginUrl,
        'trial_end_date' => $trialEndDate,
        'demo_url' => $demoUrl,
        'basic_total' => $basicTotal,
    ];

    $dbMessage = getDripTemplate($stepNumber, $language, $placeholders);
    if ($dbMessage) {
        return $dbMessage;
    }

    // If requested EN but no DB template, try DB SW fallback
    if ($language === 'en') {
        $dbMessage = getDripTemplate($stepNumber, 'sw', $placeholders);
        if ($dbMessage) return $dbMessage;
    }

    // Hardcoded Swahili fallback (original from Part 3)
    $messages = [
        1 => "Habari {$name}! \xF0\x9F\x8F\xA0\n\n"
            . "Karibu Fabonic Real Estate \xE2\x80\x94 mfumo wa kisasa wa kusimamia mali zako.\n\n"
            . "Akaunti yako ya majaribio imeanzishwa kwa siku 14 (hadi {$trialEndDate}).\n\n"
            . "Ingia hapa: {$loginUrl}\n\n"
            . "Katika siku zijazo, tutakuonyesha jinsi Fabonic Real Estate inavyoweza kukusaidia:\n"
            . "\xE2\x9C\x85 Kusimamia mali na vyumba vyako\n"
            . "\xE2\x9C\x85 Kufuatilia wapangaji na mikataba\n"
            . "\xE2\x9C\x85 Kukusanya kodi kwa urahisi\n"
            . "\xE2\x9C\x85 Kupata ripoti za kifedha\n\n"
            . "Kama una swali lolote, jibu ujumbe huu!\n\n"
            . "\xE2\x80\x94 Timu ya Fabonic Real Estate",
        2 => "Habari {$name}! \xF0\x9F\x91\x8B\n\n"
            . "Je, umeshaingia kwenye akaunti yako? Hapa kuna hatua 3 za kuanza:\n\n"
            . "1\xEF\xB8\x8F\xE2\x83\xA3 Ongeza mali yako ya kwanza (jengo/nyumba)\n"
            . "2\xEF\xB8\x8F\xE2\x83\xA3 Ongeza vyumba/nafasi za kupangisha\n"
            . "3\xEF\xB8\x8F\xE2\x83\xA3 Sajili wapangaji wako\n\n"
            . "Inachukua dakika 5 tu! \xE2\x8F\xB1\xEF\xB8\x8F\n\n"
            . "Ingia sasa: {$loginUrl}\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        3 => "Habari {$name}! \xF0\x9F\x93\x8B\n\n"
            . "Je, unajua Fabonic Real Estate inaweza kukutengenezea mikataba ya upangaji (PDF) kwa sekunde chache?\n\n"
            . "\xE2\x9C\x85 Chagua template ya mkataba\n"
            . "\xE2\x9C\x85 Jaza maelezo ya mpangaji\n"
            . "\xE2\x9C\x85 Mfumo unaunda PDF moja kwa moja\n"
            . "\xE2\x9C\x85 Tuma kwa mpangaji kupitia WhatsApp au email\n\n"
            . "Pia, kila mpangaji anapolipa kodi, risiti inatengenezwa moja kwa moja! \xF0\x9F\xA7\xBE\n\n"
            . "Jaribu sasa: {$loginUrl}\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        4 => "Habari {$name}! \xE2\x8F\xB0\n\n"
            . "Wapangaji wako wanacheleweshwa kulipa kodi? Fabonic Real Estate inaweza kusaidia:\n\n"
            . "\xF0\x9F\x93\xB1 Tuma vikumbusho vya kodi kupitia WhatsApp kabla ya tarehe ya malipo\n"
            . "\xF0\x9F\x93\xB1 Tuma taarifa ya ucheleweshaji kiotomatiki\n"
            . "\xF0\x9F\x93\xB1 Fuatilia malipo yote kwa urahisi\n\n"
            . "Hakuna tena kufuatilia wapangaji kwa simu!\n\n"
            . "Angalia jinsi inavyofanya kazi: {$loginUrl}\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        5 => "Habari {$name}! \xF0\x9F\x93\x8A\n\n"
            . "Umefika nusu ya majaribio yako! Umeshajaribu vitu hivi?\n\n"
            . "\xE2\x98\x90 Kuongeza mali na vyumba\n"
            . "\xE2\x98\x90 Kusajili wapangaji\n"
            . "\xE2\x98\x90 Kutengeneza mkataba wa PDF\n"
            . "\xE2\x98\x90 Kurekodi malipo ya kodi\n"
            . "\xE2\x98\x90 Kuangalia dashibodi ya takwimu\n\n"
            . "Kama hujafanya bado, ingia sasa na jaribu \xE2\x80\x94 bure kabisa hadi {$trialEndDate}!\n\n"
            . "{$loginUrl}\n\n"
            . "Una swali? Jibu ujumbe huu! \xF0\x9F\x92\xAC\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        6 => "Habari {$name}! \xF0\x9F\x8C\x9F\n\n"
            . "Ukijua kuwa na Fabonic Real Estate Professional, unaweza:\n\n"
            . "\xF0\x9F\x8F\xA0 Wapangaji wako wanaingia wenyewe kuangalia bakaa na risiti\n"
            . "\xF0\x9F\x93\xB1 Vikumbusho vya kodi vinatumwa kiotomatiki\n"
            . "\xF0\x9F\xA7\xBE Risiti zinatumwa WhatsApp moja kwa moja\n\n"
            . "Na Enterprise unapata:\n"
            . "\xE2\x9A\xA1 Bili za umeme na maji kiotomatiki\n"
            . "\xF0\x9F\x94\xA7 Mfumo wa matengenezo\n"
            . "\xF0\x9F\x93\x88 Ripoti za faida na hasara\n\n"
            . "Unaweza kuanzia 1,000 TZS tu kwa chumba kwa mwezi!\n\n"
            . "Angalia bei zote: {$demoUrl}/#pricing\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        7 => "Habari {$name}! \xF0\x9F\x97\xA3\xEF\xB8\x8F\n\n"
            . "Watumiaji wetu wanasema nini:\n\n"
            . "\"Zamani nilikuwa natumia daftari kufuatilia wapangaji 50. Sasa Fabonic Real Estate inafanya kazi yote \xE2\x80\x94 kodi, risiti, vikumbusho. Nimeokoa masaa mengi!\"\n"
            . "\xE2\x80\x94 Mpangishaji, Dar es Salaam\n\n"
            . "\"Mfumo wa mikataba ya PDF ni wa kushangaza. Wapangaji wangu wanapokea mkataba wa kitaalamu ndani ya dakika.\"\n"
            . "\xE2\x80\x94 Kampuni ya Mali, Arusha\n\n"
            . "Jaribu mwenyewe: {$loginUrl}\n\n"
            . "Siku 3 tu zimebaki za majaribio! \xE2\x8F\xB3\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        8 => "Habari {$name}! \xE2\x9A\xA0\xEF\xB8\x8F\n\n"
            . "Majaribio yako ya Fabonic Real Estate yanaisha KESHO ({$trialEndDate})!\n\n"
            . "Usipoteze data na mipangilio yako \xE2\x80\x94 endelea na usajili:\n\n"
            . "\xF0\x9F\x92\xB0 Basic: 9,999 TZS/chumba/mwezi \xE2\x80\x94 Vipengele vyote vya msingi\n"
            . "\xF0\x9F\x92\xB0 Professional: 14,999 TZS/chumba/mwezi \xE2\x80\x94 + Portal ya wapangaji + Vikumbusho\n"
            . "\xF0\x9F\x92\xB0 Enterprise: 19,999 TZS/chumba/mwezi \xE2\x80\x94 Vipengele vyote!\n\n"
            . "Kwa vyumba {$unitCount}: kuanzia {$unitCount} x 9,999 = {$basicTotal} TZS/mwezi tu!\n\n"
            . "Lipa sasa na uendelee: {$loginUrl}/modules/subscription/subscribe.php\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        9 => "Habari {$name}! \xF0\x9F\x94\x92\n\n"
            . "Majaribio yako ya Fabonic Real Estate yameisha leo.\n\n"
            . "Data yako BADO iko salama kwa siku 30 \xE2\x80\x94 lakini huwezi kufikia mfumo hadi ulipe.\n\n"
            . "Lipa sasa na uendelee kusimamia mali zako kwa urahisi:\n"
            . "{$loginUrl}/modules/subscription/subscribe.php\n\n"
            . "Kama una swali kuhusu bei au unahitaji msaada, jibu ujumbe huu.\n\n"
            . "Tunaweza kukusaidia! \xF0\x9F\xA4\x9D\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        10 => "Habari {$name}! \xF0\x9F\x91\x8B\n\n"
            . "Bado tunasubiri kurudi kwako! Akaunti yako ya Fabonic Real Estate bado ipo.\n\n"
            . "Je, kuna kitu kilikuzuia? Tuambie:\n\n"
            . "1\xEF\xB8\x8F\xE2\x83\xA3 Bei ni kubwa sana\n"
            . "2\xEF\xB8\x8F\xE2\x83\xA3 Sijapata muda wa kujaribu vizuri\n"
            . "3\xEF\xB8\x8F\xE2\x83\xA3 Ninahitaji vipengele vingine\n"
            . "4\xEF\xB8\x8F\xE2\x83\xA3 Ninatumia mfumo mwingine\n\n"
            . "Jibu na nambari au maelezo yako \xE2\x80\x94 tutakusaidia! \xF0\x9F\x92\xAC\n\n"
            . "\xE2\x80\x94 Timu ya FIS",
        11 => "Habari {$name}!\n\n"
            . "Huu ni ujumbe wetu wa mwisho kuhusu majaribio yako ya Fabonic Real Estate.\n\n"
            . "Tukumbuke: Data yako itahifadhiwa kwa siku 30 tangu majaribio yameisha. Baada ya hapo, itafutwa.\n\n"
            . "Ukitaka kuendelea: {$loginUrl}/modules/subscription/subscribe.php\n\n"
            . "Ukibadilisha mawazo yako baadaye, unaweza kuanza upya wakati wowote kwenye: {$demoUrl}\n\n"
            . "Asante kwa kujaribu Fabonic Real Estate! \xF0\x9F\x99\x8F\n\n"
            . "\xE2\x80\x94 Timu ya FIS"
    ];

    return $messages[$stepNumber] ?? null;
}

/**
 * Get drip schedule: step number => days after signup
 */
function getDripSchedule() {
    return [
        1 => 0,    // Day 0: Welcome (sent on approval/provisioning)
        2 => 1,    // Day 1: Getting started
        3 => 3,    // Day 3: Lease & rent
        4 => 5,    // Day 5: Reminders
        5 => 7,    // Day 7: Mid-trial check-in
        6 => 9,    // Day 9: Professional features
        7 => 11,   // Day 11: Testimonial
        8 => 13,   // Day 13: Urgency
        9 => 14,   // Day 14: Trial expired
        10 => 17,  // Day 17: Follow up
        11 => 21,  // Day 21: Final follow up
    ];
}

/**
 * Get a system setting from central DB
 */
function getDripSetting($key, $default = null) {
    $central = getCentralDb();
    if (!$central) return $default;

    try {
        $stmt = $central->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Initialize drip campaign for a trial request.
 * Enhanced: supports language preference and approval_date scheduling.
 */
function initDripCampaign($trialRequestId, $phone, $name, $language = null) {
    $central = getCentralDb();
    if (!$central) return false;

    if (!$language) {
        $language = getDripSetting('drip_default_language', 'sw');
    }

    try {
        // Check if campaign already exists
        $stmt = $central->prepare("SELECT id FROM drip_campaigns_re WHERE trial_request_id = ?");
        $stmt->execute([$trialRequestId]);
        if ($stmt->fetch()) return true;

        $sendHour = (int) getDripSetting('drip_send_hour', 9);
        $nextSendAt = date('Y-m-d') . ' ' . sprintf('%02d', $sendHour) . ':00:00';
        // If already past send hour today, schedule for tomorrow
        if ($nextSendAt <= date('Y-m-d H:i:s')) {
            $nextSendAt = date('Y-m-d', strtotime('+1 day')) . ' ' . sprintf('%02d', $sendHour) . ':00:00';
        }

        $skipWeekends = (int) getDripSetting('drip_skip_weekends', 0);

        $stmt = $central->prepare("
            INSERT INTO drip_campaigns_re
                (trial_request_id, phone, name, language, skip_weekends, status, current_step, next_send_at)
            VALUES (?, ?, ?, ?, ?, 'active', 0, ?)
        ");
        $stmt->execute([$trialRequestId, formatPhoneWA($phone), $name, $language, $skipWeekends, $nextSendAt]);
        $campaignId = $central->lastInsertId();

        // Pre-create all drip message records
        $schedule = getDripSchedule();
        $stmtMsg = $central->prepare("
            INSERT INTO drip_messages_re (campaign_id, step_number, message_template, send_after_days, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");

        foreach ($schedule as $step => $days) {
            $stmtMsg->execute([$campaignId, $step, "drip_step_{$step}", $days]);
        }

        return true;
    } catch (PDOException $e) {
        error_log("initDripCampaign error: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// Message Queue (Rate-Limited Sending)
// ============================================================

/**
 * Enqueue a WhatsApp message for rate-limited sending.
 * Returns the queue entry ID, or false on failure.
 */
function enqueueWhatsApp($phone, $message, $campaignId = null, $messageId = null, $priority = 5) {
    $central = getCentralDb();
    if (!$central) return false;

    try {
        $stmt = $central->prepare("
            INSERT INTO drip_message_queue (campaign_id, message_id, phone, message, priority, status)
            VALUES (?, ?, ?, ?, ?, 'queued')
        ");
        $stmt->execute([$campaignId, $messageId, formatPhoneWA($phone), $message, $priority]);
        return $central->lastInsertId();
    } catch (PDOException $e) {
        error_log("enqueueWhatsApp error: " . $e->getMessage());
        return false;
    }
}

/**
 * Process the message queue with rate limiting.
 * Called by drip-cron.php after scheduling step.
 *
 * @param int $maxPerMinute Max messages to send per minute (default from settings)
 * @return array Summary: [sent, failed, remaining]
 */
function processMessageQueue($maxPerMinute = null) {
    $central = getCentralDb();
    if (!$central) return ['sent' => 0, 'failed' => 0, 'remaining' => 0];

    if ($maxPerMinute === null) {
        $maxPerMinute = (int) getDripSetting('drip_rate_limit', 30);
    }

    $sent = 0;
    $failed = 0;

    try {
        // Get queued messages, ordered by priority then age
        $stmt = $central->prepare("
            SELECT * FROM drip_message_queue
            WHERE status IN ('queued', 'failed')
            AND (status = 'queued' OR (status = 'failed' AND attempts < max_attempts))
            ORDER BY priority ASC, created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$maxPerMinute]);
        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $central->prepare("
            UPDATE drip_message_queue
            SET status = ?, attempts = attempts + 1, last_attempt_at = NOW(),
                sent_at = ?, error = ?, response_data = ?
            WHERE id = ?
        ");

        foreach ($queue as $item) {
            // Mark as sending
            $central->prepare("UPDATE drip_message_queue SET status = 'sending' WHERE id = ?")->execute([$item['id']]);

            $result = sendWhatsApp($item['phone'], $item['message']);

            if ($result['success']) {
                $updateStmt->execute(['sent', date('Y-m-d H:i:s'), null, $result['response'] ?? null, $item['id']]);
                $sent++;

                // Update drip_messages_re if linked
                if ($item['message_id']) {
                    $central->prepare("
                        UPDATE drip_messages_re SET status = 'sent', sent_at = NOW()
                        WHERE id = ? AND status != 'delivered'
                    ")->execute([$item['message_id']]);
                }
            } else {
                $errorMsg = $result['error'] ?? ('HTTP ' . ($result['http_code'] ?? '?'));
                $newStatus = ($item['attempts'] + 1 >= $item['max_attempts']) ? 'failed' : 'failed';
                $updateStmt->execute([$newStatus, null, $errorMsg, $result['response'] ?? null, $item['id']]);
                $failed++;

                // Update drip_messages_re if max retries exceeded
                if ($item['message_id'] && ($item['attempts'] + 1 >= $item['max_attempts'])) {
                    $central->prepare("
                        UPDATE drip_messages_re
                        SET status = 'failed', last_error = ?, retry_count = ?
                        WHERE id = ?
                    ")->execute([$errorMsg, $item['attempts'] + 1, $item['message_id']]);
                }
            }

            // Simple rate limiting: ~30/min = 1 every 2 seconds
            if ($sent + $failed < count($queue)) {
                usleep(intval(60000000 / $maxPerMinute)); // microseconds
            }
        }

        // Count remaining
        $remaining = (int) $central->query("
            SELECT COUNT(*) FROM drip_message_queue
            WHERE status IN ('queued') OR (status = 'failed' AND attempts < max_attempts)
        ")->fetchColumn();

    } catch (PDOException $e) {
        error_log("processMessageQueue error: " . $e->getMessage());
    }

    return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining ?? 0];
}

// ============================================================
// Email Helpers
// ============================================================

/**
 * Send HTML email
 */
function sendEmailHTML($to, $subject, $htmlBody) {
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM
    ];

    return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
}

/**
 * Send trial request confirmation email
 */
function sendTrialConfirmationEmail($email, $name, $company) {
    $subject = "Fabonic Real Estate - Tumepokea Ombi Lako! / We Received Your Request!";

    $body = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#f4f6f9;">
    <div style="background:linear-gradient(135deg,#003b7a,#002a57);padding:30px;text-align:center;border-radius:10px 10px 0 0;">
        <h1 style="color:white;margin:0;font-size:1.5rem;">Fabonic Real Estate</h1>
        <p style="color:#e8a838;margin:5px 0 0 0;">Property Management Made Simple</p>
    </div>
    <div style="background:white;padding:30px;border-radius:0 0 10px 10px;">
        <h2 style="color:#003b7a;margin-top:0;">Tumepokea Ombi Lako!</h2>
        <p>Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>
        <p>Asante kwa kuomba majaribio ya Fabonic Real Estate kwa <strong>' . htmlspecialchars($company) . '</strong>.</p>

        <div style="background:#e9f1fb;padding:20px;border-radius:8px;margin:20px 0;border-left:4px solid #003b7a;">
            <h3 style="margin-top:0;color:#003b7a;">Hatua Zinazofuata:</h3>
            <ol style="margin-bottom:0;">
                <li>Timu yetu itakagua ombi lako (ndani ya masaa 24)</li>
                <li>Akaunti yako itaandaliwa</li>
                <li>Utapokea taarifa ya kuingia kupitia email na WhatsApp</li>
            </ol>
        </div>

        <p>Your trial includes:</p>
        <ul>
            <li>14 days free on the Professional tier</li>
            <li>Full access to all Professional features</li>
            <li>Properties, units, tenants, leases, payments</li>
            <li>Tenant portal &amp; automated reminders</li>
        </ul>

        <p>If you have any questions, reply to this email or send us a WhatsApp message.</p>

        <div style="text-align:center;margin:30px 0;">
            <a href="https://real.co.tz" style="background:#003b7a;color:white;padding:12px 30px;text-decoration:none;border-radius:50px;font-weight:bold;">Visit Our Website</a>
        </div>

        <p>Best regards,<br><strong>Fabonic Real Estate Team</strong></p>
    </div>
    <div style="text-align:center;padding:15px;color:#999;font-size:0.8rem;">
        Fabonic Real Estate &mdash; real.co.tz<br>
        &copy; ' . date('Y') . ' Fabonic. All rights reserved.
    </div>
</body>
</html>';

    return sendEmailHTML($email, $subject, $body);
}
