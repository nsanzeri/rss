<?php
// pricing.php
require_once __DIR__ . "/_layout.php";
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
		// If you don't have subscription_tier yet, you can safely keep "free"
		$currentTier = "free";
	}
}

// Tier definitions (copy-only now; limits later)
$tiers = [
		"free" => [
				"name" => "Backstage Pass",
				"label" => "Free — Public",
				"price" => "$0",
				"tagline" => "For fans, collaborators, and getting started",
				"bullets" => [
						"View public availability links",
						"Share calendars",
						"Discover artists and venues",
						"No account required for viewing"
				],
				"cta" => ["text" => "Browse public links", "href" => "public.php"], // adjust as needed
				"footnote" => "Perfect for the public side of the marketplace."
		],
		"solo" => [
				"name" => "Headliner Solo",
				"label" => "Solo",
				"price" => "Coming soon",
				"tagline" => "For independent musicians and solo performers",
				"bullets" => [
						"1 user",
						"Manage your personal availability",
						"Import external calendars",
						"Share booking links",
						"Basic profile and branding"
				],
				"cta" => ["text" => "Start Solo", "href" => "register.php"], // or onboarding later
				"footnote" => "Designed for working solo acts."
		],
		"team" => [
				"name" => "Headliner Crew",
				"label" => "Band / Team",
				"price" => "Coming soon",
				"tagline" => "For bands, duos, and small teams",
				"bullets" => [
						"Multiple musicians under one act",
						"Shared availability",
						"Roles (admin / member)",
						"Coordinate schedules across members",
						"Band calendars"
				],
				"cta" => ["text" => "Create a Team", "href" => "register.php"],
				"footnote" => "Ideal for groups that book together."
		],
		"venue" => [
				"name" => "House Booker",
				"label" => "Venue / Manager",
				"price" => "Coming soon",
				"tagline" => "For venues, booking agents, and promoters",
				"bullets" => [
						"Manage multiple venues",
						"Separate calendars per venue",
						"Delegate booking access to staff",
						"Accept booking inquiries",
						"Embed calendars on your site"
				],
				"cta" => ["text" => "Manage Venues", "href" => "register.php"],
				"footnote" => "Built for people who manage shows — not just play them."
		],
		"pro" => [
				"name" => "Circuit Pro",
				"label" => "Marketplace (Later)",
				"price" => "Later",
				"tagline" => "For agencies and high-volume operators",
				"bullets" => [
						"Cross-entity booking (venues ↔ bands ↔ artists)",
						"Discovery tools",
						"Advanced reporting",
						"Priority placement"
				],
				"cta" => ["text" => "See the roadmap", "href" => "roadmap.php"], // optional
				"footnote" => "We’re building toward this — without rushing it."
		],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<style>
  /* Pricing-specific styles (avoid clobbering layout .page-head) */
  .pricing-wrap { padding: 6px 0 26px; max-width: 1180px; margin: 0 auto; }
  .pricing-top { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin: 6px 0 18px; }
  .pricing-top p { margin: 0; opacity: 0.85; max-width: 760px; }

  .badge-current {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(0,0,0,0.18);
    backdrop-filter: blur(8px);
    font-size: 13px;
    white-space: nowrap;
  }
  .badge-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: rgba(255,215,120,0.95);
    box-shadow: 0 0 16px rgba(255,215,120,0.45);
  }

  .pricing-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:16px; margin-top: 18px; }
  .tier-card {
    grid-column: span 6;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(0,0,0,0.22);
    box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    padding: 18px;
    position: relative;
    overflow: hidden;
  }
  @media (min-width: 980px) { .tier-card { grid-column: span 4; } .tier-card.wide { grid-column: span 6; } }
  @media (max-width: 720px) { .tier-card { grid-column: span 12; } .pricing-top { flex-direction:column; align-items:flex-start; } }

  .tier-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:8px; }
  .tier-kicker { font-size: 12px; letter-spacing: 0.14em; text-transform: uppercase; opacity: 0.8; }
  .tier-name { margin: 8px 0 0; font-size: 20px; letter-spacing: 0.2px; }
  .tier-tagline { margin: 8px 0 0; opacity: 0.85; font-size: 14px; }
  .tier-price { text-align:right; font-weight:700; font-size:18px; margin-top:2px; opacity:0.95; }

  .pill-current {
    display:inline-flex; align-items:center; gap:8px;
    padding: 7px 10px; border-radius:999px;
    border: 1px solid rgba(255,215,120,0.28);
    background: rgba(255,215,120,0.08);
    font-size: 12px;
    margin-top: 10px;
  }

  .tier-bullets { margin:14px 0 0; padding:0; list-style:none; display:grid; gap:10px; }
  .tier-bullets li { display:flex; gap:10px; align-items:flex-start; opacity:0.92; font-size:14px; }
  .check {
    margin-top: 2px; width:18px; height:18px; border-radius:6px;
    border:1px solid rgba(255,255,255,0.14); background: rgba(0,0,0,0.18);
    display:inline-flex; align-items:center; justify-content:center; font-size:12px; line-height:1;
  }

  .tier-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; padding-top:14px; border-top:1px solid rgba(255,255,255,0.10); }
  .tier-footnote { opacity:0.75; font-size:12.5px; margin:0; }

  .btn {
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    padding: 10px 12px; border-radius:12px;
    border:1px solid rgba(255,255,255,0.14); background: rgba(0,0,0,0.22);
    color: inherit; text-decoration:none; font-weight:600; font-size:13px; white-space:nowrap;
    transition: transform 120ms ease, border-color 120ms ease;
  }
  .btn:hover { transform: translateY(-1px); border-color: rgba(255,215,120,0.35); }
  .btn.primary { border-color: rgba(255,215,120,0.28); background: rgba(255,215,120,0.08); }

  .callout {
    margin-top: 18px;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(0,0,0,0.18);
    padding: 16px 18px;
    opacity: 0.92;
  }
  .callout strong { font-weight: 700; }
</style>

<div class="pricing-wrap">

  <div class="pricing-top">
    <p>Simple pricing that scales with how you manage shows — solo, band, or venues. No billing hooked up yet; this is the roadmap and positioning.</p>

    <div class="badge-current" title="Display only — no billing logic yet">
      <span class="badge-dot"></span>
      Current plan: <strong><?= h(strtoupper($currentTier)) ?></strong>
    </div>
  </div>

  <div class="pricing-grid">
    <?php foreach ($tiers as $key => $t): ?>
      <section class="tier-card <?= ($key === "pro") ? "wide" : "" ?>">
        <div class="tier-top">
          <div>
            <div class="tier-kicker"><?= h($t["label"]) ?></div>
            <div class="tier-name"><?= h($t["name"]) ?></div>
            <div class="tier-tagline"><?= h($t["tagline"]) ?></div>

            <?php if ($currentTier === $key): ?>
              <div class="pill-current">
                <span class="badge-dot" style="width:7px;height:7px;"></span>
                You’re on this plan
              </div>
            <?php endif; ?>
          </div>

          <div class="tier-price"><?= h($t["price"]) ?></div>
        </div>

        <ul class="tier-bullets">
          <?php foreach ($t["bullets"] as $b): ?>
            <li><span class="check">✓</span><span><?= h($b) ?></span></li>
          <?php endforeach; ?>
        </ul>

        <div class="tier-footer">
          <p class="tier-footnote"><?= h($t["footnote"]) ?></p>
          <a class="btn <?= ($key === "solo") ? "primary" : "" ?>" href="<?= h($t["cta"]["href"]) ?>">
            <?= h($t["cta"]["text"]) ?>
          </a>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <div class="callout">
    <strong>Start simple. Upgrade only when you need to.</strong>
    Your calendars, links, and org structure can grow without rework — the system is designed around roles, entities, and limits.
  </div>

</div>
</body>
</html>
