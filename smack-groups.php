<?php
/**
 * SNAPSMACK - Groups management (v0.1 — foundation)
 *
 * Flickr Groups, reborn and SELF-HOSTED. A group is: members (snap_community_users),
 * a shared photo pool (the snap_collections pattern), and local discussion (the
 * snap_community_comments pattern — NOT the remote forum). This v0.1 admin page is
 * the foundation: create / list / publish / delete a group and set its policies.
 *
 * Phase 2 (planned, see _continuity/2026-08-14-groups-build-plan.md):
 *   - member join/approve UI + front-end join flow
 *   - pool add/remove/reorder (lift the engine from smack-collections.php)
 *   - discussion topics + replies (mirror process-community-comment.php)
 *   - public group pages (group.php / groups.php)
 *   - a federated Group actor (Announce fan-out) so Pixelfed/Mastodon can join
 *
 * Gated behind community_enabled (groups need community users) AND a dedicated
 * community_groups_enabled toggle (Settings → Interaction).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

// ── Self-healing schema: create the group tables if a live install lacks them.
// Mirrors database/schema/snapsmack_canonical.sql. snap_groups first (the others
// FK to it). Idempotent — CREATE TABLE IF NOT EXISTS is a no-op once present.
function snap_groups_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_groups` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `name` varchar(150) NOT NULL,
        `slug` varchar(150) NOT NULL,
        `description` text DEFAULT NULL,
        `rules` text DEFAULT NULL,
        `cover_image_id` int unsigned DEFAULT NULL,
        `privacy` enum('public','members','invite') NOT NULL DEFAULT 'public',
        `join_policy` enum('open','approval','closed') NOT NULL DEFAULT 'open',
        `pool_policy` enum('members','moderated') NOT NULL DEFAULT 'members',
        `created_by` int unsigned DEFAULT NULL,
        `member_count` int unsigned NOT NULL DEFAULT 0,
        `published` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_group_slug` (`slug`),
        KEY `idx_group_pub` (`published`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_group_members` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `group_id` int unsigned NOT NULL,
        `user_id` int unsigned NOT NULL,
        `role` enum('member','moderator','admin') NOT NULL DEFAULT 'member',
        `status` enum('active','pending','banned') NOT NULL DEFAULT 'active',
        `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_group_user` (`group_id`, `user_id`),
        KEY `idx_gm_user` (`user_id`),
        KEY `idx_gm_group_status` (`group_id`, `status`),
        CONSTRAINT `fk_gm_group` FOREIGN KEY (`group_id`) REFERENCES `snap_groups` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_group_pool` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `group_id` int unsigned NOT NULL,
        `image_id` int unsigned NOT NULL,
        `added_by` int unsigned DEFAULT NULL,
        `status` enum('live','pending','removed') NOT NULL DEFAULT 'live',
        `sort_order` int NOT NULL DEFAULT 0,
        `caption` text DEFAULT NULL,
        `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_group_image` (`group_id`, `image_id`),
        KEY `idx_gp_group` (`group_id`, `status`, `sort_order`),
        CONSTRAINT `fk_gp_group` FOREIGN KEY (`group_id`) REFERENCES `snap_groups` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_group_discussion` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `group_id` int unsigned NOT NULL,
        `thread_id` int unsigned DEFAULT NULL,
        `user_id` int unsigned DEFAULT NULL,
        `title` varchar(200) DEFAULT NULL,
        `body` text NOT NULL,
        `status` enum('visible','hidden','deleted') NOT NULL DEFAULT 'visible',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `edited_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_gd_group_thread` (`group_id`, `thread_id`, `created_at`),
        CONSTRAINT `fk_gd_group` FOREIGN KEY (`group_id`) REFERENCES `snap_groups` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try {
    snap_groups_ensure_tables($pdo);
} catch (PDOException $e) {
    error_log('Groups ensure-tables failed: ' . $e->getMessage());
}

// ── Feature gate. Groups require the community system (they are built on
// community users). A dedicated toggle lets an owner run community WITHOUT groups.
$community_on = ($settings['community_enabled'] ?? '0') === '1';
$groups_on    = ($settings['community_groups_enabled'] ?? '0') === '1';

// ── Helper: a URL-safe slug from a name, made unique against existing groups.
function snap_groups_slugify(PDO $pdo, string $name): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'group';
    $base = substr($base, 0, 140);
    $slug = $base;
    $n = 2;
    $chk = $pdo->prepare("SELECT 1 FROM snap_groups WHERE slug = ? LIMIT 1");
    while (true) {
        $chk->execute([$slug]);
        if (!$chk->fetchColumn()) return $slug;
        $slug = $base . '-' . $n++;
    }
}

// ── POST handlers (PRG: redirect with ?msg= so a refresh never re-submits) ────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $groups_on) {
    // CSRF is already enforced globally by csrf_check() in core/auth-smack.php
    // (fails closed with 403 before this handler runs), so no per-handler check.
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $out = 'A group needs a name.';
        } else {
            $slug        = snap_groups_slugify($pdo, $name);
            $description = trim((string)($_POST['description'] ?? ''));
            $privacy     = in_array($_POST['privacy'] ?? '', ['public', 'members', 'invite'], true) ? $_POST['privacy'] : 'public';
            $join_policy = in_array($_POST['join_policy'] ?? '', ['open', 'approval', 'closed'], true) ? $_POST['join_policy'] : 'open';
            $pool_policy = in_array($_POST['pool_policy'] ?? '', ['members', 'moderated'], true) ? $_POST['pool_policy'] : 'members';
            $pdo->prepare(
                "INSERT INTO snap_groups (name, slug, description, privacy, join_policy, pool_policy, published)
                 VALUES (?, ?, ?, ?, ?, ?, 0)"
            )->execute([substr($name, 0, 150), $slug, $description, $privacy, $join_policy, $pool_policy]);
            $out = 'Group "' . $name . '" created (hidden). Publish it when the pool is ready.';
        }
        header('Location: smack-groups.php?msg=' . urlencode($out));
        exit;
    }

    if ($action === 'toggle_publish') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $pdo->prepare("UPDATE snap_groups SET published = 1 - published WHERE id = ?")->execute([$gid]);
        header('Location: smack-groups.php?msg=' . urlencode('Group visibility updated.'));
        exit;
    }

    if ($action === 'delete') {
        $gid = (int)($_POST['group_id'] ?? 0);
        // Members / pool / discussion cascade via their FKs.
        $pdo->prepare("DELETE FROM snap_groups WHERE id = ?")->execute([$gid]);
        header('Location: smack-groups.php?msg=' . urlencode('Group deleted.'));
        exit;
    }
}

if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];

// ── Load the group list (with live member + pool counts).
$groups = [];
if ($groups_on) {
    $groups = $pdo->query(
        "SELECT g.*,
                (SELECT COUNT(*) FROM snap_group_members m WHERE m.group_id = g.id AND m.status = 'active') AS members,
                (SELECT COUNT(*) FROM snap_group_pool p WHERE p.group_id = g.id AND p.status = 'live')       AS pool_size
         FROM snap_groups g
         ORDER BY g.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Groups';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>
<div class="main">
    <div class="page-header">
        <h1>Groups</h1>
        <p class="dim">Flickr-style groups, self-hosted: members, a shared photo pool, and discussion. <span class="dim">(v0.1 foundation)</span></p>
    </div>

    <?php if ($msg): ?>
        <div class="box" style="border-left:3px solid var(--accent, #39ff14);"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if (!$community_on): ?>
        <div class="box">
            <h3>Community is off</h3>
            <p>Groups are built on community users, so the community system must be on first.
               Turn it on in <a href="smack-community-settings.php">Settings &rarr; Interaction</a>, then enable Groups there.</p>
        </div>
    <?php elseif (!$groups_on): ?>
        <div class="box">
            <h3>Groups are off</h3>
            <p>Enable Groups in <a href="smack-community-settings.php">Settings &rarr; Interaction</a> to start creating them.</p>
        </div>
    <?php else: ?>

        <div class="box">
            <h3>Create a group</h3>
            <form method="POST" action="smack-groups.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="create">
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>NAME</label>
                        <input type="text" name="name" maxlength="150" required placeholder="e.g. Golden Hour">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>WHO CAN SEE IT</label>
                        <select name="privacy">
                            <option value="public">Public — anyone</option>
                            <option value="members">Members only</option>
                            <option value="invite">Invite only</option>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>WHO CAN JOIN</label>
                        <select name="join_policy">
                            <option value="open">Open — join instantly</option>
                            <option value="approval">Approval required</option>
                            <option value="closed">Closed — invite only</option>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>WHO CAN ADD PHOTOS</label>
                        <select name="pool_policy">
                            <option value="members">Any member</option>
                            <option value="moderated">Members, moderator-approved</option>
                        </select>
                    </div>
                </div>
                <div class="lens-input-wrapper" style="margin-top:10px;">
                    <label>DESCRIPTION</label>
                    <textarea name="description" rows="2" placeholder="What is this group for?"></textarea>
                </div>
                <button type="submit" class="btn-smack" style="margin-top:12px;">CREATE GROUP</button>
            </form>
        </div>

        <div class="box">
            <h3><?php echo count($groups); ?> group<?php echo count($groups) === 1 ? '' : 's'; ?></h3>
            <?php if (!$groups): ?>
                <p class="dim">No groups yet. Create the first one above.</p>
            <?php else: ?>
                <table class="data-table" style="width:100%;">
                    <thead>
                        <tr><th>Name</th><th>Visibility</th><th>Join</th><th>Members</th><th>Pool</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($g['name']); ?></strong><br>
                                <span class="dim" style="font-size:.8rem;">/<?php echo htmlspecialchars($g['slug']); ?></span></td>
                            <td><?php echo htmlspecialchars($g['privacy']); ?></td>
                            <td><?php echo htmlspecialchars($g['join_policy']); ?></td>
                            <td><?php echo (int)$g['members']; ?></td>
                            <td><?php echo (int)$g['pool_size']; ?></td>
                            <td><?php echo ((int)$g['published'] === 1) ? '<span style="color:var(--accent,#39ff14);">Live</span>' : '<span class="dim">Hidden</span>'; ?></td>
                            <td style="white-space:nowrap;">
                                <form method="POST" action="smack-groups.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <input type="hidden" name="action" value="toggle_publish">
                                    <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
                                    <button type="submit" class="btn-small"><?php echo ((int)$g['published'] === 1) ? 'Hide' : 'Publish'; ?></button>
                                </form>
                                <form method="POST" action="smack-groups.php" style="display:inline;"
                                      onsubmit="return confirm('Delete this group? Members, pool, and discussion are removed. This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>
<?php
include 'core/admin-footer.php';
// ===== SNAPSMACK EOF =====
