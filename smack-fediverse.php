<?php
/**
 * SNAPSMACK - FEDIVERSE Interactions
 *
 * The conversation side of SMACKVERSE — because federation isn't plumbing,
 * it's people. This page is where the blog talks BACK:
 *   - incoming fediverse replies (moderation queue, fediverse-only view)
 *   - live conversations with a REPLY tool — replies federate as the blog
 *     actor, MENTION the commenter, and deliver straight to their server so
 *     they get a real notification
 *   - fediverse likes (who applauded what)
 *   - current followers
 *
 * Settings/health/handle live in smack-smackverse.php (SMACKVERSE Federation).
 * This page is interaction only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/smackverse.php';

$msg = '';

$sv_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
sv_ensure_tables($pdo);
$sv_on = sv_enabled($sv_settings);

/** Best-effort @user@host from an actor URL. */
$fedi_handle = function (string $actor_url, string $fallback = ''): string {
    $host = parse_url($actor_url, PHP_URL_HOST) ?: '';
    $user = trim($fallback);
    $user = $user !== '' ? preg_replace('/[@\s].*$/', '', ltrim($user, '@'))
                         : basename(parse_url($actor_url, PHP_URL_PATH) ?: '');
    if ($user === '') return $actor_url;
    return '@' . $user . ($host !== '' ? '@' . $host : '');
};

// ── ACTIONS ──────────────────────────────────────────────────────────────────

// REPLY (POST): insert an approved local comment threaded to the fediverse
// parent, federate it (Mention + direct delivery), push the queue now.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $parent_id  = (int)($_POST['parent_id'] ?? 0);
    $reply_text = trim($_POST['reply_text'] ?? '');
    if ($parent_id > 0 && $reply_text !== '') {
        $ps = $pdo->prepare("SELECT * FROM snap_comments WHERE id = ? AND ap_source = 'fediverse' LIMIT 1");
        $ps->execute([$parent_id]);
        $parent = $ps->fetch(PDO::FETCH_ASSOC);
        if ($parent) {
            $author = trim($_SESSION['username'] ?? '') ?: ($sv_settings['site_name'] ?? 'Admin');
            $pdo->prepare(
                "INSERT INTO snap_comments
                    (img_id, comment_author, comment_text, comment_date, is_approved,
                     ap_source, ap_in_reply_to)
                 VALUES (?, ?, ?, NOW(), 1, 'local', ?)"
            )->execute([
                (int)$parent['img_id'],
                $author,
                $reply_text,
                trim($parent['ap_object_id'] ?? '') ?: null,
            ]);
            $reply_id = (int)$pdo->lastInsertId();
            if ($sv_on) {
                try {
                    sv_federate_comment($pdo, $reply_id, $sv_settings);
                    sv_process_deliveries($pdo, $sv_settings, 30);   // don't wait for cron
                    $msg = "Reply sent. Federated to your followers and delivered to "
                         . htmlspecialchars($fedi_handle($parent['ap_actor_url'] ?? '', $parent['comment_author'] ?? '')) . ".";
                } catch (Throwable $e) {
                    $msg = "Reply saved, but federation hiccuped: " . htmlspecialchars($e->getMessage());
                }
            } else {
                $msg = "Reply saved. SMACKVERSE is off, so it stays local until federation is enabled.";
            }
        }
    }
}

// Approve / terminate (GET, house style).
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] === 'approve') {
        $pdo->prepare("UPDATE snap_comments SET is_approved = 1 WHERE id = ? AND ap_source = 'fediverse'")
            ->execute([$id]);
        $msg = "Transmission authorized. It's live on the post.";
    } elseif ($_GET['action'] === 'terminate') {
        $pdo->prepare("DELETE FROM snap_comments WHERE id = ? AND ap_source = 'fediverse'")
            ->execute([$id]);
        $msg = "Transmission terminated.";
    }
}

// ── DATA ─────────────────────────────────────────────────────────────────────

$pending = $pdo->query(
    "SELECT c.*, i.img_title, i.img_file FROM snap_comments c
     LEFT JOIN snap_images i ON i.id = c.img_id
     WHERE c.ap_source = 'fediverse' AND c.is_approved = 0
     ORDER BY c.comment_date DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$live = $pdo->query(
    "SELECT c.*, i.img_title, i.img_file FROM snap_comments c
     LEFT JOIN snap_images i ON i.id = c.img_id
     WHERE c.ap_source = 'fediverse' AND c.is_approved = 1
     ORDER BY c.comment_date DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

// Local replies threaded to those conversations, keyed by parent ap_object_id.
$replies_by_parent = [];
$parent_oids = array_values(array_filter(array_map(
    fn($c) => trim($c['ap_object_id'] ?? ''), $live
)));
if ($parent_oids) {
    $ph = implode(',', array_fill(0, count($parent_oids), '?'));
    $rs = $pdo->prepare(
        "SELECT * FROM snap_comments
         WHERE ap_source = 'local' AND ap_in_reply_to IN ($ph)
         ORDER BY comment_date ASC"
    );
    $rs->execute($parent_oids);
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $replies_by_parent[$r['ap_in_reply_to']][] = $r;
    }
}

$likes = [];
try {
    $likes = $pdo->query(
        "SELECT * FROM snap_ap_likes ORDER BY created_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may lag on a fresh install */ }
$like_title_img  = $pdo->prepare("SELECT img_title FROM snap_images WHERE id = ? LIMIT 1");
$like_title_post = $pdo->prepare("SELECT title FROM snap_posts WHERE id = ? LIMIT 1");

$followers = [];
try {
    $followers = $pdo->query(
        "SELECT actor_url, followed_at FROM snap_ap_followers WHERE is_active = 1
         ORDER BY followed_at DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may lag */ }

$page_title = "Fediverse";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>FEDIVERSE</h2>
        <div class="header-actions">
            <div class="status-pill <?php echo $sv_on ? 'status-online' : 'status-offline'; ?>">
                SMACKVERSE: <?php echo $sv_on ? 'FEDERATING' : 'OFF'; ?>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="box"><p><?php echo $msg; ?></p></div>
    <?php endif; ?>

    <?php if (!$sv_on): ?>
        <div class="box">
            <p>SMACKVERSE is switched off — nothing federates in or out until you flip it on in
               <a href="smack-smackverse.php">SMACKVERSE Federation</a>. Anything below is history.</p>
        </div>
    <?php endif; ?>

    <div class="box mt-30">
        <h3>INCOMING TRANSMISSIONS<?php echo $pending ? ' (' . count($pending) . ')' : ''; ?></h3>
        <p class="skin-desc-text">Replies from the fediverse awaiting your say-so. Authorize to put one live on its post (and into the conversation below).</p>
        <?php if (!$pending): ?>
            <p>Nothing waiting.</p>
        <?php else: ?>
            <div class="recent-list">
                <?php foreach ($pending as $c): ?>
                    <div class="recent-item">
                        <div class="item-details">
                            <?php if (!empty($c['img_file'])): ?>
                                <img src="/<?php echo htmlspecialchars($c['img_file']); ?>" class="archive-thumb">
                            <?php endif; ?>
                            <div class="item-text">
                                <div class="signal-sender">
                                    <a href="<?php echo htmlspecialchars($c['ap_actor_url'] ?? '#'); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($fedi_handle($c['ap_actor_url'] ?? '', $c['comment_author'] ?? '')); ?>
                                    </a>
                                </div>
                                <div class="signal-body"><?php echo nl2br(htmlspecialchars($c['comment_text'] ?? '')); ?></div>
                                <div class="signal-meta">
                                    ON: <?php echo htmlspecialchars($c['img_title'] ?? 'UNKNOWN SOURCE'); ?>
                                    | <?php echo htmlspecialchars($c['comment_date'] ?? ''); ?>
                                </div>
                                <div class="item-actions">
                                    <a href="?action=approve&id=<?php echo (int)$c['id']; ?>" class="action-authorize">AUTHORIZE</a>
                                    <a href="?action=terminate&id=<?php echo (int)$c['id']; ?>" class="action-delete"
                                       onclick="return confirm('Terminate this transmission?');">TERMINATE</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="box mt-30">
        <h3>CONVERSATIONS</h3>
        <p class="skin-desc-text">Live fediverse comments. Reply from here — your reply federates as the blog, mentions the commenter, and lands in their notifications on their own server.</p>
        <?php if (!$live): ?>
            <p>No conversations yet. They'll show up when the fediverse starts talking.</p>
        <?php else: ?>
            <div class="recent-list">
                <?php foreach ($live as $c): ?>
                    <div class="recent-item">
                        <div class="item-details">
                            <?php if (!empty($c['img_file'])): ?>
                                <img src="/<?php echo htmlspecialchars($c['img_file']); ?>" class="archive-thumb">
                            <?php endif; ?>
                            <div class="item-text">
                                <div class="signal-sender">
                                    <a href="<?php echo htmlspecialchars($c['ap_actor_url'] ?? '#'); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($fedi_handle($c['ap_actor_url'] ?? '', $c['comment_author'] ?? '')); ?>
                                    </a>
                                </div>
                                <div class="signal-body"><?php echo nl2br(htmlspecialchars($c['comment_text'] ?? '')); ?></div>
                                <div class="signal-meta">
                                    ON: <?php echo htmlspecialchars($c['img_title'] ?? 'UNKNOWN SOURCE'); ?>
                                    | <?php echo htmlspecialchars($c['comment_date'] ?? ''); ?>
                                </div>

                                <?php foreach ($replies_by_parent[trim($c['ap_object_id'] ?? '')] ?? [] as $r): ?>
                                    <div class="signal-body" style="margin-top:8px; padding-left:14px; border-left:2px solid var(--accent, #888);">
                                        <strong><?php echo htmlspecialchars($r['comment_author'] ?? ''); ?>:</strong>
                                        <?php echo nl2br(htmlspecialchars($r['comment_text'] ?? '')); ?>
                                    </div>
                                <?php endforeach; ?>

                                <details style="margin-top:8px;">
                                    <summary class="action-authorize" style="cursor:pointer;">REPLY</summary>
                                    <form method="POST" style="margin-top:8px;">
                                        <input type="hidden" name="action" value="reply">
                                        <input type="hidden" name="parent_id" value="<?php echo (int)$c['id']; ?>">
                                        <textarea name="reply_text" rows="3" style="width:100%;" required
                                                  placeholder="Say it. This federates as the blog and notifies them."></textarea>
                                        <button type="submit" class="btn-smack" style="margin-top:6px;">SEND REPLY</button>
                                    </form>
                                </details>

                                <div class="item-actions">
                                    <a href="?action=terminate&id=<?php echo (int)$c['id']; ?>" class="action-delete"
                                       onclick="return confirm('Terminate this transmission? Any replies stay on the post.');">TERMINATE</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="dash-grid dash-grid-2 mt-30">
        <div class="box box-flex">
            <h3>FEDIVERSE APPLAUSE</h3>
            <p class="skin-desc-text">Likes from out there. They fold into the like counts on your posts.</p>
            <?php if (!$likes): ?>
                <p>No likes yet.</p>
            <?php else: ?>
                <div class="recent-list">
                    <?php foreach ($likes as $l): ?>
                        <?php
                        $t_title = '';
                        if (($l['target_type'] ?? '') === 'post') {
                            $like_title_post->execute([(int)$l['target_id']]);
                            $t_title = (string)$like_title_post->fetchColumn();
                        } else {
                            $like_title_img->execute([(int)$l['target_id']]);
                            $t_title = (string)$like_title_img->fetchColumn();
                        }
                        ?>
                        <div class="recent-item">
                            <div class="item-text">
                                <a href="<?php echo htmlspecialchars($l['actor_url']); ?>" target="_blank" rel="noopener">
                                    <?php echo htmlspecialchars($fedi_handle($l['actor_url'])); ?>
                                </a>
                                liked <strong><?php echo htmlspecialchars($t_title ?: ('#' . (int)$l['target_id'])); ?></strong>
                                <span class="signal-meta"><?php echo htmlspecialchars($l['created_at'] ?? ''); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="box box-flex">
            <h3>FOLLOWERS<?php echo $followers ? ' (' . count($followers) . ')' : ''; ?></h3>
            <p class="skin-desc-text">Accounts following this blog across the fediverse.</p>
            <?php if (!$followers): ?>
                <p>Nobody yet. Give them a reason.</p>
            <?php else: ?>
                <div class="recent-list">
                    <?php foreach ($followers as $f): ?>
                        <div class="recent-item">
                            <div class="item-text">
                                <a href="<?php echo htmlspecialchars($f['actor_url']); ?>" target="_blank" rel="noopener">
                                    <?php echo htmlspecialchars($fedi_handle($f['actor_url'])); ?>
                                </a>
                                <span class="signal-meta">since <?php echo htmlspecialchars($f['followed_at'] ?? ''); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
