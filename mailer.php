<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends a 6-digit code. $purpose is 'register' or 'reset'.
 * Returns true on success, or an error string.
 */
function send_code_email(string $to, string $username, string $code, string $purpose = 'register')
{
    $cfg = require __DIR__ . '/mail_config.php';

    if (!empty($cfg['dev_mode'])) {
        file_put_contents(__DIR__ . '/mail_outbox.log',
            date('c') . " purpose=$purpose to=$to code=$code\n", FILE_APPEND | LOCK_EX);
        return true;
    }

    if (is_file(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    } elseif (is_file(__DIR__ . '/PHPMailer/src/PHPMailer.php')) {
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';
    } else {
        return 'PHPMailer is not installed. See EMAIL_SETUP.txt.';
    }

    if ($purpose === 'reset') {
        $subject = 'Your HelmetWatch password reset code';
        $intro   = 'We received a request to reset your HelmetWatch password. Your reset code is:';
        $outro   = 'It expires in 10 minutes. If you did not request this, ignore this email and your password stays unchanged.';
        $alt     = "Hi $username, your HelmetWatch password reset code is $code. It expires in 10 minutes. If you did not request this, ignore this email.";
    } else {
        $subject = 'Your HelmetWatch verification code';
        $intro   = 'Your verification code is:';
        $outro   = 'It expires in 10 minutes. If you did not try to register, ignore this email.';
        $alt     = "Hi $username, your HelmetWatch verification code is $code. It expires in 10 minutes.";
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = $cfg['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = str_replace(' ', '', $cfg['app_password']);
        $mail->SMTPSecure = ($cfg['port'] == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $mail->Body    = "<div style=\"font-family:Arial,sans-serif;font-size:14px\">"
                       . "<p>Hi $u,</p><p>$intro</p>"
                       . "<p style=\"font-size:28px;font-weight:bold;letter-spacing:6px\">$code</p>"
                       . "<p>$outro</p></div>";
        $mail->AltBody = $alt;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return 'Could not send the email: ' . $mail->ErrorInfo;
    }
}

/** Kept so register.php and verify_email.php keep working unchanged. */
function send_verification_email(string $to, string $username, string $code)
{
    return send_code_email($to, $username, $code, 'register');
}
