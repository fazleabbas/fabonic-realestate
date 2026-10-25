<?php
/**
 * Email deliverability test — sends one HTML email via mail() using the
 * configured From address (noreply@real.co.tz).
 * TEMPORARY diagnostic. DELETE this file after go-live.
 *
 * Run: https://real.co.tz/api/test-mail.php?key=<TEST_MAIL_KEY>&to=you@gmail.com
 */
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/secrets.php';
if (!appSecretMatches('TEST_MAIL_KEY', $_GET['key'] ?? null)) {
    http_response_code(403);
    die('Access denied');
}
header('Content-Type: text/plain; charset=utf-8');

$to = filter_var($_GET['to'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$to) {
    die("Add a recipient: ...&to=you@gmail.com\n");
}

$subject = 'Fabonic Real Estate — test email';
$body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:20px;">'
      . '<div style="background:linear-gradient(135deg,#003b7a,#002a57);padding:24px;border-radius:10px;text-align:center;">'
      . '<h1 style="color:#fff;margin:0;">It works!</h1>'
      . '<p style="color:#f5a623;margin:6px 0 0;">Fabonic Real Estate</p></div>'
      . '<p style="margin-top:20px;">This is a deliverability test sent from <strong>' . MAIL_FROM . '</strong> on real.co.tz.</p>'
      . '<p>If you received this in your <strong>inbox</strong> (not spam), email delivery is working.</p>'
      . '</body></html>';

$headers = [
    'MIME-Version: 1.0',
    'Content-type: text/html; charset=UTF-8',
    'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . MAIL_FROM,
    'X-Mailer: PHP/' . phpversion(),
];

echo "From: " . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\n";
echo "To:   {$to}\n";
echo str_repeat('-', 40) . "\n";

if (mail($to, $subject, $body, implode("\r\n", $headers))) {
    echo "mail() accepted the message. ✓\n";
    echo "Now check {$to} — INBOX first, then Spam/Promotions.\n";
    echo "(Landing in spam = SPF/DKIM still need fixing in Email Deliverability.)\n";
} else {
    echo "mail() returned FALSE — the server refused to queue it.\n";
    echo "Check that noreply@real.co.tz exists as an email account, and that\n";
    echo "PHP mail() / sendmail is enabled for this account.\n";
}
