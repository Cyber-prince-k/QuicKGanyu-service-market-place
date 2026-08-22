<?php
/**
 * QuickGanyu – SMTP mailer (no external dependencies).
 *
 * Uses PHP stream_socket_client() to speak SMTP over TLS (port 465) with Gmail.
 * All outgoing mail is sent from the QuickGanyu Gmail account via an App Password.
 */

define('SMTP_HOST',     'ssl://smtp.gmail.com');
define('SMTP_PORT',     465);
define('SMTP_USER',     'princekamnga1@gmail.com');
define('SMTP_PASS',     'ddhanhqfwzxwzheq');
define('SMTP_FROM',     'princekamnga1@gmail.com');
define('SMTP_FROM_NAME','QuickGanyu');

/**
 * Send an HTML email via Gmail SMTP.
 *
 * @param  string $to        Recipient email address.
 * @param  string $subject   Email subject line.
 * @param  string $htmlBody  Full HTML body (will be wrapped in the branded template).
 * @return bool              true on success, false on failure.
 */
function smtp_send(string $to, string $subject, string $htmlBody): bool {
    $errno  = 0;
    $errstr = '';
    $conn   = @stream_socket_client(
        SMTP_HOST . ':' . SMTP_PORT,
        $errno, $errstr, 15
    );
    if (!$conn) {
        error_log("QuickGanyu mailer: could not connect to SMTP – $errstr ($errno)");
        return false;
    }

    $read = function () use ($conn): string {
        $data = '';
        while ($line = fgets($conn, 512)) {
            $data .= $line;
            if ($line[3] === ' ') break; // last line of multi-line response
        }
        return $data;
    };

    $cmd = function (string $command) use ($conn, $read): string {
        fwrite($conn, $command . "\r\n");
        return $read();
    };

    $read(); // 220 greeting

    $cmd('EHLO ' . gethostname());
    $cmd('AUTH LOGIN');
    $cmd(base64_encode(SMTP_USER));
    $r = $cmd(base64_encode(SMTP_PASS));

    if (strpos($r, '235') === false) {
        error_log("QuickGanyu mailer: AUTH failed – $r");
        fclose($conn);
        return false;
    }

    $cmd('MAIL FROM:<' . SMTP_FROM . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $cmd('DATA');

    // Build the raw message
    $boundary = 'qg_' . bin2hex(random_bytes(8));
    $headers  = implode("\r\n", [
        'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM . '>',
        'To: ' . $to,
        'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B'),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        'X-Mailer: QuickGanyu-PHP-Mailer',
    ]);

    $encoded = quoted_printable_encode($htmlBody);
    $message = $headers . "\r\n\r\n" . $encoded . "\r\n.\r\n";

    $r = $cmd($message);
    $cmd('QUIT');
    fclose($conn);

    if (strpos($r, '250') === false) {
        error_log("QuickGanyu mailer: DATA failed – $r");
        return false;
    }
    return true;
}

/**
 * Wrap content in the QuickGanyu branded email template.
 *
 * @param  string $preheader Short preview text shown in inbox list.
 * @param  string $content   Inner HTML (paragraphs, tables, buttons etc.).
 * @return string            Full HTML document ready to send.
 */
function email_template(string $preheader, string $content): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>QuickGanyu</title>
  <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
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
    .info-row{display:flex;align-items:baseline;gap:10px;margin:6px 0;}
    .info-row .field{font-size:13px;color:#888;min-width:120px;}
    .info-row .val{font-size:15px;font-weight:600;color:#0b0b0b;}
    .cta-btn{display:block;width:fit-content;margin:28px auto 0;background:#f5c842;color:#0b0b0b !important;text-decoration:none;font-weight:700;font-size:15px;padding:14px 36px;border-radius:999px;text-align:center;}
    .notice{background:#f8f8f8;border-radius:12px;padding:14px 18px;font-size:13px;color:#666;margin-top:24px;line-height:1.6;}
    .notice strong{color:#0b0b0b;}
    .divider{height:1px;background:#f0f0f0;margin:28px 0;}
    .email-footer{background:#0b0b0b;padding:24px 40px;text-align:center;}
    .email-footer p{margin:0;font-size:12px;color:#666;line-height:1.8;}
    .email-footer a{color:#f5c842;text-decoration:none;}
    .pill{display:inline-block;background:#f5c842;color:#0b0b0b;border-radius:999px;padding:3px 12px;font-size:12px;font-weight:700;}
    .declined-box{background:#fff0f2;border-left:4px solid #b00020;border-radius:0 12px 12px 0;padding:18px 20px;margin:24px 0;}
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
  <!-- Preheader (hidden preview text) -->
  <span style="display:none;max-height:0;overflow:hidden;">{$preheader}&nbsp;&#847;&nbsp;</span>

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
        <a href="mailto:info@quickganyu.com">info@quickganyu.com</a> &nbsp;&bull;&nbsp;
        +265 999 000 000
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
