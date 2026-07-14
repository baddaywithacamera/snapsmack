<?php
/**
 * SNAPSMACK — SMACKVERSE : Standalone Pixelfed-compatible client  (pixel.php)
 *
 * A STANDALONE page (no admin header/sidebar/footer) that opens from SnapSmack
 * and works like a real Pixelfed instance — follow / like / comment / boost /
 * search / hashtags / DMs / timelines / notifications. Owner-only (auth-smack),
 * rendered on its own so it reads as Pixelfed, not the CMS. Posting is NOT here
 * (that's the blog); this is the INTERACTION surface.
 *
 * UI is ORIGINAL (assets/css/ss-pixel.css + assets/js/ss-pixel.js), matched to
 * the live pixelfed.ca look by observation — no Pixelfed source was copied
 * (theirs is GPL; ours must stay clean). DATA + INTERACTIONS reuse SnapSmack's
 * own engine (core/smackverse.php) via the ?ajax=<panel> read endpoints and the
 * sspf_action POST handlers below — the same contract the admin client used, so
 * nothing about the engine is rebuilt.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once 'core/auth-smack.php';       // owner gate + session + global csrf_check()
require_once 'core/smackverse.php';

$sv_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
sv_ensure_tables($pdo);
$sv_on = sv_enabled($sv_settings);

/* ── POST interactions (JSON) — CSRF already enforced by auth-smack ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sspf_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = (string)$_POST['sspf_action'];
    if (!$sv_on) { echo json_encode(['ok' => false, 'msg' => 'SMACKVERSE is off — flip it on in Federation first.']); exit; }

    $ok = false; $msg = 'Unknown action.'; $extra = [];
    switch ($act) {
        case 'follow':
            list($ok, $msg) = sv_follow_actor($pdo, $sv_settings, (string)($_POST['target'] ?? ''));
            if ($ok) {
                $resolved = (stripos((string)($_POST['target'] ?? ''), 'https://') === 0)
                    ? (string)$_POST['target'] : (string)sv_webfinger_lookup((string)($_POST['target'] ?? ''));
                list($st, $rid) = sv_following_state($pdo, $resolved);
                $extra = ['state' => $st, 'row_id' => $rid];
            }
            break;
        case 'unfollow':
            list($ok, $msg) = sv_unfollow_actor($pdo, $sv_settings, (int)($_POST['row_id'] ?? 0));
            if ($ok) $extra = ['state' => '', 'row_id' => 0];
            break;
        case 'like':
            list($ok, $msg) = sv_like_remote($pdo, $sv_settings, (string)($_POST['object'] ?? ''), (string)($_POST['actor'] ?? ''));
            break;
        case 'unlike':
            list($ok, $msg) = sv_unlike_remote($pdo, $sv_settings, (string)($_POST['object'] ?? ''), (string)($_POST['actor'] ?? ''));
            break;
        case 'reply':
            list($ok, $msg) = sv_reply_remote($pdo, $sv_settings, (string)($_POST['object'] ?? ''), (string)($_POST['actor'] ?? ''), (string)($_POST['content'] ?? ''));
            break;
        case 'boost':
            list($ok, $msg) = sv_boost_remote($pdo, $sv_settings, (string)($_POST['object'] ?? ''));
            break;
        case 'mark_read':
            sv_mark_notifications_read($pdo); $ok = true; $msg = ''; $extra = ['unread' => 0];
            break;
        case 'dm_send':
            list($ok, $msg) = sv_send_dm($pdo, $sv_settings, (string)($_POST['target'] ?? ''), (string)($_POST['body'] ?? ''), trim((string)($_POST['media'] ?? '')) ?: null);
            break;
    }
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── AJAX (JSON) reads — must return BEFORE any HTML ──────────────────────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $panel = preg_replace('/[^a-z]/', '', (string)$_GET['ajax']);

    if ($panel === 'home') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => []]); exit; }
        $items = sv_home_timeline($pdo, 60);
        if (!$items) { @set_time_limit(30); $items = sv_home_feed($pdo, 6, 40); }
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES); exit;
    }

    if ($panel === 'notifications') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => [], 'unread' => 0]); exit; }
        echo json_encode(['ok' => true, 'items' => sv_notifications($pdo, 60), 'unread' => sv_unread_count($pdo)], JSON_UNESCAPED_SLASHES); exit;
    }

    if ($panel === 'direct') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'threads' => []]); exit; }
        $tactor = trim((string)($_GET['actor'] ?? ''));
        if ($tactor !== '') {
            try { $pdo->prepare("UPDATE snap_ap_dms SET is_read = 1 WHERE remote_actor_url = ? AND direction = 'in'")->execute([$tactor]); } catch (Exception $e) {}
            $ms = $pdo->prepare("SELECT id, remote_handle, direction, body, media_url, is_deleted, created_at
                 FROM snap_ap_dms WHERE remote_actor_url = ? ORDER BY created_at ASC, id ASC LIMIT 500");
            $ms->execute([$tactor]);
            echo json_encode(['ok' => true, 'messages' => $ms->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_SLASHES); exit;
        }
        $threads = [];
        try {
            $threads = $pdo->query(
                "SELECT remote_actor_url, MAX(remote_handle) AS handle, MAX(created_at) AS last_at,
                        SUM(direction = 'in' AND is_read = 0 AND is_deleted = 0) AS unread,
                        MAX(is_request) AS is_request,
                        SUBSTRING_INDEX(MAX(CONCAT(created_at, '\\n', COALESCE(body,''))), '\\n', -1) AS last_body
                 FROM snap_ap_dms GROUP BY remote_actor_url ORDER BY last_at DESC LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $threads = []; }
        echo json_encode(['ok' => true, 'threads' => $threads], JSON_UNESCAPED_SLASHES); exit;
    }

    if ($panel === 'thread') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => []]); exit; }
        $object = trim((string)($_GET['object'] ?? ''));
        if ($object === '') { echo json_encode(['ok' => true, 'items' => []]); exit; }
        @set_time_limit(20);
        echo json_encode(['ok' => true, 'items' => sv_status_replies($object)], JSON_UNESCAPED_SLASHES); exit;
    }

    if ($panel === 'local' || $panel === 'global') {
        if (!$sv_on) { echo json_encode(['ok' => true, 'items' => []]); exit; }
        $host = trim((string)($sv_settings['smackverse_home_instance'] ?? ''));
        if ($host === '') {
            try {
                $h = (string)$pdo->query("SELECT actor_url FROM snap_ap_following WHERE state='accepted' ORDER BY followed_at DESC LIMIT 1")->fetchColumn();
                if ($h !== '') $host = parse_url($h, PHP_URL_HOST) ?: '';
            } catch (Exception $e) {}
        }
        $host = preg_replace('/[^a-z0-9.\-]/i', '', $host);
        if ($host === '') { echo json_encode(['ok' => true, 'items' => [], 'msg' => 'Follow someone (or set a home instance) and the ' . $panel . ' timeline lights up.']); exit; }
        @set_time_limit(30);
        echo json_encode(['ok' => true, 'items' => sv_public_timeline($host, $panel === 'local', 30)], JSON_UNESCAPED_SLASHES); exit;
    }

    if ($panel === 'profile' || $panel === 'search') {
        $target = trim((string)($_GET['handle'] ?? ''));
        if ($target === '' && $panel === 'profile') $target = sv_actor_url($sv_settings);
        if ($target === '') { echo json_encode(['ok' => false, 'msg' => 'Type a handle (@user@host) or a #hashtag.']); exit; }

        if ($panel === 'search') {
            $looks_handle = (strpos($target, '@') !== false && strpos($target, ' ') === false) || stripos($target, 'https://') === 0;

            if ($target !== '' && $target[0] !== '#' && !$looks_handle && function_exists('sv_authed_search')) {
                $sr = sv_pick_search_account($pdo)
                    ? sv_authed_search($pdo, $sv_settings, $target, '')
                    : (function_exists('sv_hub_search') ? sv_hub_search($pdo, $sv_settings, 'query', $target, 40) : null);
                if (is_array($sr)) {
                    $sr_accts = []; foreach (($sr['accounts'] ?? []) as $ac) { if (is_array($ac)) $sr_accts[] = sv_map_account_card($ac); }
                    $sr_items = []; foreach (($sr['statuses'] ?? []) as $st) { if (is_array($st)) { $row = sv_map_status_row($st); if ($row) $sr_items[] = $row; } }
                    if ($sr_accts || $sr_items) {
                        echo json_encode(['ok' => true, 'mode' => 'results', 'query' => $target, 'accounts' => $sr_accts, 'items' => $sr_items], JSON_UNESCAPED_SLASHES); exit;
                    }
                }
            }

            if ($target[0] === '#' || !$looks_handle) {
                $tag = preg_replace('/[^a-z0-9_]/i', '', ltrim($target, '#'));
                if ($tag === '') { echo json_encode(['ok' => false, 'msg' => 'Enter a hashtag like #sunset.']); exit; }
                $authed = null;
                if (function_exists('sv_authed_hashtag_timeline') && sv_pick_search_account($pdo)) { @set_time_limit(30); $authed = sv_authed_hashtag_timeline($pdo, $sv_settings, $tag, 40); }
                if ((!is_array($authed) || !$authed) && function_exists('sv_hub_search')) { @set_time_limit(30); $authed = sv_hub_search($pdo, $sv_settings, 'hashtag', $tag, 40); }
                if (is_array($authed) && $authed) { echo json_encode(['ok' => true, 'mode' => 'feed', 'title' => '#' . $tag, 'items' => $authed], JSON_UNESCAPED_SLASHES); exit; }

                $host = trim((string)($sv_settings['smackverse_home_instance'] ?? ''));
                if ($host === '') {
                    try {
                        $h = (string)$pdo->query("SELECT actor_url FROM snap_ap_following WHERE state='accepted' ORDER BY followed_at DESC LIMIT 1")->fetchColumn();
                        if ($h !== '') $host = parse_url($h, PHP_URL_HOST) ?: '';
                    } catch (Exception $e) {}
                }
                $host = preg_replace('/[^a-z0-9.\-]/i', '', $host);
                if ($host === '') { echo json_encode(['ok' => false, 'msg' => 'Follow someone (or set a home instance) so hashtag search has a server to ask.']); exit; }
                @set_time_limit(30);
                echo json_encode(['ok' => true, 'mode' => 'feed', 'title' => '#' . $tag, 'items' => sv_hashtag_timeline($host, $tag, 40)], JSON_UNESCAPED_SLASHES); exit;
            }
        }

        $actor = sv_crawl_actor($target);
        if ($actor === null) { echo json_encode(['ok' => false, 'msg' => 'Could not resolve "' . $target . '" — check the handle (@user@host), or try a #hashtag.']); exit; }
        $is_self = ($actor['id'] === sv_actor_url($sv_settings));
        $posts = sv_fetch_gallery($actor, $is_self ? 500 : 36);
        list($state, $row_id) = sv_following_state($pdo, $actor['id']);
        echo json_encode(['ok' => true, 'mode' => 'profile', 'actor' => $actor, 'posts' => $posts, 'state' => $state, 'row_id' => $row_id, 'is_self' => $is_self], JSON_UNESCAPED_SLASHES); exit;
    }

    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_SLASHES); exit;
}

/* ── Shell data: this blog's own actor for the left card ──────────────────── */
$sv_handle = ''; $sv_avatar = ''; $sv_name = 'This blog';
try {
    $actor_doc = sv_actor_doc($pdo, $sv_settings);
    $sv_user = $actor_doc['preferredUsername'] ?? 'blog';
    $sv_name = (string)($actor_doc['name'] ?? $sv_user);
    $sv_host = parse_url($actor_doc['id'] ?? sv_actor_url($sv_settings), PHP_URL_HOST) ?: '';
    if ($sv_host !== '') $sv_handle = '@' . $sv_user . '@' . $sv_host;
    $icon = $actor_doc['icon'] ?? null;
    if (is_array($icon)) { $sv_avatar = (string)($icon['url'] ?? ($icon[0]['url'] ?? '')); }
    elseif (is_string($icon)) { $sv_avatar = $icon; }
} catch (Throwable $e) {}

$sv_unread = $sv_on ? sv_unread_count($pdo) : 0;
$ver = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : time();

function px_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMACKVERSE — Pixelfed</title>
<?php if (function_exists('csrf_meta_tag')) csrf_meta_tag(); ?>
<script>try{var _t=localStorage.getItem('pixel-theme')||'dark';document.documentElement.setAttribute('data-theme',_t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}</script>
<link rel="stylesheet" href="assets/css/ss-pixel.css?v=<?php echo px_e($ver); ?>">
</head>
<body>

<header class="sx-top">
  <div class="sx-top-in">
    <a class="sx-brand" href="pixel.php"><span class="sx-mark"></span> SMACKVERSE</a>
    <div class="sx-search"><span class="sx-mag">&#128269;</span>
      <input type="text" placeholder="@user@host  ·  #hashtag" aria-label="Search the fediverse">
    </div>
    <div class="sx-top-right">
      <button class="sx-theme" aria-label="Toggle light/dark">&#9790;</button>
      <div class="sx-account">
        <button class="sx-me-btn" aria-label="Your account" aria-haspopup="true">
          <img class="sx-me" src="<?php echo px_e($sv_avatar); ?>" alt="You">
        </button>
        <div class="sx-account-menu" hidden>
          <div class="sx-account-who">
            <span class="sx-account-status">&#9679; Signed in</span>
            <span class="sx-account-handle"><?php echo px_e($sv_handle ?: 'this blog'); ?></span>
          </div>
          <a href="pixel.php" data-panel="profile" class="sx-account-item">My profile</a>
          <a href="index.php" class="sx-account-item">Back to blog</a>
          <a href="logout.php" class="sx-account-item sx-account-logout">Log out</a>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="sx-app"
     data-api="pixel.php"
     data-actor="<?php echo px_e($sv_handle); ?>"
     data-avatar="<?php echo px_e($sv_avatar); ?>"
     data-enabled="<?php echo $sv_on ? '1' : '0'; ?>"
     data-unread="<?php echo (int)$sv_unread; ?>"
     data-default-panel="<?php echo px_e(in_array($_GET['panel'] ?? '', ['home','local','global','notifications','direct','profile','discover'], true) ? (string)$_GET['panel'] : 'home'); ?>">

  <!-- LEFT -->
  <aside class="sx-col-left">
    <div class="sx-idcard">
      <img class="sx-av" src="<?php echo px_e($sv_avatar); ?>" alt="">
      <div>
        <div class="sx-name"><?php echo px_e($sv_name); ?></div>
        <div class="sx-handle"><?php echo px_e($sv_handle); ?></div>
      </div>
    </div>

    <a class="sx-create" href="smack-post-solo.php" title="Posting happens in the blog composer (same fields, same posts)">&#8593; Create New Post</a>

    <nav class="sx-nav">
      <div class="sx-nav-top">
        <a data-panel="home" class="active"><span class="sx-ic">&#8962;</span>Home</a>
        <a data-panel="local"><span class="sx-ic">&#9776;</span>Local</a>
        <a data-panel="global"><span class="sx-ic">&#127760;</span>Global</a>
      </div>
      <div class="sx-nav-list">
        <a data-panel="discover"><span class="sx-ic">&#9673;</span>Discover</a>
        <a data-panel="direct"><span class="sx-ic">&#9993;</span>Direct Messages</a>
        <a data-panel="notifications"><span class="sx-ic">&#9825;</span>Notifications<?php if ($sv_unread > 0): ?><span class="sx-badge"><?php echo (int)$sv_unread; ?></span><?php endif; ?></a>
        <a data-panel="profile"><span class="sx-ic">&#9728;</span>Profile</a>
      </div>
    </nav>

    <div class="sx-foot">
      <a href="index.php">Back to blog</a><a href="#">Help</a><a href="#">Privacy</a><a href="#">Terms</a>
      <span>SMACKVERSE</span>
    </div>
  </aside>

  <!-- CENTER -->
  <main class="sx-col-center">
    <section class="sx-panel active" data-panel="home"><div class="sx-panel-body"></div></section>
    <section class="sx-panel" data-panel="local"><div class="sx-page-title">Local</div><div class="sx-panel-body"></div></section>
    <section class="sx-panel" data-panel="global"><div class="sx-page-title">Global</div><div class="sx-panel-body"></div></section>
    <section class="sx-panel" data-panel="discover"><div class="sx-page-title">Discover</div><div class="sx-panel-body"><div class="sx-note">Discover lights up once search + trending are wired.</div></div></section>
    <section class="sx-panel" data-panel="notifications"><div class="sx-page-title">Notifications</div><div class="sx-panel-body"></div></section>
    <section class="sx-panel" data-panel="direct"><div class="sx-page-title">Direct Messages</div><div class="sx-panel-body"></div></section>
    <section class="sx-panel" data-panel="profile"><div class="sx-panel-body"></div></section>
    <section class="sx-panel" data-panel="search"><div class="sx-page-title">Search</div><div class="sx-panel-body"></div></section>
  </main>

  <!-- RIGHT -->
  <aside class="sx-col-right">
    <div class="sx-railcard">
      <div class="sx-railcard-h">Notifications<span class="sx-sp"></span><button aria-label="Refresh">&#8635;</button></div>
      <div class="sx-rail-body"></div>
    </div>
  </aside>
</div>

<script src="assets/js/ss-pixel.js?v=<?php echo px_e($ver); ?>" defer></script>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
