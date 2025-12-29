<?php
// core/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/env.php';

class Mailer {
	public static function send(array $opts): array {
		// opts: to_email, to_name?, subject, html, text?, reply_to_email?, reply_to_name?
		$toEmail = trim((string)($opts['to_email'] ?? ''));
		$subject = (string)($opts['subject'] ?? '');
		$html    = (string)($opts['html'] ?? '');
		$text    = (string)($opts['text'] ?? strip_tags($html));
		
		if ($toEmail === '' || $subject === '' || ($html === '' && $text === '')) {
			return ['ok' => false, 'error' => 'Missing to_email/subject/body'];
		}
		
		$fromEmail = env('MAIL_FROM_ADDRESS', 'no-reply@example.com');
		$fromName  = env('MAIL_FROM_NAME', env('APP_NAME', 'App'));
		
		$replyToEmail = $opts['reply_to_email'] ?? null;
		$replyToName  = $opts['reply_to_name'] ?? null;
		
		$driver = env('MAIL_DRIVER', 'smtp'); // smtp or mail
		
		$mail = new PHPMailer(true);
		
		try {
			$mail->CharSet = 'UTF-8';
			$mail->setFrom($fromEmail, $fromName);
			
			if ($replyToEmail) {
				$mail->addReplyTo($replyToEmail, $replyToName ?: $replyToEmail);
			}
			
			// Optional: dev override so you don’t spam real users
			$override = env('MAIL_TO_OVERRIDE', null);
			if ($override) {
				$mail->addAddress($override, 'Dev Inbox');
				$mail->addCustomHeader('X-Original-To', $toEmail);
			} else {
				$mail->addAddress($toEmail, $opts['to_name'] ?? '');
			}
			
			$mail->Subject = $subject;
			
			if ($driver === 'smtp') {
				$mail->isSMTP();
				$mail->Host = env('MAIL_HOST');
				$mail->Port = (int)env('MAIL_PORT', 587);
				$mail->SMTPAuth = true;
				$mail->Username = env('MAIL_USERNAME');
				$mail->Password = env('MAIL_PASSWORD');
				
				$enc = strtolower((string)env('MAIL_ENCRYPTION', 'tls'));
				if ($enc === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				elseif ($enc === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				else $mail->SMTPSecure = false;
				
				// Helpful in shared hosting oddities:
				// $mail->SMTPOptions = [
				//   'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
				// ];
			} else {
				// fallback to PHP mail()
				$mail->isMail();
			}
			
			$mail->isHTML(true);
			$mail->Body    = $html ?: nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
			$mail->AltBody = $text;
			
			$mail->send();
			return ['ok' => true, 'error' => null];
		} catch (Exception $e) {
			// Avoid leaking SMTP creds; return message only
			return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
		}
	}
}
