<?php
// ============================================================
// includes/mailer.php
// Place this file at: /als/admin-web/includes/mailer.php
//
// Run once via SSH to install PHPMailer:
//   cd /path/to/your/webroot && composer require phpmailer/phpmailer
//
// Find your mail settings in cPanel:
//   Email Accounts → noreply@als-system.online → Connect Devices
// ============================================================

// ── CHANGE THESE 3 LINES ──────────────────────────────────────
define('ALS_MAIL_HOST', 'mail.als-system.online'); // from cPanel "Connect Devices"
define('ALS_MAIL_USER', 'noreply@als-system.online');
define('ALS_MAIL_PASS', 'YOUR_EMAIL_PASSWORD_HERE'); // ← put your cPanel email password
// ─────────────────────────────────────────────────────────────
define('ALS_MAIL_FROM_NAME', 'ALS La Carlota City Division');
define('ALS_MAIL_PORT', 465); // 465 = SSL (standard on cPanel)

require_once __DIR__ . '/../../../vendor/autoload.php';
// ^ vendor/ should be in your web root where you ran composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function als_mail(string $to, string $subject, string $html, string $toName = ''): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = ALS_MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = ALS_MAIL_USER;
        $mail->Password   = ALS_MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = ALS_MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(ALS_MAIL_USER, ALS_MAIL_FROM_NAME);
        $mail->addAddress($to, $toName ?: $to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = wordwrap(strip_tags(str_replace(
            ['<br>','<br/>','</p>','</li>','</h1>','</h2>','</div>'], "\n", $html
        )), 75);
        $mail->send();
        error_log("ALS Mail OK → {$to} | {$subject}");
        return true;
    } catch (Exception $e) {
        error_log("ALS Mail FAIL → {$to} | " . $mail->ErrorInfo);
        return false;
    }
}