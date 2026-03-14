<?php
/**
 * SMACK CENTRAL - Forum Admin
 * Alpha v0.7.3
 *
 * Moderation and management for the SnapSmack community forum.
 * Covers: registered installs, categories, threads, replies.
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-forum.php';
$sc_page_title = 'Forum Admin';

$db      = sc_forum_db();
$flash   = '';
$section = $_GET['section'] ?? 'installs';

// ── POST actions ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Installs
    if ($action === 'ban_install' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_banned = 1 WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Install banned.';
    } elseif ($action === 'unban_install' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_banned = 0 WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Install unbanned.';
    } elseif ($action === 'promote_mod' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_moderator = 1 WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Install promoted to moderator.';
    } elseif ($action === 'demote_mod' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_installs SET is_moderator = 0 WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Moderator privileges removed.';
    }

    // Categories
    elseif ($action === 'toggle_category' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_categories SET is_active = 1 - is_active WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Category updated.';
    } elseif ($action === 'save_category_order' && !empty($_POST['order'])) {
        $ids = array_map('intval', explode(',', $_POST['order']));
        $stmt = $db->prepare("UPDATE ss_forum_categories SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $stmt->execute([$i + 1, $id]);
        }
        $flash = 'Category order saved.';
    }

    // Threads
    elseif ($action === 'pin_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_pinned = 1 - is_pinned WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Thread pin toggled.';
    } elseif ($action === 'lock_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_locked = 1 - is_locked WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Thread lock toggled.';
    } elseif ($action === 'delete_thread' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_threads SET is_deleted = 1 WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Thread deleted.';
    }

    // Replies
    elseif ($action === 'delete_reply' && !empty($_POST['id'])) {
        $db->prepare("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = ?")
           ->execute([(int)$_POST['id']]);
        $flash = 'Reply deleted.';
    }

    // Hub posting — post thread or reply as "SnapSmack HQ"
    elseif ($action === 'hub_post_thread') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        $title  = trim($_POST['title']        ?? '');
        $body   = trim($_POST['body']         ?? '');
        if ($cat_id && $title !== '' && $body !== '') {
            // Look up the hub install row
            $hub = $db->query("SELECT id, display_name FROM ss_forum_installs WHERE domain = 'snapsmack.ca' LIMIT 1")->fetch();
            if ($hub) {
                $db->beginTransaction();
                try {
                    $db->prepare(
                        "INSERT INTO ss_forum_threads (category_id, install_id, display_name, title, body) VALUES (?, ?, ?, ?, ?)"
                    )->execute([$cat_id, $hub['id'], $hub['display_name'], $title, $body]);
                    $db->prepare("UPDATE ss_forum_categories SET thread_count = thread_count + 1 WHERE id = ?")->execute([$cat_id]);
                    $db->commit();
                    $flash = 'Thread posted as ' . $hub['display_name'] . '.';
                    $section = 'threads';
                } catch (Exception $e) {
                    $db->rollBack();
                    $flash = 'Error posting thread: ' . $e->getMessage();
                }
            } else {
                $flash = 'Hub install not found. Run the moderator migration first.';
            }
        } else {
            $flash = 'Category, title, and body are all required.';
            $section = 'post';
        }
    } elseif ($action === 'hub_post_reply') {
        $thread_id = (int)($_POST['thread_id'] ?? 0);
        $body      = trim($_POST['body']       ?? '');
        if ($thread_id && $body !== '') {
            $hub = $db->query("SELECT id, display_name FROM ss_forum_installs WHERE domain = 'snapsmack.ca' LIMIT 1")->fetch();
            if ($hub) {
                $db->beginTransaction();
                try {
                    $db->prepare(
                        "INSERT INTO ss_forum_replies (thread_id, install_id, display_name, body) VALUES (?, ?, ?, ?)"
                    )->execute([$thread_id, $hub['id'], $hub['display_name'], $body]);
                    $t_stmt = $db->prepare("SELECT category_id FROM ss_forum_threads WHERE id = ? LIMIT 1");
                    $t_stmt->execute([$thread_id]);
                    $t_row = $t_stmt->fetch();
                    $db->prepare("UPDATE ss_forum_threads SET reply_count = reply_count + 1, last_reply_at = NOW() WHERE id = ?")->execute([$thread_id]);
                    if ($t_row) {
                        $db->prepare("UPDATE ss_forum_categories SET reply_count = reply_count + 1 WHERE id = ?")->execute([$t_row['category_id']]);
                    }
                    $db->commit();
                    $flash = 'Reply posted as ' . $hub['display_name'] . '.';
                } catch (Exception $e) {
                    $db->rollBack();
                    $flash = 'Error posting reply: ' . $e->getMessage();
                }
            } else {
                $flash = 'Hub install not found. Run the moderator migration first.';
            }
        } else {
            $flash = 'Thread and body are required.';
            $section = 'post';
        }
    }

    header('Location: sc-forum.php?section=' . urlencode($section) . ($flash ? '&flash=' . urlencode($flash) : ''));
    exit;
}

if (!empty($_GET['flash'])) $flash = htmlspecialchars($_GET['flash']);

// ── Data ─────────────────────────────────────────────────────────────────────

$installs   = [];
$categories = [];
$threads    = [];

try {
    $installs = $db->query("
        SELECT id, domain, display_name, ss_version, registered_at, last_seen_at, is_banned, is_moderator
        FROM ss_forum_installs
        ORDER BY is_banned ASC, registered_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $categories = $db->query("
        SELECT id, slug, name, description, sort_order, is_active, thread_count, reply_count
        FROM ss_forum_categories
        ORDER BY sort_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $threads = $db->query("
        SELECT t.id, t.title, t.display_name, t.is_pinned, t.is_locked, t.is_deleted,
               t.reply_count, t.created_at, t.last_reply_at,
               c.name AS category_name,
               i.domain
        FROM ss_forum_threads t
        JOIN ss_forum_categories c ON c.id = t.category_id
        JOIN ss_forum_installs   i ON i.id = t.install_id
        ORDER BY t.created_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $flash = 'Database error: ' . htmlspecialchars($e->getMessage());
}

$active_installs = count(array_filter($installs, fn($r) => !$r['is_banned']));
$banned_installs = count($installs) - $active_installs;
$total_threads   = count(array_filter($threads, fn($r) => !$r['is_deleted']));

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">Forum Admin</span>
  <span class="sc-dim"><?php echo $active_installs; ?> active installs &bull; <?php echo count($categories); ?> categories &bull; <?php echo $total_threads; ?> threads</span>
</div>

<?php if ($flash): ?>
<div class="sc-flash"><?php echo $flash; ?></div>
<?php endif; ?>

<!-- Section tabs -->
<div class="sc-tab-bar">
  <a href="?section=installs"   class="sc-tab <?php echo $section === 'installs'   ? 'active' : ''; ?>">Installs (<?php echo count($installs); ?>)</a>
  <a href="?section=categories" class="sc-tab <?php echo $section === 'categories' ? 'active' : ''; ?>">Categories</a>
  <a href="?section=threads"    class="sc-tab <?php echo $section === 'threads'    ? 'active' : ''; ?>">Threads</a>
  <a href="?section=post"       class="sc-tab <?php echo $section === 'post'       ? 'active' : ''; ?>">Post as HQ</a>
</div>

<?php if ($section === 'installs'): ?>
<!-- ── INSTALLS ── -->
<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Registered Installs</span></div>
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
      <?php foreach ($installs as $row): ?>
        <tr class="<?php echo $row['is_banned'] ? 'sc-row--muted' : ''; ?>">
          <td><?php echo htmlspecialchars($row['domain']); ?></td>
          <td><?php echo htmlspecialchars($row['display_name']); ?></td>
          <td><?php echo htmlspecialchars($row['ss_version']); ?></td>
          <td class="sc-dim"><?php echo htmlspecialchars(substr($row['registered_at'], 0, 10)); ?></td>
          <td class="sc-dim"><?php echo htmlspecialchars(substr($row['last_seen_at'], 0, 10)); ?></td>
          <td>
            <?php if ($row['is_moderator']): ?>
              <span class="sc-badge sc-badge--ok">Moderator</span>
            <?php else: ?>
              <span class="sc-badge">Member</span>
            <?php endif; ?>
          </td>
          <td><?php echo $row['is_banned'] ? '<span class="sc-badge sc-badge--warn">Banned</span>' : '<span class="sc-badge sc-badge--ok">Active</span>'; ?></td>
          <td>
            <div class="sc-btn-row sc-btn-row--tight">
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="<?php echo $row['is_moderator'] ? 'demote_mod' : 'promote_mod'; ?>">
                <button type="submit" class="sc-btn sc-btn--sm"><?php echo $row['is_moderator'] ? 'Demote' : 'Promote'; ?></button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="<?php echo $row['is_banned'] ? 'unban_install' : 'ban_install'; ?>">
                <button type="submit" class="sc-btn sc-btn--sm <?php echo $row['is_banned'] ? '' : 'sc-btn--danger'; ?>">
                  <?php echo $row['is_banned'] ? 'Unban' : 'Ban'; ?>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($section === 'categories'): ?>
<!-- ── CATEGORIES ── -->
<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Forum Categories</span></div>
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
          <td><?php echo $row['is_active'] ? '<span class="sc-badge sc-badge--ok">Active</span>' : '<span class="sc-badge">Hidden</span>'; ?></td>
          <td>
            <form method="post">
              <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
              <input type="hidden" name="action" value="toggle_category">
              <button type="submit" class="sc-btn sc-btn--sm">
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

<?php elseif ($section === 'threads'): ?>
<!-- ── THREADS ── -->
<div class="sc-box">
  <div class="sc-box-header">
    <span class="sc-box-title">Threads</span>
    <span class="sc-dim" style="font-size:.8rem;">Most recent 200 &bull; soft-deleted rows shown muted</span>
  </div>
  <div class="sc-box-body sc-box-body--flush">
    <?php if (empty($threads)): ?>
      <p class="sc-dim sc-pad">No threads yet.</p>
    <?php else: ?>
    <table class="sc-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Category</th>
          <th>Posted by</th>
          <th>Domain</th>
          <th>Replies</th>
          <th>Date</th>
          <th>Flags</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($threads as $row): ?>
        <tr class="<?php echo $row['is_deleted'] ? 'sc-row--muted' : ''; ?>">
          <td><?php echo htmlspecialchars($row['title']); ?></td>
          <td class="sc-dim"><?php echo htmlspecialchars($row['category_name']); ?></td>
          <td><?php echo htmlspecialchars($row['display_name']); ?></td>
          <td class="sc-dim"><?php echo htmlspecialchars($row['domain']); ?></td>
          <td><?php echo (int)$row['reply_count']; ?></td>
          <td class="sc-dim"><?php echo htmlspecialchars(substr($row['created_at'], 0, 10)); ?></td>
          <td>
            <?php if ($row['is_pinned']): ?><span class="sc-badge sc-badge--ok">Pinned</span> <?php endif; ?>
            <?php if ($row['is_locked']): ?><span class="sc-badge sc-badge--warn">Locked</span> <?php endif; ?>
            <?php if ($row['is_deleted']): ?><span class="sc-badge">Deleted</span> <?php endif; ?>
          </td>
          <td>
            <?php if (!$row['is_deleted']): ?>
            <div class="sc-btn-row sc-btn-row--tight">
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="pin_thread">
                <button class="sc-btn sc-btn--sm"><?php echo $row['is_pinned'] ? 'Unpin' : 'Pin'; ?></button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="lock_thread">
                <button class="sc-btn sc-btn--sm"><?php echo $row['is_locked'] ? 'Unlock' : 'Lock'; ?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this thread?')">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="action" value="delete_thread">
                <button class="sc-btn sc-btn--sm sc-btn--danger">Delete</button>
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

<?php elseif ($section === 'post'): ?>
<!-- ── POST AS HQ ── -->
<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Post Thread as SnapSmack HQ</span></div>
  <div class="sc-box-body">
    <form method="post">
      <input type="hidden" name="action" value="hub_post_thread">
      <div class="sc-field">
        <label class="sc-label">Category</label>
        <select name="category_id" class="sc-input" required>
          <option value="">— Select —</option>
          <?php foreach ($categories as $c): ?>
            <?php if ($c['is_active']): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sc-field">
        <label class="sc-label">Title</label>
        <input type="text" name="title" class="sc-input" maxlength="200" required>
      </div>
      <div class="sc-field">
        <label class="sc-label">Body</label>
        <textarea name="body" class="sc-input" rows="8" style="height:auto; resize:vertical;" required></textarea>
      </div>
      <button type="submit" class="sc-btn sc-btn--primary" style="margin-top:12px;">Post Thread</button>
    </form>
  </div>
</div>

<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Reply to Thread as SnapSmack HQ</span></div>
  <div class="sc-box-body">
    <form method="post">
      <input type="hidden" name="action" value="hub_post_reply">
      <div class="sc-field">
        <label class="sc-label">Thread ID</label>
        <input type="number" name="thread_id" class="sc-input" min="1" required>
        <span class="sc-dim" style="font-size:.75rem; margin-top:4px; display:block;">Find the thread ID in the Threads tab.</span>
      </div>
      <div class="sc-field">
        <label class="sc-label">Body</label>
        <textarea name="body" class="sc-input" rows="6" style="height:auto; resize:vertical;" required></textarea>
      </div>
      <button type="submit" class="sc-btn sc-btn--primary" style="margin-top:12px;">Post Reply</button>
    </form>
  </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
