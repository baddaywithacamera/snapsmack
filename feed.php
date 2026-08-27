<?php
/**
 * PHOTOBLOGS.FYI — public network feed.
 *
 * A three-across grid of square photo tiles — up to the last 20 posts per member
 * blog from the last four weeks, roughly newest-first with light randomisation and
 * a de-clump so no blog runs back-to-back. Each tile opens the original post on the
 * blog's own server in a new tab; the post title shows on hover. No image is stored
 * here — thumbnails are hotlinked from the cache the directory poller fills.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF ===== ?>
 * Last non-empty line of this file MUST match the line above.
 */
require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';

$items = [];
try {
    // Only the last four weeks are eligible; newest first before we shuffle.
    $raw_items = $pdo->query("SELECT f.*,l.name,l.host
        FROM snap_directory_feed_items f
        JOIN snap_directory_listings l ON l.id=f.listing_id
        WHERE l.state='active' AND l.feed_status<>'dead'
          AND f.published_at >= (UTC_TIMESTAMP() - INTERVAL 28 DAY)
        ORDER BY f.published_at DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $per_blog = [];
    foreach ($raw_items as $item) {
        $listing_id = (int)$item['listing_id'];
        if (($per_blog[$listing_id] ?? 0) >= 20) continue;   // last 20 entries per blog
        $items[] = $item;
        $per_blog[$listing_id] = ($per_blog[$listing_id] ?? 0) + 1;
    }
    // Roughly new -> old, with a bit of randomisation and the odd older post
    // surfacing: jitter each timestamp by up to ~3 days, then sort by that.
    foreach ($items as &$it) { $it['_sort'] = strtotime((string)$it['published_at']) + mt_rand(-259200, 259200); }
    unset($it);
    usort($items, static fn($a, $b) => $b['_sort'] <=> $a['_sort']);
    // De-clump: don't run the same blog back-to-back where a different one is free.
    $ordered = []; $pending = $items;
    while ($pending) {
        $last = $ordered ? (int)$ordered[count($ordered) - 1]['listing_id'] : -1;
        $pick = null;
        foreach ($pending as $k => $cand) { if ((int)$cand['listing_id'] !== $last) { $pick = $k; break; } }
        if ($pick === null) $pick = array_key_first($pending);
        $ordered[] = $pending[$pick];
        unset($pending[$pick]);
        $pending = array_values($pending);
    }
    $items = $ordered;
} catch (Throwable $e) { $items = []; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Feed — photoblogs.fyi</title>
<meta name="description" content="The latest photographs from independent SnapSmack photo blogs, each tile linking to its origin post.">
<style>
:root{--red:#d40000;--black:#111;--ink:#f4f4f4;--muted:#999;--line:#292929;--card:#171717}*{box-sizing:border-box}
html,body{min-height:100%}body{margin:0;background:var(--black);color:var(--ink);font-family:Georgia,'Times New Roman',serif;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}a{color:inherit;text-decoration:none}
.site-header{min-height:3rem;background:#050505;border-bottom:1px solid #181818;display:flex;align-items:center}.site-header .inner{width:calc(100% - 6rem);margin:auto;display:flex;justify-content:space-between;align-items:center;gap:2rem}.brand{font:900 1.05rem/1 'Arial Black',Arial,sans-serif;text-transform:uppercase;letter-spacing:.04em}.dot{color:var(--red)}.nav{display:flex;gap:.85rem;align-items:center;font:700 .78rem/1 'Courier New',monospace;letter-spacing:.14em;text-transform:uppercase}.nav a{color:#ccc;padding:.25rem 0;border-bottom:2px solid transparent}.nav a:hover,.nav .active{color:#fff;border-bottom-color:var(--red)}.sep{color:#454545}
main{flex:1}.wrap{width:min(72rem,calc(100% - 2.5rem));margin:auto}.head{padding:3rem 0 1.5rem;border-bottom:1px solid var(--line)}.kicker{font:700 .8rem/1 'Courier New',monospace;letter-spacing:.2em;text-transform:uppercase;color:#cfcfcf;margin:0 0 1.1rem}.head h1{font:900 clamp(2.1rem,7vw,3.8rem)/.95 'Arial Black',Arial,sans-serif;text-transform:uppercase;margin:0}.head p:last-child{color:var(--muted);font-size:1.1rem;line-height:1.6;margin:.9rem 0 0}
/* Classic three-across grid of square tiles. Post title shows on hover; the tile
   opens the original post on its blog in a new tab. */
.grid{list-style:none;padding:0;margin:2rem 0 3rem;display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem}
.tile{position:relative;display:block;aspect-ratio:1/1;overflow:hidden;background:var(--card)}
.tile img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .25s}
.tile:hover img{transform:scale(1.04)}
.tile .cap{position:absolute;left:0;right:0;bottom:0;padding:1.6rem .7rem .55rem;background:linear-gradient(transparent,rgba(0,0,0,.85));opacity:0;transition:opacity .15s;pointer-events:none}
.tile:hover .cap,.tile:focus-visible .cap{opacity:1}
.tile .cap .b{display:block;font:900 .68rem/1.2 'Arial Black',Arial,sans-serif;text-transform:uppercase;color:#fff;letter-spacing:.02em}
.tile .cap .t{display:block;color:#e8e8e8;font-size:.82rem;line-height:1.35;margin-top:.15rem}
.tile:focus-visible{outline:2px solid var(--red);outline-offset:-2px}
.empty{color:var(--muted);padding:3rem 0}
@media(max-width:520px){.grid{gap:.25rem}.tile .cap{padding:1rem .5rem .4rem}}
.site-footer{border-top:3px solid var(--red);padding:1rem 1.5rem;text-align:center;color:#888;font:.64rem/1.7 'Courier New',monospace;letter-spacing:.05em;text-transform:uppercase}.site-footer .sep{margin:0 .9rem}.site-footer a{color:#bbb}
@media(max-width:700px){.site-header{padding:.9rem 0}.site-header .inner{width:calc(100% - 2.5rem);align-items:flex-start;flex-direction:column;gap:.75rem}.nav{gap:.55rem;font-size:.68rem;letter-spacing:.08em;flex-wrap:wrap}.site-footer .sep{margin:0 .35rem}}
</style>
</head>
<body>
<header class="site-header"><div class="inner"><a class="brand" href="/">Photoblogs<span class="dot">.</span>fyi</a><nav class="nav" aria-label="Primary navigation"><a href="/">Home</a><span class="sep">|</span><a href="/directory.php">Directory</a><span class="sep">|</span><a class="active" aria-current="page" href="/feed.php">Feed</a><span class="sep">|</span><a href="/page.php?slug=about">About</a></nav></div></header>
<main><div class="wrap">
<header class="head"><p class="kicker">Find · Follow · Be Found</p><h1>The Feed<span class="dot">.</span></h1><p>The latest photographs from across the network. Every tile opens the original post on its blog.</p></header>
<?php if (!$items): ?><p class="empty">No photographs have been collected yet.</p><?php else: ?>
<ul class="grid">
<?php foreach ($items as $item): ?>
<li><a class="tile" href="<?php echo $h($item['post_url']); ?>" target="_blank" rel="nofollow noopener" aria-label="<?php echo $h(($item['title'] ?: $item['name'])); ?> — opens the original post on <?php echo $h($item['name']); ?> in a new tab"><img src="<?php echo $h($item['image_url']); ?>" alt="<?php echo $h($item['alt_text'] ?: $item['title'] ?: $item['name']); ?>" loading="lazy"><span class="cap"><span class="b"><?php echo $h($item['name']); ?></span><?php if (($item['title'] ?? '') !== ''): ?><span class="t"><?php echo $h($item['title']); ?></span><?php endif; ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div></main>
<footer class="site-footer"><span>© <?php echo date('Y'); ?> <a href="/">photoblogs.fyi</a></span><span class="sep">|</span><span>Email: <a href="mailto:sean@photoblogs.fyi">sean@photoblogs.fyi</a></span><span class="sep">|</span><span>Theme: New Horizon</span><span class="sep">|</span><span>Powered by <a href="https://snapsmack.ca">SnapSmack</a> <?php echo $h(defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : ''); ?></span><span class="sep">|</span><a href="/rss.php">RSS</a></footer>
</body></html>
<?php // ===== SNAPSMACK EOF ===== ?>
