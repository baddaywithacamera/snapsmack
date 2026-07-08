<?php
/**
 * SNAPSMACK - DIRECT MESSAGES
 *
 * The blog's single-actor DM inbox. One thread per remote fediverse account.
 * Send, reply, and unsend private messages AS the blog. Inbound direct-visibility
 * Notes are captured here (never the public timeline/comments). Fediverse DMs are
 * NOT end-to-end encrypted — the page says so, matching Mastodon's own warning.
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

// ── ACTIONS ──────────────────────────────────────────────────────────────────

// New message (POST): compose to a fresh recipient.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!$sv_on) {
        $msg = 'SMACKVERSE is off — enable federation before messaging anyone.';
    } else {
        $dm_media = trim((string)($_POST['dm_media'] ?? '')) ?: null;
        list($ok, $m) = sv_send_dm($pdo, $sv_settings,
            (string)($_POST['dm_target'] ?? ''), (string)($_POST['dm_body'] ?? ''), $dm_media);
        $msg = htmlspecialchars($m);
    }
}

// Reply within a thread (POST): threads under the latest inbound message.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $to   = (string)($_POST['thread_actor'] ?? '');
    $body = (string)($_POST['reply_body'] ?? '');
    if (!$sv_on) {
        $msg = 'SMACKVERSE is off — enable federation to reply.';
    } elseif ($to !== '' && trim($body) !== '') {
        // Thread the reply under the most recent INBOUND note from this actor.
        $ps = $pdo->prepare(
            "SELECT note_id FROM snap_ap_dms
             WHERE remote_actor_url = ? AND direction = 'in' AND is_deleted = 0
             ORDER BY created_at DESC LIMIT 1"
        );
        $ps->execute([$to]);
        $parent = $ps->fetchColumn() ?: null;
        list($ok, $m) = sv_send_dm($pdo, $sv_settings, $to, $body, null, $parent ?: null);
        $msg = htmlspecialchars($m);
    }
}

// Unsend one of OUR messages (POST): Delete/Tombstone + mark deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unsend') {
    list($ok, $m) = sv_unsend_dm($pdo, $sv_settings, (int)($_POST['dm_id'] ?? 0));
    $msg = htmlspecialchars($m);
}

// ── DATA ─────────────────────────────────────────────────────────────────────

// Which thread is open (an actor URL passed through the URL).
$open = isset($_GET['thread']) ? (string)$_GET['thread'] : '';

// Opening a thread marks its inbound messages read.
if ($open !== '') {
    try {
        $pdo->prepare("UPDATE snap_ap_dms SET is_read = 1 WHERE remote_actor_url = ? AND direction = 'in'")
            ->execute([$open]);
    } catch (Exception $e) { /* table may lag */ }
}

// Thread list — newest activity first, with unread + request flags.
$threads = [];
try {
    $threads = $pdo->query(
        "SELECT remote_actor_url,
                MAX(remote_handle) AS handle,
                MAX(created_at)    AS last_at,
                SUM(direction = 'in' AND is_read = 0 AND is_deleted = 0) AS unread,
                MAX(is_request)    AS is_request,
                SUBSTRING_INDEX(MAX(CONCAT(created_at, '\\n', COALESCE(body,''))), '\\n', -1) AS last_body
         FROM snap_ap_dms
         GROUP BY remote_actor_url
         ORDER BY last_at DESC
         LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $threads = []; }

// Messages in the open thread.
$messages = [];
$open_handle = '';
if ($open !== '') {
    $ms = $pdo->prepare(
        "SELECT * FROM snap_ap_dms WHERE remote_actor_url = ? ORDER BY created_at ASC, id ASC LIMIT 500"
    );
    $ms->execute([$open]);
    $messages = $ms->fetchAll(PDO::FETCH_ASSOC);
    foreach ($messages as $mm) { if (!empty($mm['remote_handle'])) { $open_handle = $mm['remote_handle']; break; } }
}

$page_title = "Messages";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>MESSAGES</h2>
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
            <p>SMACKVERSE is switched off — nothing sends or arrives until you flip it on in
               <a href="smack-smackverse.php">SMACKVERSE Federation</a>. Anything below is history.</p>
        </div>
    <?php endif; ?>

    <div class="box">
        <p class="skin-desc-text">Private, one-to-one messages between this blog and fediverse accounts.
           <strong>Direct messages are not encrypted</strong> — servers on both ends can read them, exactly like Mastodon. Don't send anything you wouldn't put on a postcard.</p>
    </div>

    <!-- NEW MESSAGE ------------------------------------------------------------->
    <div class="box mt-30">
        <h3>NEW MESSAGE</h3>
        <form method="POST">
            <input type="hidden" name="action" value="send">
            <div class="lens-input-wrapper">
                <label>TO</label>
                <input type="text" name="dm_target" placeholder="@user@host or an actor URL" class="full-width-select">
            </div>
            <div class="lens-input-wrapper">
                <label>MESSAGE</label>
                <textarea name="dm_body" rows="3" placeholder="Say hello…"></textarea>
            </div>
            <div class="lens-input-wrapper">
                <label>PHOTO / MEDIA URL (optional)</label>
                <input type="text" name="dm_media" placeholder="https://…/photo.jpg to attach a photo — or paste a post/profile/#hashtag link in the message" class="full-width-select">
            </div>
            <button type="submit" class="master-update-btn"<?php echo $sv_on ? '' : ' disabled'; ?>>SEND</button>
        </form>
    </div>

    <!-- THREADS ----------------------------------------------------------------->
    <div class="box mt-30">
        <h3>CONVERSATIONS<?php echo $threads ? ' (' . count($threads) . ')' : ''; ?></h3>
        <?php if (!$threads): ?>
            <p>No messages yet.</p>
        <?php else: ?>
            <div class="recent-list">
                <?php foreach ($threads as $t): ?>
                    <?php $is_open = ($t['remote_actor_url'] === $open); ?>
                    <div class="recent-item"<?php echo $is_open ? ' style="outline:1px solid var(--accent,#4fd);"' : ''; ?>>
                        <div class="item-text">
                            <div class="signal-sender">
                                <a href="?thread=<?php echo urlencode($t['remote_actor_url']); ?>">
                                    <?php echo htmlspecialchars($t['handle'] ?: $t['remote_actor_url']); ?>
                                </a>
                                <?php if ((int)$t['unread'] > 0): ?>
                                    <span class="status-pill status-online" style="margin-left:8px;"><?php echo (int)$t['unread']; ?> NEW</span>
                                <?php endif; ?>
                                <?php if ((int)$t['is_request'] === 1): ?>
                                    <span class="status-pill status-offline" style="margin-left:8px;">REQUEST</span>
                                <?php endif; ?>
                            </div>
                            <div class="signal-body"><?php echo htmlspecialchars(mb_strimwidth((string)$t['last_body'], 0, 120, '…')); ?></div>
                            <div class="signal-meta"><?php echo htmlspecialchars((string)$t['last_at']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- OPEN THREAD ------------------------------------------------------------->
    <?php if ($open !== ''): ?>
    <div class="box mt-30">
        <h3>THREAD &mdash; <?php echo htmlspecialchars($open_handle ?: $open); ?></h3>
        <?php if (!$messages): ?>
            <p>No messages in this thread.</p>
        <?php else: ?>
            <div class="recent-list">
                <?php foreach ($messages as $mm): ?>
                    <?php $out = ($mm['direction'] === 'out'); ?>
                    <div class="recent-item" style="<?php echo $out ? 'background:var(--input-bg,#111);' : ''; ?>">
                        <div class="item-text">
                            <div class="signal-sender"><?php echo $out ? 'YOU' : htmlspecialchars($mm['remote_handle'] ?: 'THEM'); ?></div>
                            <?php if (!empty($mm['is_deleted'])): ?>
                                <div class="signal-body"><em>(message unsent)</em></div>
                            <?php else: ?>
                                <div class="signal-body"><?php echo nl2br(htmlspecialchars((string)$mm['body'])); ?></div>
                                <?php if (!empty($mm['media_url'])): ?>
                                    <div><a href="<?php echo htmlspecialchars($mm['media_url']); ?>" target="_blank" rel="noopener">[attached image]</a></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="signal-meta"><?php echo htmlspecialchars((string)$mm['created_at']); ?></div>
                            <?php if ($out && empty($mm['is_deleted'])): ?>
                                <div class="item-actions">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Unsend this message?');">
                                        <input type="hidden" name="action" value="unsend">
                                        <input type="hidden" name="dm_id" value="<?php echo (int)$mm['id']; ?>">
                                        <button type="submit" class="btn-clear">UNSEND</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="mt-30">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="thread_actor" value="<?php echo htmlspecialchars($open); ?>">
            <div class="lens-input-wrapper">
                <label>REPLY</label>
                <textarea name="reply_body" rows="3" placeholder="Reply to <?php echo htmlspecialchars($open_handle ?: 'them'); ?>…"></textarea>
            </div>
            <button type="submit" class="master-update-btn"<?php echo $sv_on ? '' : ' disabled'; ?>>SEND REPLY</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
