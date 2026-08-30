<?php
/**
 * SNAPSMACK — DIRECTORY  [0.7.547]
 *
 * On a normal blog: the opt-in to be LISTED on photoblogs.fyi. Separate from
 * JOIN NETWORK (the relay) and ROLL CALL (fediverse.info). Turning it ON is
 * step-up gated (password + 2FA) because a public listing reflects on the whole
 * network; nothing appears until the hub approves it.
 *
 * On the photoblogs.fyi hub itself: the same page shows the MODERATION queue —
 * approve / hide / remove submitted listings.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the marker above.
 */
require_once 'core/auth-smack.php';
require_once 'core/fediverse.php';              // sv_handle / sv_domain
require_once 'core/photoblogs-directory.php';

$self = basename($_SERVER['SCRIPT_NAME'] ?? 'smack-directory.php');
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$dir_upsert = function (string $k, string $v) use ($pdo, &$settings) {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([$k, $v]);
    $settings[$k] = $v;
};

$is_hub = ($settings['site_mode'] ?? '') === 'fedistructure'
       && ($settings['node_role'] ?? '') === 'hub'
       && ($settings['distribution_profile'] ?? '') === 'smackcast';

$msg = '';

// --- Save topics only (metadata, no step-up) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'directory_topics') {
    $dir_upsert('photoblogs_topics', trim((string)($_POST['topics'] ?? '')));
    header('Location: ' . $self . '?msg=' . urlencode('Topics saved.'));
    exit;
}

// --- Enable listing (step-up: password + 2FA — it's a public listing) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'directory_enable') {
    require_once 'core/reauth.php';
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        $msg = 'NOT LISTED — ' . $ra['error'];
    } else {
        $dir_upsert('photoblogs_topics', trim((string)($_POST['topics'] ?? ($settings['photoblogs_topics'] ?? 'photography'))));
        $dir_upsert('photoblogs_listed', '1');
        list(, $m) = pbdir_submit($settings, 'register');
        header('Location: ' . $self . '?msg=' . urlencode($m));
        exit;
    }
}

// --- Disable listing (delist) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'directory_disable') {
    $dir_upsert('photoblogs_listed', '0');
    list(, $m) = pbdir_submit($settings, 'remove');
    header('Location: ' . $self . '?msg=' . urlencode($m));
    exit;
}

// --- Hub moderation: approve / hide / remove a listing ---
if ($is_hub && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'directory_moderate') {
    $id    = max(0, (int)($_POST['listing_id'] ?? 0));
    $state = (string)($_POST['listing_state'] ?? '');
    if ($id > 0 && in_array($state, ['active', 'hidden', 'removed', 'pending'], true)) {
        try {
            $pdo->prepare("UPDATE snap_directory_listings SET state=?, updated_at=NOW() WHERE id=?")
                ->execute([$state, $id]);
        } catch (Throwable $e) { /* table absent on a fresh hub — ignore */ }
    }
    header('Location: ' . $self . '?msg=' . urlencode('Listing updated.'));
    exit;
}

// --- Load listings for the hub moderation view ---
$dir_listings = [];
if ($is_hub) {
    try {
        $dir_listings = $pdo->query(
            "SELECT * FROM snap_directory_listings
             ORDER BY FIELD(state,'pending','active','hidden','removed'), updated_at DESC
             LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $dir_listings = []; }
}

// Relay + hub membership, keyed by host, for the On Relay / On Hub columns.
// A directory listing is a separate thing from JOINING the relay or being a
// connected hub node — these two lookups say which of those a listed blog is.
$relay_hosts = [];  // hosts with an active relay subscription (JOIN NETWORK)
$hub_hosts   = [];  // hosts registered as a node on this hub
if ($is_hub) {
    try {
        foreach ($pdo->query("SELECT actor_url FROM snap_relay_subscribers WHERE state='active'")
                     ->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $h = strtolower((string)parse_url((string)$u, PHP_URL_HOST));
            if ($h !== '') $relay_hosts[$h] = true;
        }
    } catch (Throwable $e) { /* relay tables absent = nothing joined */ }
    try {
        foreach ($pdo->query("SELECT site_url FROM snap_multisite_nodes")
                     ->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $h = strtolower((string)parse_url((string)$u, PHP_URL_HOST));
            if ($h !== '') $hub_hosts[$h] = true;
        }
    } catch (Throwable $e) { /* multisite tables absent */ }
}

$listed     = pbdir_is_listed($settings);
$cur_topics = implode(', ', pbdir_topics($settings));
$hub_host   = (string)parse_url(pbdir_hub_url($settings), PHP_URL_HOST);

$page_title = 'Directory';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>DIRECTORY</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="alert alert-warn">&gt; <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php // The hub IS the directory — it never lists itself. Opt-in form is spokes only. ?>
    <?php if (!$is_hub): ?>
    <!-- LIST ON PHOTOBLOGS.FYI -->
    <div class="box mb-20">
        <h3>LIST ON PHOTOBLOGS.FYI</h3>
        <p class="dim mb-20">
            Add this blog to the <strong><?php echo htmlspecialchars($hub_host); ?></strong> directory so people
            (and search engines) can find you by what you shoot. This is separate from JOIN NETWORK (the relay
            that carries posts between blogs) and from ROLL CALL (which lists you on fediverse.info). Your photos
            stay on your own server &mdash; the directory links back to you and stores nothing. You can delist any time.
        </p>

        <?php if ($listed): ?>
            <div class="alert alert-success">&#10003; This blog is opted in to the photoblogs.fyi directory (topics: <code><?php echo htmlspecialchars($cur_topics); ?></code>). It appears once <?php echo htmlspecialchars($hub_host); ?> approves it.</div>

            <form method="post" action="" class="mb-14">
                <input type="hidden" name="action" value="directory_topics">
                <label>TOPICS (comma-separated &mdash; the genres you're filed under)</label>
                <input type="text" name="topics" maxlength="200" value="<?php echo htmlspecialchars($cur_topics); ?>" autocomplete="off">
                <button type="submit" class="btn-smack mt-10">SAVE TOPICS</button>
            </form>

            <form method="post" action="" onsubmit="return confirm('Delist this blog from photoblogs.fyi?');">
                <input type="hidden" name="action" value="directory_disable">
                <button type="submit" class="btn-smack btn-danger">DELIST</button>
            </form>
        <?php else: ?>
            <p class="dim mb-20">
                Because a public listing reflects on the whole network, turning it on takes your password and
                2FA code &mdash; the same deliberate step as enabling federation.
            </p>
            <form method="post" action="">
                <input type="hidden" name="action" value="directory_enable">

                <label>TOPICS (comma-separated &mdash; the genres you're filed under)</label>
                <input type="text" name="topics" maxlength="200" value="<?php echo htmlspecialchars($cur_topics); ?>" autocomplete="off">

                <div class="reauth-row mt-14">
                    <div>
                        <label>PASSWORD</label>
                        <input type="password" name="reauth_password" autocomplete="current-password">
                    </div>
                    <div>
                        <label>2FA CODE</label>
                        <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="one-time-code">
                    </div>
                </div>

                <button type="submit" class="btn-smack mt-14">LIST THIS BLOG</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; // !$is_hub — opt-in form hidden on the hub ?>

    <?php if ($is_hub): ?>
    <!-- HUB MODERATION -->
    <div class="box mb-20">
        <h3>DIRECTORY LISTINGS &mdash; MODERATION</h3>
        <p class="dim mb-20">
            Blogs that asked to be listed on this hub. <strong>Pending</strong> ones are not public until you
            approve them. Public directory: <code>/directory</code>.
        </p>

        <?php if (!$dir_listings): ?>
            <p class="dim">No listings yet.</p>
        <?php else: ?>
            <table class="data-table w-100 dir-table">
                <thead>
                    <tr><th>Blog</th><th>Handle</th><th>Topics</th><th>State</th><th>On&nbsp;Relay</th><th>On&nbsp;Hub</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($dir_listings as $L): ?>
                    <?php $topics_disp = implode(', ', json_decode((string)($L['topics'] ?? '[]'), true) ?: []);
                          $lh = strtolower((string)($L['host'] ?? '')); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$L['name']); ?></strong><br>
                            <a href="<?php echo htmlspecialchars((string)$L['site_url']); ?>" target="_blank" rel="noopener nofollow"><?php echo htmlspecialchars((string)$L['host']); ?></a></td>
                        <td><code><?php echo htmlspecialchars((string)$L['handle']); ?></code></td>
                        <td><?php echo htmlspecialchars($topics_disp); ?></td>
                        <td><strong><?php echo htmlspecialchars((string)$L['state']); ?></strong></td>
                        <td class="dir-flag"><?php echo isset($relay_hosts[$lh]) ? '<span class="dir-yes">&#10003;</span>' : '<span class="dir-no">&mdash;</span>'; ?></td>
                        <td class="dir-flag"><?php echo isset($hub_hosts[$lh]) ? '<span class="dir-yes">&#10003;</span>' : '<span class="dir-no">&mdash;</span>'; ?></td>
                        <td>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="directory_moderate">
                                <input type="hidden" name="listing_id" value="<?php echo (int)$L['id']; ?>">
                                <div class="dir-mod-actions">
                                <?php if ($L['state'] !== 'active'): ?>
                                    <button type="submit" name="listing_state" value="active" class="btn-smack">APPROVE</button>
                                <?php endif; ?>
                                <?php if ($L['state'] !== 'hidden'): ?>
                                    <button type="submit" name="listing_state" value="hidden" class="btn-smack">HIDE</button>
                                <?php endif; ?>
                                    <button type="submit" name="listing_state" value="removed" class="btn-smack btn-danger" onclick="return confirm('Remove this listing?');">REMOVE</button>
                                </div>
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

<?php include 'core/admin-footer.php'; ?>

<?php // ===== SNAPSMACK EOF ===== ?>
