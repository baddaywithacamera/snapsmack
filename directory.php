<?php
/**
 * SNAPSMACK — photoblogs.fyi PUBLIC DIRECTORY  [0.7.547]
 *
 * Renders the APPROVED (state='active') directory listings. Browse by topic,
 * search, sort — all server-side, no JavaScript. This is a people/site listing;
 * photographs belong to the separate public feed.
 * Reachable at /directory.php (a pretty /directory rewrite is a follow-up).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';   // $pdo

$rows = [];
try {
    $has_feed_health = (bool)$pdo->query("SHOW COLUMNS FROM snap_directory_listings LIKE 'feed_status'")->fetchColumn();
    $where = $has_feed_health ? "state='active' AND feed_status<>'dead'" : "state='active'";
    $order = $has_feed_health ? 'COALESCE(last_post_at,updated_at)' : 'updated_at';
    $rows = $pdo->query("SELECT * FROM snap_directory_listings WHERE {$where} ORDER BY {$order} DESC LIMIT 500")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

// Decode directory metadata once. Samples are retained for API compatibility,
// but the directory is a blog listing and does not render feed photographs.
foreach ($rows as &$r) {
    $r['_topics']  = json_decode((string)($r['topics'] ?? '[]'), true) ?: [];
    $r['_samples'] = json_decode((string)($r['samples'] ?? '[]'), true) ?: [];
}
unset($r);

// Topic counts across all active listings (for the chips).
$topic_counts = [];
foreach ($rows as $r) {
    foreach ($r['_topics'] as $t) {
        $k = trim((string)$t);
        if ($k === '') continue;
        $topic_counts[$k] = ($topic_counts[$k] ?? 0) + 1;
    }
}
uksort($topic_counts, 'strcasecmp');

// Filters (server-side).
$q        = trim((string)($_GET['q'] ?? ''));
$topic    = trim((string)($_GET['topic'] ?? ''));
$sort     = (string)($_GET['sort'] ?? 'recent');
if (!in_array($sort, ['recent', 'name'], true)) $sort = 'recent';
$filtered = [];
foreach ($rows as $r) {
    if ($topic !== '') {
        $hit = false;
        foreach ($r['_topics'] as $t) { if (strcasecmp(trim((string)$t), $topic) === 0) { $hit = true; break; } }
        if (!$hit) continue;
    }
    if ($q !== '') {
        $hay = strtolower($r['name'] . ' ' . $r['host'] . ' ' . $r['handle'] . ' ' . implode(' ', $r['_topics']));
        if (strpos($hay, strtolower($q)) === false) continue;
    }
    $filtered[] = $r;
}
if ($sort === 'name') {
    usort($filtered, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
} else {
    // Default discovery order: recent blogs dominate, with a deterministic
    // daily rotation of older-but-still-active blogs woven in for fairness.
    // Stable-for-the-day ordering avoids a disorienting reshuffle on refresh.
    $inactive_before = strtotime('-30 days');
    $recent = [];
    $inactive = [];
    foreach ($filtered as $listing) {
        $updated = strtotime((string)($listing['last_post_at'] ?? $listing['updated_at'] ?? '')) ?: 0;
        if ($updated >= $inactive_before) $recent[] = $listing;
        else $inactive[] = $listing;
    }
    usort($recent, function ($a, $b) {
        $bd = (string)($b['last_post_at'] ?? $b['updated_at'] ?? '');
        $ad = (string)($a['last_post_at'] ?? $a['updated_at'] ?? '');
        return strcmp($bd, $ad);
    });
    $rotation_day = gmdate('Y-m-d');
    usort($inactive, function ($a, $b) use ($rotation_day) {
        $ak = hash('sha256', $rotation_day . ':' . (string)($a['id'] ?? $a['site_url'] ?? ''));
        $bk = hash('sha256', $rotation_day . ':' . (string)($b['id'] ?? $b['site_url'] ?? ''));
        return strcmp($ak, $bk);
    });

    $filtered = [];
    $inactive_i = 0;
    foreach ($recent as $i => $listing) {
        $filtered[] = $listing;
        // Give one inactive-but-live blog a turn after every four recent blogs.
        if (($i + 1) % 4 === 0 && isset($inactive[$inactive_i])) {
            $filtered[] = $inactive[$inactive_i++];
        }
    }
    while (isset($inactive[$inactive_i])) $filtered[] = $inactive[$inactive_i++];
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$initials = function (string $name): string {
    $name = trim($name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    $s = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $s .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    return $s;
};
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Directory — photoblogs.fyi</title>
<meta name="description" content="Browse real photographers on their own SnapSmack blogs, by what they shoot. Every link goes to the photographer's own site.">
<style>
  :root{--red:#D40000;--link:#F50A0A;--black:#111;--panel:#141414;--line:#282828;--ink:#f4f4f4;--muted:#9a9a9a;--card:#171717;}
  *{box-sizing:border-box}
  html,body{min-height:100%}
  body{margin:0;background:var(--black);color:var(--ink);font-family:Georgia,'Iowan Old Style','Times New Roman',serif;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column}
  .wrap{width:min(72rem,calc(100% - 2.5rem));margin:0 auto}
  a{color:var(--ink);text-decoration:none}
  .site-header{height:3rem;background:#050505;border-bottom:1px solid #181818;display:flex;align-items:center;flex:none}
  .site-header .inner{width:calc(100% - 6rem);margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:2rem}
  .site-brand{font:900 1.05rem/1 'Arial Black',Arial,sans-serif;letter-spacing:.04em;text-transform:uppercase}
  .site-brand .dot{color:var(--red)}
  .site-nav{display:flex;align-items:center;gap:.85rem;font:700 .78rem/1 'Courier New',monospace;letter-spacing:.14em}
  .site-nav a{color:#cfcfcf;padding:.25rem 0;border-bottom:2px solid transparent}
  .site-nav a:hover,.site-nav a.active{color:#fff;border-bottom-color:var(--red)}
  .site-nav .sep{color:#454545}
  main{flex:1}
  .directory-head{padding:3rem 0 1.5rem;border-bottom:1px solid var(--line)}
  .kicker{font:700 .8rem/1 'Courier New',monospace;letter-spacing:.2em;text-transform:uppercase;color:#cfcfcf;margin:0 0 1.1rem}
  .wordmark{font:900 clamp(2.1rem,7vw,3.8rem)/.95 'Arial Black',Arial,sans-serif;letter-spacing:-.02em;text-transform:uppercase;margin:0}
  .wordmark .dot{color:var(--red)}
  .lede{font-size:clamp(1rem,2.4vw,1.2rem);line-height:1.6;color:var(--muted);max-width:40rem;margin:.9rem 0 0}
  .controls{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;margin:2rem 0 1.2rem}
  .search{display:flex;gap:.5rem;flex:1 1 20rem}
  .search input{flex:1;background:var(--panel);border:1px solid var(--line);color:var(--ink);font:1rem/1.4 Georgia,serif;padding:.7rem .9rem;border-radius:2px}
  .search button,.seg{background:var(--ink);color:var(--black);border:1px solid var(--ink);font:700 .78rem/1 'Helvetica Neue',Arial,sans-serif;padding:.6rem .8rem;cursor:pointer;border-radius:2px}
  .sort{display:flex;align-items:center;gap:.5rem}
  .sort a{border:1px solid var(--line);color:var(--muted);font:700 .74rem/1 'Helvetica Neue',Arial,sans-serif;padding:.5rem .7rem;border-radius:2px}
  .sort a.on{background:var(--ink);color:var(--black);border-color:var(--ink)}
  .chips{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 2rem}
  .chip{border:1px solid var(--line);color:var(--ink);padding:.4rem .7rem;font:.86rem/1 'Helvetica Neue',Arial,sans-serif;border-radius:2px}
  .chip b{color:var(--muted);font-weight:700;margin-left:.35rem;font-size:.8em}
  .chip:hover{border-color:var(--red)}
  .chip.on{background:var(--red);border-color:var(--red);color:#fff}
  .chip.on b{color:#ffd0d0}
  .grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(15.5rem,1fr));gap:1.1rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:3px;overflow:hidden;display:flex;flex-direction:column;transition:border-color .15s,transform .15s}
  .card:hover{border-color:var(--red);transform:translateY(-2px)}
  .body{padding:.9rem 1rem 1rem;display:flex;flex-direction:column;flex:1}
  .meta{display:flex;align-items:center;gap:.65rem}
  .avatar{width:2.2rem;height:2.2rem;border-radius:50%;flex:none;display:grid;place-items:center;font:900 .8rem/1 'Arial Black',Arial,sans-serif;color:#fff;background:var(--red);background-size:cover;background-position:center}
  .name{font:900 1.02rem/1.15 'Arial Black',Arial,sans-serif;text-transform:uppercase;letter-spacing:.01em;margin:0}
  .who{font:.78rem/1.3 'Courier New',monospace;color:var(--muted);margin:.15rem 0 0}
  .blurb{font-size:.92rem;line-height:1.5;color:#d8d8d8;margin:.75rem 0 .6rem}
  .tags{margin:0 0 .9rem;display:flex;flex-wrap:wrap;gap:.35rem}
  .tags span{font:.72rem/1 'Helvetica Neue',Arial,sans-serif;color:var(--muted);border-bottom:1px solid var(--red);padding-bottom:1px}
  .foot{margin:auto 0 0;display:flex;align-items:flex-end;justify-content:space-between;gap:.75rem}
  .updated{color:#707070;font:.68rem/1.3 'Courier New',monospace;text-align:right}
  .visit{font:700 .8rem/1 'Helvetica Neue',Arial,sans-serif;color:var(--ink);border-bottom:2px solid var(--red);padding-bottom:2px}
  .visit:hover{color:var(--red)}
  .empty{border:1px solid var(--line);background:var(--panel);border-radius:3px;padding:2.5rem;text-align:center;color:var(--muted);margin-top:2rem}
  .join{margin:2.5rem 0 3rem;text-align:center;color:var(--muted);font-size:.92rem}
  .join a{border-bottom:1px solid var(--red)}
  .site-footer{border-top:3px solid var(--red);padding:1rem 1.5rem;text-align:center;color:#888;font:.64rem/1.7 'Courier New',monospace;letter-spacing:.05em;text-transform:uppercase;flex:none}
  .site-footer a{color:#bbb}
  .site-footer .sep{color:#555;margin:0 .9rem}
  @media(max-width:700px){
    .site-header{height:auto;padding:.9rem 0}.site-header .inner{width:calc(100% - 2.5rem);align-items:flex-start;flex-direction:column;gap:.75rem}
    .site-nav{gap:.55rem;font-size:.68rem;letter-spacing:.08em;flex-wrap:wrap}
    .site-footer .sep{margin:0 .35rem}
  }
</style>
</head>
<body>
<header class="site-header">
  <div class="inner">
    <a class="site-brand" href="/">Photoblogs<span class="dot">.</span>fyi</a>
    <nav class="site-nav" aria-label="Primary navigation">
      <a href="/">Home</a><span class="sep" aria-hidden="true">|</span>
      <a class="active" href="/directory.php" aria-current="page">Directory</a><span class="sep" aria-hidden="true">|</span>
      <a href="/feed.php">Feed</a><span class="sep" aria-hidden="true">|</span>
      <a href="/page.php?slug=about">About</a>
    </nav>
  </div>
</header>

<main>
<div class="wrap">

  <header class="directory-head">
    <p class="kicker">Find · Follow · Be Found</p>
    <h1 class="wordmark">The Directory<span class="dot">.</span></h1>
    <p class="lede">Independent photography blogs, on their own sites. Browse by photographer or by what they shoot.</p>
  </header>

  <form class="controls" method="get" action="">
    <div class="search">
      <input type="search" name="q" value="<?php echo $h($q); ?>" placeholder="Search photographers, topics, places…" aria-label="Search the directory">
      <?php if ($topic !== ''): ?><input type="hidden" name="topic" value="<?php echo $h($topic); ?>"><?php endif; ?>
      <button type="submit">Search</button>
    </div>
    <div class="sort">
      <?php $qs = fn($s) => '?' . http_build_query(array_filter(['q'=>$q,'topic'=>$topic,'sort'=>$s])); ?>
      <a class="<?php echo $sort==='recent'?'on':''; ?>" href="<?php echo $h($qs('recent')); ?>">Recently updated</a>
      <a class="<?php echo $sort==='name'?'on':''; ?>" href="<?php echo $h($qs('name')); ?>">A–Z</a>
    </div>
  </form>

  <?php if ($topic_counts): ?>
  <nav class="chips" aria-label="Browse by topic">
    <a class="chip <?php echo $topic===''?'on':''; ?>" href="?<?php echo $h(http_build_query(array_filter(['q'=>$q,'sort'=>$sort]))); ?>">All <b><?php echo count($rows); ?></b></a>
    <?php foreach ($topic_counts as $t => $c): ?>
      <a class="chip <?php echo strcasecmp($t,$topic)===0?'on':''; ?>" href="?<?php echo $h(http_build_query(array_filter(['q'=>$q,'topic'=>$t,'sort'=>$sort]))); ?>"><?php echo $h($t); ?> <b><?php echo (int)$c; ?></b></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if (!$filtered): ?>
    <div class="empty">
      <?php if (!$rows): ?>
        No blogs are listed yet. Be the first — opt in from your SnapSmack blog's Directory page.
      <?php else: ?>
        No blogs match that search. <a class="visit" href="directory.php">Clear filters</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <ul class="grid">
      <?php foreach ($filtered as $r): ?>
        <li class="card">
          <div class="body">
            <div class="meta">
              <span class="avatar"<?php echo !empty($r['avatar_url']) ? ' style="background-image:url(\''.$h($r['avatar_url']).'\')"' : ''; ?>><?php echo empty($r['avatar_url']) ? $h($initials((string)$r['name'])) : ''; ?></span>
              <div>
                <h3 class="name"><?php echo $h($r['name']); ?></h3>
                <p class="who"><?php echo $h($r['host']); ?></p>
              </div>
            </div>
            <?php if (trim((string)$r['description']) !== ''): ?>
              <p class="blurb"><?php echo $h($r['description']); ?></p>
            <?php endif; ?>
            <?php if ($r['_topics']): ?>
              <p class="tags"><?php foreach (array_slice($r['_topics'],0,4) as $t): ?><span><?php echo $h($t); ?></span><?php endforeach; ?></p>
            <?php endif; ?>
            <p class="foot">
              <a class="visit" href="<?php echo $h($r['site_url']); ?>" rel="nofollow noopener">Visit blog ↗</a>
              <?php $activity_at = $r['last_post_at'] ?? $r['updated_at'] ?? ''; ?>
              <?php if ($activity_at !== ''): ?><span class="updated">Updated <?php echo $h(date('M j, Y', strtotime((string)$activity_at))); ?></span><?php endif; ?>
            </p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p class="join">Run a SnapSmack photo blog? <a href="/for-admins">Learn how to join the directory.</a></p>

</div>
</main>

<footer class="site-footer">
  <span>© <?php echo date('Y'); ?> <a href="/">photoblogs.fyi</a></span><span class="sep">|</span>
  <span>Email: <a href="mailto:sean@photoblogs.fyi">sean@photoblogs.fyi</a></span><span class="sep">|</span>
  <span>Theme: New Horizon</span><span class="sep">|</span>
  <span>Powered by <a href="https://snapsmack.ca">SnapSmack</a> <?php echo $h(defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : ''); ?></span><span class="sep">|</span>
  <a href="/feed">RSS</a>
</footer>
</body>
</html>
<?php // ===== SNAPSMACK EOF ===== ?>
