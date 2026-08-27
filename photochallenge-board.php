<?php
/**
 * SNAPSMACK - PHOTO CHALLENGE public board
 *
 * The live board for the photofri.day / artfri.day profile running on this
 * install's SMACKVERSE actor. Reads core/photochallenge.php's pc_board_ranked()
 * — which reads only the CMS's own snap_ap_timeline — so NO participant image is
 * stored or re-hosted here: every card hotlinks the origin thumbnail and links,
 * rel=canonical, back to the origin post. Tease then eject.
 *
 * 404s unless the challenge profile is enabled (photochallenge_enabled = 1).
 * Self-contained: the §18 look (red #D40000 on black, Arial Black display,
 * Courier kickers, Georgia body) is inline, no external assets.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/smackverse.php';
require_once __DIR__ . '/core/photochallenge.php';

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!pc_enabled($settings) || !pc_feed_enabled($settings)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
       . '<body style="background:#111;color:#f4f4f4;font-family:Georgia,serif;text-align:center;padding:80px 20px;">'
       . '<h1 style="font-family:Arial Black,sans-serif;color:#D40000;">404</h1>'
       . '<p>The challenge feed is not available.</p></body>';
    exit;
}

$win  = pc_window($settings);
$tag  = pc_tag($settings);
$rows = pc_board_ranked($pdo, $settings, $win, 120);
$site = rtrim(sv_base($settings), '/');
$hof_url = $site . '/photochallenge-hof.php';
$state   = $win['open'] ? 'OPEN' : 'CLOSED';
$title   = 'PHOTO FRIDAY — ' . $win['label'];
$feed_layout = (($settings['photochallenge_feed_layout'] ?? 'three') === 'masonry') ? 'masonry' : 'three';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $esc($title); ?></title>
<meta name="description" content="This week's Photo Friday board — entries tagged #<?php echo $esc($tag); ?>, every photo pointing home to its maker.">
<meta name="robots" content="index, follow">
<style>
  :root { --red:#D40000; --link:#F50A0A; --black:#111111; --ink:#f4f4f4; --muted:#9a9a9a; }
  * { box-sizing:border-box; margin:0; padding:0; }
  html,body { min-height:100%; }
  body {
    background:var(--black); color:var(--ink);
    font-family:Georgia,'Times New Roman',serif;
    -webkit-font-smoothing:antialiased;
    display:flex; flex-direction:column; min-height:100vh;
  }
  body::before {
    content:""; position:fixed; inset:0;
    background:radial-gradient(ellipse at 50% 0%, rgba(212,0,0,0.10), transparent 60%);
    pointer-events:none; z-index:0;
  }
  a { color:var(--link); }
  .topnav {
    position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between;
    gap:16px; padding:18px 24px; border-bottom:1px solid #2a2a2a; flex-wrap:wrap;
  }
  .topnav-brand {
    font-family:'Arial Black',Arial,sans-serif; text-transform:uppercase; letter-spacing:-0.01em;
    font-size:0.95rem; color:var(--ink); text-decoration:none;
  }
  .topnav-brand .dot { color:var(--red); }
  .topnav-links { display:flex; gap:22px; flex-wrap:wrap; }
  .topnav-links a {
    font-family:Arial,Helvetica,sans-serif; font-weight:700; text-transform:uppercase;
    letter-spacing:0.04em; font-size:0.8rem; color:var(--ink); opacity:0.68; text-decoration:none; padding-bottom:3px;
  }
  .topnav-links a:hover, .topnav-links a[aria-current="page"] { color:var(--link); opacity:1; }
  .topnav-links a[aria-current="page"] { border-bottom:2px solid var(--red); }

  .head { position:relative; z-index:1; text-align:center; padding:44px 24px 26px; }
  .kicker {
    font-family:'Courier New',monospace; letter-spacing:0.42em; text-transform:uppercase;
    font-size:0.72rem; color:var(--red); padding-left:0.42em; margin-bottom:14px;
  }
  h1 {
    font-family:'Arial Black',Arial,sans-serif; font-weight:900; letter-spacing:-0.02em;
    line-height:0.92; font-size:clamp(2.1rem,7vw,3.8rem); text-transform:uppercase; color:var(--ink);
  }
  h1 .dot { color:var(--red); }
  .state {
    display:inline-block; margin-top:16px; font-family:'Arial Black',Arial,sans-serif;
    text-transform:uppercase; letter-spacing:0.14em; font-size:0.74rem; padding:8px 16px;
  }
  .state.open   { background:var(--red); color:#fff; }
  .state.closed { border:2px solid var(--red); color:var(--ink); }
  .subhead { margin-top:14px; color:var(--muted); font-size:0.95rem; }
  .subhead code { color:var(--ink); border-bottom:1px solid var(--red); }

  main { position:relative; z-index:1; flex:1; width:100%; max-width:1180px; margin:0 auto; padding:10px 20px 60px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:14px; }
  .card {
    position:relative; display:block; text-decoration:none; color:var(--ink);
    background:#0b0b0b; border:1px solid #262626; overflow:hidden;
    transition:border-color 0.15s, transform 0.15s;
  }
  .card:hover, .card:focus-visible { border-color:var(--red); transform:translateY(-2px); outline:none; }
  .thumb { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; background:#1a1a1a; }
  .thumb.none {
    display:flex; align-items:center; justify-content:center;
    font-family:'Courier New',monospace; font-size:0.7rem; color:var(--muted);
  }
  .meta { display:flex; align-items:center; gap:8px; padding:10px 12px; }
  .by { font-family:'Courier New',monospace; font-size:0.74rem; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .rank {
    flex:none; font-family:'Arial Black',Arial,sans-serif; font-size:0.72rem;
    background:var(--red); color:#fff; padding:3px 7px; letter-spacing:0.04em;
  }
  .rank.gold   { background:#e8b100; color:#111; }
  .rank.silver { background:#c8c8c8; color:#111; }
  .rank.bronze { background:#b5702e; color:#111; }
  .tag-badge {
    flex:none; font-family:'Courier New',monospace; font-size:0.62rem; color:var(--muted);
    border:1px solid #333; padding:2px 5px; text-transform:uppercase;
  }
  .empty {
    text-align:center; color:var(--muted); padding:70px 20px; font-size:1.1rem; line-height:1.7;
  }
  .empty code { color:var(--ink); border-bottom:1px solid var(--red); }
  footer {
    position:relative; z-index:1; border-top:3px solid var(--red); padding:22px 24px; text-align:center;
  }
  footer p { font-family:'Courier New',monospace; font-size:0.68rem; letter-spacing:0.06em; color:#888; }
  footer a { color:var(--link); text-decoration:none; }
  footer a:hover { text-decoration:underline; }
</style>
<link rel="stylesheet" href="<?php echo $esc($site); ?>/assets/css/photochallenge-board-layouts.css?v=<?php echo $esc(defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '1'); ?>">
</head>
<body>

  <nav class="topnav" aria-label="Primary">
    <a class="topnav-brand" href="<?php echo $esc($site); ?>/">PHOTO<span class="dot">·</span>CHALLENGE</a>
    <div class="topnav-links">
      <a href="" aria-current="page">The Board</a>
      <a href="<?php echo $esc($hof_url); ?>">Hall of Fame</a>
    </div>
  </nav>

  <header class="head">
    <p class="kicker">The Federated Photo Challenge</p>
    <h1>PHOTO FRIDAY</h1>
    <div><span class="state <?php echo strtolower($state); ?>"><?php echo $state; ?> &middot; <?php echo $esc($win['label']); ?></span></div>
    <p class="subhead">Post a photo tagged <code>#<?php echo $esc($tag); ?></code> and follow to join &mdash; every card points home to its maker.</p>
  </header>

  <main>
    <?php if (!$rows): ?>
      <p class="empty">
        No entries yet.<br>
        Post a photo with <code>#<?php echo $esc($tag); ?></code> from your own site or instance,<br>
        and follow this challenge to join the board.
      </p>
    <?php else: ?>
      <div class="grid grid--<?php echo $esc($feed_layout); ?>">
        <?php foreach ($rows as $r):
            $rank = (int)($r['rank'] ?? 0);
            $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
        ?>
          <?php /* SECAUDIT 047: scheme-guard federation URL — only http(s) becomes a live href */
                $__u = (string)($r['url'] ?? ''); $__safe = preg_match('#^https?://#i', $__u) ? $__u : ''; ?>
          <a class="card" href="<?php echo $__safe !== '' ? $esc($__safe) : '#'; ?>" rel="canonical noopener" target="_blank"
             title="<?php echo $esc($r['excerpt']); ?>">
            <?php if (($r['thumb'] ?? '') !== ''): ?>
              <img class="thumb" loading="lazy" src="<?php echo $esc($r['thumb']); ?>" alt="Photo by <?php echo $esc($r['handle']); ?>">
            <?php else: ?>
              <span class="thumb none">no preview</span>
            <?php endif; ?>
            <span class="meta">
              <?php if ($rank > 0): ?><span class="rank <?php echo $rankClass; ?>">#<?php echo $rank; ?></span><?php endif; ?>
              <span class="by"><?php echo $esc($r['handle']); ?></span>
              <?php if (!empty($r['horsconcours'])): ?><span class="tag-badge" title="hors concours">hc</span>
              <?php elseif (!empty($r['is_boost'])): ?><span class="tag-badge">boost</span><?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <footer>
    <p>
      No photo stored here &mdash; every image loads from, and links back to, its origin.
      &middot; <a href="<?php echo $esc($hof_url); ?>">Hall of Fame</a>
      &middot; Part of the <a href="https://snapsmack.ca/">SNAPSMACK</a> network
    </p>
  </footer>

</body>
</html>
<?php // ===== SNAPSMACK EOF =====
