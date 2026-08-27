<?php
/** PHOTOBLOGS.FYI — public network feed. */
require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';

$items = [];
try {
    $raw_items = $pdo->query("SELECT f.*,l.name,l.host
        FROM snap_directory_feed_items f
        JOIN snap_directory_listings l ON l.id=f.listing_id
        WHERE l.state='active' AND l.feed_status<>'dead'
        ORDER BY f.published_at DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $per_blog = [];
    foreach ($raw_items as $item) {
        $listing_id = (int)$item['listing_id'];
        if (($per_blog[$listing_id] ?? 0) >= 10) continue;
        $items[] = $item;
        $per_blog[$listing_id] = ($per_blog[$listing_id] ?? 0) + 1;
    }
} catch (Throwable $e) { $items = []; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Feed — photoblogs.fyi</title>
<meta name="description" content="Recent photographs from independent SnapSmack photo blogs. One post per blog per day, linked to its origin.">
<style>
:root{--red:#d40000;--black:#111;--ink:#f4f4f4;--muted:#999;--line:#292929;--card:#171717}*{box-sizing:border-box}
html,body{min-height:100%}body{margin:0;background:var(--black);color:var(--ink);font-family:Georgia,'Times New Roman',serif;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}a{color:inherit;text-decoration:none}
.site-header{min-height:3rem;background:#050505;border-bottom:1px solid #181818;display:flex;align-items:center}.site-header .inner{width:calc(100% - 6rem);margin:auto;display:flex;justify-content:space-between;align-items:center;gap:2rem}.brand{font:900 1.05rem/1 'Arial Black',Arial,sans-serif;text-transform:uppercase;letter-spacing:.04em}.dot{color:var(--red)}.nav{display:flex;gap:.85rem;align-items:center;font:700 .78rem/1 'Courier New',monospace;letter-spacing:.14em;text-transform:uppercase}.nav a{color:#ccc;padding:.25rem 0;border-bottom:2px solid transparent}.nav a:hover,.nav .active{color:#fff;border-bottom-color:var(--red)}.sep{color:#454545}
main{flex:1}.wrap{width:min(72rem,calc(100% - 2.5rem));margin:auto}.head{padding:3rem 0 1.5rem;border-bottom:1px solid var(--line)}.kicker{font:700 .8rem/1 'Courier New',monospace;letter-spacing:.2em;text-transform:uppercase;color:#cfcfcf;margin:0 0 1.1rem}.head h1{font:900 clamp(2.1rem,7vw,3.8rem)/.95 'Arial Black',Arial,sans-serif;text-transform:uppercase;margin:0}.head p:last-child{color:var(--muted);font-size:1.1rem;line-height:1.6;margin:.9rem 0 0}
.grid{list-style:none;padding:0;margin:2rem 0 3rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:1.1rem}.card{background:var(--card);border:1px solid var(--line);overflow:hidden}.photo{display:block;aspect-ratio:4/3;background:#222;overflow:hidden}.photo img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .2s}.card:hover{border-color:var(--red)}.card:hover img{transform:scale(1.015)}.body{padding:.8rem .9rem 1rem}.blog{font:900 .92rem/1.2 'Arial Black',Arial,sans-serif;text-transform:uppercase}.title{color:#d7d7d7;margin:.35rem 0 0;font-size:.9rem;line-height:1.4}.date{color:#777;font:.68rem/1.3 'Courier New',monospace;margin:.65rem 0 0}.empty{color:var(--muted);padding:3rem 0}
.site-footer{border-top:3px solid var(--red);padding:1rem 1.5rem;text-align:center;color:#888;font:.64rem/1.7 'Courier New',monospace;letter-spacing:.05em;text-transform:uppercase}.site-footer .sep{margin:0 .9rem}.site-footer a{color:#bbb}
@media(max-width:700px){.site-header{padding:.9rem 0}.site-header .inner{width:calc(100% - 2.5rem);align-items:flex-start;flex-direction:column;gap:.75rem}.nav{gap:.55rem;font-size:.68rem;letter-spacing:.08em;flex-wrap:wrap}.site-footer .sep{margin:0 .35rem}}
</style>
</head>
<body>
<header class="site-header"><div class="inner"><a class="brand" href="/">Photoblogs<span class="dot">.</span>fyi</a><nav class="nav" aria-label="Primary navigation"><a href="/">Home</a><span class="sep">|</span><a href="/directory.php">Directory</a><span class="sep">|</span><a class="active" aria-current="page" href="/feed.php">Feed</a><span class="sep">|</span><a href="/page.php?slug=about">About</a></nav></div></header>
<main><div class="wrap">
<header class="head"><p class="kicker">Find · Follow · Be Found</p><h1>The Feed<span class="dot">.</span></h1><p>One photograph per blog per day. Every image opens the original published post.</p></header>
<?php if (!$items): ?><p class="empty">No photographs have been collected yet.</p><?php else: ?>
<ul class="grid">
<?php foreach ($items as $item): ?>
<li class="card"><a class="photo" href="<?php echo $h($item['post_url']); ?>" rel="nofollow noopener" aria-label="Open <?php echo $h($item['title'] ?: $item['name']); ?>"><img src="<?php echo $h($item['image_url']); ?>" alt="<?php echo $h($item['alt_text'] ?: $item['title']); ?>" loading="lazy"></a><div class="body"><a class="blog" href="<?php echo $h($item['post_url']); ?>" rel="nofollow noopener"><?php echo $h($item['name']); ?></a><?php if ($item['title'] !== ''): ?><p class="title"><?php echo $h($item['title']); ?></p><?php endif; ?><p class="date"><?php echo $h(date('M j, Y', strtotime((string)$item['published_at']))); ?></p></div></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div></main>
<footer class="site-footer"><span>© <?php echo date('Y'); ?> <a href="/">photoblogs.fyi</a></span><span class="sep">|</span><span>Email: <a href="mailto:sean@photoblogs.fyi">sean@photoblogs.fyi</a></span><span class="sep">|</span><span>Theme: New Horizon</span><span class="sep">|</span><span>Powered by <a href="https://snapsmack.ca">SnapSmack</a> <?php echo $h(defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : ''); ?></span><span class="sep">|</span><a href="/rss.php">RSS</a></footer>
</body></html>
<?php // ===== SNAPSMACK EOF ===== ?>
