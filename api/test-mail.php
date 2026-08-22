<?php
/**
 * QuickGanyu – email send test.
 * Call: http://localhost/roi/api/test-mail.php?to=your@email.com
 * Remove this file before going live.
 */
require_once __DIR__ . '/helpers.php';
header('Content-Type: text/plain; charset=utf-8');

$to = trim($_GET['to'] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: test-mail.php?to=your@email.com\n";
    exit;
}

echo "Sending test email to: $to\n";

send_mail(
    $to,
    'QuickGanyu – Email test',
    '<h1>Email is working! &#9989;</h1>
     <p>This is a test message from <strong>QuickGanyu</strong> to confirm that your SMTP
        configuration is working correctly.</p>
     <div class="info-box">
       <div class="label">SMTP details</div>
       <div class="value" style="font-size:14px;">smtp.gmail.com:465 (SSL)</div>
     </div>
     <p>If you received this, email delivery is fully operational.</p>'
);

echo "Done. Check your inbox (and spam folder).\n";
echo "Also check: " . realpath(__DIR__ . '/../mailbox') . " for a local copy.\n";
