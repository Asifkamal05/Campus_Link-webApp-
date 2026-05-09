<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

const CAMPUSLINK_SMTP_HOST = 'smtp.gmail.com';
const CAMPUSLINK_SMTP_PORT = 587;
const CAMPUSLINK_SMTP_USER = 'moontashir.azim@gmail.com';
const CAMPUSLINK_SMTP_PASS = 'kpke byqf ulam bras';

function generateVerificationCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendVerificationCodeEmail(string $recipientEmail, string $recipientName, string $verificationCode): void
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = CAMPUSLINK_SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = CAMPUSLINK_SMTP_USER;
    $mail->Password = CAMPUSLINK_SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = CAMPUSLINK_SMTP_PORT;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(CAMPUSLINK_SMTP_USER, 'CampusLink');
    $mail->addAddress($recipientEmail, $recipientName !== '' ? $recipientName : $recipientEmail);
    $mail->Subject = 'CampusLink Verification Code';
    $mail->Body = "Your CampusLink verification code is: {$verificationCode}\n\nThis code is required to activate your student account.";

    if (!$mail->send()) {
        throw new Exception('Unable to send verification email.');
    }
}
