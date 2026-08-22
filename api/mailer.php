<?php
/**
 * QuickGanyu – SMTP mailer (no external dependencies).
 * Connects to Gmail over SSL port 465 using an App Password.
 */

define('SMTP_HOST',      'ssl://smtp.gmail.com');
define('SMTP_PORT',      465);
define('SMTP_USER',      'princekamnga1@gmail.com');
define('SMTP_PASS',      'ddhanhqfwzxwzheq');
define('SMTP_FROM',      'princekamnga1@gmail.com');
define('SMTP_FROM_NAME', 'QuickGanyu');

/**
 * Send an HTML email via Gmail SMTP.
 * Returns true on success, false on any failure (failure is also error_logged).
 */
function smtp_send(string $to, string $subject, string $htmlBody): bool {

    // Build an SSL context that relaxes certificate verification for XAMPP
    // (XAMPP ships without a CA bundle so strict verification always fails).
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $errno  = 0;
    $errstr = '';
    $conn   = @stream_socket_client(
        SMTP_HOST . ':' . SMTP_PORT,
        $errno, $errstr, 20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$conn) {
        error_log("QuickGanyu mailer: could not connect to SMTP – $errstr ($errno)");
        return false;
    }

    stream_set_timeout($conn, 20);

    /**
     * Read one full SMTP response (may be multi-line).
     * A line ending in "XXX " (code + space) is the last line.
     */
    $read = function () use ($conn): string {
        $data = '';
        while (!feof($conn)) {
            $line = fgets($conn, 512);
            if ($line === false) break;
            $data .= $line;
            // SMTP continuation lines look like "250-…"; final line is "250 …"
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };

    $cmd = function (string $command) use ($conn, $read): string {
        fwrite($conn, $command . "\r\n");
        return $read();
    };

    // ── SMTP handshake ────────────────────────────────────────────────────────
    $greeting = $read(); // 220 smtp.gmail.com ready
    if (strpos($greeting, '220') === false) {
        error_log("QuickGanyu mailer: unexpected greeting – $greeting");
        fclose($conn);
        return false;
    }

    $ehlo = $cmd('EHLO ' . (gethostname() ?: 'localhost'));
    if (strpos($ehlo, '250') === false) {
        error_log("QuickGanyu mailer: EHLO failed – $ehlo");
        fclose($conn);
        return false;
    }

    // AUTH LOGIN
    $cmd('AUTH LOGIN');
    $cmd(base64_encode(SMTP_USER));
    $authReply = $cmd(base64_encode(SMTP_PASS));

    if (strpos($authReply, '235') === false) {
        error_log("QuickGanyu mailer: AUTH LOGIN failed – $authReply");
        fclose($conn);
        return false;
    }

    // Envelope
    $mailFrom = $cmd('MAIL FROM:<' . SMTP_FROM . '>');
    if (strpos($mailFrom, '250') === false) {
        error_log("QuickGanyu mailer: MAIL FROM failed – $mailFrom");
        fclose($conn);
        return false;
    }

    $rcptTo = $cmd('RCPT TO:<' . $to . '>');
    if (strpos($rcptTo, '250') === false) {
        error_log("QuickGanyu mailer: RCPT TO failed for $to – $rcptTo");
        fclose($conn);
        return false;
    }

    $dataReply = $cmd('DATA');
    if (strpos($dataReply, '354') === false) {
        error_log("QuickGanyu mailer: DATA command failed – $dataReply");
        fclose($conn);
        return false;
    }

    // ── Build and send the raw message ────────────────────────────────────────
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    $headers = implode("\r\n", [
        'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM . '>',
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        'X-Mailer: QuickGanyu-PHP-Mailer',
        'Date: ' . date('r'),
    ]);

    $encoded = quoted_printable_encode($htmlBody);

    // Write headers + body + end-of-data marker
    fwrite($conn, $headers . "\r\n\r\n" . $encoded . "\r\n.\r\n");
    $sendReply = $read();

    $cmd('QUIT');
    fclose($conn);

    if (strpos($sendReply, '250') === false) {
        error_log("QuickGanyu mailer: message rejected – $sendReply");
        return false;
    }

    return true;
}

/**
 * Wrap inner HTML content in the QuickGanyu branded email template.
 *
 * @param  string $preheader  Short preview text shown in inbox list (≤ 100 chars).
 * @param  string $content    Inner HTML — paragraphs, info boxes, CTA button etc.
 * @return string             Full HTML document ready to send.
 */
function email_template(string $preheader, string $content): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>QuickGanyu</title>
  <style>
    body,html{margin:0;padding:0;background:#f4f1ea;font-family:'Segoe UI',Roboto,Arial,sans-serif;color:#1e1e1e;}
    .email-wrap{max-width:600px;margin:32px auto;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.10);}
    .email-header{background:#0b0b0b;padding:28px 40px 24px;text-align:center;}
    .email-header .logo{font-size:26px;font-weight:700;color:#f5c842;letter-spacing:-0.5px;}
    .email-header .logo span{color:#ffffff;font-weight:300;}
    .email-header .tagline{color:#888;font-size:12px;margin-top:4px;letter-spacing:0.06em;text-transform:uppercase;}
    .email-body{padding:40px 40px 32px;}
    .email-body h1{font-size:24px;font-weight:700;margin:0 0 16px;color:#0b0b0b;line-height:1.3;}
    .email-body p{font-size:15px;line-height:1.75;margin:0 0 16px;color:#444;}
    .info-box{background:#fef9e7;border-left:4px solid #f5c842;border-radius:0 12px 12px 0;padding:18px 20px;margin:24px 0;}
    .info-box .label{font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;}
    .info-box .value{font-size:22px;font-weight:700;color:#0b0b0b;letter-spacing:0.06em;font-family:monospace;}
    .cta-btn{display:block;width:fit-content;margin:28px auto 0;background:#f5c842;color:#0b0b0b !important;text-decoration:none;font-weight:700;font-size:15px;padding:14px 36px;border-radius:999px;text-align:center;}
    .notice{background:#f8f8f8;border-radius:12px;padding:14px 18px;font-size:13px;color:#666;margin-top:24px;line-height:1.6;}
    .notice strong{color:#0b0b0b;}
    .divider{height:1px;background:#f0f0f0;margin:28px 0;}
    .email-footer{background:#0b0b0b;padding:24px 40px;text-align:center;}
    .email-footer p{margin:0;font-size:12px;color:#666;line-height:1.8;}
    .email-footer a{color:#f5c842;text-decoration:none;}
    .pill{display:inline-block;background:#f5c842;color:#0b0b0b;border-radius:999px;padding:3px 12px;font-size:12px;font-weight:700;}
    .declined-box{background:#fff0f2;border-left:4px solid #b00020;border-radius:0 12px 12px 0;padding:18px 20px;margin:24px 0;}
    .declined-box .label{font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;}
    .declined-box .value{font-size:17px;font-weight:700;color:#b00020;}
    @media only screen and (max-width:640px){
      .email-wrap{margin:0;border-radius:0;}
      .email-body{padding:28px 24px 24px;}
      .email-footer{padding:20px 24px;}
      .email-header{padding:22px 24px 18px;}
    }
  </style>
</head>
<body>
  <span style="display:none;max-height:0;overflow:hidden;mso-hide:all;">{$preheader} &zwnj;&nbsp;</span>

  <div class="email-wrap">
    <div class="email-header">
      <div class="logo">Quick<span>Ganyu</span> &#9889;</div>
      <div class="tagline">Malawi&rsquo;s service marketplace</div>
    </div>

    <div class="email-body">
      {$content}
    </div>

    <div class="email-footer">
      <p>
        &copy; {$year} QuickGanyu &mdash; built for Malawi.<br>
        <a href="mailto:info@quickganyu.com">info@quickganyu.com</a> &nbsp;&bull;&nbsp; +265 999 000 000
      </p>
      <p style="margin-top:8px;color:#444;font-size:11px;">
        You received this email because an action was taken on your QuickGanyu account.
      </p>
    </div>
  </div>
</body>
</html>
HTML;
}
