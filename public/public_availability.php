<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();

page_header("Public Link");
?>
<div class="card">
  <div class="card-body">
    <p class="muted">Send this link to clients. It shows your availability without requiring a login.</p>
    <div class="field">
      <label>Your public availability URL</label>
      <input readonly value="<?= h(BASE_URL) ?>/share.php?u=<?= h($u['public_key'] ?? '') ?>"/>
    </div>
    <p class="muted">Tip: you can add <code>&start=YYYY-MM-DD&end=YYYY-MM-DD</code> to prefill a range.</p>
  </div>
</div>
<?php page_footer(); ?>
