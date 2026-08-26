<?php
/**
 * SNAPSMACK — photoblogs.fyi PUBLIC DIRECTORY  [0.7.547]
 *
 * Renders the APPROVED (state='active') directory listings. Browse by topic,
 * search, sort — all server-side, no JavaScript. Every card links back to the
 * photographer's own site (dofollow — the directory passes its link equity OUT
 * to members on purpose; sending traffic + SEO back is the whole point);
 * nothing is re-hosted here.
 * Reachable at /directory.php (a pretty /directory rewrite is a follow-up).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';   // $pdo

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM snap_directory_listings WHERE state='active' ORDER BY updated_at DESC LIMIT 500")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

// Decode topics/samples once.
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
$sort     = (string)($_GET['sort'] ?? 'newest');
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
} // 'newest' keeps the SQL order (updated_at DESC)

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
  body{margin:0;background:var(--black);color:var(--ink);font-family:Georgia,'Iowan Old Style','Times New Roman',serif;-webkit-font-smoothing:antialiased;padding:0 1.25rem 5rem}
  .wrap{max-width:72rem;margin:0 auto}
  a{color:var(--ink);text-decoration:none}
  header{padding:3rem 0 1.5rem;border-bottom:1px solid var(--line)}
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
  .shots{display:grid;grid-template-columns:2fr 1fr 1fr;gap:2px;height:8.5rem;background:var(--line)}
  .shot{background-size:cover;background-position:center}
  .ph1{background:linear-gradient(135deg,#3a4a55,#0d1418)}.ph2{background:linear-gradient(135deg,#556b74,#1c2a2e)}.ph3{background:linear-gradient(135deg,#22323a,#05080a)}
  .body{padding:.9rem 1rem 1rem;display:flex;flex-direction:column;flex:1}
  .meta{display:flex;align-items:center;gap:.65rem}
  .avatar{width:2.2rem;height:2.2rem;border-radius:50%;flex:none;display:grid;place-items:center;font:900 .8rem/1 'Arial Black',Arial,sans-serif;color:#fff;background:var(--red);background-size:cover;background-position:center}
  .name{font:900 1.02rem/1.15 'Arial Black',Arial,sans-serif;text-transform:uppercase;letter-spacing:.01em;margin:0}
  .who{font:.78rem/1.3 'Courier New',monospace;color:var(--muted);margin:.15rem 0 0}
  .blurb{font-size:.92rem;line-height:1.5;color:#d8d8d8;margin:.75rem 0 .6rem}
  .tags{margin:0 0 .9rem;display:flex;flex-wrap:wrap;gap:.35rem}
  .tags span{font:.72rem/1 'Helvetica Neue',Arial,sans-serif;color:var(--muted);border-bottom:1px solid var(--red);padding-bottom:1px}
  .foot{margin:auto 0 0}
  .visit{font:700 .8rem/1 'Helvetica Neue',Arial,sans-serif;color:var(--ink);border-bottom:2px solid var(--red);padding-bottom:2px}
  .visit:hover{color:var(--red)}
  .empty{border:1px solid var(--line);background:var(--panel);border-radius:3px;padding:2.5rem;text-align:center;color:var(--muted);margin-top:2rem}
  .join{border:1px solid var(--red);border-radius:3px;padding:1.8rem;margin:3rem 0 0;text-align:center}
  .join h2{font:900 1.3rem/1.1 'Arial Black',Arial,sans-serif;text-transform:uppercase;margin:0 0 .6rem}
  .join p{color:var(--muted);max-width:34rem;margin:0 auto 1.2rem;line-height:1.6}
  .join-btn{display:inline-block;font:900 .78rem/1 'Arial Black',Arial,sans-serif;text-transform:uppercase;letter-spacing:.12em;color:#fff;background:var(--red);padding:12px 22px}
  footer{margin-top:3rem;padding-top:1.4rem;border-top:1px solid var(--line);color:var(--muted);font:.82rem/1.6 'Courier New',monospace}
  footer a{border-bottom:1px solid var(--red)}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <p class="kicker">photoblogs.fyi</p>
    <h1 class="wordmark">The Directory<span class="dot">.</span></h1>
    <p class="lede">Real photographers, on their own sites. Browse by what they shoot — every link goes straight to their blog.</p>
  </header>

  <form class="controls" method="get" action="">
    <div class="search">
      <input type="search" name="q" value="<?php echo $h($q); ?>" placeholder="Search photographers, topics, places…" aria-label="Search the directory">
      <?php if ($topic !== ''): ?><input type="hidden" name="topic" value="<?php echo $h($topic); ?>"><?php endif; ?>
      <button type="submit">Search</button>
    </div>
    <div class="sort">
      <?php $qs = fn($s) => '?' . http_build_query(array_filter(['q'=>$q,'topic'=>$topic,'sort'=>$s])); ?>
      <a class="<?php echo $sort==='newest'?'on':''; ?>" href="<?php echo $h($qs('newest')); ?>">Newest</a>
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
          <div class="shots">
            <?php $sm = $r['_samples']; ?>
            <?php for ($i = 0; $i < 3; $i++): ?>
              <?php if (!empty($sm[$i])): ?>
                <span class="shot" style="background-image:url('<?php echo $h($sm[$i]); ?>')"></span>
              <?php else: ?>
                <span class="shot ph<?php echo $i+1; ?>"></span>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
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
            <p class="foot"><a class="visit" href="<?php echo $h($r['site_url']); ?>" rel="noopener">Visit blog ↗</a></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <section class="join">
    <h2>Want to be found too?</h2>
    <p>Run a SnapSmack photo blog? Opt in from your admin (Fediverse → Directory) and you'll appear here — your photos stay on your own server.</p>
    <a class="join-btn" href="/for-admins">How it works</a>
  </section>

  <footer>photoblogs.fyi · lists the SnapSmack network only · <a href="/">home</a></footer>

</div>
</body>
</html>
<?php // ===== SNAPSMACK EOF ===== ?>
