<?php
/**
 * SNAPSMACK — Public fediverse profile (Pixelfed-faithful)
 *
 * The human-facing half of the actor at the address a Pixelfed user expects
 * (instance/username). Machines asking for activity+json are handled upstream
 * in index.php (content negotiation → the actor doc); this file renders the
 * BROWSER view: a profile that presents like a Pixelfed profile — avatar, name,
 * @handle, post/follower/following counts, bio, and a square photo grid — with
 * a Follow button that runs the standard remote-interaction flow (bounces the
 * visitor to their own instance to confirm). One server, one fediverse user.
 *
 * Expects $pdo, $settings in scope (included from index.php). No writes; the
 * only interactive control is the remote-follow doorway, which lands as a
 * normal signed activity in the hardened inbox.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/smackverse.php';

$pp_base   = rtrim(defined('BASE_URL') ? BASE_URL : sv_base($settings), '/') . '/';
$pp_actor  = sv_actor_doc($pdo, $settings);
$pp_handle = sv_handle($settings);
$pp_host   = sv_domain($settings);
$pp_name   = html_entity_decode((string)($pp_actor['name'] ?? ($settings['site_name'] ?? $pp_handle)), ENT_QUOTES | ENT_HTML5);
$pp_bio    = html_entity_decode(trim(strip_tags((string)($settings['smackverse_bio'] ?? ($settings['site_description'] ?? '')))), ENT_QUOTES | ENT_HTML5);
$pp_tagline = html_entity_decode(trim((string)($settings['site_tagline'] ?? '')), ENT_QUOTES | ENT_HTML5);

$pp_avatar = '';
if (isset($pp_actor['icon']['url'])) $pp_avatar = (string)$pp_actor['icon']['url'];

// Counts.
$pp_followers = 0; $pp_following = 0; $pp_posts = 0;
try { $pp_followers = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1")->fetchColumn(); } catch (Throwable $e) {}
try { $pp_following = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_following WHERE state = 'accepted'")->fetchColumn(); } catch (Throwable $e) {}
try {
    // MODE-AWARE (Sean + Claude, 0.7.40x): mirror sv_outbox_doc. Count standalone
    // published images (photoblog / SMACKONEOUT: post_id IS NULL) AND grouped
    // posts (GRAMOFSMACK: single/carousel/panorama). The old query counted ONLY
    // snap_posts, so a photoblog whose content lives entirely in snap_images
    // reported 0 here while its outbox — and every remote — showed them all.
    $pp_posts  = (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_images
         WHERE img_status = 'published' AND img_date <= NOW() AND post_id IS NULL"
    )->fetchColumn();
    $pp_posts += (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_posts
         WHERE status = 'published' AND created_at <= NOW()
           AND post_type IN ('single','carousel','panorama')"
    )->fetchColumn();
} catch (Throwable $e) {}

// Photo grid — mode-aware, one tile per published unit, ordered by the SAME key
// the outbox federates on (sv_outbox_doc): COALESCE(fedi_published_at,created_at)
// for grouped posts, img_date for standalone images — so the profile grid mirrors
// both the lighttable's imprinted grid order and what remotes actually show.
// GRAMOFSMACK posts come through the cover-pivot join; SMACKONEOUT photoblog
// images (post_id IS NULL) come through the UNION branch — the drawer the old
// posts-only query never opened. created_at stays the real display date for
// timeago; d is ordering only.
$pp_tiles = [];
try {
    $st = $pdo->query(
        "SELECT * FROM (
            SELECT i.id, i.post_id, i.img_file, i.img_slug,
                   i.img_thumb_square, i.img_thumb_aspect, i.img_width, i.img_height,
                   pi.img_size_pct, pi.img_border_px, pi.img_border_color,
                   pi.img_bg_color, pi.img_shadow,
                   p.created_at, p.post_type,
                   (SELECT COUNT(*) FROM snap_post_images c WHERE c.post_id = p.id) AS image_count,
                   COALESCE(p.fedi_published_at, p.created_at) AS d, 'post' AS kind
              FROM snap_posts p
              JOIN snap_post_images pi ON pi.post_id = p.id
                 AND pi.image_id = (SELECT image_id FROM snap_post_images
                                    WHERE post_id = p.id
                                    ORDER BY is_cover DESC, sort_position ASC LIMIT 1)
              JOIN snap_images i ON i.id = pi.image_id AND i.img_status = 'published'
             WHERE p.status = 'published' AND p.created_at <= NOW()
               AND p.post_type IN ('single','carousel','panorama')
            UNION ALL
            SELECT i.id, i.post_id, i.img_file, i.img_slug,
                   i.img_thumb_square, i.img_thumb_aspect, i.img_width, i.img_height,
                   NULL AS img_size_pct, NULL AS img_border_px, NULL AS img_border_color,
                   NULL AS img_bg_color, NULL AS img_shadow,
                   i.img_date AS created_at, NULL AS post_type,
                   1 AS image_count,
                   i.img_date AS d, 'image' AS kind
              FROM snap_images i
             WHERE i.img_status = 'published' AND i.img_date <= NOW() AND i.post_id IS NULL
         ) u
         ORDER BY d DESC, kind ASC, id DESC
         LIMIT 60"
    );
    $pp_tiles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $pp_tiles = []; }

function pp_thumb(string $file, string $base): string {
    $file = ltrim(str_replace('\\', '/', $file), '/');
    $rel  = trim(dirname($file), '/.') ;
    $rel  = ($rel === '' ? '' : $rel . '/') . 'thumbs/t_' . basename($file);
    return $base . $rel;
}
function pp_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function pp_timeago(?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt); if ($t === false) return '';
    $d = max(0, time() - $t);
    if ($d < 60)      return $d . 's';
    if ($d < 3600)    return floor($d / 60) . 'm';
    if ($d < 86400)   return floor($d / 3600) . 'h';
    if ($d < 604800)  return floor($d / 86400) . 'd';
    if ($d < 2629800) return floor($d / 604800) . 'w';
    if ($d < 31557600)return floor($d / 2629800) . 'mo';
    return floor($d / 31557600) . 'y';
}

$pp_full_handle = '@' . $pp_handle . '@' . $pp_host;
$pp_followerr   = ($_GET['follow'] ?? '') === 'badhandle';
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
// Never let Cloudflare/browser serve a stale profile — it changes as posts and
// counts change, and a cached copy also means the page's PHP never runs.
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pp_e($pp_name); ?> (<?php echo pp_e($pp_full_handle); ?>)</title>
<link rel="alternate" type="application/activity+json" href="<?php echo pp_e(sv_actor_url($settings)); ?>">
<style>
  :root{ --bg:#fff; --panel:#fafafa; --line:#dbdbdb; --fg:#161616; --muted:#8e8e8e; --accent:#3897f0; }
  *{ box-sizing:border-box; }
  body{ margin:0; background:var(--bg); color:var(--fg);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  a{ color:inherit; text-decoration:none; }
  .pp-topbar{ position:sticky; top:0; z-index:10; background:#fff; border-bottom:1px solid var(--line); }
  .pp-topbar-in{ max-width:1040px; margin:0 auto; padding:12px 18px; display:flex; align-items:center; }
  .pp-logo{ font-size:1.05rem; color:var(--fg); text-decoration:none; }
  .pp-logo-title{ font-weight:800; letter-spacing:.2px; }
  .pp-logo-tag{ color:var(--muted); font-weight:400; }
  .pp-topbar-handle{ margin-left:auto; color:var(--muted); font-size:.85rem; }
  .pp-shell{ max-width:1040px; margin:0 auto; padding:26px 18px 70px; display:flex; gap:30px; align-items:flex-start; }
  .pp-side{ width:330px; flex:0 0 330px; text-align:center; }
  .pp-side-av{ width:150px; height:150px; border-radius:50%; object-fit:cover; background:var(--panel); }
  .pp-side-name{ font-weight:700; font-size:1.15rem; margin-top:14px; }
  .pp-side-handle{ color:var(--accent); font-size:.95rem; display:block; margin-top:2px; }
  .pp-counts{ display:flex; justify-content:center; gap:34px; margin:18px 0; }
  .pp-counts div{ line-height:1.2; }
  .pp-counts b{ display:block; font-weight:700; font-size:1.1rem; }
  .pp-counts span{ color:var(--muted); font-size:.82rem; }
  .pp-follow{ display:flex; flex-direction:column; gap:8px; margin-bottom:18px; }
  .pp-follow input{ width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:8px;
                    background:var(--panel); color:var(--fg); font-size:.85rem; }
  .pp-follow button{ width:100%; padding:9px; border:none; border-radius:8px; background:var(--accent);
                     color:#fff; font-weight:700; font-size:.9rem; cursor:pointer; }
  .pp-follow-hint{ text-align:left; color:var(--muted); font-size:.78rem; line-height:1.45; margin-bottom:2px; }
  .pp-follow-hint code{ font-size:.76rem; background:var(--panel); border:1px solid var(--line);
                        border-radius:5px; padding:1px 5px; white-space:nowrap; }
  .pp-err{ color:#f87171; font-size:.78rem; margin-bottom:6px; text-align:left; }
  .pp-biobox{ text-align:left; background:var(--panel); border:1px solid var(--line); border-radius:12px;
              padding:14px; font-size:.86rem; line-height:1.5; }
  .pp-biobox a{ color:var(--accent); }
  .pp-joined{ text-align:left; color:var(--muted); font-size:.8rem; margin-top:14px;
              background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px 12px; }
  .pp-side-foot{ text-align:left; color:var(--muted); font-size:.75rem; margin-top:14px; }
  .pp-side-foot a{ margin-right:12px; }
  .pp-powered{ text-align:left; color:var(--muted); font-size:.72rem; font-weight:600; margin-top:10px; }
  .pp-powered a{ color:inherit; text-decoration:none; }
  .pp-powered a:hover{ color:var(--accent); }
  .pp-main{ flex:1; min-width:0; }
  .pp-lay{ position:absolute; opacity:0; pointer-events:none; }
  .pp-tabbar{ display:flex; align-items:center; border-bottom:1px solid var(--line); }
  .pp-tab{ padding:12px 4px; font-weight:700; font-size:.82rem; letter-spacing:.06em; text-transform:uppercase;
           border-bottom:2px solid var(--fg); margin-bottom:-1px; }
  .pp-toggle{ margin-left:auto; display:flex; gap:16px; }
  .pp-toggle label{ color:var(--muted); font-size:1.1rem; cursor:pointer; line-height:1; }
  #pplay-grid:checked ~ .pp-tabbar label[for="pplay-grid"],
  #pplay-mason:checked ~ .pp-tabbar label[for="pplay-mason"],
  #pplay-list:checked ~ .pp-tabbar label[for="pplay-list"]{ color:var(--fg); }
  .pp-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:3px; margin-top:20px; }
  .pp-tile{ position:relative; display:block; aspect-ratio:1/1; background:var(--panel); overflow:hidden; }
  .pp-tile img{ width:100%; height:100%; object-fit:cover; display:block; }
  .pp-badge{ position:absolute; right:6px; bottom:6px; background:rgba(0,0,0,.62); color:#fff;
             font-size:.68rem; font-weight:600; padding:2px 6px; border-radius:5px; }
  .pp-multi{ position:absolute; right:6px; top:6px; color:#fff; line-height:0; filter:drop-shadow(0 1px 2px rgba(0,0,0,.7)); }
  /* Tiles show the pre-baked pixelfed square (crop + frame baked in) — no CSS
     matte needed; the tile is a plain square cover, exactly like Pixelfed. */
  #pplay-mason:checked ~ .pp-grid{ display:block; column-count:3; column-gap:3px; }
  #pplay-mason:checked ~ .pp-grid .pp-tile{ aspect-ratio:auto; break-inside:avoid; margin-bottom:3px; }
  #pplay-mason:checked ~ .pp-grid .pp-tile img{ height:auto; }
  #pplay-list:checked ~ .pp-grid{ display:block; max-width:560px; margin:20px auto 0; }
  #pplay-list:checked ~ .pp-grid .pp-tile{ aspect-ratio:auto; margin-bottom:14px; border-radius:8px; }
  #pplay-list:checked ~ .pp-grid .pp-tile img{ height:auto; }
  .pp-empty{ margin-top:50px; text-align:center; color:var(--muted); }
  @media (max-width:820px){ .pp-shell{ flex-direction:column; } .pp-side{ width:100%; flex-basis:auto; } }
</style>
</head>
<body>
  <header class="pp-topbar"><div class="pp-topbar-in">
    <a class="pp-logo" href="<?php echo pp_e($pp_base); ?>"><b class="pp-logo-title"><?php echo pp_e($pp_name); ?></b><?php if ($pp_tagline !== ''): ?><span class="pp-logo-tag"> / <?php echo pp_e($pp_tagline); ?></span><?php endif; ?></a>
    <span class="pp-topbar-handle"><?php echo pp_e($pp_full_handle); ?></span>
  </div></header>

  <div class="pp-shell">
    <aside class="pp-side">
      <?php if ($pp_avatar !== ''): ?>
        <img class="pp-side-av" src="<?php echo pp_e($pp_avatar); ?>" alt="">
      <?php else: ?><div class="pp-side-av"></div><?php endif; ?>
      <div class="pp-side-name"><?php echo pp_e($pp_name); ?></div>
      <a class="pp-side-handle" href="<?php echo pp_e(sv_actor_url($settings)); ?>"><?php echo pp_e($pp_full_handle); ?></a>
      <div class="pp-counts">
        <div><b><?php echo number_format($pp_posts); ?></b><span>Posts</span></div>
        <div><b><?php echo number_format($pp_followers); ?></b><span>Followers</span></div>
        <div><b><?php echo number_format($pp_following); ?></b><span>Following</span></div>
      </div>
      <form class="pp-follow" method="get" action="<?php echo pp_e($pp_base); ?>ap/remote-follow">
        <?php if ($pp_followerr): ?><div class="pp-err">Couldn't read that handle — use <strong>you@your-instance.social</strong>.</div><?php endif; ?>
        <div class="pp-follow-hint">Follow <?php echo pp_e($pp_full_handle); ?> from your own Mastodon or Pixelfed account. Type your handle below (like <code>you@mastodon.social</code>) and you'll be sent to your own instance to confirm. There's no account to create here.</div>
        <input type="text" name="handle" placeholder="you@your-instance" aria-label="Your fediverse handle" required>
        <button type="submit">Follow</button>
      </form>
      <div class="pp-biobox"><?php echo nl2br(pp_e($pp_bio)); ?><?php if ($pp_bio !== ''): ?><br><br><?php endif; ?>See <a href="<?php echo pp_e($pp_base); ?>"><?php echo pp_e($pp_host); ?></a> for more.</div>
      <?php $pp_joined = !empty($pp_actor['published']) ? date('F Y', strtotime((string)$pp_actor['published'])) : ''; ?>
      <?php if ($pp_joined !== ''): ?><div class="pp-joined">&#128339; Joined <?php echo pp_e($pp_joined); ?></div><?php endif; ?>
      <nav class="pp-side-foot"><a href="<?php echo pp_e($pp_base); ?>">Home</a><a href="<?php echo pp_e($pp_base); ?>about">About</a></nav>
      <div class="pp-powered">Powered by <a href="https://snapsmack.ca" target="_blank" rel="noopener">SNAPSMACK</a></div>
    </aside>

    <main class="pp-main">
      <input type="radio" name="pplay" id="pplay-grid" class="pp-lay" checked>
      <input type="radio" name="pplay" id="pplay-mason" class="pp-lay">
      <input type="radio" name="pplay" id="pplay-list" class="pp-lay">
      <div class="pp-tabbar">
        <span class="pp-tab">Posts</span>
        <span class="pp-toggle">
          <label for="pplay-grid" title="Grid">&#9638;</label>
          <label for="pplay-mason" title="Masonry">&#9636;</label>
          <label for="pplay-list" title="List">&#9776;</label>
        </span>
      </div>
      <?php if ($pp_tiles): ?>
        <div class="pp-grid">
          <?php
          foreach ($pp_tiles as $t):
              // Pixelfed-friendly image: the SAME baked square the Note ships as
              // its attachment — p_ fedi bake ?? f_ frame bake. The crop + frame
              // are baked into the pixels, so the tile needs ZERO CSS matte and
              // is byte-for-byte what a Pixelfed viewer sees (Sean's call: stop
              // monkeying with CSS, grab the baked version). Falls back to the
              // square/aspect thumb only for posts not yet baked, so nothing
              // renders blank.
              $thumb = sv_fedi_bake_url($t, $settings) ?? sv_frame_url($t, $settings);
              if ($thumb === null) {
                  $tsrc  = trim((string)($t['img_thumb_square'] ?? '')) ?: trim((string)($t['img_thumb_aspect'] ?? ''));
                  $thumb = $tsrc !== '' ? $pp_base . ltrim(str_replace('\\', '/', $tsrc), '/')
                                        : pp_thumb((string)$t['img_file'], $pp_base);
              }
              // Link to the Pixelfed-faithful single-post view (content-negotiated).
              $link  = !empty($t['post_id'])
                  ? $pp_base . 'ap/note/p/' . (int)$t['post_id']
                  : $pp_base . 'ap/note/i/' . (int)$t['id'];
              $multi = (int)($t['image_count'] ?? 0) > 1;
              $ago   = pp_timeago($t['created_at'] ?? null);
              ?>
            <a class="pp-tile" href="<?php echo pp_e($link); ?>">
              <img loading="lazy" src="<?php echo pp_e($thumb); ?>" alt="">
              <?php if ($multi): ?><span class="pp-multi"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4h11a2 2 0 0 1 2 2v11h-2V6H7V4zm-3 4h11a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2z"/></svg></span><?php endif; ?>
              <?php if ($ago !== ''): ?><span class="pp-badge"><?php echo pp_e($ago); ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="pp-empty">No public posts yet.</div>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
