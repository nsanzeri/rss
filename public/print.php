<?php
require_once __DIR__ . "/_layout.php";
require_login();

page_header('Pretty Print');

// A tiny helper page for quick debugging and formatted dumps.
$sample = [
  'now' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
  'user' => auth_user(),
  'get' => $_GET,
];
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <h1 style="margin:0 0 6px;">Pretty Print</h1>
  <p class="muted" style="margin:0 0 14px;">Placeholder utility page. Use it as a safe place to dump arrays while building new features.</p>

  <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
    <h3 style="margin:0 0 10px;">Sample debug output</h3>
    <pre style="white-space:pre-wrap;overflow:auto;margin:0;"><code><?php echo h(json_encode($sample, JSON_PRETTY_PRINT)); ?></code></pre>
  </div>
</div>

<?php page_footer(); ?>
