<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function send_mail($env_data, $to, $subject, $body, $business_name = '') {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $env_data['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $env_data['MAIL_USERNAME'];
        $mail->Password   = $env_data['MAIL_PASSWORD'];
        $mail->SMTPSecure = strtolower($env_data['MAIL_ENCRYPTION']) === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $env_data['MAIL_PORT'];
        $mail->setFrom($env_data['MAIL_USERNAME'], $business_name ?: $env_data['MAIL_USERNAME']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        return $mail->ErrorInfo;
    }
}
