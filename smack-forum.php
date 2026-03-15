<?php
/**
 * SNAPSMACK - Community Forum
 * Alpha v0.7.3
 *
 * Forum client for the SnapSmack admin community. Connects to the hub API on
 * snapsmack.ca (or a self-hosted fork) via REST. Access is restricted to
 * install administrators. Auto-registers the install on first visit.
 */

require_once 'core/auth.php';

// Load settings before any output — needed for API calls and registration.
if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── Constants ─────────────────────────────────────────────────────────────────
$forum_api_url = rtrim($settings['forum_api_url'] ?? 'https://snapsmack.ca/api/forum', '/');
$forum_api_key = $settings['forum_api_key'] ?? '';
$forum_enabled = ($settings['forum_enabled'] ?? '1') === '1';

// ── API Helper ────────────────────────────────────────────────────────────────
/**
 * Call the forum REST API.
 * Returns the decoded JSON response plus:
 *   _code  — HTTP status code (0 on connection failure)
 *   _error — true when _code >= 400 or cURL failed
 */
function forum_api(string $method, string $endpoint, array $body = [], string $key = ''): array {
    global $forum_api_url;
    $url = $forum_api_url . '/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $raw === false) {
        return ['_error' => true, '_code' => 0, '_message' => 'Could not reach the forum server. (' . $err . ')'];
    }
    $data           = json_decode($raw, true) ?? [];
    $data['_code']  = $code;
    $data['_error'] = $code >= 400;
    return $data;
}

/**
 * Generate a two-letter avatar initial from a display name.
 */
function forum_initials(string $name): string {
    $parts = preg_split('/[\s\-_.]+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}

/**
 * Deterministic avatar colour from a display name.  Returns an HSL hue so we
 * can keep saturation / lightness consistent across the dark theme.
 */
function forum_avatar_hue(string $name): int {
    return crc32($name) % 360;
}

/**
 * Render an avatar element.  If a domain is available, uses the site's
 * favicon via Google's favicon service with a fallback to the initials
 * circle on load error.  Without a domain, renders initials directly.
 *
 * $size: 'sm' | '' | 'lg'
 */
function forum_avatar(string $name, string $domain = '', string $size = ''): string {
    $hue      = forum_avatar_hue($name);
    $initials = htmlspecialchars(forum_initials($name));
    $cls      = 'forum-avatar' . ($size ? " forum-avatar--{$size}" : '');
    $bg       = "background:hsl({$hue},55%,45%);";

    if ($domain !== '') {
        // Google's public favicon API — returns a 16/32/64px icon for any domain.
        $fav_url = 'https://www.google.com/s2/favicons?domain=' . urlencode($domain) . '&sz=64';
        // On error, swap the <img> for the initials fallback
        return '<div class="' . $cls . '" style="' . $bg . '">'
             . '<img src="' . htmlspecialchars($fav_url) . '" '
             . 'alt="" style="width:100%;height:100%;border-radius:4px;object-fit:cover;" '
             . 'onerror="this.remove();">'
             . '<span class="forum-avatar__fallback">' . $initials . '</span>'
             . '</div>';
    }

    return '<div class="' . $cls . '" style="' . $bg . '">' . $initials . '</div>';
}

/**
 * Human-friendly relative timestamp ("3 h ago", "yesterday", "Mar 4").
 */
function forum_ago(string $datetime): string {
    $ts   = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'yesterday';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}

// ── Auto-Registration ─────────────────────────────────────────────────────────
// On first visit (no stored API key), register this install and persist the key.
$reg_error = '';
if ($forum_enabled && $forum_api_key === '') {
    $domain       = preg_replace('/^https?:\/\//i', '', rtrim($settings['site_url'] ?? '', '/'));
    $domain       = rtrim($domain, '/');
    $display_name = $settings['site_name'] ?? $domain;
    $ss_version   = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.7';

    $reg = forum_api('POST', 'register', [
        'domain'       => $domain,
        'display_name' => $display_name,
        'ss_version'   => $ss_version,
    ]);

    if (!$reg['_error'] && !empty($reg['api_key'])) {
        $new_key = $reg['api_key'];
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('forum_api_key', ?)
             ON DUPLICATE KEY UPDATE setting_val = ?"
        )->execute([$new_key, $new_key]);
        $forum_api_key = $new_key;
        $settings['forum_api_key'] = $new_key;
    } else {
        $reg_error = $reg['message'] ?? $reg['_message'] ?? 'Registration failed. Check that the forum server is reachable.';
    }
}

// ── POST Action Handlers ──────────────────────────────────────────────────────
// All mutations are processed before HTML output so we can redirect cleanly.
$action = $_POST['action'] ?? '';
$msg    = '';

if ($action === 'post-thread' && $forum_enabled && $forum_api_key) {
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $title  = trim($_POST['title']        ?? '');
    $body   = trim($_POST['body']         ?? '');

    if ($cat_id && $title !== '' && $body !== '') {
        $res = forum_api('POST', 'threads', [
            'category_id' => $cat_id,
            'title'       => $title,
            'body'        => $body,
        ], $forum_api_key);
        if (!$res['_error']) {
            header('Location: smack-forum.php?view=thread&id=' . (int)$res['thread_id'] . '&posted=1');
            exit;
        }
        $msg = 'Error: ' . ($res['message'] ?? 'Could not post thread.');
    } else {
        $msg = 'Board, title, and body are all required.';
    }
}

if ($action === 'post-reply' && $forum_enabled && $forum_api_key) {
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $body      = trim($_POST['body']       ?? '');

    if ($thread_id && $body !== '') {
        $res = forum_api('POST', "threads/{$thread_id}/replies", ['body' => $body], $forum_api_key);
        if (!$res['_error']) {
            header("Location: smack-forum.php?view=thread&id={$thread_id}&replied=1");
            exit;
        }
        $msg = 'Error: ' . ($res['message'] ?? 'Could not post reply.');
    } else {
        $msg = 'Reply body is required.';
    }
}

if ($action === 'pin-thread' && $forum_enabled && $forum_api_key) {
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $pin_val   = (int)($_POST['pin_value'] ?? 0);
    if ($thread_id) {
        forum_api('PATCH', "threads/{$thread_id}", ['is_pinned' => (bool)$pin_val], $forum_api_key);
    }
    header("Location: smack-forum.php?view=thread&id={$thread_id}");
    exit;
}

if ($action === 'lock-thread' && $forum_enabled && $forum_api_key) {
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $lock_val  = (int)($_POST['lock_value'] ?? 0);
    if ($thread_id) {
        forum_api('PATCH', "threads/{$thread_id}", ['is_locked' => (bool)$lock_val], $forum_api_key);
    }
    header("Location: smack-forum.php?view=thread&id={$thread_id}");
    exit;
}

if ($action === 'delete-thread' && $forum_enabled && $forum_api_key) {
    $thread_id  = (int)($_POST['thread_id']  ?? 0);
    $return_cat = (int)($_POST['return_cat'] ?? 0);
    if ($thread_id) {
        forum_api('DELETE', "threads/{$thread_id}", [], $forum_api_key);
    }
    $dest = $return_cat ? "smack-forum.php?view=threads&cat={$return_cat}" : 'smack-forum.php';
    header("Location: {$dest}");
    exit;
}

if ($action === 'delete-reply' && $forum_enabled && $forum_api_key) {
    $reply_id  = (int)($_POST['reply_id']  ?? 0);
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    if ($reply_id) {
        forum_api('DELETE', "replies/{$reply_id}", [], $forum_api_key);
    }
    header("Location: smack-forum.php?view=thread&id={$thread_id}");
    exit;
}

// ── View Routing ──────────────────────────────────────────────────────────────
$view           = $_GET['view']           ?? 'categories';
$cat_id         = (int)($_GET['cat']      ?? 0);
$thread_id      = (int)($_GET['id']       ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$new_thread_cat = (int)($_GET['new_thread_cat'] ?? $cat_id);

$api_data   = [];
$api_error  = '';
$is_mod     = false;

if ($forum_enabled && $forum_api_key) {
    if ($view === 'categories') {
        $api_data  = forum_api('GET', 'categories', [], $forum_api_key);
        if ($api_data['_error']) $api_error = $api_data['message'] ?? $api_data['_message'] ?? 'Could not load categories.';
    } elseif ($view === 'threads') {
        $api_data  = forum_api('GET', "threads?cat={$cat_id}&page={$page}", [], $forum_api_key);
        if ($api_data['_error']) $api_error = $api_data['message'] ?? $api_data['_message'] ?? 'Could not load threads.';
        $is_mod = !empty($api_data['caller_is_mod']);
    } elseif ($view === 'thread') {
        $api_data  = forum_api('GET', "threads/{$thread_id}", [], $forum_api_key);
        if ($api_data['_error']) $api_error = $api_data['message'] ?? $api_data['_message'] ?? 'Thread not found.';
        $is_mod = !empty($api_data['caller_is_mod']);
    }
    // new-thread fetches categories inline below
}

// Category accent colours — rotates through a palette for visual variety.
$cat_accents = ['#e45735','#e2b714','#39FF14','#00bcd4','#ab47bc','#ff7043','#26a69a','#5c6bc0'];

$current_page = 'smack-forum.php';
require 'core/admin-header.php';
require 'core/sidebar.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════════
   FORUM — Discourse-inspired dark theme
   ══════════════════════════════════════════════════════════════════════════════ */

/* ── Breadcrumbs ─────────────────────────────────────────────────────────── */
.forum-crumbs {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #222;
}
.forum-crumbs a {
    color: #777;
    text-decoration: none;
    transition: color 0.15s;
}
.forum-crumbs a:hover { color: #ccc; }
.forum-crumbs .sep { color: #333; }
.forum-crumbs .current { color: #ccc; font-weight: 600; }

/* ── Category grid — Discourse-style rows with coloured accent ───────────── */
.forum-cat-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.forum-cat-row {
    display: grid;
    grid-template-columns: 4px 1fr 100px 100px;
    align-items: center;
    background: #181818;
    border-radius: 4px;
    text-decoration: none;
    transition: background 0.15s;
    overflow: hidden;
}
.forum-cat-row:hover { background: #1e1e1e; }
.forum-cat-row__accent {
    width: 4px;
    align-self: stretch;
    border-radius: 4px 0 0 4px;
}
.forum-cat-row__info {
    padding: 18px 20px;
}
.forum-cat-row__name {
    font-weight: 700;
    font-size: 0.9rem;
    color: #ddd;
    margin-bottom: 4px;
}
.forum-cat-row__desc {
    font-size: 0.78rem;
    color: #666;
    line-height: 1.4;
}
.forum-cat-row__stat {
    text-align: center;
    padding: 18px 12px;
}
.forum-cat-row__stat-num {
    display: block;
    font-size: 1.1rem;
    font-weight: 700;
    color: #aaa;
}
.forum-cat-row__stat-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #555;
    margin-top: 2px;
}

/* ── Thread list — Discourse-style rows ──────────────────────────────────── */
.forum-thread-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.forum-thread-row {
    display: grid;
    grid-template-columns: 44px 1fr 64px 100px;
    align-items: center;
    gap: 0;
    background: #181818;
    border-radius: 4px;
    padding: 14px 16px;
    transition: background 0.15s;
}
.forum-thread-row:hover { background: #1e1e1e; }
.forum-thread-row--pinned { border-left: 3px solid #e2b714; }

.forum-avatar {
    width: 36px;
    height: 36px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    font-weight: 700;
    color: #000;
    flex-shrink: 0;
    letter-spacing: 0.5px;
}
.forum-avatar--sm {
    width: 32px;
    height: 32px;
    font-size: 0.6rem;
}
.forum-avatar--lg {
    width: 44px;
    height: 44px;
    font-size: 0.75rem;
}
/* When favicon loads, hide the text fallback behind it */
.forum-avatar { position: relative; overflow: hidden; }
.forum-avatar img { position: relative; z-index: 2; }
.forum-avatar__fallback {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
}

.forum-thread-row__info {
    min-width: 0;
    padding: 0 12px;
}
.forum-thread-row__title {
    font-weight: 600;
    font-size: 0.88rem;
    color: #ddd;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.forum-thread-row__title a {
    color: inherit;
    text-decoration: none;
}
.forum-thread-row__title a:hover { color: #fff; }
.forum-thread-row__byline {
    font-size: 0.72rem;
    color: #555;
}
.forum-thread-row__replies {
    text-align: center;
    font-size: 0.88rem;
    font-weight: 600;
    color: #888;
}
.forum-thread-row__replies small {
    display: block;
    font-size: 0.6rem;
    font-weight: 400;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #444;
    margin-top: 1px;
}
.forum-thread-row__activity {
    text-align: right;
    font-size: 0.75rem;
    color: #555;
}
.forum-badges {
    display: inline-flex;
    gap: 4px;
    margin-right: 6px;
    vertical-align: middle;
}
.forum-badge {
    display: inline-block;
    font-size: 9px;
    padding: 1px 5px;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    vertical-align: middle;
}
.forum-badge--pinned { background: #e2b714; color: #000; }
.forum-badge--locked { background: #333; color: #888; }

/* ── Thread detail — Discourse-style post stream ─────────────────────────── */
.forum-stream {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.forum-post {
    display: grid;
    grid-template-columns: 64px 1fr;
    gap: 0;
    border-bottom: 1px solid #1a1a1a;
    padding: 24px 0;
}
.forum-post:first-child { padding-top: 0; }
.forum-post:last-child { border-bottom: none; }
.forum-post__gutter {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-top: 2px;
}
.forum-post__content {
    min-width: 0;
}
.forum-post__header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 0.78rem;
}
.forum-post__author {
    font-weight: 700;
    color: #ccc;
}
.forum-post__time {
    color: #555;
}
.forum-post__actions {
    margin-left: auto;
}
.forum-post__body {
    font-size: 0.88rem;
    line-height: 1.7;
    color: #bbb;
    white-space: pre-wrap;
    word-break: break-word;
}
.forum-post--op .forum-post__body {
    font-size: 0.92rem;
}

/* ── Reply composer ──────────────────────────────────────────────────────── */
.forum-composer {
    background: #181818;
    border-radius: 6px;
    padding: 20px 24px;
    margin-top: 24px;
}
.forum-composer__label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #555;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.forum-composer textarea {
    width: 100%;
    resize: vertical;
    min-height: 120px;
    background: #111;
    border: 1px solid #2a2a2a;
    border-radius: 4px;
    color: #ccc;
    font-family: inherit;
    font-size: 0.88rem;
    line-height: 1.6;
    padding: 14px 16px;
    transition: border-color 0.15s;
}
.forum-composer textarea:focus {
    border-color: #39FF14;
    outline: none;
    box-shadow: 0 0 8px rgba(57, 255, 20, 0.15);
}
.forum-composer__footer {
    display: flex;
    justify-content: flex-end;
    margin-top: 12px;
}

/* ── Thread title bar ────────────────────────────────────────────────────── */
.forum-thread-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #eee;
    margin-bottom: 6px;
    line-height: 1.3;
}
.forum-thread-meta {
    font-size: 0.75rem;
    color: #555;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid #222;
}

/* ── Pagination ──────────────────────────────────────────────────────────── */
.forum-pagination {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #1a1a1a;
}

/* ── Header bar (title + new thread button) ──────────────────────────────── */
.forum-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.forum-header__title {
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #888;
}
.forum-new-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 16px;
    background: #39FF14;
    color: #000;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: none;
    border-radius: 4px;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.15s, box-shadow 0.15s;
}
.forum-new-btn:hover {
    background: #2ce00f;
    box-shadow: 0 0 12px rgba(57, 255, 20, 0.3);
}

/* ── Empty state ─────────────────────────────────────────────────────────── */
.forum-empty {
    text-align: center;
    padding: 48px 24px;
    color: #555;
    font-size: 0.85rem;
}
.forum-empty strong {
    display: block;
    font-size: 0.95rem;
    color: #777;
    margin-bottom: 8px;
}

/* ── Emoji picker ────────────────────────────────────────────────────────── */
.forum-emoji-bar { display: flex; gap: 2px; margin-bottom: 8px; flex-wrap: wrap; }
.forum-emoji-btn {
    background: none; border: 1px solid transparent; border-radius: 4px;
    font-size: 1.2rem; padding: 4px 6px; cursor: pointer; line-height: 1;
    transition: all 0.12s;
}
.forum-emoji-btn:hover { background: #222; border-color: #333; transform: scale(1.2); }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 680px) {
    .forum-cat-row {
        grid-template-columns: 4px 1fr;
    }
    .forum-cat-row__stat { display: none; }
    .forum-thread-row {
        grid-template-columns: 40px 1fr;
        padding: 12px;
    }
    .forum-thread-row__replies,
    .forum-thread-row__activity { display: none; }
    .forum-post {
        grid-template-columns: 1fr;
    }
    .forum-post__gutter { display: none; }
}
</style>

<div class="main">
  <div class="dash-grid">

    <?php if (!$forum_enabled): ?>
    <!-- ── DISABLED ──────────────────────────────────────────────────── -->
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header"><span class="box-title">COMMUNITY FORUM</span></div>
      <div class="box-body forum-empty">
        <strong>Forum Disabled</strong>
        <p>Enable it under <a href="smack-config.php">Configuration &rarr; Architecture &amp; Interaction</a>.</p>
      </div>
    </div>

    <?php elseif ($reg_error): ?>
    <!-- ── REGISTRATION FAILED ───────────────────────────────────────── -->
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header"><span class="box-title">COMMUNITY FORUM</span></div>
      <div class="box-body">
        <div class="alert"><?php echo htmlspecialchars($reg_error); ?></div>
        <p class="dim">Make sure your Site URL is set correctly in <a href="smack-config.php">Configuration</a>, then reload this page to retry.</p>
      </div>
    </div>

    <?php elseif ($api_error): ?>
    <!-- ── API ERROR ─────────────────────────────────────────────────── -->
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header"><span class="box-title">COMMUNITY FORUM</span></div>
      <div class="box-body">
        <div class="alert"><?php echo htmlspecialchars($api_error); ?></div>
      </div>
    </div>

    <?php elseif ($view === 'categories'): ?>
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!-- CATEGORIES                                                        -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header">
        <span class="box-title">COMMUNITY FORUM</span>
        <span class="dim" style="font-size: 11px; margin-left: auto;"><?php echo count($cats); ?> BOARDS</span>
      </div>
      <div class="box-body">

        <?php if (isset($_GET['posted'])): ?>
          <div class="alert alert-success" style="margin-bottom: 20px;">Thread posted successfully.</div>
        <?php endif; ?>

        <?php $cats = $api_data['categories'] ?? []; ?>
        <?php if (empty($cats)): ?>
          <div class="forum-empty">
            <strong>No boards found</strong>
            <p>Check that the forum schema has been imported on snapsmack.ca.</p>
          </div>
        <?php else: ?>

        <!-- Column labels -->
        <div style="display:grid; grid-template-columns:4px 1fr 100px 100px; font-size:0.65rem; text-transform:uppercase; letter-spacing:1px; color:#444; padding:0 0 8px 0;">
          <span></span>
          <span style="padding-left:20px;">Board</span>
          <span style="text-align:center;">Threads</span>
          <span style="text-align:center;">Replies</span>
        </div>

        <div class="forum-cat-list">
          <?php foreach ($cats as $ci => $cat):
              $accent = $cat_accents[$ci % count($cat_accents)];
          ?>
          <a href="smack-forum.php?view=threads&cat=<?php echo (int)$cat['id']; ?>" class="forum-cat-row">
            <div class="forum-cat-row__accent" style="background:<?php echo $accent; ?>;"></div>
            <div class="forum-cat-row__info">
              <div class="forum-cat-row__name"><?php echo htmlspecialchars($cat['name']); ?></div>
              <?php if (!empty($cat['description'])): ?>
              <div class="forum-cat-row__desc"><?php echo htmlspecialchars($cat['description']); ?></div>
              <?php endif; ?>
            </div>
            <div class="forum-cat-row__stat">
              <span class="forum-cat-row__stat-num"><?php echo (int)$cat['thread_count']; ?></span>
              <span class="forum-cat-row__stat-label">Threads</span>
            </div>
            <div class="forum-cat-row__stat">
              <span class="forum-cat-row__stat-num"><?php echo (int)$cat['reply_count']; ?></span>
              <span class="forum-cat-row__stat-label">Replies</span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <?php elseif ($view === 'threads'): ?>
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!-- THREADS                                                           -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <?php
    $threads  = $api_data['threads']     ?? [];
    $has_more = $api_data['has_more']    ?? false;
    $cat_name = !empty($threads) ? ($threads[0]['category_name'] ?? 'Board') : 'Board';
    ?>
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header">
        <span class="box-title">
          <a href="smack-forum.php" style="opacity:.45; text-decoration:none; font-weight:400;">FORUM</a>
          &nbsp;/&nbsp;<?php echo htmlspecialchars(strtoupper($cat_name)); ?>
        </span>
        <a href="smack-forum.php?view=new-thread&new_thread_cat=<?php echo $cat_id; ?>" class="forum-new-btn" style="margin-left:auto;">+ New Thread</a>
      </div>
      <div class="box-body">

        <?php if (isset($_GET['replied'])): ?>
          <div class="alert alert-success" style="margin-bottom:20px;">Reply posted successfully.</div>
        <?php endif; ?>

        <?php if (empty($threads)): ?>
          <div class="forum-empty">
            <strong>No threads yet</strong>
            <p>Be the first to start a conversation.</p>
          </div>
        <?php else: ?>

        <!-- Column labels -->
        <div style="display:grid; grid-template-columns:44px 1fr 64px 100px; font-size:0.65rem; text-transform:uppercase; letter-spacing:1px; color:#444; padding:0 16px 10px;">
          <span></span>
          <span style="padding-left:12px;">Topic</span>
          <span style="text-align:center;">Replies</span>
          <span style="text-align:right;">Activity</span>
        </div>

        <div class="forum-thread-list">
          <?php foreach ($threads as $t):
              $pinned   = !empty($t['is_pinned']);
              $locked   = !empty($t['is_locked']);
              $last_act = $t['last_reply_at'] ?? $t['created_at'];
          ?>
          <div class="forum-thread-row<?php echo $pinned ? ' forum-thread-row--pinned' : ''; ?>">
            <?php echo forum_avatar($t['display_name'], $t['author_domain'] ?? ''); ?>
            <div class="forum-thread-row__info">
              <div class="forum-thread-row__title">
                <?php if ($pinned || $locked): ?>
                <span class="forum-badges">
                  <?php if ($pinned): ?><span class="forum-badge forum-badge--pinned">Pinned</span><?php endif; ?>
                  <?php if ($locked): ?><span class="forum-badge forum-badge--locked">Locked</span><?php endif; ?>
                </span>
                <?php endif; ?>
                <a href="smack-forum.php?view=thread&id=<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['title']); ?></a>
              </div>
              <div class="forum-thread-row__byline">
                <?php echo htmlspecialchars($t['display_name']); ?>
                &middot;
                <?php echo date('M j, Y', strtotime($t['created_at'])); ?>
              </div>
            </div>
            <div class="forum-thread-row__replies">
              <?php echo (int)$t['reply_count']; ?>
              <small>replies</small>
            </div>
            <div class="forum-thread-row__activity"><?php echo forum_ago($last_act); ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($page > 1 || $has_more): ?>
        <div class="forum-pagination">
          <?php if ($page > 1): ?>
            <a href="smack-forum.php?view=threads&cat=<?php echo $cat_id; ?>&page=<?php echo $page - 1; ?>" class="action-edit">&larr; Prev</a>
          <?php endif; ?>
          <span class="dim" style="font-size:0.75rem; line-height:32px;">Page <?php echo $page; ?></span>
          <?php if ($has_more): ?>
            <a href="smack-forum.php?view=threads&cat=<?php echo $cat_id; ?>&page=<?php echo $page + 1; ?>" class="action-edit">Next &rarr;</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

      </div>
    </div>

    <?php elseif ($view === 'thread'): ?>
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!-- THREAD DETAIL                                                     -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <?php
    $thread      = $api_data['thread']  ?? [];
    $replies     = $api_data['replies'] ?? [];
    $my_display  = $settings['site_name'] ?? '';
    ?>
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header">
        <span class="box-title" style="font-size:0.72rem; font-weight:400;">
          <a href="smack-forum.php" style="opacity:.45; text-decoration:none;">FORUM</a>
          &nbsp;/&nbsp;
          <a href="smack-forum.php?view=threads&cat=<?php echo (int)($thread['category_id'] ?? 0); ?>" style="opacity:.45; text-decoration:none;">
            <?php echo htmlspecialchars(strtoupper($thread['category_name'] ?? 'BOARD')); ?>
          </a>
        </span>
        <a href="smack-forum.php?view=new-thread&new_thread_cat=<?php echo (int)($thread['category_id'] ?? 0); ?>" class="forum-new-btn" style="margin-left:auto;">+ New Thread</a>
      </div>
      <div class="box-body">

        <?php if (isset($_GET['replied'])): ?>
          <div class="alert alert-success" style="margin-bottom:20px;">Reply posted successfully.</div>
        <?php endif; ?>
        <?php if ($msg): ?>
          <div class="alert" style="margin-bottom:20px;"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if (empty($thread)): ?>
          <div class="forum-empty">
            <strong>Thread not found</strong>
            <p>It may have been removed.</p>
          </div>
        <?php else: ?>

        <!-- Thread title -->
        <div class="forum-thread-title">
          <?php if (!empty($thread['is_pinned'])): ?><span class="forum-badge forum-badge--pinned" style="font-size:10px; vertical-align:middle; margin-right:8px;">Pinned</span><?php endif; ?>
          <?php if (!empty($thread['is_locked'])): ?><span class="forum-badge forum-badge--locked" style="font-size:10px; vertical-align:middle; margin-right:8px;">Locked</span><?php endif; ?>
          <?php echo htmlspecialchars($thread['title']); ?>
        </div>
        <div class="forum-thread-meta">
          <?php echo htmlspecialchars($thread['category_name'] ?? ''); ?>
          &middot;
          <?php echo count($replies); ?> <?php echo count($replies) === 1 ? 'reply' : 'replies'; ?>
          &middot;
          started <?php echo date('M j, Y', strtotime($thread['created_at'])); ?>
        </div>

        <!-- Post stream -->
        <div class="forum-stream">

          <!-- Opening Post -->
          <div class="forum-post forum-post--op">
            <div class="forum-post__gutter">
              <?php echo forum_avatar($thread['display_name'], $thread['author_domain'] ?? '', 'lg'); ?>
            </div>
            <div class="forum-post__content">
              <div class="forum-post__header">
                <span class="forum-post__author"><?php echo htmlspecialchars($thread['display_name']); ?></span>
                <span class="forum-post__time"><?php echo date('M j, Y \a\t g:ia', strtotime($thread['created_at'])); ?></span>
                <?php if ($thread['display_name'] === $my_display || $is_mod): ?>
                <div class="forum-post__actions">
                  <?php if ($is_mod): ?>
                  <form method="post" action="smack-forum.php" style="display:inline;">
                    <input type="hidden" name="action"     value="pin-thread">
                    <input type="hidden" name="thread_id"  value="<?php echo (int)$thread['id']; ?>">
                    <input type="hidden" name="pin_value"  value="<?php echo $thread['is_pinned'] ? 0 : 1; ?>">
                    <button type="submit" class="action-edit"><?php echo $thread['is_pinned'] ? 'Unpin' : 'Pin'; ?></button>
                  </form>
                  <form method="post" action="smack-forum.php" style="display:inline;">
                    <input type="hidden" name="action"     value="lock-thread">
                    <input type="hidden" name="thread_id"  value="<?php echo (int)$thread['id']; ?>">
                    <input type="hidden" name="lock_value" value="<?php echo $thread['is_locked'] ? 0 : 1; ?>">
                    <button type="submit" class="action-edit"><?php echo $thread['is_locked'] ? 'Unlock' : 'Lock'; ?></button>
                  </form>
                  <?php endif; ?>
                  <form method="post" action="smack-forum.php" style="display:inline;"
                        onsubmit="return confirm('Delete this thread and all its replies?');">
                    <input type="hidden" name="action"     value="delete-thread">
                    <input type="hidden" name="thread_id"  value="<?php echo (int)$thread['id']; ?>">
                    <input type="hidden" name="return_cat" value="<?php echo (int)$thread['category_id']; ?>">
                    <button type="submit" class="action-delete">Delete</button>
                  </form>
                </div>
                <?php endif; ?>
              </div>
              <div class="forum-post__body"><?php echo nl2br(htmlspecialchars($thread['body'])); ?></div>
            </div>
          </div>

          <!-- Replies -->
          <?php foreach ($replies as $r): ?>
          <div class="forum-post">
            <div class="forum-post__gutter">
              <?php echo forum_avatar($r['display_name'], $r['author_domain'] ?? '', 'lg'); ?>
            </div>
            <div class="forum-post__content">
              <div class="forum-post__header">
                <span class="forum-post__author"><?php echo htmlspecialchars($r['display_name']); ?></span>
                <span class="forum-post__time"><?php echo date('M j, Y \a\t g:ia', strtotime($r['created_at'])); ?></span>
                <?php if ($r['display_name'] === $my_display || $is_mod): ?>
                <div class="forum-post__actions">
                  <form method="post" action="smack-forum.php" style="display:inline;"
                        onsubmit="return confirm('Delete this reply?');">
                    <input type="hidden" name="action"    value="delete-reply">
                    <input type="hidden" name="reply_id"  value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
                    <button type="submit" class="action-delete">Delete</button>
                  </form>
                </div>
                <?php endif; ?>
              </div>
              <div class="forum-post__body"><?php echo nl2br(htmlspecialchars($r['body'])); ?></div>
            </div>
          </div>
          <?php endforeach; ?>

        </div><!-- /.forum-stream -->

        <!-- Reply composer -->
        <?php if (!$thread['is_locked']): ?>
        <div class="forum-composer">
          <div class="forum-composer__label">
            <?php
            // The composer avatar uses the current install's own domain
            $my_domain = preg_replace('/^https?:\/\//i', '', rtrim($settings['site_url'] ?? '', '/'));
            $my_domain = rtrim($my_domain, '/');
            echo forum_avatar($my_display, $my_domain, 'sm');
            ?>
            Reply as <?php echo htmlspecialchars($my_display); ?>
          </div>
          <?php if ($msg && $action === 'post-reply'): ?>
            <div class="alert" style="margin-bottom:12px;"><?php echo htmlspecialchars($msg); ?></div>
          <?php endif; ?>
          <form method="post" action="smack-forum.php">
            <input type="hidden" name="action"    value="post-reply">
            <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
            <div class="forum-emoji-bar">
              <?php foreach (['😊','😂','🤔','👍','👎','🔥','❤️','🎉','💡','⚠️','🐛','✅','❌','🚀','💎','📌','🔒','👀','🙏','💬'] as $em): ?>
              <button type="button" class="forum-emoji-btn" onclick="forumInsertEmoji(this,'<?php echo $em; ?>')"><?php echo $em; ?></button>
              <?php endforeach; ?>
            </div>
            <textarea name="body" placeholder="Write your reply&#8230;" required><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
            <div class="forum-composer__footer">
              <button type="submit" class="forum-new-btn">Post Reply</button>
            </div>
          </form>
        </div>
        <?php else: ?>
          <div class="forum-empty" style="padding:24px;">
            This thread is locked.
          </div>
        <?php endif; ?>

        <?php endif; // thread exists ?>
      </div>
    </div>

    <?php elseif ($view === 'new-thread'): ?>
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!-- NEW THREAD FORM                                                   -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <?php
    $cats_res      = forum_api('GET', 'categories', [], $forum_api_key);
    $cats_list     = $cats_res['categories'] ?? [];
    $form_cat_name = '';
    foreach ($cats_list as $fc) {
        if ((int)$fc['id'] === $new_thread_cat) { $form_cat_name = $fc['name']; break; }
    }
    ?>
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header">
        <span class="box-title">
          <a href="smack-forum.php" style="opacity:.45; text-decoration:none; font-weight:400;">FORUM</a>
          <?php if ($new_thread_cat): ?>
          &nbsp;/&nbsp;
          <a href="smack-forum.php?view=threads&cat=<?php echo $new_thread_cat; ?>" style="opacity:.45; text-decoration:none; font-weight:400;">
            <?php echo htmlspecialchars(strtoupper($form_cat_name ?: 'BOARD')); ?>
          </a>
          <?php endif; ?>
          &nbsp;/&nbsp;NEW THREAD
        </span>
      </div>
      <div class="box-body">

        <?php if ($msg && $action === 'post-thread'): ?>
          <div class="alert" style="margin-bottom:16px;"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <form method="post" action="smack-forum.php">
          <input type="hidden" name="action" value="post-thread">

          <div class="lens-input-wrapper">
            <label>BOARD</label>
            <select name="category_id" required>
              <option value="">— Select a board —</option>
              <?php foreach ($cats_list as $fc): ?>
              <option value="<?php echo (int)$fc['id']; ?>"
                <?php echo ($new_thread_cat === (int)$fc['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($fc['name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="lens-input-wrapper">
            <label>TITLE</label>
            <input type="text" name="title" maxlength="200" placeholder="Thread title" required
                   value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
          </div>

          <div class="lens-input-wrapper">
            <label>BODY</label>
            <div class="forum-emoji-bar">
              <?php foreach (['😊','😂','🤔','👍','👎','🔥','❤️','🎉','💡','⚠️','🐛','✅','❌','🚀','💎','📌','🔒','👀','🙏','💬'] as $em): ?>
              <button type="button" class="forum-emoji-btn" onclick="forumInsertEmoji(this,'<?php echo $em; ?>')"><?php echo $em; ?></button>
              <?php endforeach; ?>
            </div>
            <textarea name="body" rows="10" placeholder="Describe your issue, question, or topic&#8230;"
                      style="width:100%; resize:vertical;" required><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
            <span class="dim" style="font-size:0.72rem; margin-top:6px;">20,000 character limit.</span>
          </div>

          <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;">
            <?php $back_url = $new_thread_cat ? "smack-forum.php?view=threads&cat={$new_thread_cat}" : 'smack-forum.php'; ?>
            <a href="<?php echo $back_url; ?>" class="action-edit" style="line-height:36px;">CANCEL</a>
            <button type="submit" class="forum-new-btn">Post Thread</button>
          </div>
        </form>

      </div>
    </div>

    <?php endif; // end view routing ?>

  </div><!-- /.dash-grid -->
</div><!-- /.main -->

<script>
function forumInsertEmoji(btn, emoji) {
    var form = btn.closest('form') || btn.closest('.lens-input-wrapper')?.closest('form');
    var ta = form ? form.querySelector('textarea') : btn.closest('.lens-input-wrapper')?.querySelector('textarea');
    if (!ta) return;
    var start = ta.selectionStart;
    var end   = ta.selectionEnd;
    var val   = ta.value;
    ta.value  = val.substring(0, start) + emoji + val.substring(end);
    ta.selectionStart = ta.selectionEnd = start + emoji.length;
    ta.focus();
}
</script>
<?php require 'core/admin-footer.php'; ?>
