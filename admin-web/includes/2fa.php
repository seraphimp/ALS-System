<?php
require_once 'vendor/autoload.php'; // Install: composer require sonata-project/google-authenticator

use Sonata\GoogleAuthenticator\GoogleAuthenticator;

function generate_2fa_secret() {
    $g = new GoogleAuthenticator();
    return $g->generateSecret();
}

function verify_2fa_code($secret, $code) {
    $g = new GoogleAuthenticator();
    return $g->checkCode($secret, $code);
}

function send_2fa_email($to_email, $code, $username) {
    $subject = "Your ALS Admin 2FA Login Code";
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 20px; text-align: center; }
            .code { font-size: 32px; font-weight: bold; color: #1e40af; padding: 20px; text-align: center; letter-spacing: 5px; }
            .warning { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>ALS Admin Portal</h2>
            </div>
            <div class='code'>
                $code
            </div>
            <p>Hello $username,</p>
            <p>Use the verification code above to complete your login to the ALS Admin Portal.</p>
            <div class='warning'>
                <strong>⚠️ Security Notice:</strong> This code expires in 5 minutes. 
                If you didn't request this, please change your password immediately.
            </div>
            <p>Best regards,<br>ALS Admin Team</p>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: ALS Admin <noreply@als-system.com>" . "\r\n";
    
    return mail($to_email, $subject, $message, $headers);
}

function generate_email_code() {
    return sprintf("%06d", mt_rand(1, 999999));
}