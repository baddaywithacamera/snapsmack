<?php
/**
 * SNAPSMACK — photoblogs.fyi PUBLIC DIRECTORY  [0.7.547]
 *
 * Renders the APPROVED (state='active') directory listings as a PICTURE-FIRST
 * grid: each card is just the blog's photos, and the whole card links back to
 * that blog's own site DOFOLLOW — the directory passes its link equity OUT to
 * members on purpose; sending traffic + SEO back is the whole point. Nothing is
 * re-hosted here. Photo tiles are filled from each blog's own /rss.php feed
 * (parallel fetch, cached 6h) when it hasn't submitted samples via the API, so
 * the grid shows real pictures and never a placeholder box. Browse by topic,
 * search, sort — all server-side, no JS. A blog with no photo yet is hidden
 * rather than shown as an empty tile.
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

// --- Fill the photo tiles from each blog's own RSS feed (cached), so the
// --- directory shows real pictures instead of empty placeholders. Hub-side and
// --- self-healing: no per-blog upload step. Samples a blog explicitly submitted
// --- via the API win; RSS only fills the blanks, refreshed every 6 hours.
$dir_need = [];
foreach ($rows as $i => $r) {
    if (empty($r['_samples']) && !empty($r['site_url'])) {
        $dir_need[$i] = rtrim((string)$r['site_url'], '/');
    }
}
if ($dir_need) {
    $cache_file = sys_get_temp_dir() . '/pbdir_rss_samples.json';
    $cache = is_readable($cache_file) ? (json_decode((string)file_get_contents($cache_file), true) ?: []) : [];
    $now = time();
    $ttl = 21600; // 6 hours
    $fetch = [];
    foreach ($dir_need as $i => $url) {
        $c = $cache[$url] ?? null;
        if (is_array($c) && ($now - (int)($c['t'] ?? 0)) < $ttl) {
            if (!empty($c['s'])) $rows[$i]['_samples'] = $c['s'];
        } else {
            $fetch[$i] = $url;
        }
    }
    if ($fetch && function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($fetch as $i => $url) {
            $ch = curl_init($url . '/rss.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT      => 'photoblogs.fyi-directory/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        foreach ($handles as $i => $ch) {
            $body = (string)curl_multi_getcontent($ch);
            $imgs = [];
            if ($body !== '' && preg_match_all(
                '~<(?:img[^>]+src|enclosure[^>]+url|media:content[^>]+url)=["\']([^"\']+\.(?:jpe?g|png|webp))["\']~i',
                $body, $m)) {
                foreach ($m[1] as $u) {
                    $u = html_entity_decode($u, ENT_QUOTES);
                    if (stripos($u, 'https://') === 0 && filter_var($u, FILTER_VALIDATE_URL)
                        && !in_array($u, $imgs, true)) {
                        $imgs[] = $u;
                    }
                    if (count($imgs) >= 3) break;
                }
            }
            if ($imgs) $rows[$i]['_samples'] = $imgs;
            $cache[$fetch[$i]] = ['t' => $now, 's' => $imgs];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        @file_put_contents($cache_file, json_encode($cache), LOCK_EX);
    }
}

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
  .controls{display:flex;flex-wrap:wrap;gap:1rem;align-items:stretch;justify-content:space-between;margin:2rem 0 1.2rem}
  .search{display:flex;gap:.5rem;flex:1 1 20rem}
  .search input{flex:1;background:var(--panel);border:1px solid var(--line);color:var(--ink);font:1rem/1.4 Georgia,serif;padding:.7rem .9rem;border-radius:2px}
  .search button,.seg{background:var(--ink);color:var(--black);border:1px solid var(--ink);font:700 .78rem/1 'Helvetica Neue',Arial,sans-serif;padding:.6rem .8rem;cursor:pointer;border-radius:2px}
  .sort{display:flex;flex-direction:column;gap:.4rem}
  .sort a{flex:1;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);color:var(--muted);font:700 .74rem/1 'Helvetica Neue',Arial,sans-serif;padding:.35rem .9rem;border-radius:2px}
  .sort a.on{background:var(--ink);color:var(--black);border-color:var(--ink)}
  .chips{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 2rem}
  .chip{border:1px solid var(--line);color:var(--ink);padding:.4rem .7rem;font:.86rem/1 'Helvetica Neue',Arial,sans-serif;border-radius:2px}
  .chip b{color:var(--muted);font-weight:700;margin-left:.35rem;font-size:.8em}
  .chip:hover{border-color:var(--red)}
  .chip.on{background:var(--red);border-color:var(--red);color:#fff}
  .chip.on b{color:#ffd0d0}
  .grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(15.5rem,1fr));gap:1.1rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:3px;overflow:hidden;transition:border-color .15s,transform .15s}
  .card:hover{border-color:var(--red);transform:translateY(-2px)}
  .card-link{display:block}
  .shots{display:grid;grid-template-columns:2fr 1fr 1fr;gap:2px;height:11rem;background:var(--line)}
  .shots-1{grid-template-columns:1fr}
  .shots-2{grid-template-columns:1fr 1fr}
  .shots-3{grid-template-columns:2fr 1fr 1fr}
  .shot{background-size:cover;background-position:center;background-color:#0d1418}
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
        <?php $sm = array_values(array_filter((array)$r['_samples'])); if (!$sm) continue; /* pictures-first: hide a blog that has no photo yet, don't show an empty box */ ?>
        <li class="card">
          <a class="card-link" href="<?php echo $h($r['site_url']); ?>" rel="noopener" title="<?php echo $h($r['name']); ?>" aria-label="<?php echo $h($r['name']); ?>">
            <span class="shots shots-<?php echo min(3, count($sm)); ?>">
              <?php foreach (array_slice($sm, 0, 3) as $u): ?>
                <span class="shot" style="background-image:url('<?php echo $h($u); ?>')"></span>
              <?php endforeach; ?>
            </span>
          </a>
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
