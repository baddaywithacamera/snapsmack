<?php
/**
 * SMACK CENTRAL - Community Forum
 *
 * Full Discourse-style forum interface for the hub admin. The hub owner
 * posts and moderates as the Pan Galactic Straw Boss (PGSB). Reads directly
 * from the forum database — no API round-trips. Mod controls are contextual
 * and always visible. Install and board management are accessible via the
 * PGSB admin nav rather than as peer tabs.
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-forum.php';
$sc_page_title = 'Community Forum';

$db    = sc_forum_db();
$flash = '';

// ── PGSB Identity ─────────────────────────────────────────────────────────────
// The hub install (snapsmack.ca) is the Pan Galactic Straw Boss — the admin
// identity for all hub posts. Posts from this install_id get the PGSB badge.
// Changing these constants here changes how the hub admin appears everywhere.
define('PGSB_DISPLAY_NAME', 'Pan Galactic Straw Boss');
define('PGSB_SHORT',        'PGSB');
define('PGSB_DOMAIN',       'snapsmack.ca');

// Load the hub install row once — used to detect PGSB posts and to post as PGSB.
$hub_row = $db->query(
    "SELECT id FROM ss_forum_installs WHERE domain = 'snapsmack.ca' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$hub_id = $hub_row ? (int)$hub_row['id'] : 0;

// ── Helper Functions ──────────────────────────────────────────────────────────

function sc_forum_initials(string $name): string {
    $parts = preg_split('/[\s\-_.]+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}

function sc_forum_avatar_hue(string $name): int {
    return crc32($name) % 360;
}

function sc_forum_avatar(string $name, string $domain = '', string $size = ''): string {
    $hue      = sc_forum_avatar_hue($name);
    $initials = htmlspecialchars(sc_forum_initials($name));
    $cls      = 'scf-avatar' . ($size ? " scf-avatar--{$size}" : '');
    $bg       = "background:hsl({$hue},55%,45%);";

    if ($domain !== '') {
        $fav_url = 'https://www.google.com/s2/favicons?domain=' . urlencode($domain) . '&sz=64';
        return '<div class="' . $cls . '" style="' . $bg . '">'
             . '<img src="' . htmlspecialchars($fav_url) . '" alt="" '
             . 'style="width:100%;height:100%;border-radius:4px;object-fit:cover;" '
             . 'onerror="this.remove();">'
             . '<span class="scf-avatar__fallback">' . $initials . '</span>'
             . '</div>';
    }

    return '<div class="' . $cls . '" style="' . $bg . '">' . $initials . '</div>';
}

function sc_forum_ago(string $datetime): string {
    $ts   = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'yesterday';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}

// ── View Routing ──────────────────────────────────────────────────────────────
$section   = $_GET['section'] ?? 'forum';      // forum | installs | boards
$view      = $_GET['view']    ?? 'categories'; // categories | threads | thread | new-thread
$cat_id    = (int)($_GET['cat']  ?? 0);
$thread_id = (int)($_GET['id']   ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 30;

$cat_accents = ['#e45735','#e2b714','#39FF14','#00bcd4','#ab47bc','#ff7043','#26a69a','#5c6bc0'];
$emoji_set   = ['😊','😂','🤔','👍','👎','🔥','❤️','🎉','💡','⚠️','🐛','✅','❌','🚀','💎','📌','🔒','👀','🙏','💬'];

// ── POST Actions ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Install management ───────────────────────────────────────────────────
    if ($action === 'ban_install' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_banned = 1 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash = 'Install banned.';
    } elseif ($action === 'unban_install' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_banned = 0 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash = 'Install unbanned.';
    } elseif ($action === 'promote_mod' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_moderator = 1 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash = 'Install promoted to moderator.';
    } elseif ($action === 'demote_mod' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_moderator = 0 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash = 'Moderator privileges removed.';
    } elseif ($action === 'rename_install' && !empty($_POST['id']) && isset($_POST['display_name'])) {
        $new_name = trim($_POST['display_name']);
        if ($new_name !== '' && mb_strlen($new_name) <= 100) {
            $db->prepare("UPDATE ss_forum_installs SET display_name = ? WHERE id = ?")->execute([$new_name, (int)$_POST['id']]);
            $flash = 'Display name updated.';
        } else {
            $flash = 'Display name must be 1–100 characters.';
        }

    // ── Board management ─────────────────────────────────────────────────────
    } elseif ($action === 'toggle_category' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_categories SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash = 'Board visibility toggled.';
    } elseif ($action === 'save_category_order' && !empty($_POST['order'])) {
        $ids  = array_map('intval', explode(',', $_POST['order']));
        $stmt = $db->prepare("UPDATE ss_forum_categories SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $id) $stmt->execute([$i + 1, $id]);
        $flash = 'Board order saved.';

    // ── Thread moderation ────────────────────────────────────────────────────
    } elseif ($action === 'pin_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_pinned = 1 - is_pinned WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash      = 'Thread pin toggled.';
        $thread_id  = (int)$_POST['id'];
        $view       = 'thread';
    } elseif ($action === 'lock_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_locked = 1 - is_locked WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash      = 'Thread lock toggled.';
        $thread_id  = (int)$_POST['id'];
        $view       = 'thread';
    } elseif ($action === 'delete_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_deleted = 1 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash      = 'Thread soft-deleted.';
        $thread_id  = (int)$_POST['id'];
        $view       = 'thread';
    } elseif ($action === 'restore_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_deleted = 0 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash      = 'Thread restored.';
        $thread_id  = (int)$_POST['id'];
        $view       = 'thread';

    // ── Reply moderation ─────────────────────────────────────────────────────
    } elseif ($action === 'delete_reply' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash      = 'Reply soft-deleted.';
        $thread_id  = (int)($_POST['thread_id'] ?? 0);
        $view       = 'thread';
    } elseif ($action === 'restore_reply' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_replies SET is_deleted = 0 WHERE id = ?")->execute([(int)$_POST['id']]);
        $flash      = 'Reply restored.';
        $thread_id  = (int)($_POST['thread_id'] ?? 0);
        $view       = 'thread';

    // ── Post as PGSB ─────────────────────────────────────────────────────────
    } elseif ($action === 'hub_post_thread') {
        $p_cat   = (int)($_POST['category_id'] ?? 0);
        $p_title = trim($_POST['title'] ?? '');
        $p_body  = trim($_POST['body']  ?? '');
        if ($p_cat && $p_title !== '' && $p_body !== '') {
            if ($hub_id) {
                $db->beginTransaction();
                try {
                    $db->prepare(
                        "INSERT INTO ss_forum_threads (category_id, install_id, display_name, title, body)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([$p_cat, $hub_id, PGSB_DISPLAY_NAME, $p_title, $p_body]);
                    $new_tid = $db->lastInsertId();
                    $db->prepare(
                        "UPDATE ss_forum_categories SET thread_count = thread_count + 1 WHERE id = ?"
                    )->execute([$p_cat]);
                    $db->commit();
                    $flash     = 'Thread posted.';
                    $thread_id = (int)$new_tid;
                    $view      = 'thread';
                } catch (Exception $e) {
                    $db->rollBack();
                    $flash = 'Error: ' . $e->getMessage();
                }
            } else {
                $flash = 'Hub install not found. Register snapsmack.ca as a forum install first.';
            }
        } else {
            $flash = 'Board, title, and body are all required.';
        }

    } elseif ($action === 'hub_post_reply') {
        $p_tid  = (int)($_POST['thread_id'] ?? 0);
        $p_body = trim($_POST['body'] ?? '');
        if ($p_tid && $p_body !== '') {
            if ($hub_id) {
                $db->beginTransaction();
                try {
                    $db->prepare(
                        "INSERT INTO ss_forum_replies (thread_id, install_id, display_name, body)
                         VALUES (?, ?, ?, ?)"
                    )->execute([$p_tid, $hub_id, PGSB_DISPLAY_NAME, $p_body]);
                    $t_row = $db->prepare(
                        "SELECT category_id FROM ss_forum_threads WHERE id = ? LIMIT 1"
                    );
                    $t_row->execute([$p_tid]);
                    $cat_row = $t_row->fetch();
                    $db->prepare(
                        "UPDATE ss_forum_threads SET reply_count = reply_count + 1, last_reply_at = NOW() WHERE id = ?"
                    )->execute([$p_tid]);
                    if ($cat_row) {
                        $db->prepare(
                            "UPDATE ss_forum_categories SET reply_count = reply_count + 1 WHERE id = ?"
                        )->execute([$cat_row['category_id']]);
                    }
                    $db->commit();
                    $flash     = 'Reply posted.';
                    $thread_id = $p_tid;
                    $view      = 'thread';
                } catch (Exception $e) {
                    $db->rollBack();
                    $flash = 'Error: ' . $e->getMessage();
                }
            } else {
                $flash = 'Hub install not found.';
            }
        } else {
            $flash = 'Thread and body are required.';
        }
    }

    // Redirect for simple POST actions that leave us at categories with no thread
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash && $view === 'categories' && !$thread_id) {
        header('Location: sc-forum.php?section=' . urlencode($section) . '&flash=' . urlencode($flash));
        exit;
    }
}

if (!empty($_GET['flash'])) $flash = htmlspecialchars($_GET['flash']);

// ── Data Loading ──────────────────────────────────────────────────────────────

$categories = $db->query("
    SELECT id, slug, name, description, sort_order, is_active, thread_count, reply_count
    FROM ss_forum_categories
    ORDER BY sort_order ASC
")->fetchAll(PDO::FETCH_ASSOC);

$install_count = (int)$db->query("SELECT COUNT(*) FROM ss_forum_installs WHERE is_banned = 0")->fetchColumn();
$total_threads = (int)$db->query("SELECT COUNT(*) FROM ss_forum_threads WHERE is_deleted = 0")->fetchColumn();
$total_replies = (int)$db->query("SELECT COUNT(*) FROM ss_forum_replies WHERE is_deleted = 0")->fetchColumn();

require __DIR__ . '/sc-layout-top.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════════
   COMMUNITY FORUM — Discourse-style interface with PGSB superpowers
   Uses scf- prefix (Smack Central Forum) to avoid collisions with sc- classes.
   ══════════════════════════════════════════════════════════════════════════════ */

/* ── PGSB Identity Bar ─────────────────────────────────────────────────────── */
.scf-pgsb-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--sc-bg-box);
    border: 1px solid var(--sc-border);
    border-radius: var(--sc-radius);
    padding: 10px 16px;
    margin-bottom: 20px;
    gap: 12px;
}
.scf-pgsb-identity {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.scf-pgsb-name {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--sc-text);
    letter-spacing: 0.02em;
}
.scf-pgsb-nav {
    display: flex;
    align-items: center;
    gap: 2px;
}
.scf-pgsb-link {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    padding: 5px 12px;
    border-radius: var(--sc-radius-sm);
    color: var(--sc-text-dim);
    text-decoration: none;
    transition: color 0.15s, background 0.15s;
}
.scf-pgsb-link:hover { color: var(--sc-text); background: var(--sc-bg-row-alt); }
.scf-pgsb-link.active { color: var(--sc-accent); background: var(--sc-accent-dim); }

/* ── Breadcrumbs ───────────────────────────────────────────────────────────── */
.scf-crumbs {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--sc-border);
}
.scf-crumbs a { color: var(--sc-text-dim); text-decoration: none; }
.scf-crumbs a:hover { color: var(--sc-text); }
.scf-crumbs .sep { color: #333; }
.scf-crumbs .current { color: var(--sc-text); font-weight: 600; }
.scf-crumbs-actions { margin-left: auto; display: flex; gap: 8px; }

/* ── Category grid ─────────────────────────────────────────────────────────── */
.scf-cat-list { display: flex; flex-direction: column; gap: 2px; }
.scf-cat-row {
    display: grid;
    grid-template-columns: 4px 1fr 100px 100px;
    align-items: center;
    background: var(--sc-bg-box);
    border-radius: var(--sc-radius);
    text-decoration: none;
    transition: background 0.15s;
    overflow: hidden;
}
.scf-cat-row:hover { background: var(--sc-bg-row-alt); }
.scf-cat-row--hidden { opacity: 0.4; }
.scf-cat-row__accent { width: 4px; align-self: stretch; border-radius: 4px 0 0 4px; }
.scf-cat-row__info { padding: 18px 20px; }
.scf-cat-row__name { font-weight: 700; font-size: 0.9rem; color: var(--sc-text); margin-bottom: 4px; }
.scf-cat-row__desc { font-size: 0.78rem; color: var(--sc-text-dim); line-height: 1.4; }
.scf-cat-row__stat { text-align: center; padding: 18px 12px; }
.scf-cat-row__stat-num { display: block; font-size: 1.1rem; font-weight: 700; color: #aaa; }
.scf-cat-row__stat-label { display: block; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.8px; color: var(--sc-text-dim); margin-top: 2px; }

/* ── Thread list ───────────────────────────────────────────────────────────── */
.scf-thread-list { display: flex; flex-direction: column; gap: 2px; }
.scf-thread-row {
    display: grid;
    grid-template-columns: 44px 1fr 64px 100px 88px;
    align-items: center;
    gap: 0;
    background: var(--sc-bg-box);
    border-radius: var(--sc-radius);
    padding: 14px 16px;
    transition: background 0.15s;
}
.scf-thread-row:hover { background: var(--sc-bg-row-alt); }
.scf-thread-row--pinned { border-left: 3px solid #e2b714; }
.scf-thread-row--deleted { opacity: 0.35; }

/* ── Avatars ───────────────────────────────────────────────────────────────── */
.scf-avatar {
    width: 36px; height: 36px; border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.68rem; font-weight: 700; color: #000;
    flex-shrink: 0; letter-spacing: 0.5px;
    position: relative; overflow: hidden;
}
.scf-avatar--sm { width: 32px; height: 32px; font-size: 0.6rem; }
.scf-avatar--lg { width: 44px; height: 44px; font-size: 0.75rem; }
.scf-avatar img { position: relative; z-index: 2; width: 100%; height: 100%; border-radius: 4px; object-fit: cover; }
.scf-avatar__fallback {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center; z-index: 1;
}

/* ── Thread row parts ──────────────────────────────────────────────────────── */
.scf-thread-row__info { min-width: 0; padding: 0 12px; }
.scf-thread-row__title { font-weight: 600; font-size: 0.88rem; color: var(--sc-text); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.scf-thread-row__title a { color: inherit; text-decoration: none; }
.scf-thread-row__title a:hover { color: #fff; }
.scf-thread-row__byline { font-size: 0.72rem; color: var(--sc-text-dim); }
.scf-thread-row__replies { text-align: center; font-size: 0.88rem; font-weight: 600; color: #888; }
.scf-thread-row__replies small { display: block; font-size: 0.6rem; font-weight: 400; text-transform: uppercase; letter-spacing: 0.5px; color: #444; margin-top: 1px; }
.scf-thread-row__activity { text-align: right; font-size: 0.75rem; color: var(--sc-text-dim); }
.scf-thread-row__mod { display: flex; gap: 4px; justify-content: flex-end; }

/* ── Badges ────────────────────────────────────────────────────────────────── */
.scf-badges { display: inline-flex; gap: 4px; margin-right: 6px; vertical-align: middle; }
.scf-badge {
    display: inline-block; font-size: 9px; padding: 2px 6px;
    border-radius: 3px; text-transform: uppercase; letter-spacing: 0.5px;
    font-weight: 700; vertical-align: middle;
}
.scf-badge--pinned  { background: #e2b714; color: #000; }
.scf-badge--locked  { background: #333; color: #888; }
.scf-badge--deleted { background: var(--sc-danger-bg); color: var(--sc-danger); }
.scf-badge--hq      { background: var(--sc-accent-dim); color: var(--sc-accent); }
/* PGSB gold badge — used on hub admin posts */
.scf-badge--pgsb    { background: #b8860b; color: #ffe97a; border: 1px solid #e2b714; letter-spacing: 0.08em; }

/* ── Post stream ───────────────────────────────────────────────────────────── */
.scf-stream { display: flex; flex-direction: column; }
.scf-post {
    display: grid; grid-template-columns: 64px 1fr;
    border-bottom: 1px solid var(--sc-border); padding: 24px 0;
}
.scf-post:first-child { padding-top: 0; }
.scf-post:last-child { border-bottom: none; }
.scf-post--deleted { opacity: 0.35; }
.scf-post--pgsb { background: rgba(178, 134, 11, 0.04); border-left: 2px solid #e2b714; margin-left: -2px; padding-left: 2px; }
.scf-post__gutter { display: flex; flex-direction: column; align-items: center; padding-top: 2px; }
.scf-post__content { min-width: 0; }
.scf-post__header {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 12px; font-size: 0.78rem; flex-wrap: wrap;
}
.scf-post__author { font-weight: 700; color: var(--sc-text); }
.scf-post__domain { color: var(--sc-text-dim); font-size: 0.7rem; }
.scf-post__time   { color: var(--sc-text-dim); }
.scf-post__mod    { margin-left: auto; display: flex; gap: 4px; }
.scf-post__body   { font-size: 0.88rem; line-height: 1.7; color: #bbb; white-space: pre-wrap; word-break: break-word; }
.scf-post--op .scf-post__body { font-size: 0.92rem; }

/* ── Thread title bar ──────────────────────────────────────────────────────── */
.scf-thread-header {
    display: flex; align-items: flex-start; gap: 16px;
    margin-bottom: 6px; flex-wrap: wrap;
}
.scf-thread-title { font-size: 1.3rem; font-weight: 700; color: #eee; line-height: 1.3; flex: 1; min-width: 0; }
.scf-thread-controls { display: flex; gap: 4px; flex-shrink: 0; padding-top: 4px; }
.scf-thread-meta { font-size: 0.75rem; color: var(--sc-text-dim); margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--sc-border); }

/* ── Composer ──────────────────────────────────────────────────────────────── */
.scf-composer {
    background: var(--sc-bg-box); border-radius: var(--sc-radius);
    padding: 20px 24px; margin-top: 24px;
    border: 1px solid var(--sc-border);
}
.scf-composer--pgsb { border-color: rgba(226, 183, 20, 0.25); }
.scf-composer__label {
    font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.8px;
    color: var(--sc-text-dim); margin-bottom: 12px;
    display: flex; align-items: center; gap: 10px;
}
.scf-composer textarea {
    width: 100%; resize: vertical; min-height: 120px;
    background: var(--sc-bg-input); border: 1px solid var(--sc-border-input);
    border-radius: var(--sc-radius-sm); color: var(--sc-text);
    font-family: var(--sc-font); font-size: 0.88rem; line-height: 1.6; padding: 14px 16px;
    transition: border-color 0.15s; box-sizing: border-box;
}
.scf-composer textarea:focus { border-color: var(--sc-accent); outline: none; box-shadow: 0 0 8px var(--sc-accent-glow); }
.scf-composer__footer { display: flex; justify-content: flex-end; margin-top: 12px; gap: 8px; }

/* ── Buttons ───────────────────────────────────────────────────────────────── */
.scf-new-btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 36px; padding: 0 16px;
    background: var(--sc-accent); color: #000;
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;
    border: none; border-radius: var(--sc-radius); text-decoration: none; cursor: pointer;
    transition: background 0.15s, box-shadow 0.15s;
}
.scf-new-btn:hover { background: #53ff2a; box-shadow: 0 0 12px var(--sc-accent-glow); color: #000; }
.scf-mod-btn {
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    padding: 3px 8px; border-radius: 3px; border: 1px solid var(--sc-border);
    background: transparent; color: var(--sc-text-dim); cursor: pointer;
    transition: all 0.15s; text-decoration: none; display: inline-flex; align-items: center;
    line-height: 22px;
}
.scf-mod-btn:hover        { border-color: var(--sc-accent); color: var(--sc-accent); }
.scf-mod-btn--danger:hover  { border-color: var(--sc-danger); color: var(--sc-danger); }
.scf-mod-btn--restore:hover { border-color: var(--sc-success); color: var(--sc-success); }
.scf-mod-btn--active { border-color: var(--sc-accent); color: var(--sc-accent); }

/* ── Column labels ─────────────────────────────────────────────────────────── */
.scf-col-labels {
    display: grid; font-size: 0.65rem; text-transform: uppercase;
    letter-spacing: 1px; color: var(--sc-text-dim); padding: 0 0 8px 0;
}

/* ── Pagination ────────────────────────────────────────────────────────────── */
.scf-pagination { display: flex; gap: 8px; justify-content: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--sc-border); }

/* ── Empty state ───────────────────────────────────────────────────────────── */
.scf-empty { text-align: center; padding: 48px 24px; color: var(--sc-text-dim); font-size: 0.85rem; }
.scf-empty strong { display: block; font-size: 0.95rem; color: #777; margin-bottom: 8px; }

/* ── Emoji picker ──────────────────────────────────────────────────────────── */
.scf-emoji-bar { display: flex; gap: 2px; margin-bottom: 8px; flex-wrap: wrap; }
.scf-emoji-btn {
    background: none; border: 1px solid transparent; border-radius: 4px;
    font-size: 1.2rem; padding: 4px 6px; cursor: pointer; line-height: 1;
    transition: all 0.12s;
}
.scf-emoji-btn:hover { background: var(--sc-bg-row-alt); border-color: var(--sc-border); transform: scale(1.2); }

/* ── Locked thread notice ──────────────────────────────────────────────────── */
.scf-locked-notice {
    margin-top: 24px; padding: 16px 20px;
    background: var(--sc-bg-box); border-radius: var(--sc-radius);
    border: 1px solid var(--sc-border);
    font-size: 0.82rem; color: var(--sc-text-dim); text-align: center;
}

/* ── Responsive ────────────────────────────────────────────────────────────── */
@media (max-width: 720px) {
    .scf-cat-row   { grid-template-columns: 4px 1fr 60px; }
    .scf-cat-row .scf-cat-row__stat:last-of-type { display: none; }
    .scf-thread-row { grid-template-columns: 40px 1fr 60px; padding: 12px; }
    .scf-thread-row__activity, .scf-thread-row__mod { display: none; }
    .scf-post { grid-template-columns: 1fr; }
    .scf-post__gutter { display: none; }
    .scf-pgsb-bar { flex-direction: column; align-items: flex-start; }
}
</style>

<?php
// ── Page header ───────────────────────────────────────────────────────────────
?>
<div class="sc-page-header">
  <span class="sc-page-title">Community Forum</span>
  <span class="sc-dim"><?php echo $install_count; ?> installs &bull; <?php echo $total_threads; ?> threads &bull; <?php echo $total_replies; ?> replies</span>
</div>

<!-- ── PGSB identity + admin nav ─────────────────────────────────────────── -->
<div class="scf-pgsb-bar">
  <div class="scf-pgsb-identity">
    <?php echo sc_forum_avatar(PGSB_DISPLAY_NAME, PGSB_DOMAIN, 'sm'); ?>
    <span class="scf-badge scf-badge--pgsb"><?php echo PGSB_SHORT; ?></span>
    <span class="scf-pgsb-name"><?php echo PGSB_DISPLAY_NAME; ?></span>
  </div>
  <nav class="scf-pgsb-nav">
    <a href="sc-forum.php?section=forum"
       class="scf-pgsb-link <?php echo $section === 'forum' ? 'active' : ''; ?>">Forum</a>
    <a href="sc-forum.php?section=installs"
       class="scf-pgsb-link <?php echo $section === 'installs' ? 'active' : ''; ?>">
      Installs (<?php echo $install_count; ?>)</a>
    <a href="sc-forum.php?section=boards"
       class="scf-pgsb-link <?php echo $section === 'boards' ? 'active' : ''; ?>">Boards</a>
  </nav>
</div>

<?php if ($flash): ?>
<div class="sc-flash"><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<?php if ($section === 'forum'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- FORUM                                                                     -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->

<?php if ($view === 'categories'): ?>
<!-- ── CATEGORIES ─────────────────────────────────────────────────────────── -->
<?php
$active_cats = array_filter($categories, fn($c) => $c['is_active']);
?>
<div class="scf-col-labels" style="grid-template-columns: 4px 1fr 100px 100px; padding-right: 8px;">
  <span></span>
  <span style="padding-left:20px;">Board</span>
  <span style="text-align:center;">Threads</span>
  <span style="text-align:center;">Replies</span>
</div>

<div class="scf-cat-list">
  <?php foreach ($categories as $ci => $cat):
      $accent = $cat_accents[$ci % count($cat_accents)];
  ?>
  <a href="sc-forum.php?section=forum&view=threads&cat=<?php echo (int)$cat['id']; ?>"
     class="scf-cat-row<?php echo !$cat['is_active'] ? ' scf-cat-row--hidden' : ''; ?>">
    <div class="scf-cat-row__accent" style="background:<?php echo $accent; ?>;"></div>
    <div class="scf-cat-row__info">
      <div class="scf-cat-row__name"><?php echo htmlspecialchars($cat['name']); ?></div>
      <?php if (!empty($cat['description'])): ?>
      <div class="scf-cat-row__desc"><?php echo htmlspecialchars($cat['description']); ?></div>
      <?php endif; ?>
    </div>
    <div class="scf-cat-row__stat">
      <span class="scf-cat-row__stat-num"><?php echo (int)$cat['thread_count']; ?></span>
      <span class="scf-cat-row__stat-label">Threads</span>
    </div>
    <div class="scf-cat-row__stat">
      <span class="scf-cat-row__stat-num"><?php echo (int)$cat['reply_count']; ?></span>
      <span class="scf-cat-row__stat-label">Replies</span>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php elseif ($view === 'threads'): ?>
<!-- ── THREAD LIST ────────────────────────────────────────────────────────── -->
<?php
$cat_name = 'Board';
foreach ($categories as $c) {
    if ((int)$c['id'] === $cat_id) { $cat_name = $c['name']; break; }
}
$offset = ($page - 1) * $per_page;
$stmt   = $db->prepare("
    SELECT t.id, t.install_id, t.title, t.display_name,
           t.is_pinned, t.is_locked, t.is_deleted,
           t.reply_count, t.created_at, t.last_reply_at,
           i.domain
    FROM ss_forum_threads t
    JOIN ss_forum_installs i ON i.id = t.install_id
    WHERE t.category_id = ?
    ORDER BY t.is_pinned DESC, COALESCE(t.last_reply_at, t.created_at) DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$cat_id, $per_page + 1, $offset]);
$threads  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$has_more = count($threads) > $per_page;
if ($has_more) array_pop($threads);
?>

<div class="scf-crumbs">
  <a href="sc-forum.php?section=forum">Forum</a>
  <span class="sep">/</span>
  <span class="current"><?php echo htmlspecialchars(strtoupper($cat_name)); ?></span>
  <div class="scf-crumbs-actions">
    <a href="sc-forum.php?section=forum&view=new-thread&cat=<?php echo $cat_id; ?>" class="scf-new-btn">+ New Thread</a>
  </div>
</div>

<?php if (empty($threads)): ?>
  <div class="scf-empty">
    <strong>No threads yet</strong>
    <p>Post the first thread as <?php echo PGSB_SHORT; ?>.</p>
  </div>
<?php else: ?>

<div class="scf-col-labels" style="grid-template-columns: 44px 1fr 64px 100px 88px;">
  <span></span>
  <span style="padding-left:12px;">Topic</span>
  <span style="text-align:center;">Replies</span>
  <span style="text-align:right;">Activity</span>
  <span style="text-align:right;">Mod</span>
</div>

<div class="scf-thread-list">
  <?php foreach ($threads as $t):
      $pinned  = !empty($t['is_pinned']);
      $locked  = !empty($t['is_locked']);
      $deleted = !empty($t['is_deleted']);
      $is_pgsb = ($hub_id > 0 && (int)$t['install_id'] === $hub_id);
      $last_act = $t['last_reply_at'] ?? $t['created_at'];
  ?>
  <div class="scf-thread-row<?php echo $pinned ? ' scf-thread-row--pinned' : ''; ?><?php echo $deleted ? ' scf-thread-row--deleted' : ''; ?>">
    <?php echo sc_forum_avatar($t['display_name'], $t['domain'] ?? ''); ?>
    <div class="scf-thread-row__info">
      <div class="scf-thread-row__title">
        <?php if ($pinned || $locked || $deleted || $is_pgsb): ?>
        <span class="scf-badges">
          <?php if ($is_pgsb):  ?><span class="scf-badge scf-badge--pgsb">PGSB</span><?php endif; ?>
          <?php if ($pinned):   ?><span class="scf-badge scf-badge--pinned">Pinned</span><?php endif; ?>
          <?php if ($locked):   ?><span class="scf-badge scf-badge--locked">Locked</span><?php endif; ?>
          <?php if ($deleted):  ?><span class="scf-badge scf-badge--deleted">Deleted</span><?php endif; ?>
        </span>
        <?php endif; ?>
        <a href="sc-forum.php?section=forum&view=thread&id=<?php echo (int)$t['id']; ?>">
          <?php echo htmlspecialchars($t['title']); ?>
        </a>
      </div>
      <div class="scf-thread-row__byline">
        <?php echo htmlspecialchars($is_pgsb ? PGSB_DISPLAY_NAME : $t['display_name']); ?>
        &middot;
        <?php echo date('M j, Y', strtotime($t['created_at'])); ?>
      </div>
    </div>
    <div class="scf-thread-row__replies">
      <?php echo (int)$t['reply_count']; ?>
      <small>replies</small>
    </div>
    <div class="scf-thread-row__activity"><?php echo sc_forum_ago($last_act); ?></div>
    <div class="scf-thread-row__mod">
      <form method="post" style="display:inline;">
        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
        <input type="hidden" name="action" value="pin_thread">
        <button class="scf-mod-btn<?php echo $pinned ? ' scf-mod-btn--active' : ''; ?>" title="<?php echo $pinned ? 'Unpin' : 'Pin'; ?>">
          <?php echo $pinned ? '📌' : 'Pin'; ?></button>
      </form>
      <?php if ($deleted): ?>
      <form method="post" style="display:inline;">
        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
        <input type="hidden" name="action" value="restore_thread">
        <button class="scf-mod-btn scf-mod-btn--restore">Restore</button>
      </form>
      <?php else: ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Soft-delete this thread?')">
        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
        <input type="hidden" name="action" value="delete_thread">
        <button class="scf-mod-btn scf-mod-btn--danger">Del</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($page > 1 || $has_more): ?>
<div class="scf-pagination">
  <?php if ($page > 1): ?>
    <a href="sc-forum.php?section=forum&view=threads&cat=<?php echo $cat_id; ?>&page=<?php echo $page - 1; ?>" class="scf-mod-btn">&larr; Prev</a>
  <?php endif; ?>
  <span class="sc-dim" style="line-height:28px;">Page <?php echo $page; ?></span>
  <?php if ($has_more): ?>
    <a href="sc-forum.php?section=forum&view=threads&cat=<?php echo $cat_id; ?>&page=<?php echo $page + 1; ?>" class="scf-mod-btn">Next &rarr;</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($view === 'thread'): ?>
<!-- ── THREAD DETAIL ──────────────────────────────────────────────────────── -->
<?php
$stmt = $db->prepare("
    SELECT t.*, i.domain AS author_domain, c.name AS category_name
    FROM ss_forum_threads t
    JOIN ss_forum_installs i ON i.id = t.install_id
    JOIN ss_forum_categories c ON c.id = t.category_id
    WHERE t.id = ?
    LIMIT 1
");
$stmt->execute([$thread_id]);
$thread = $stmt->fetch(PDO::FETCH_ASSOC);

$replies = [];
if ($thread) {
    $r_stmt = $db->prepare("
        SELECT r.*, i.domain AS author_domain
        FROM ss_forum_replies r
        JOIN ss_forum_installs i ON i.id = r.install_id
        WHERE r.thread_id = ?
        ORDER BY r.created_at ASC
    ");
    $r_stmt->execute([$thread_id]);
    $replies = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php if (!$thread): ?>
  <div class="scf-empty"><strong>Thread not found.</strong></div>
<?php else: ?>
<?php
$t_is_pgsb = ($hub_id > 0 && (int)$thread['install_id'] === $hub_id);
?>

<div class="scf-crumbs">
  <a href="sc-forum.php?section=forum">Forum</a>
  <span class="sep">/</span>
  <a href="sc-forum.php?section=forum&view=threads&cat=<?php echo (int)$thread['category_id']; ?>">
    <?php echo htmlspecialchars(strtoupper($thread['category_name'])); ?>
  </a>
  <span class="sep">/</span>
  <span class="current">Thread #<?php echo (int)$thread['id']; ?></span>
  <div class="scf-crumbs-actions">
    <a href="sc-forum.php?section=forum&view=new-thread&cat=<?php echo (int)$thread['category_id']; ?>" class="scf-mod-btn">+ New Thread</a>
  </div>
</div>

<!-- Thread title + mod controls -->
<div class="scf-thread-header">
  <div class="scf-thread-title">
    <?php if ($t_is_pgsb): ?><span class="scf-badge scf-badge--pgsb" style="vertical-align:middle; margin-right:6px;">PGSB</span><?php endif; ?>
    <?php if (!empty($thread['is_pinned'])): ?><span class="scf-badge scf-badge--pinned" style="vertical-align:middle; margin-right:6px;">Pinned</span><?php endif; ?>
    <?php if (!empty($thread['is_locked'])): ?><span class="scf-badge scf-badge--locked" style="vertical-align:middle; margin-right:6px;">Locked</span><?php endif; ?>
    <?php if (!empty($thread['is_deleted'])): ?><span class="scf-badge scf-badge--deleted" style="vertical-align:middle; margin-right:6px;">Deleted</span><?php endif; ?>
    <?php echo htmlspecialchars($thread['title']); ?>
  </div>
  <div class="scf-thread-controls">
    <form method="post" style="display:inline;">
      <input type="hidden" name="id" value="<?php echo (int)$thread['id']; ?>">
      <input type="hidden" name="action" value="pin_thread">
      <button class="scf-mod-btn<?php echo !empty($thread['is_pinned']) ? ' scf-mod-btn--active' : ''; ?>">
        <?php echo !empty($thread['is_pinned']) ? 'Unpin' : 'Pin'; ?>
      </button>
    </form>
    <form method="post" style="display:inline;">
      <input type="hidden" name="id" value="<?php echo (int)$thread['id']; ?>">
      <input type="hidden" name="action" value="lock_thread">
      <button class="scf-mod-btn<?php echo !empty($thread['is_locked']) ? ' scf-mod-btn--active' : ''; ?>">
        <?php echo !empty($thread['is_locked']) ? 'Unlock' : 'Lock'; ?>
      </button>
    </form>
    <?php if (!empty($thread['is_deleted'])): ?>
    <form method="post" style="display:inline;">
      <input type="hidden" name="id" value="<?php echo (int)$thread['id']; ?>">
      <input type="hidden" name="action" value="restore_thread">
      <button class="scf-mod-btn scf-mod-btn--restore">Restore Thread</button>
    </form>
    <?php else: ?>
    <form method="post" style="display:inline;" onsubmit="return confirm('Soft-delete this thread?')">
      <input type="hidden" name="id" value="<?php echo (int)$thread['id']; ?>">
      <input type="hidden" name="action" value="delete_thread">
      <button class="scf-mod-btn scf-mod-btn--danger">Delete Thread</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="scf-thread-meta">
  <?php echo htmlspecialchars($thread['category_name']); ?>
  &middot;
  <?php echo count($replies); ?> <?php echo count($replies) === 1 ? 'reply' : 'replies'; ?>
  &middot;
  started <?php echo date('M j, Y', strtotime($thread['created_at'])); ?>
</div>

<!-- Post stream -->
<div class="scf-stream">

  <!-- ── Opening post ───────────────────────────────────────────────────── -->
  <div class="scf-post scf-post--op<?php echo $t_is_pgsb ? ' scf-post--pgsb' : ''; ?><?php echo !empty($thread['is_deleted']) ? ' scf-post--deleted' : ''; ?>">
    <div class="scf-post__gutter">
      <?php echo sc_forum_avatar(
          $t_is_pgsb ? PGSB_DISPLAY_NAME : $thread['display_name'],
          $thread['author_domain'] ?? '',
          'lg'
      ); ?>
    </div>
    <div class="scf-post__content">
      <div class="scf-post__header">
        <span class="scf-post__author">
          <?php echo htmlspecialchars($t_is_pgsb ? PGSB_DISPLAY_NAME : $thread['display_name']); ?>
        </span>
        <?php if ($t_is_pgsb): ?>
        <span class="scf-badge scf-badge--pgsb">PGSB</span>
        <?php else: ?>
        <span class="scf-post__domain"><?php echo htmlspecialchars($thread['author_domain'] ?? ''); ?></span>
        <?php endif; ?>
        <span class="scf-post__time"><?php echo date('M j, Y \a\t g:ia', strtotime($thread['created_at'])); ?></span>
        <div class="scf-post__mod">
          <?php /* OP delete is already in the thread-controls above — no duplicate here */ ?>
        </div>
      </div>
      <div class="scf-post__body"><?php echo nl2br(htmlspecialchars($thread['body'])); ?></div>
    </div>
  </div>

  <!-- ── Replies ────────────────────────────────────────────────────────── -->
  <?php foreach ($replies as $r):
      $r_is_pgsb = ($hub_id > 0 && (int)$r['install_id'] === $hub_id);
  ?>
  <div class="scf-post<?php echo $r_is_pgsb ? ' scf-post--pgsb' : ''; ?><?php echo !empty($r['is_deleted']) ? ' scf-post--deleted' : ''; ?>">
    <div class="scf-post__gutter">
      <?php echo sc_forum_avatar(
          $r_is_pgsb ? PGSB_DISPLAY_NAME : $r['display_name'],
          $r['author_domain'] ?? '',
          'lg'
      ); ?>
    </div>
    <div class="scf-post__content">
      <div class="scf-post__header">
        <span class="scf-post__author">
          <?php echo htmlspecialchars($r_is_pgsb ? PGSB_DISPLAY_NAME : $r['display_name']); ?>
        </span>
        <?php if ($r_is_pgsb): ?>
        <span class="scf-badge scf-badge--pgsb">PGSB</span>
        <?php else: ?>
        <span class="scf-post__domain"><?php echo htmlspecialchars($r['author_domain'] ?? ''); ?></span>
        <?php endif; ?>
        <?php if (!empty($r['is_deleted'])): ?>
        <span class="scf-badge scf-badge--deleted">Deleted</span>
        <?php endif; ?>
        <span class="scf-post__time"><?php echo date('M j, Y \a\t g:ia', strtotime($r['created_at'])); ?></span>
        <div class="scf-post__mod">
          <?php if (!empty($r['is_deleted'])): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
            <input type="hidden" name="action" value="restore_reply">
            <button class="scf-mod-btn scf-mod-btn--restore">Restore</button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Soft-delete this reply?')">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
            <input type="hidden" name="action" value="delete_reply">
            <button class="scf-mod-btn scf-mod-btn--danger">Del</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="scf-post__body"><?php echo nl2br(htmlspecialchars($r['body'])); ?></div>
    </div>
  </div>
  <?php endforeach; ?>

</div><!-- /.scf-stream -->

<!-- ── PGSB reply composer ────────────────────────────────────────────────── -->
<?php if (!$thread['is_locked']): ?>
<div class="scf-composer scf-composer--pgsb">
  <div class="scf-composer__label">
    <?php echo sc_forum_avatar(PGSB_DISPLAY_NAME, PGSB_DOMAIN, 'sm'); ?>
    <span class="scf-badge scf-badge--pgsb"><?php echo PGSB_SHORT; ?></span>
    Reply as <?php echo PGSB_DISPLAY_NAME; ?>
  </div>
  <form method="post">
    <input type="hidden" name="action"    value="hub_post_reply">
    <input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>">
    <div class="scf-emoji-bar">
      <?php foreach ($emoji_set as $em): ?>
      <button type="button" class="scf-emoji-btn"
              onclick="scfInsertEmoji(this,'<?php echo $em; ?>')"><?php echo $em; ?></button>
      <?php endforeach; ?>
    </div>
    <textarea name="body" placeholder="Write your reply&#8230;" required></textarea>
    <div class="scf-composer__footer">
      <button type="submit" class="scf-new-btn">Post as <?php echo PGSB_SHORT; ?></button>
    </div>
  </form>
</div>
<?php else: ?>
<div class="scf-locked-notice">
  🔒 This thread is locked. You can unlock it from the controls above.
</div>
<?php endif; ?>

<?php endif; // thread exists ?>

<?php elseif ($view === 'new-thread'): ?>
<!-- ── NEW THREAD ─────────────────────────────────────────────────────────── -->
<?php
$new_cat_id    = (int)($_GET['cat'] ?? 0);
$form_cat_name = '';
foreach ($categories as $c) {
    if ((int)$c['id'] === $new_cat_id) { $form_cat_name = $c['name']; break; }
}
?>

<div class="scf-crumbs">
  <a href="sc-forum.php?section=forum">Forum</a>
  <span class="sep">/</span>
  <?php if ($new_cat_id): ?>
  <a href="sc-forum.php?section=forum&view=threads&cat=<?php echo $new_cat_id; ?>">
    <?php echo htmlspecialchars(strtoupper($form_cat_name ?: 'BOARD')); ?>
  </a>
  <span class="sep">/</span>
  <?php endif; ?>
  <span class="current">New Thread as <?php echo PGSB_SHORT; ?></span>
</div>

<div class="scf-composer scf-composer--pgsb">
  <div class="scf-composer__label">
    <?php echo sc_forum_avatar(PGSB_DISPLAY_NAME, PGSB_DOMAIN, 'sm'); ?>
    <span class="scf-badge scf-badge--pgsb"><?php echo PGSB_SHORT; ?></span>
    New Thread as <?php echo PGSB_DISPLAY_NAME; ?>
  </div>
  <form method="post">
    <input type="hidden" name="action" value="hub_post_thread">
    <div class="sc-field" style="margin-bottom:16px;">
      <label class="sc-label">Board</label>
      <select name="category_id" class="sc-input" required>
        <option value="">— Select a board —</option>
        <?php foreach ($categories as $c): ?>
          <?php if ($c['is_active']): ?>
          <option value="<?php echo (int)$c['id']; ?>"
                  <?php echo ($new_cat_id === (int)$c['id']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($c['name']); ?>
          </option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sc-field" style="margin-bottom:16px;">
      <label class="sc-label">Title</label>
      <input type="text" name="title" class="sc-input" maxlength="200" required
             placeholder="Thread title">
    </div>
    <div class="sc-field" style="margin-bottom:16px;">
      <label class="sc-label">Body</label>
      <div class="scf-emoji-bar">
        <?php foreach ($emoji_set as $em): ?>
        <button type="button" class="scf-emoji-btn"
                onclick="scfInsertEmoji(this,'<?php echo $em; ?>')"><?php echo $em; ?></button>
        <?php endforeach; ?>
      </div>
      <textarea name="body" class="sc-input" rows="10"
                style="height:auto; resize:vertical; width:100%; box-sizing:border-box;"
                required placeholder="Write your thread&#8230;"></textarea>
    </div>
    <div class="scf-composer__footer">
      <?php
      $back = $new_cat_id
          ? "sc-forum.php?section=forum&view=threads&cat={$new_cat_id}"
          : 'sc-forum.php?section=forum';
      ?>
      <a href="<?php echo $back; ?>" class="scf-mod-btn" style="line-height:36px; padding:0 16px;">Cancel</a>
      <button type="submit" class="scf-new-btn">Post as <?php echo PGSB_SHORT; ?></button>
    </div>
  </form>
</div>

<?php endif; // end view routing ?>

<?php elseif ($section === 'installs'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- INSTALLS                                                                  -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php
$installs = $db->query("
    SELECT id, domain, display_name, ss_version, registered_at, last_seen_at, is_banned, is_moderator
    FROM ss_forum_installs
    ORDER BY is_banned ASC, registered_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="sc-box">
  <div class="sc-box-header">
    <span class="sc-box-title">Registered Installs</span>
    <span class="sc-dim"><?php echo count($installs); ?> total</span>
  </div>
  <div class="sc-box-body sc-box-body--flush">
    <?php if (empty($installs)): ?>
      <p class="sc-dim sc-pad">No installs registered yet.</p>
    <?php else: ?>
    <table class="sc-table">
      <thead>
        <tr>
          <th>Domain</th>
          <th>Display Name</th>
          <th>Version</th>
          <th>Registered</th>
          <th>Last Seen</th>
          <th>Role</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($installs as $row):
          $is_hub = ($row['domain'] === PGSB_DOMAIN);
      ?>
        <tr class="<?php echo $row['is_banned'] ? 'sc-row--muted' : ''; ?>">
          <td>
            <?php echo htmlspecialchars($row['domain']); ?>
            <?php if ($is_hub): ?><span class="scf-badge scf-badge--pgsb" style="margin-left:6px;">PGSB</span><?php endif; ?>
          </td>
          <td>
            <?php if (!$is_hub): ?>
            <span onclick="scfEditInstallName(this,<?php echo (int)$row['id']; ?>,<?php echo htmlspecialchars(json_encode($row['display_name'])); ?>)"
                  title="Click to rename"
                  style="cursor:pointer; border-bottom:1px dashed rgba(255,255,255,.3);">
              <?php echo htmlspecialchars($row['display_name']); ?>
            </span>
            <?php else: ?>
            <?php echo htmlspecialchars(PGSB_DISPLAY_NAME); ?>
            <?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($row['ss_version']); ?></td>
          <td class="sc-dim"><?php echo substr($row['registered_at'], 0, 10); ?></td>
          <td class="sc-dim"><?php echo substr($row['last_seen_at'], 0, 10); ?></td>
          <td>
            <?php if ($is_hub): ?>
              <span class="scf-badge scf-badge--pgsb">PGSB</span>
            <?php elseif ($row['is_moderator']): ?>
              <span class="sc-badge sc-badge--ok">Moderator</span>
            <?php else: ?>
              <span class="sc-badge">Member</span>
            <?php endif; ?>
          </td>
          <td>
            <?php echo $row['is_banned']
                ? '<span class="sc-badge sc-badge--warn">Banned</span>'
                : '<span class="sc-badge sc-badge--ok">Active</span>'; ?>
          </td>
          <td>
            <?php if (!$is_hub): ?>
            <div class="sc-btn-row sc-btn-row--tight">
              <form method="post" style="display:inline;">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="<?php echo $row['is_moderator'] ? 'demote_mod' : 'promote_mod'; ?>">
                <button class="sc-btn sc-btn--sm"><?php echo $row['is_moderator'] ? 'Demote' : 'Promote'; ?></button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="<?php echo $row['is_banned'] ? 'unban_install' : 'ban_install'; ?>">
                <button class="sc-btn sc-btn--sm <?php echo $row['is_banned'] ? '' : 'sc-btn--danger'; ?>">
                  <?php echo $row['is_banned'] ? 'Unban' : 'Ban'; ?>
                </button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Hidden rename form — submitted by scfEditInstallName() in smack-sc-forum.js -->
<form id="scf-rename-form" method="post" style="display:none;">
  <input type="hidden" name="action"       value="rename_install">
  <input type="hidden" name="id"           id="scf-rename-id">
  <input type="hidden" name="display_name" id="scf-rename-val">
</form>

<?php elseif ($section === 'boards'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- BOARDS                                                                    -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="sc-box">
  <div class="sc-box-header">
    <span class="sc-box-title">Forum Boards</span>
    <span class="sc-dim"><?php echo count($categories); ?> boards</span>
  </div>
  <div class="sc-box-body sc-box-body--flush">
    <table class="sc-table">
      <thead>
        <tr>
          <th>Order</th>
          <th>Name</th>
          <th>Slug</th>
          <th>Description</th>
          <th>Threads</th>
          <th>Replies</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $row): ?>
        <tr class="<?php echo !$row['is_active'] ? 'sc-row--muted' : ''; ?>">
          <td class="sc-dim"><?php echo (int)$row['sort_order']; ?></td>
          <td><?php echo htmlspecialchars($row['name']); ?></td>
          <td class="sc-mono"><?php echo htmlspecialchars($row['slug']); ?></td>
          <td class="sc-dim"><?php echo htmlspecialchars($row['description']); ?></td>
          <td><?php echo (int)$row['thread_count']; ?></td>
          <td><?php echo (int)$row['reply_count']; ?></td>
          <td>
            <?php echo $row['is_active']
                ? '<span class="sc-badge sc-badge--ok">Active</span>'
                : '<span class="sc-badge">Hidden</span>'; ?>
          </td>
          <td>
            <form method="post">
              <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
              <input type="hidden" name="action" value="toggle_category">
              <button class="sc-btn sc-btn--sm">
                <?php echo $row['is_active'] ? 'Hide' : 'Show'; ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // end section routing ?>

<script src="../assets/js/smack-sc-forum.js"></script>
<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // EOF
