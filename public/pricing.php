<?php
// pricing.php
require_once __DIR__ . "/_layout.php";

// Hide the app nav when viewing pricing as a public/anonymous visitor
$HIDE_NAV = empty($_SESSION["user_id"]);
page_header('Pricing');

// Fetch user + current plan (NO billing logic — just display)
$currentTier = "free"; // default for logged-out / public
$user = null;

if (!empty($_SESSION["user_id"])) {
	$pdo = db();
	$stmt = $pdo->prepare("SELECT id, email, subscription_tier FROM users WHERE id = ?");
	$stmt->execute([$_SESSION["user_id"]]);
	$user = $stmt->fetch();
	
	if (!empty($user["subscription_tier"])) {
		$currentTier = $user["subscription_tier"];
	} else {
		$currentTier = "free";
	}
}

function tier_badge(string $tierKey, string $currentTier): string {
	if ($tierKey === $currentTier) return '<span class="pill pill-current">Current</span>';
	return '';
}

function cta_href(string $tierKey, bool $loggedIn): string {
	if ($tierKey === "network") return "roadmap.php";
	if ($loggedIn) return "settings.php";
	return "register.php";
}

function cta_text(string $tierKey, bool $loggedIn): string {
	if ($tierKey === "network") return "Join the Waitlist";
	if ($loggedIn) return "Manage Plan";
	if ($tierKey === "free") return "Create Free Profile";
	if ($tierKey === "pro") return "Upgrade to Pro";
	if ($tierKey === "studio") return "Upgrade to Studio";
	return "Get Started";
}

$loggedIn = !empty($_SESSION["user_id"]);

$tiers = [
		"free" => [
				"icon" => "🟢",
				"name" => "Get Seen",
				"price_mo" => "$0",
				"price_yr" => "",
				"sub" => "Be discoverable. Get booked. Pay nothing.",
				"profiles" => "1 profile",
				"bullets" => [
						"Public profile + appear in discovery",
						"Receive booking inquiries",
						"Basic dashboard (upcoming / pending)",
						"Respond to inquiries",
				],
				"tools" => "Tools: —",
				"accent" => "free",
				"ribbon" => "",
		],
		"pro" => [
				"icon" => "🔵",
				"name" => "Book More. Stress Less.",
				"price_mo" => "$10 / month",
				"price_yr" => "$100 / year",
				"sub" => "Your working toolkit for bookings, calendar, and availability.",
				"profiles" => "Up to 3 profiles",
				"bullets" => [
						"Full bookings management (pending + confirmed)",
						"Calendar sync (Google / ICS)",
						"Availability tools",
						"Printable show sheets",
						"Basic CRM (contacts + notes)",
						"Basic earnings tracking",
				],
				"tools" => "Tools: Core (Calendar, Availability, Print, CRM basics)",
				"accent" => "pro",
				"ribbon" => "Most Popular",
		],
		"studio" => [
				"icon" => "🟣",
				"name" => "Run It Like a Business",
				"price_mo" => "$20 / month",
				"price_yr" => "$200 / year",
				"sub" => "Advanced tools for serious volume, growth, and automation.",
				"profiles" => "Up to 5 profiles",
				"bullets" => [
						"Everything in Pro",
						"Bulk show uploads (Bands-in-Town style)",
						"Advanced CRM (tags + follow-ups)",
						"Website widgets (dates / availability)",
						"Social helpers (share packs, quick links)",
						"Multi-profile management",
				],
				"tools" => "Tools: Advanced (Bulk, Widgets, CRM+, Social)",
				"accent" => "studio",
				"ribbon" => "",
		],
		"network" => [
				"icon" => "⚫",
				"name" => "Network",
				"price_mo" => "$30 / month",
				"price_yr" => "$300 / year",
				"sub" => "For promoters, agencies, and venue groups running at scale.",
				"profiles" => "Unlimited profiles",
				"bullets" => [
						"Everything in Studio",
						"Team members & roles (coming soon)",
						"Organization-wide reporting (coming soon)",
						"Priority discovery placement (coming soon)",
						"Advanced integrations (coming soon)",
				],
				"tools" => "Tools: Org (Teams, Reporting, Integrations) — Coming Soon",
				"accent" => "network",
				"ribbon" => "Coming Soon",
		],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<style>
  /* Pricing-specific styles — keep isolated to avoid clobbering app */
  .pricing-wrap { padding: 10px 0 34px; max-width: 1180px; margin: 0 auto; }
  .pricing-hero { padding: 6px 0 14px; }
  .pricing-hero h1 { font-size: 34px; line-height: 1.05; margin: 0 0 10px; letter-spacing: -0.02em; }
  .pricing-hero p { margin: 0 0 12px; color: rgba(15,23,42,.70); max-width: 70ch; }
  .pricing-hero .micro { color: rgba(15,23,42,.58); font-size: 13px; }

  .tier-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 14px; margin-top: 14px; }
  @media (max-width: 1060px) { .tier-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
  @media (max-width: 640px)  { .tier-grid { grid-template-columns: 1fr; } }

  .tier {
    background: #fff;
    border: 1px solid rgba(15,23,42,.10);
    border-radius: 18px;
    box-shadow: 0 18px 60px rgba(2,6,23,.06);
    padding: 18px;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 430px;
  }
  .tier .top { display:flex; justify-content:space-between; align-items:flex-start; gap: 10px; }
  .tier .icon { font-size: 22px; }
  .tier .name { font-size: 18px; font-weight: 850; margin: 4px 0 4px; }
  .tier .sub { color: rgba(15,23,42,.68); margin: 0 0 12px; }
  .tier .price { font-size: 26px; font-weight: 900; letter-spacing: -0.02em; margin: 8px 0 4px; }
  .tier .price small { font-size: 14px; color: rgba(15,23,42,.62); font-weight: 650; margin-left: 6px; }
  .tier .profiles { margin: 6px 0 10px; font-weight: 750; color: rgba(15,23,42,.82); }

  .tier ul { padding-left: 18px; margin: 10px 0 0; color: rgba(15,23,42,.72); }
  .tier li { margin: 7px 0; }

  .tier .tools { margin-top: 10px; font-size: 13px; color: rgba(15,23,42,.60); }
  .tier .footer { margin-top: auto; padding-top: 14px; display:flex; align-items:center; justify-content:space-between; gap: 12px; }
  .tier .cta { display:inline-flex; align-items:center; justify-content:center; padding: 10px 14px; border-radius: 999px; text-decoration:none; font-weight: 800; border: 1px solid rgba(15,23,42,.14); background: #fff; }
  .tier .cta:hover { filter: brightness(0.98); }
  .tier .cta.primary { background: #7c3aed; border-color:#7c3aed; color:#fff; }
  .tier .cta.primary:hover { background: #5b21b6; border-color:#5b21b6; }

  .pill { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding: 6px 10px; font-size: 12px; font-weight: 800; border: 1px solid rgba(15,23,42,.14); background: rgba(15,23,42,.03); color: rgba(15,23,42,.78); white-space: nowrap; }
  .pill-hot { background: rgba(124,58,237,.10); border-color: rgba(124,58,237,.22); color: rgba(91,33,182,1); }
  .pill-soon { background: rgba(2,6,23,.05); border-color: rgba(2,6,23,.12); color: rgba(2,6,23,.80); }
  .pill-current { background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.20); color: rgba(21,128,61,1); }

  .compare { margin-top: 22px; background:#fff; border:1px solid rgba(15,23,42,.10); border-radius: 18px; overflow:hidden; box-shadow: 0 18px 60px rgba(2,6,23,.05); }
  .compare h2 { margin: 0; padding: 16px 18px; border-bottom: 1px solid rgba(15,23,42,.10); font-size: 16px; letter-spacing: -0.01em; }
  .compare table { width:100%; border-collapse: collapse; }
  .compare th, .compare td { padding: 12px 12px; border-bottom: 1px solid rgba(15,23,42,.08); vertical-align: top; text-align:left; }
  .compare th { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: rgba(15,23,42,.62); background: rgba(15,23,42,.02); }
  .compare td { color: rgba(15,23,42,.78); }
  .check { font-weight: 900; }
  .lock { color: rgba(15,23,42,.40); }

  .callouts { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; margin-top: 16px; }
  @media (max-width: 900px){ .callouts{ grid-template-columns: 1fr; } }
  .callout { background:#fff; border:1px solid rgba(15,23,42,.10); border-radius: 18px; padding: 14px 16px; box-shadow: 0 18px 60px rgba(2,6,23,.04); }
  .callout strong { display:block; margin-bottom: 6px; }
  .callout span { color: rgba(15,23,42,.68); }

  .faq { margin-top: 18px; background:#fff; border:1px solid rgba(15,23,42,.10); border-radius: 18px; overflow:hidden; box-shadow: 0 18px 60px rgba(2,6,23,.04); }
  .faq h2 { margin:0; padding: 16px 18px; border-bottom: 1px solid rgba(15,23,42,.10); font-size: 16px; letter-spacing: -0.01em; }
  .faq .q { padding: 14px 18px; border-bottom: 1px solid rgba(15,23,42,.08); }
  .faq .q:last-child { border-bottom: 0; }
  .faq .q strong { display:block; margin-bottom: 6px; }
  .faq .q p { margin: 0; color: rgba(15,23,42,.70); }

  /* Billing toggle */
  .billing-toggle{
    margin-top: 12px;
    display: inline-flex;
    border: 1px solid rgba(15,23,42,.12);
    background: rgba(255,255,255,.75);
    border-radius: 999px;
    padding: 4px;
    gap: 4px;
    backdrop-filter: blur(10px);
  }
  .bill-btn{
    border: 0;
    background: transparent;
    padding: 9px 12px;
    border-radius: 999px;
    cursor: pointer;
    font-weight: 850;
    color: rgba(15,23,42,.72);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    line-height: 1;
  }
  .bill-btn:hover{ background: rgba(15,23,42,.05); }
  .bill-btn.active{
    background: #fff;
    color: rgba(15,23,42,.92);
    box-shadow: 0 10px 24px rgba(2,6,23,.08);
    border: 1px solid rgba(15,23,42,.10);
  }
  .bill-btn .save{
    font-size: 12px;
    font-weight: 900;
    color: rgba(91,33,182,1);
    background: rgba(124,58,237,.10);
    border: 1px solid rgba(124,58,237,.18);
    padding: 4px 8px;
    border-radius: 999px;
  }

  /* Price swap */
  .price .yr { display:none; }
  body.billing-yearly .price .mo { display:none; }
  body.billing-yearly .price .yr { display:inline; }
  .price span { display:inline; }
  .price .yr { font-size: 26px; font-weight: 900; letter-spacing: -0.02em; margin: 8px 0 4px;  }

</style>
</head>
<body>

<div class="pricing-wrap">
  <div class="pricing-hero">
    <h1>Simple pricing for every stage of live entertainment.</h1>
    <p>
      Discover shows for free. Get booked for free.
      Upgrade only when you want more power — bookings, calendar, tools, and automation.
    </p>
    <p class="micro">No contracts • Cancel anytime • Upgrade or downgrade instantly</p>
  
    <div class="billing-toggle" role="group" aria-label="Billing period">
      <button type="button" class="bill-btn active" data-billing="monthly">Monthly</button>
      <button type="button" class="bill-btn" data-billing="yearly">Yearly <span class="save">Save 2 months</span></button>
    </div>
</div>

  <div class="tier-grid">
    <?php foreach ($tiers as $key => $t): ?>
      <section class="tier tier-<?= h($t["accent"]) ?>">
        <div class="top">
          <div>
            <div class="icon"><?= h($t["icon"]) ?></div>
            <div class="name"><?= h($t["name"]) ?></div>
          </div>

          <div style="display:flex; gap:8px; align-items:center;">
            <?php if (!empty($t["ribbon"])): ?>
              <span class="pill <?= $key === "pro" ? "pill-hot" : ($key === "network" ? "pill-soon" : "") ?>">
                <?= h($t["ribbon"]) ?>
              </span>
            <?php endif; ?>
            <?= tier_badge($key, $currentTier) ?>
          </div>
        </div>

        <div class="price">
          <span class="mo"><?= h($t["price_mo"]) ?></span>
          <?php if (!empty($t["price_yr"])): ?>
            <span class="yr"><?= h($t["price_yr"]) ?></span>
          <?php endif; ?>
        </div>

        <p class="sub"><?= h($t["sub"]) ?></p>
        <div class="profiles"><?= h($t["profiles"]) ?></div>

        <ul>
          <?php foreach ($t["bullets"] as $b): ?>
            <li><?= h($b) ?></li>
          <?php endforeach; ?>
        </ul>

        <div class="tools"><?= h($t["tools"]) ?></div>

        <div class="footer">
          <a class="cta <?= ($key === "pro") ? "primary" : "" ?> <?= ($key === "network") ? "disabled" : "" ?>"
             href="<?= h(cta_href($key, $loggedIn)) ?>">
            <?= h(cta_text($key, $loggedIn)) ?>
          </a>

          <?php if ($key === "network"): ?>
            <span class="pill pill-soon">Coming Soon</span>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <div class="compare">
    <h2>Compare plans (aligned to your menu)</h2>
    <table>
      <thead>
        <tr>
          <th style="width: 22%;">Menu Item</th>
          <th>Free</th>
          <th>Pro<br><span style="font-weight:650; color:rgba(15,23,42,.55);">$10/mo • $100/yr</span></th>
          <th>Studio<br><span style="font-weight:650; color:rgba(15,23,42,.55);">$20/mo • $200/yr</span></th>
          <th>Network<br><span style="font-weight:650; color:rgba(15,23,42,.55);">$30/mo • $300/yr</span></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Dashboard</strong></td>
          <td><span class="check">✅</span> basic</td>
          <td><span class="check">✅</span> full</td>
          <td><span class="check">✅</span> advanced</td>
          <td><span class="check">✅</span> advanced</td>
        </tr>
        <tr>
          <td><strong>Bookings</strong></td>
          <td><span class="lock">🔒</span> limited</td>
          <td><span class="check">✅</span> full</td>
          <td><span class="check">✅</span> full + advanced</td>
          <td><span class="check">✅</span> full + org</td>
        </tr>
        <tr>
          <td><strong>Profile</strong></td>
          <td><span class="check">✅</span> 1</td>
          <td><span class="check">✅</span> up to 3</td>
          <td><span class="check">✅</span> up to 5</td>
          <td><span class="check">✅</span> unlimited</td>
        </tr>
        <tr>
          <td><strong>Tools</strong></td>
          <td><span class="lock">❌</span> —</td>
          <td><span class="check">✅</span> core tools</td>
          <td><span class="check">✅</span> advanced tools</td>
          <td><span class="check">✅</span> org tools <em>(coming soon)</em></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="callouts">
    <div class="callout">
      <strong>Public browsing stays free.</strong>
      <span>People can discover shows and send inquiries without creating an account.</span>
    </div>
    <div class="callout">
      <strong>Free still gets you booked.</strong>
      <span>Paid plans unlock power tools, automation, and time-savings — not access to customers.</span>
    </div>
    <div class="callout">
      <strong>Ridiculous value by design.</strong>
      <span>You’re replacing multiple tools with one system built for live entertainment.</span>
    </div>
  </div>

  <div class="faq">
    <h2>FAQ</h2>
    <div class="q">
      <strong>Do I need an account to browse or hire entertainment?</strong>
      <p>No. Anyone can browse, search by date range, and send inquiries without an account.</p>
    </div>
    <div class="q">
      <strong>Can I get booked on the Free plan?</strong>
      <p>Yes. Paid plans give you better tools, more profiles, and more control — not the ability to receive inquiries.</p>
    </div>
    <div class="q">
      <strong>Can I upgrade later?</strong>
      <p>Anytime. Your data stays with you, and you can upgrade or downgrade whenever you want.</p>
    </div>
    <div class="q">
      <strong>What does “Coming Soon” mean for Network?</strong>
      <p>Network pricing is here so organizations can plan ahead. Team features, reporting, and deeper integrations will roll out as the platform grows.</p>
    </div>
  </div>

</div>


<script>
(function(){
  const KEY = "rss_billing";
  const root = document.body;
  const buttons = Array.from(document.querySelectorAll(".bill-btn"));
  if (!buttons.length) return;

  function setBilling(mode){
    if (mode === "yearly") {
      root.classList.add("billing-yearly");
    } else {
      root.classList.remove("billing-yearly");
      mode = "monthly";
    }
    buttons.forEach(btn => btn.classList.toggle("active", btn.dataset.billing === mode));
    try { localStorage.setItem(KEY, mode); } catch(e) {}
  }

  // init
  let mode = "monthly";
  try {
    const saved = localStorage.getItem(KEY);
    if (saved) mode = saved;
  } catch(e) {}
  setBilling(mode);

  // clicks
  buttons.forEach(btn => btn.addEventListener("click", () => setBilling(btn.dataset.billing)));
})();
</script>

</body>
</html>
