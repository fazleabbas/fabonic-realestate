<?php
/**
 * Fabonic Real Estate — SMS Gateway (Sprint Marketing & Technology, API v3.0)
 * ------------------------------------------------------------
 * Single place that talks to the SMS provider. Used by the reminder cron and
 * the manual "send now" path in api/reminders.php.
 *
 * Endpoint  : POST {sms_api_url}/api/SendSMS   (JSON body)
 * Success   : {"message_id":4125,"status":"S","remarks":"...","uid":"..."}
 *             status S = submitted, F = failed to submit.
 *
 * Everything is configured per tenant in Settings -> Reminders -> SMS Gateway,
 * so no credentials live in code.
 */

/**
 * Sprint wants "country code + number" with NO plus sign (e.g. 255712000111).
 * Tenants type numbers as 0712..., +255712... or 255712... — normalise all three.
 */
function smsFormatPhone($phone, $defaultCountry = '255')
{
    $p = preg_replace('/[^0-9+]/', '', (string)$phone);
    if ($p === '') {
        return '';
    }
    if (strpos($p, '+') === 0) {
        return substr($p, 1);                 // +255712... -> 255712...
    }
    if (strpos($p, '0') === 0) {
        return $defaultCountry . substr($p, 1); // 0712... -> 255712...
    }
    return $p;                                 // already 255712...
}

/**
 * Sprint encoding flag: 'T' plain text (GSM-7), 'U' unicode.
 * Anything outside printable ASCII (accents, Arabic, emoji) needs unicode, and
 * sending it as 'T' would arrive mangled.
 */
function smsEncodingFor($message)
{
    return preg_match('/[^\x20-\x7E\r\n]/', $message) ? 'U' : 'T';
}

/** Is the gateway configured well enough to attempt a send? */
function smsIsConfigured()
{
    return getSetting('sms_api_url', '') !== ''
        && getSetting('sms_api_id', '') !== ''
        && getSetting('sms_api_password', '') !== ''
        && getSetting('sms_sender_id', '') !== '';
}

/**
 * Send one SMS.
 *
 * @param string $phone   Recipient, any local format.
 * @param string $message Body text.
 * @param string $uid     Optional reference echoed back in the delivery report.
 * @return array{success:bool, message:string, message_id:?string}
 */
function sendSMS($phone, $message, $uid = '')
{
    if (!smsIsConfigured()) {
        return ['success' => false, 'message' => 'SMS gateway not configured', 'message_id' => null];
    }

    $to = smsFormatPhone($phone, getSetting('sms_country_code', '255'));
    if ($to === '') {
        return ['success' => false, 'message' => 'Recipient has no phone number', 'message_id' => null];
    }

    $base = rtrim(getSetting('sms_api_url', ''), '/');

    $payload = [
        'api_id'       => getSetting('sms_api_id', ''),
        'api_password' => getSetting('sms_api_password', ''),
        // Rent reminders and receipts are transactional, not marketing.
        'sms_type'     => getSetting('sms_type', 'T'),
        'encoding'     => smsEncodingFor($message),
        'sender_id'    => getSetting('sms_sender_id', ''),
        'phonenumber'  => $to,
        'textmessage'  => $message,
    ];

    if ($uid !== '') {
        $payload['uid'] = $uid;
    }
    $callback = getSetting('sms_callback_url', '');
    if ($callback !== '') {
        $payload['callback_url'] = $callback;
    }

    $ch = curl_init($base . '/api/SendSMS');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'message' => 'cURL error: ' . $curlErr, 'message_id' => null];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return [
            'success'    => false,
            'message'    => 'Unexpected gateway response (HTTP ' . $httpCode . ')',
            'message_id' => null,
        ];
    }

    // Sprint returns status "S" (submitted) or "F" (failed).
    if (($data['status'] ?? '') === 'S') {
        return [
            'success'    => true,
            'message'    => $data['remarks'] ?? 'Submitted',
            'message_id' => isset($data['message_id']) ? (string)$data['message_id'] : null,
        ];
    }

    return [
        'success'    => false,
        'message'    => $data['remarks'] ?? ('Gateway returned HTTP ' . $httpCode),
        'message_id' => isset($data['message_id']) ? (string)$data['message_id'] : null,
    ];
}

/**
 * Account balance — shown on the settings page so a tenant notices before
 * reminders start failing for lack of credit.
 *
 * @return array{success:bool, balance:?string, currency:?string, message:string}
 */
function smsCheckBalance()
{
    if (!smsIsConfigured()) {
        return ['success' => false, 'balance' => null, 'currency' => null, 'message' => 'Not configured'];
    }

    $base = rtrim(getSetting('sms_api_url', ''), '/');
    $ch = curl_init($base . '/api/CheckBalance');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'api_id'       => getSetting('sms_api_id', ''),
            'api_password' => getSetting('sms_api_password', ''),
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'balance' => null, 'currency' => null, 'message' => $curlErr];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['BalanceAmount'])) {
        return ['success' => false, 'balance' => null, 'currency' => null, 'message' => 'Unexpected response'];
    }

    return [
        'success'  => true,
        'balance'  => (string)$data['BalanceAmount'],
        'currency' => (string)($data['CurrenceCode'] ?? ''),   // spelling is the vendor's
        'message'  => 'OK',
    ];
}
