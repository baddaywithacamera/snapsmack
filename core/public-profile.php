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
$pp_name   = $pp_actor['name'] ?? ($settings['site_name'] ?? $pp_handle);
$pp_bio    = trim(strip_tags((string)($settings['smackverse_bio'] ?? ($settings['site_description'] ?? ''))));

$pp_avatar = '';
if (isset($pp_actor['icon']['url'])) $pp_avatar = (string)$pp_actor['icon']['url'];

// Counts.
$pp_followers = 0; $pp_following = 0; $pp_posts = 0;
try { $pp_followers = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1")->fetchColumn(); } catch (Throwable $e) {}
try { $pp_following = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_following WHERE state = 'accepted'")->fetchColumn(); } catch (Throwable $e) {}
try {
    // is_cover lives on the snap_post_images PIVOT, not snap_images — the old
    // query referenced it on the wrong table, threw, and silently counted 0.
    $pp_posts = (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_posts
         WHERE status = 'published' AND created_at <= NOW()
           AND post_type IN ('single','carousel','panorama')"
    )->fetchColumn();
} catch (Throwable $e) {}

// Photo grid — one tile per published post (its cover), in the blog's grid
// order (sort_order), so the profile mirrors the home feed. Cover = the
// is_cover pivot row, falling back to the first image if none is flagged.
$pp_tiles = [];
try {
    $st = $pdo->query(
        "SELECT i.id, i.post_id, i.img_file, i.img_slug
         FROM snap_posts p
         JOIN snap_post_images pi ON pi.post_id = p.id
            AND pi.image_id = (SELECT image_id FROM snap_post_images
                               WHERE post_id = p.id
                               ORDER BY is_cover DESC, sort_position ASC LIMIT 1)
         JOIN snap_images i ON i.id = pi.image_id AND i.img_status = 'published'
         WHERE p.status = 'published' AND p.created_at <= NOW()
           AND p.post_type IN ('single','carousel','panorama')
         ORDER BY CASE WHEN p.sort_order > 0 THEN 1 ELSE 0 END ASC,
                  p.sort_order ASC, p.created_at DESC
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

$pp_full_handle = '@' . $pp_handle . '@' . $pp_host;
$pp_followerr   = ($_GET['follow'] ?? '') === 'badhandle';
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pp_e($pp_name); ?> (<?php echo pp_e($pp_full_handle); ?>)</title>
<link rel="alternate" type="application/activity+json" href="<?php echo pp_e(sv_actor_url($settings)); ?>">
<style>
  :root{ --pp-bg:#fff; --pp-fg:#161616; --pp-muted:#6b7280; --pp-line:#e6e6e6; --pp-accent:#6366f1; --pp-tile:#f2f2f2; }
  *{ box-sizing:border-box; }
  body{ margin:0; background:var(--pp-bg); color:var(--pp-fg);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .pp-top{ border-bottom:1px solid var(--pp-line); }
  .pp-top-inner{ max-width:935px; margin:0 auto; padding:14px 20px; display:flex; align-items:center; gap:14px; }
  .pp-logo{ font-weight:800; letter-spacing:.3px; font-size:1.1rem;
            background:linear-gradient(90deg,#6366f1,#a855f7); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .pp-top-handle{ margin-left:auto; color:var(--pp-muted); font-size:.9rem; }
  .pp-wrap{ max-width:935px; margin:0 auto; padding:30px 20px 60px; }
  .pp-head{ display:flex; gap:40px; align-items:flex-start; padding-bottom:34px; border-bottom:1px solid var(--pp-line); flex-wrap:wrap; }
  .pp-avatar{ width:150px; height:150px; border-radius:50%; object-fit:cover; background:var(--pp-tile); flex-shrink:0; }
  .pp-info{ min-width:240px; flex:1; }
  .pp-namerow{ display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
  .pp-username{ font-size:1.5rem; font-weight:300; }
  .pp-follow{ display:inline-flex; align-items:center; gap:8px; }
  .pp-follow input{ padding:7px 10px; border:1px solid var(--pp-line); border-radius:8px; font-size:.85rem; min-width:190px; }
  .pp-follow button{ padding:7px 18px; border:none; border-radius:8px; background:var(--pp-accent); color:#fff; font-weight:600; font-size:.85rem; cursor:pointer; }
  .pp-stats{ display:flex; gap:40px; margin:20px 0; font-size:1rem; }
  .pp-stats b{ font-weight:700; }
  .pp-stats span{ color:var(--pp-muted); }
  .pp-name{ font-weight:700; }
  .pp-bio{ margin-top:4px; white-space:pre-wrap; max-width:560px; line-height:1.45; }
  .pp-err{ margin-top:10px; color:#b91c1c; font-size:.85rem; }
  .pp-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:4px; margin-top:28px; }
  .pp-grid a{ display:block; aspect-ratio:1/1; background:var(--pp-tile); overflow:hidden; }
  .pp-grid img{ width:100%; height:100%; object-fit:cover; display:block; }
  .pp-empty{ margin-top:40px; text-align:center; color:var(--pp-muted); }
  .pp-foot{ max-width:935px; margin:0 auto; padding:24px 20px 50px; color:var(--pp-muted); font-size:.8rem; text-align:center; }
  @media (max-width:640px){ .pp-head{ gap:20px; } .pp-avatar{ width:86px; height:86px; } .pp-stats{ gap:24px; } }
</style>
</head>
<body>
  <div class="pp-top"><div class="pp-top-inner">
    <span class="pp-logo">SMACKVERSE</span>
    <span class="pp-top-handle"><?php echo pp_e($pp_full_handle); ?></span>
  </div></div>

  <div class="pp-wrap">
    <div class="pp-head">
      <?php if ($pp_avatar !== ''): ?>
        <img class="pp-avatar" src="<?php echo pp_e($pp_avatar); ?>" alt="">
      <?php else: ?>
        <div class="pp-avatar"></div>
      <?php endif; ?>
      <div class="pp-info">
        <div class="pp-namerow">
          <span class="pp-username"><?php echo pp_e($pp_handle); ?></span>
          <form class="pp-follow" method="get" action="<?php echo pp_e($pp_base); ?>ap/remote-follow">
            <input type="text" name="handle" placeholder="you@your-instance" aria-label="Your fediverse handle" required>
            <button type="submit">Follow</button>
          </form>
        </div>
        <?php if ($pp_followerr): ?>
          <div class="pp-err">Couldn't read that handle — use the form <strong>you@your-instance.social</strong>.</div>
        <?php endif; ?>
        <div class="pp-stats">
          <span><b><?php echo number_format($pp_posts); ?></b> posts</span>
          <span><b><?php echo number_format($pp_followers); ?></b> followers</span>
          <span><b><?php echo number_format($pp_following); ?></b> following</span>
        </div>
        <div class="pp-name"><?php echo pp_e($pp_name); ?></div>
        <?php if ($pp_bio !== ''): ?><div class="pp-bio"><?php echo pp_e($pp_bio); ?></div><?php endif; ?>
      </div>
    </div>

    <?php if ($pp_tiles): ?>
      <div class="pp-grid">
        <?php foreach ($pp_tiles as $t):
            $thumb = pp_thumb((string)$t['img_file'], $pp_base);
            // Link to the Pixelfed-faithful single-post view (content-negotiated).
            $link  = !empty($t['post_id'])
                ? $pp_base . 'ap/note/p/' . (int)$t['post_id']
                : $pp_base . 'ap/note/i/' . (int)$t['id']; ?>
          <a href="<?php echo pp_e($link); ?>"><img loading="lazy" src="<?php echo pp_e($thumb); ?>" alt=""></a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="pp-empty">No public posts yet.</div>
    <?php endif; ?>
  </div>

  <div class="pp-foot">
    Following <?php echo pp_e($pp_full_handle); ?> from Mastodon, Pixelfed or any fediverse app:
    search that handle in your app, or use the Follow box above.
  </div>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
