<?php
// public/admin/mail_test.php
//HIT in browser: http://localhost/rss/public/test/mail_test.php?to=nsanzeri@gmail.com
require_once __DIR__ . '/../../core/bootstrap.php'; // adjust to your bootstrap path

// Optional: lock this down if this is accessible publicly
// if (empty($_SESSION['user_id'])) { http_response_code(403); exit('Forbidden'); }

$to = $_GET['to'] ?? env('MAIL_TO_OVERRIDE', '');
if (!$to) {
  echo "Pass ?to=you@domain.com or set MAIL_TO_OVERRIDE in .env";
  exit;
}

require_once __DIR__ . '/../../core/mailer.php';

$res = Mailer::send([
  'to_email' => $to,
  'subject'  => 'Ready Set Shows SMTP Test',
  'html'     => '<p>If you got this, SMTP is working ✅</p>',
  'text'     => 'If you got this, SMTP is working.',
]);

header('Content-Type: text/plain; charset=utf-8');
echo $res['ok'] ? "OK\n" : ("FAIL: " . $res['error'] . "\n");
