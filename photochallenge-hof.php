<?php
/**
 * SNAPSMACK - PHOTO CHALLENGE Hall of Fame
 *
 * The retained record of past winners for the photofri.day / artfri.day profile.
 * By design this is a TEXT LIST OF LINKS, never a gallery: the winning photos
 * stay on their makers' own sites, and the Hall of Fame only points back to
 * them. (photofri-day spec: "Hall of Fame = text list of LINKS, no images.")
 *
 * 404s unless the challenge profile is enabled. Self-contained §18 look.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/fediverse.php';
require_once __DIR__ . '/core/photochallenge.php';

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!pc_enabled($settings)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
       . '<body style="background:#111;color:#f4f4f4;font-family:Georgia,serif;text-align:center;padding:80px 20px;">'
       . '<h1 style="font-family:Arial Black,sans-serif;color:#D40000;">404</h1>'
       . '<p>No photo challenge runs here.</p></body>';
    exit;
}

$site      = rtrim(sv_base($settings), '/');
$board_url = $site . '/photochallenge-board.php';
$hof       = pc_hof_list($pdo, 500);

// Group the flat list by week_key, newest first, places ascending within a week.
$weeks = [];
foreach ($hof as $h) {
    $wk = (string)$h['week_key'];
    $weeks[$wk][] = $h;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PHOTO CHALLENGE — Hall of Fame</title>
<meta name="description" content="Past winners of the federated Photo Friday challenge — a record of links home to the work, not a gallery.">
<meta name="robots" content="index, follow">
<style>
  :root { --red:#D40000; --link:#F50A0A; --black:#111111; --ink:#f4f4f4; --muted:#9a9a9a; }
  * { box-sizing:border-box; margin:0; padding:0; }
  html,body { min-height:100%; }
  body {
    background:var(--black); color:var(--ink);
    font-family:Georgia,'Times New Roman',serif; -webkit-font-smoothing:antialiased;
    display:flex; flex-direction:column; min-height:100vh;
  }
  body::before {
    content:""; position:fixed; inset:0;
    background:radial-gradient(ellipse at 50% 0%, rgba(212,0,0,0.10), transparent 60%);
    pointer-events:none; z-index:0;
  }
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

  .head { position:relative; z-index:1; text-align:center; padding:44px 24px 20px; }
  .kicker {
    font-family:'Courier New',monospace; letter-spacing:0.42em; text-transform:uppercase;
    font-size:0.72rem; color:var(--red); padding-left:0.42em; margin-bottom:14px;
  }
  h1 {
    font-family:'Arial Black',Arial,sans-serif; font-weight:900; letter-spacing:-0.02em;
    line-height:0.92; font-size:clamp(2.1rem,7vw,3.8rem); text-transform:uppercase; color:var(--ink);
  }
  h1 .dot { color:var(--red); }
  .lede { margin-top:14px; color:var(--muted); font-size:0.95rem; max-width:40em; margin-inline:auto; line-height:1.6; }

  main { position:relative; z-index:1; flex:1; width:100%; max-width:760px; margin:0 auto; padding:20px 24px 60px; }
  .week { margin:28px 0 0; }
  .week h2 {
    font-family:'Courier New',monospace; font-size:0.8rem; letter-spacing:0.16em; text-transform:uppercase;
    color:var(--red); border-bottom:1px solid #2a2a2a; padding-bottom:8px; margin-bottom:6px;
  }
  ol { list-style:none; }
  .row { display:flex; align-items:baseline; gap:12px; padding:11px 2px; border-bottom:1px solid #1c1c1c; }
  .place {
    flex:none; font-family:'Arial Black',Arial,sans-serif; font-size:0.78rem; min-width:2.4em; text-align:center;
    padding:3px 0; background:#1a1a1a; color:var(--muted);
  }
  .place.gold { background:#e8b100; color:#111; }
  .place.silver { background:#c8c8c8; color:#111; }
  .place.bronze { background:#b5702e; color:#111; }
  .who { font-family:Georgia,serif; }
  .who a { color:var(--ink); text-decoration:none; border-bottom:1px solid var(--red); }
  .who a:hover { color:var(--link); }
  .cap { color:var(--muted); font-size:0.9rem; }
  .empty { text-align:center; color:var(--muted); padding:70px 20px; font-size:1.05rem; line-height:1.7; }

  footer { position:relative; z-index:1; border-top:3px solid var(--red); padding:22px 24px; text-align:center; }
  footer p { font-family:'Courier New',monospace; font-size:0.68rem; letter-spacing:0.06em; color:#888; }
  footer a { color:var(--link); text-decoration:none; }
  footer a:hover { text-decoration:underline; }
</style>
</head>
<body>

  <nav class="topnav" aria-label="Primary">
    <a class="topnav-brand" href="<?php echo $esc($site); ?>/">PHOTO<span class="dot">·</span>CHALLENGE</a>
    <div class="topnav-links">
      <a href="<?php echo $esc($board_url); ?>">The Board</a>
      <a href="" aria-current="page">Hall of Fame</a>
    </div>
  </nav>

  <header class="head">
    <p class="kicker">The Record</p>
    <h1>HALL OF FAME</h1>
    <p class="lede">Past winners of Photo Friday. Not a gallery &mdash; a list of links home. The photographs live where they were made; this only remembers, and points back.</p>
  </header>

  <main>
    <?php if (!$weeks): ?>
      <p class="empty">No winners yet.<br>The first Friday is still to come.</p>
    <?php else: ?>
      <?php foreach ($weeks as $wk => $entries): ?>
        <section class="week">
          <h2><?php echo $esc($wk); ?></h2>
          <ol>
            <?php foreach ($entries as $e):
                $pl = (int)$e['place'];
                $plClass = $pl === 1 ? 'gold' : ($pl === 2 ? 'silver' : ($pl === 3 ? 'bronze' : ''));
            ?>
              <li class="row">
                <span class="place <?php echo $plClass; ?>"><?php echo $pl; ?></span>
                <span class="who">
                  <?php /* SECAUDIT 047: scheme-guard federation URL */
                        $__pu = (string)($e['post_url'] ?? ''); $__psafe = preg_match('#^https?://#i', $__pu) ? $__pu : ''; ?>
                  <?php if ($__psafe !== ''): ?>
                    <a href="<?php echo $esc($__psafe); ?>" rel="noopener" target="_blank"><?php echo $esc($e['handle']); ?></a>
                  <?php else: ?>
                    <?php echo $esc($e['handle']); ?>
                  <?php endif; ?>
                  <?php if (($e['caption'] ?? '') !== ''): ?>
                    <span class="cap">&mdash; <?php echo $esc($e['caption']); ?></span>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <footer>
    <p>
      <a href="<?php echo $esc($board_url); ?>">This week's board</a>
      &middot; Part of the <a href="https://snapsmack.ca/">SNAPSMACK</a> network
    </p>
  </footer>

</body>
</html>
<?php // ===== SNAPSMACK EOF =====
