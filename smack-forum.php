<?php
/**
 * SNAPSMACK - Community Forum
 * Alpha v0.7.2
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

$api_data  = [];
$api_error = '';

if ($forum_enabled && $forum_api_key) {
    if ($view === 'categories') {
        $api_data  = forum_api('GET', 'categories', [], $forum_api_key);
        if ($api_data['_error']) $api_error = $api_data['message'] ?? $api_data['_message'] ?? 'Could not load categories.';
    } elseif ($view === 'threads') {
        $api_data  = forum_api('GET', "threads?cat={$cat_id}&page={$page}", [], $forum_api_key);
        if ($api_data['_error']) $api_error = $api_data['message'] ?? $api_data['_message'] ?? 'Could not load threads.';
    } elseif ($view === 'thread') {
        $api_data  = forum_api('GET', "threads/{$thread_id}", [], $forum_api_key);
        if ($api_data['_error']) $api_error = $api_data['message'] ?? $api_data['_message'] ?? 'Thread not found.';
    }
    // new-thread fetches categories inline below
}

$current_page = 'smack-forum.php';
require 'core/admin-header.php';
require 'core/sidebar.php';
?>

<style>
/* ── Forum post cards ────────────────────────────────────────────────────── */
.forum-post {
    border-width: 1px;
    border-style: solid;
    border-radius: 4px;
    margin-bottom: 12px;
    overflow: hidden;
}
.forum-post--op {
    margin-bottom: 20px;
}
.forum-post--compose {
    margin-top: 24px;
}
.forum-post__meta {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom-width: 1px;
    border-bottom-style: solid;
}
.forum-post__meta strong {
    font-weight: 700;
}
.forum-post__meta .form-inline {
    margin-left: auto;
}
.forum-post__body {
    padding: 16px;
    font-size: 0.88rem;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
}
.forum-breadcrumb {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 16px;
}
.forum-breadcrumb a {
    text-decoration: none;
    opacity: 0.5;
}
.forum-breadcrumb a:hover {
    opacity: 1;
}
.forum-breadcrumb span {
    opacity: 0.4;
    margin: 0 6px;
}
.forum-cat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
    margin-top: 8px;
}
.forum-cat-card {
    border-width: 1px;
    border-style: solid;
    border-radius: 4px;
    padding: 16px;
    text-decoration: none;
    display: block;
    transition: border-color 0.2s, background 0.2s;
}
.forum-cat-card__name {
    font-weight: 700;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.forum-cat-card__desc {
    font-size: 0.78rem;
    opacity: 0.6;
    margin-bottom: 10px;
    line-height: 1.4;
}
.forum-cat-card__counts {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.5;
}
.forum-thread-table {
    width: 100%;
    border-collapse: collapse;
}
.forum-thread-table th {
    text-align: left;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 1px;
    padding-bottom: 12px;
    border-bottom-width: 1px;
    border-bottom-style: solid;
    opacity: 0.6;
}
.forum-thread-table td {
    padding: 14px 0;
    vertical-align: middle;
    border-bottom-width: 1px;
    border-bottom-style: solid;
}
.forum-thread-table tr:last-child td {
    border-bottom: none;
}
.forum-thread-table td.td-right {
    text-align: right;
    font-size: 0.78rem;
    white-space: nowrap;
}
.forum-pin-badge {
    font-size: 10px;
    margin-right: 4px;
}
.forum-lock-badge {
    font-size: 10px;
    margin-right: 4px;
    opacity: 0.45;
}
.forum-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.forum-header-row .box-title {
    margin: 0;
}
</style>

<div class="main">
  <div class="dash-grid">

    <?php if (!$forum_enabled): ?>
    <!-- ── DISABLED ──────────────────────────────────────────────────── -->
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header"><span class="box-title">COMMUNITY FORUM</span></div>
      <div class="box-body" style="text-align: center; padding: 40px 24px;">
        <p class="dim" style="margin-bottom: 12px;">The community forum is currently disabled.</p>
        <p class="dim">Enable it under <a href="smack-config.php">Configuration → Architecture &amp; Interaction</a>.</p>
      </div>
    </div>

    <?php elseif ($reg_error): ?>
    <!-- ── REGISTRATION FAILED ───────────────────────────────────────── -->
    <div class="box" style="grid-column: 1 / -1;">
      <div class="box-header"><span class="box-title">COMMUNITY FORUM — REGISTRATION FAILED</span></div>
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
        <span class="dim" style="font-size: 11px; margin-left: auto;">SNAPSMACK ADMINS ONLY</span>
      </div>
      <div class="box-body">

        <?php if (isset($_GET['posted'])): ?>
          <div class="alert" style="margin-bottom: 16px;">Thread posted.</div>
        <?php endif; ?>

        <?php $cats = $api_data['categories'] ?? []; ?>
        <?php if (empty($cats)): ?>
          <p class="dim">No boards found. Check that the forum schema has been imported on snapsmack.ca.</p>
        <?php else: ?>
        <div class="forum-cat-grid">
          <?php foreach ($cats as $cat): ?>
          <a href="smack-forum.php?view=threads&cat=<?php echo (int)$cat['id']; ?>" class="forum-cat-card">
            <div class="forum-cat-card__name"><?php echo htmlspecialchars($cat['name']); ?></div>
            <?php if (!empty($cat['description'])): ?>
            <div class="forum-cat-card__desc"><?php echo htmlspecialchars($cat['description']); ?></div>
            <?php endif; ?>
            <div class="forum-cat-card__counts">
              <?php echo (int)$cat['thread_count']; ?> threads
              &nbsp;&bull;&nbsp;
              <?php echo (int)$cat['reply_count']; ?> replies
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
        <a href="smack-forum.php?view=new-thread&new_thread_cat=<?php echo $cat_id; ?>" class="action-edit" style="margin-left:auto;">+ NEW THREAD</a>
      </div>
      <div class="box-body">

        <?php if (isset($_GET['replied'])): ?>
          <div class="alert" style="margin-bottom:16px;">Reply posted.</div>
        <?php endif; ?>

        <?php if (empty($threads)): ?>
          <p class="dim">No threads yet. Be the first to post.</p>
        <?php else: ?>
        <table class="forum-thread-table">
          <thead>
            <tr>
              <th>Thread</th>
              <th style="width:80px; text-align:right;">Replies</th>
              <th style="width:140px; text-align:right;">Last Activity</th>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($threads as $t): ?>
            <tr>
              <td>
                <?php if ($t['is_pinned']): ?><span class="forum-pin-badge" title="Pinned">📌</span><?php endif; ?>
                <?php if ($t['is_locked']): ?><span class="forum-lock-badge" title="Locked">🔒</span><?php endif; ?>
                <strong><?php echo htmlspecialchars($t['title']); ?></strong><br>
                <span class="dim" style="font-size:11px;">
                  by <?php echo htmlspecialchars($t['display_name']); ?>
                  &nbsp;&bull;&nbsp;
                  <?php echo date('M j, Y', strtotime($t['created_at'])); ?>
                </span>
              </td>
              <td class="td-right"><?php echo (int)$t['reply_count']; ?></td>
              <td class="td-right dim">
                <?php echo $t['last_reply_at'] ? date('M j, Y', strtotime($t['last_reply_at'])) : '—'; ?>
              </td>
              <td class="td-right">
                <a href="smack-forum.php?view=thread&id=<?php echo (int)$t['id']; ?>" class="action-edit">READ</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($page > 1 || $has_more): ?>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
          <?php if ($page > 1): ?>
            <a href="smack-forum.php?view=threads&cat=<?php echo $cat_id; ?>&page=<?php echo $page - 1; ?>" class="action-edit">← Prev</a>
          <?php endif; ?>
          <?php if ($has_more): ?>
            <a href="smack-forum.php?view=threads&cat=<?php echo $cat_id; ?>&page=<?php echo $page + 1; ?>" class="action-edit">Next →</a>
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
        <span class="box-title" style="font-size:0.78rem; font-weight:400;">
          <a href="smack-forum.php" style="opacity:.45; text-decoration:none;">FORUM</a>
          &nbsp;/&nbsp;
          <a href="smack-forum.php?view=threads&cat=<?php echo (int)($thread['category_id'] ?? 0); ?>" style="opacity:.45; text-decoration:none;">
            <?php echo htmlspecialchars(strtoupper($thread['category_name'] ?? 'BOARD')); ?>
          </a>
          &nbsp;/&nbsp;<strong style="font-size:0.82rem;"><?php echo htmlspecialchars($thread['title'] ?? ''); ?></strong>
        </span>
        <a href="smack-forum.php?view=new-thread&new_thread_cat=<?php echo (int)($thread['category_id'] ?? 0); ?>" class="action-edit" style="margin-left:auto;">+ NEW THREAD</a>
      </div>
      <div class="box-body">

        <?php if (isset($_GET['replied'])): ?>
          <div class="alert" style="margin-bottom:16px;">Reply posted.</div>
        <?php endif; ?>
        <?php if ($msg): ?>
          <div class="alert" style="margin-bottom:16px;"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if (empty($thread)): ?>
          <p class="dim">Thread not found or has been removed.</p>
        <?php else: ?>

        <!-- Opening Post -->
        <div class="forum-post forum-post--op">
          <div class="forum-post__meta">
            <strong><?php echo htmlspecialchars($thread['display_name']); ?></strong>
            <span class="dim"><?php echo date('M j, Y \a\t g:ia', strtotime($thread['created_at'])); ?></span>
            <?php if ($thread['is_pinned']): ?><span style="font-size:10px; opacity:.6; text-transform:uppercase; letter-spacing:.5px;">Pinned</span><?php endif; ?>
            <?php if ($thread['is_locked']): ?><span style="font-size:10px; opacity:.45; text-transform:uppercase; letter-spacing:.5px;">Locked</span><?php endif; ?>
            <?php if ($thread['display_name'] === $my_display): ?>
            <form method="post" action="smack-forum.php" class="form-inline"
                  onsubmit="return confirm('Delete this thread and all its replies?');">
              <input type="hidden" name="action"     value="delete-thread">
              <input type="hidden" name="thread_id"  value="<?php echo (int)$thread['id']; ?>">
              <input type="hidden" name="return_cat" value="<?php echo (int)$thread['category_id']; ?>">
              <button type="submit" class="action-delete">Delete</button>
            </form>
            <?php endif; ?>
          </div>
          <div class="forum-post__body"><?php echo nl2br(htmlspecialchars($thread['body'])); ?></div>
        </div>

        <!-- Replies -->
        <?php foreach ($replies as $r): ?>
        <div class="forum-post">
          <div class="forum-post__meta">
            <strong><?php echo htmlspecialchars($r['display_name']); ?></strong>
            <span class="dim"><?php echo date('M j, Y \a\t g:ia', strtotime($r['created_at'])); ?></span>
            <?php if ($r['display_name'] === $my_display): ?>
            <form method="post" action="smack-forum.php" class="form-inline"
                  onsubmit="return confirm('Delete this reply?');">
              <input type="hidden" name="action"    value="delete-reply">
              <input type="hidden" name="reply_id"  value="<?php echo (int)$r['id']; ?>">
              <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
              <button type="submit" class="action-delete">Delete</button>
            </form>
            <?php endif; ?>
          </div>
          <div class="forum-post__body"><?php echo nl2br(htmlspecialchars($r['body'])); ?></div>
        </div>
        <?php endforeach; ?>

        <!-- Reply Form -->
        <?php if (!$thread['is_locked']): ?>
        <div class="forum-post forum-post--compose">
          <div class="forum-post__meta">
            <strong>REPLY AS <?php echo htmlspecialchars(strtoupper($my_display)); ?></strong>
          </div>
          <div style="padding: 16px;">
            <?php if ($msg && $action === 'post-reply'): ?>
              <div class="alert" style="margin-bottom:12px;"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>
            <form method="post" action="smack-forum.php">
              <input type="hidden" name="action"    value="post-reply">
              <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
              <div class="lens-input-wrapper" style="margin:0 0 12px;">
                <textarea name="body" rows="5" placeholder="Write your reply…"
                          style="width:100%; resize:vertical;" required><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
              </div>
              <div style="text-align:right;">
                <button type="submit" class="btn-smack btn-mt-0" style="width:auto; height:40px; padding:0 24px; margin:0;">POST REPLY</button>
              </div>
            </form>
          </div>
        </div>
        <?php else: ?>
          <p class="dim" style="text-align:center; font-size:12px; margin-top:20px;">This thread is locked.</p>
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
            <textarea name="body" rows="10" placeholder="Describe your issue, question, or topic…"
                      style="width:100%; resize:vertical;" required><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
            <span class="dim">20,000 character limit.</span>
          </div>

          <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;">
            <?php $back_url = $new_thread_cat ? "smack-forum.php?view=threads&cat={$new_thread_cat}" : 'smack-forum.php'; ?>
            <a href="<?php echo $back_url; ?>" class="action-edit">CANCEL</a>
            <button type="submit" class="btn-smack btn-mt-0" style="width:auto; height:40px; padding:0 24px; margin:0;">POST THREAD</button>
          </div>
        </form>

      </div>
    </div>

    <?php endif; // end view routing ?>

  </div><!-- /.dash-grid -->
</div><!-- /.main -->

<?php require 'core/admin-footer.php'; ?>
