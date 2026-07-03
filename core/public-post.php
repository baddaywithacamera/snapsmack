<?php
/**
 * SNAPSMACK — Public fediverse single-post view (Pixelfed-faithful)
 *
 * The human half of a Note. smackverse.php's `note` route content-negotiates:
 * a fediverse server (activity+json) gets the Note JSON; a browser gets THIS —
 * a Pixelfed-shaped post page (big photo / swipeable carousel, author, caption,
 * faves, replies) with a remote-interaction doorway that bounces a visitor to
 * their own instance to like/reply/boost. One server, one user.
 *
 * Included from smackverse.php with $pdo, $settings in scope, and
 * $GLOBALS['pp_post_id'] / $GLOBALS['pp_post_kind'] ('post'|'image') set.
 * Carousel is CSS scroll-snap — no JS (skins/pages carry zero inline script).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$pk_id   = (int)($GLOBALS['pp_post_id'] ?? 0);
$pk_kind = (string)($GLOBALS['pp_post_kind'] ?? 'image');
$pk_base = rtrim(defined('BASE_URL') ? BASE_URL : sv_base($settings), '/') . '/';

if ($pk_kind === 'post') {
    $pk_row = sv_post_row($pdo, $pk_id);
    if (!$pk_row) sv_404();
    $pk_images = sv_post_images($pdo, $pk_id);
    $pk_title  = trim((string)($pk_row['title'] ?? ''));
    $pk_desc   = trim((string)($pk_row['description'] ?? ''));
    $pk_note   = sv_base($settings) . 'ap/note/p/' . $pk_id;
    $pk_ltype  = 'post'; $pk_ltarget = $pk_id;
    $pk_pub    = (string)($pk_row['created_at'] ?? '');
} else {
    $pk_row = sv_image_row($pdo, $pk_id);
    if (!$pk_row) sv_404();
    $pk_images = [$pk_row];
    $pk_title  = trim((string)($pk_row['img_title'] ?? ''));
    $pk_desc   = trim((string)($pk_row['img_description'] ?? ''));
    $pk_note   = sv_base($settings) . 'ap/note/i/' . $pk_id;
    $pk_ltype  = 'image'; $pk_ltarget = $pk_id;
    $pk_pub    = (string)($pk_row['img_date'] ?? '');
}

// Author (this blog).
$pk_handle = '@' . sv_handle($settings) . '@' . sv_domain($settings);
$pk_name   = $settings['site_name'] ?? sv_handle($settings);
$pk_av     = sv_avatar($settings);
$pk_avatar = is_array($pk_av) ? (string)($pk_av['url'] ?? '') : '';

// Faves (federated) + approved replies.
$pk_faves = 0;
try { $pk_faves = sv_fedi_like_count($pdo, $pk_ltype, $pk_ltarget); } catch (Throwable $e) {}
$pk_replies = [];
try {
    $ids = array_map(function ($im) { return (int)$im['id']; }, $pk_images);
    if ($ids) {
        $in = implode(',', $ids);
        $pk_replies = $pdo->query(
            "SELECT comment_author, comment_text, comment_date FROM snap_comments
             WHERE img_id IN ($in) AND is_approved = 1
             ORDER BY comment_date DESC LIMIT 40"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) { $pk_replies = []; }

function pk_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function pk_img_url(array $im, string $base): string {
    $f = ltrim(str_replace('\\', '/', (string)($im['img_file'] ?? '')), '/');
    return $base . $f;
}

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pk_e($pk_title !== '' ? $pk_title : $pk_name); ?> — <?php echo pk_e($pk_handle); ?></title>
<link rel="alternate" type="application/activity+json" href="<?php echo pk_e($pk_note); ?>">
<style>
  :root{ --pk-bg:#fafafa; --pk-card:#fff; --pk-fg:#161616; --pk-muted:#6b7280; --pk-line:#e6e6e6; --pk-accent:#6366f1; }
  *{ box-sizing:border-box; }
  body{ margin:0; background:var(--pk-bg); color:var(--pk-fg);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .pk-top{ border-bottom:1px solid var(--pk-line); background:var(--pk-card); }
  .pk-top-inner{ max-width:935px; margin:0 auto; padding:14px 20px; display:flex; align-items:center; gap:14px; }
  .pk-logo{ font-weight:800; font-size:1.1rem; background:linear-gradient(90deg,#6366f1,#a855f7);
            -webkit-background-clip:text; background-clip:text; color:transparent; text-decoration:none; }
  .pk-back{ margin-left:auto; color:var(--pk-muted); font-size:.9rem; text-decoration:none; }
  .pk-wrap{ max-width:640px; margin:26px auto; background:var(--pk-card); border:1px solid var(--pk-line); border-radius:6px; overflow:hidden; }
  .pk-head{ display:flex; align-items:center; gap:10px; padding:12px 14px; }
  .pk-avatar{ width:34px; height:34px; border-radius:50%; object-fit:cover; background:#eee; }
  .pk-au{ font-weight:600; font-size:.9rem; text-decoration:none; color:inherit; }
  .pk-au-sub{ color:var(--pk-muted); font-size:.78rem; }
  .pk-media{ display:flex; overflow-x:auto; scroll-snap-type:x mandatory; background:#000; }
  .pk-media img{ width:100%; flex:0 0 100%; scroll-snap-align:center; object-fit:contain; max-height:70vh; display:block; }
  .pk-actions{ display:flex; gap:16px; align-items:center; padding:12px 14px; border-top:1px solid var(--pk-line); }
  .pk-faves{ font-weight:600; font-size:.9rem; }
  .pk-caption{ padding:0 14px 14px; font-size:.92rem; line-height:1.5; white-space:pre-wrap; }
  .pk-caption b{ margin-right:6px; }
  .pk-time{ padding:0 14px 14px; color:var(--pk-muted); font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; }
  .pk-interact{ padding:12px 14px; border-top:1px solid var(--pk-line); background:#fbfbfd; }
  .pk-interact form{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .pk-interact input{ padding:7px 10px; border:1px solid var(--pk-line); border-radius:8px; font-size:.85rem; min-width:190px; flex:1; }
  .pk-interact button{ padding:7px 16px; border:none; border-radius:8px; background:var(--pk-accent); color:#fff; font-weight:600; font-size:.85rem; cursor:pointer; }
  .pk-interact .pk-hint{ color:var(--pk-muted); font-size:.78rem; width:100%; margin-top:2px; }
  .pk-replies{ border-top:1px solid var(--pk-line); padding:12px 14px; }
  .pk-reply{ margin-bottom:10px; font-size:.88rem; line-height:1.4; }
  .pk-reply b{ margin-right:6px; }
  .pk-none{ color:var(--pk-muted); font-size:.85rem; }
</style>
</head>
<body>
  <div class="pk-top"><div class="pk-top-inner">
    <a class="pk-logo" href="<?php echo pk_e(sv_profile_url($settings)); ?>">SMACKVERSE</a>
    <a class="pk-back" href="<?php echo pk_e(sv_profile_url($settings)); ?>">← <?php echo pk_e($pk_handle); ?></a>
  </div></div>

  <div class="pk-wrap">
    <div class="pk-head">
      <?php if ($pk_avatar !== ''): ?><img class="pk-avatar" src="<?php echo pk_e($pk_avatar); ?>" alt=""><?php else: ?><span class="pk-avatar"></span><?php endif; ?>
      <div>
        <a class="pk-au" href="<?php echo pk_e(sv_profile_url($settings)); ?>"><?php echo pk_e($pk_name); ?></a>
        <div class="pk-au-sub"><?php echo pk_e($pk_handle); ?></div>
      </div>
    </div>

    <div class="pk-media">
      <?php foreach ($pk_images as $im): ?>
        <img loading="lazy" src="<?php echo pk_e(pk_img_url($im, $pk_base)); ?>" alt="<?php echo pk_e($pk_title); ?>">
      <?php endforeach; ?>
    </div>

    <div class="pk-actions">
      <span class="pk-faves">♥ <?php echo number_format((int)$pk_faves); ?></span>
      <?php if (count($pk_images) > 1): ?><span class="pk-au-sub"><?php echo count($pk_images); ?> photos — swipe</span><?php endif; ?>
    </div>

    <?php if ($pk_title !== '' || $pk_desc !== ''): ?>
      <div class="pk-caption"><b><?php echo pk_e(sv_handle($settings)); ?></b><?php
        echo pk_e(trim(($pk_title !== '' ? $pk_title . ($pk_desc !== '' ? ' — ' : '') : '') . $pk_desc)); ?></div>
    <?php endif; ?>
    <?php if ($pk_pub !== ''): ?><div class="pk-time"><?php echo pk_e(date('M j, Y', strtotime($pk_pub))); ?></div><?php endif; ?>

    <div class="pk-interact">
      <form method="get" action="<?php echo pk_e($pk_base); ?>ap/remote-interact">
        <input type="hidden" name="uri" value="<?php echo pk_e($pk_note); ?>">
        <input type="text" name="handle" placeholder="you@your-instance" aria-label="Your fediverse handle" required>
        <button type="submit">Like / Reply / Boost</button>
        <span class="pk-hint">Interact from your own Mastodon or Pixelfed account — we'll send you there to sign in.</span>
      </form>
    </div>

    <div class="pk-replies">
      <?php if ($pk_replies): ?>
        <?php foreach ($pk_replies as $r): ?>
          <div class="pk-reply"><b><?php echo pk_e((string)$r['comment_author']); ?></b><?php echo pk_e((string)$r['comment_text']); ?></div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="pk-none">No replies yet — be the first from your instance.</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
