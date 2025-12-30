<?php
require_once __DIR__ . "/../core/bootstrap.php";
$user = auth_user();

function page_header(string $title): void {
    $u = auth_user();
    // Pages like login/forgot/reset can set $HIDE_NAV = true before including _layout.php
    // to render a minimal shell without the top navigation.
    $hideNav = !empty($GLOBALS['HIDE_NAV']);

    // Single-source nav model (desktop dropdown + mobile accordion)
    $NAV = [
        'Ops' => [
            ['Dashboard', '/dashboard.php', 'home'],
            ['Calendars', '/manage_calendars.php', 'ics'],
            ['Check Availability', '/check_availability.php', 'core'],
            ['Public Link', '/public_availability.php', 'share'],
        ],
        'Shows' => [
            ['Shows (soon)', '#', 'soon'],
        ],
        'Finance' => [
            ['Finance (soon)', '#', 'soon'],
        ],
        'Social' => [
            ['Social (soon)', '#', 'soon'],
        ],
        'Connect' => [
            ['Connect (soon)', '#', 'soon'],
        ],
    ];

    // Active route → highlight module + sub-item (desktop two-row nav)
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $file = strtolower(basename($path));
    $activeModule = 'Ops';
    $activeItemPath = '';
    foreach ($NAV as $m => $items) {
        foreach ($items as $it) {
            $p = strtolower(basename($it[1] ?? ''));
            if ($p && $p === $file) {
                $activeModule = $m;
                $activeItemPath = $it[1];
                break 2;
            }
        }
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <meta name="csrf" content="<?= h(csrf_token()) ?>"/>
        <title><?= h($title) ?> • <?= h(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css"/>
        <script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
    </head>
    <body>

    <?php if ($hideNav): ?>
    <div class="app-shell">
      <main class="content" style="padding-top:2.5rem;">
    <?php else: ?>
    <!-- Mobile menu panel (opens below header; no internal header) -->
    <div class="mobile-drawer" id="mobileDrawer" aria-hidden="true">
      <div class="mobile-drawer__backdrop" data-drawer-close></div>
      <div class="mobile-drawer__panel" role="dialog" aria-label="Menu">
        <div class="mobile-acc" id="mobileAcc">
          <?php foreach ($NAV as $module => $items): ?>
            <div class="acc" data-acc>
              <button class="acc-btn" type="button" data-acc-btn>
                <span><?= h($module) ?></span>
                <span class="chev">▾</span>
              </button>
              <div class="acc-panel">
                <?php foreach ($items as [$label, $path, $tag]): ?>
                  <a class="acc-link <?= $path === '#' ? 'disabled' : '' ?>" href="<?= $path === '#' ? 'javascript:void(0)' : h(BASE_URL . $path) ?>">
                    <span><?= h($label) ?></span><span class="tag"><?= h($tag) ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="app-shell">
        <div class="shell">
  <div>
    <header class="topbar">
      <!-- Row 1: centered product title (VERY TOP LINE) -->
      <div class="topbar-row topbar-row--brand">
        <div class="brand-center" aria-label="Ready Set Shows">Ready Set Shows</div>
      </div>

      <!-- Row 2: emblem + main modules + user/logout -->
      <div class="topbar-row topbar-row--primary">
        <div class="topbar-left">
          <a class="topbar-emblem" href="<?= h(BASE_URL) ?>/dashboard.php" aria-label="Ready Set Shows">
            <span class="logo-mark" aria-hidden="true">
              <span class="logo-mark-inner">
                <span class="logo-bolt"></span>
                <span class="logo-ring"></span>
              </span>
            </span>
          </a>

          <!-- Mobile: burger to the RIGHT of emblem; becomes X + Close when open -->
          <button class="nav-toggle" type="button" aria-label="Menu" data-drawer-toggle>
            <span class="nav-ico" aria-hidden="true">☰</span>
            <span class="nav-text">Menu</span>
          </button>
        </div>

		<nav class="modulebar" aria-label="Suite">
		  <?php foreach ($NAV as $module => $items):
		    $isActive = ($module === $activeModule);
		    $firstPath = $items[0][1] ?? '#';
		    $href = ($firstPath === '#') ? 'javascript:void(0)' : (BASE_URL . $firstPath);
		  ?>
		    <a class="module-link <?= $isActive ? 'active' : '' ?>" href="<?= h($href) ?>">
		      <?= h($module) ?>
		    </a>
		  <?php endforeach; ?>
		
		  <!-- Pricing (intentionally styled differently; not a module) -->
		  <a class="module-link module-link--pricing" href="<?= h(BASE_URL) ?>/pricing.php">
		    Pricing
		  </a>
		</nav>


        <div class="topbar-right">
          <?php if ($u): ?>
          <a class="icon-btn" href="<?= h(BASE_URL) ?>/settings.php" title="Settings" aria-label="Settings">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2"/>
                <path d="M19.4 15a7.97 7.97 0 0 0 .1-2l2-1.2-2-3.5-2.3.7a8.3 8.3 0 0 0-1.7-1L15 4h-6l-.5 3a8.3 8.3 0 0 0-1.7 1l-2.3-.7-2 3.5 2 1.2a7.97 7.97 0 0 0 .1 2l-2 1.2 2 3.5 2.3-.7a8.3 8.3 0 0 0 1.7 1L9 20h6l.5-3a8.3 8.3 0 0 0 1.7-1l2.3.7 2-3.5-2-1.2Z"
                      stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
              </svg>
            </a>
            <span class="muted" style="font-size:0.85rem;"><?= h($u['email']) ?></span>
            <a class="logout-link" href="<?= h(BASE_URL) ?>/logout.php">Logout</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Row 3: sub-items aligned under the module start -->
      <div class="topbar-row topbar-row--sub">
        <div class="topbar-left topbar-left--spacer" aria-hidden="true">
          <span class="logo-mark"><span class="logo-mark-inner"></span></span>
          <span class="nav-toggle" style="visibility:hidden">
            <span class="nav-ico">☰</span><span class="nav-text">Menu</span>
          </span>
        </div>
        <nav class="subbar" aria-label="Section">
          <?php foreach (($NAV[$activeModule] ?? []) as [$label, $path, $tag]):
            $href = ($path === '#') ? 'javascript:void(0)' : (BASE_URL . $path);
            $disabled = ($path === '#') ? 'disabled' : '';
            $isActive = ($path !== '#' && $path === $activeItemPath);
          ?>
            <a class="sub-link <?= $disabled ?> <?= $isActive ? 'active' : '' ?>" href="<?= h($href) ?>">
              <?= h($label) ?>
            </a>
          <?php endforeach; ?>
        </nav>
      </div>
    </header>

        <main class="content">
            <div class="page-head">
              <h1 class="page-title"><?= h($title) ?></h1>
            </div>
    <?php endif; ?>
    <?php
}

function page_footer(): void {
    ?>
        </main>
        <?php if (!empty($GLOBALS['HIDE_NAV'])): ?>
          </div>
        <?php else: ?>
        <footer class="footer">
            <div class="footer-inner">
                <span>© <?= date('Y') ?> Ready Set Shows</span>
                <span class="muted">Ops • v1</span>
            </div>
        </footer>
  </div>
</div>
        <?php endif; ?>
    <script src="<?= h(BASE_URL) ?>/assets/js/app.js"></script>
    </body>
    </html>
    <?php
}
