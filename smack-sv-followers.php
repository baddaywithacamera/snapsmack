<?php
/**
 * SNAPSMACK - SMACKVERSE - Followers & Delivery
 *
 * One of the three pages split out of the old monolithic SMACKVERSE page
 * (0.7.405). Shares core/smackverse-admin-shared.php for settings, POST
 * handlers and render state; this page renders only its own sections.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';
require_once 'core/smackverse.php';
require_once 'core/smackverse-admin-shared.php';

$page_title = 'SMACKVERSE - Followers & Delivery';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>SMACKVERSE &mdash; FOLLOWERS &amp; DELIVERY</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="alert alert-warn">&gt; <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- STATUS -->
    <div class="box mb-20">
        <h3>THIS BLOG IN THE FEDIVERSE</h3>
        <p class="dim mb-20">
            SMACKVERSE makes this blog ONE citizen of the fediverse: people on Mastodon,
            Pixelfed, and friends can search the address below, follow it, and get new posts
            in their timeline. The blog stays home base — federation is syndication, not
            migration. Everyone who posts here posts as the blog: one site, one voice, one address.
        </p>
        <p style="font-size:20px;"><code><?php echo htmlspecialchars($sv_address); ?></code>
            <?php if ($sv_on): ?>
                <span class="alert-success" style="padding:2px 10px; margin-left:10px;">LIVE</span>
            <?php else: ?>
                <span class="dim" style="margin-left:10px;">NOT FEDERATING</span>
            <?php endif; ?>
        </p>
        <table class="admin-table" style="max-width:640px;">
            <tr>
                <td>WebFinger rewrite (.htaccess)</td>
                <td><?php echo $sv_rewrite_ok
                    ? '&#10003; found'
                    : '&#10007; missing — add: <code>RewriteRule ^\.well-known/webfinger$ smackverse.php?ap=webfinger [QSA,L]</code>'; ?></td>
            </tr>
            <tr>
                <td>AP path routes (.htaccess)</td>
                <td><?php echo $sv_aproute_ok
                    ? '&#10003; found'
                    : '&#10007; missing — add: <code>RewriteRule ^ap/(.+)$ smackverse.php?appath=$1 [L,QSA]</code> (re-toggle SMACKVERSE or run REPAIR .htaccess)'; ?></td>
            </tr>
            <tr>
                <td>Signing key</td>
                <td><?php echo $sv_has_key
                    ? '&#10003; present (SHA-256 ' . htmlspecialchars($sv_key_fp) . '…)'
                    : 'generated automatically when you enable'; ?></td>
            </tr>
            <tr>
                <td>Delivery task</td>
                <td>
                    <?php if ($sv_cron_registered): ?>
                        &#10003; scheduled (every 10 min)<?php echo $sv_cron_last !== ''
                            ? ' — last run ' . htmlspecialchars($sv_cron_last) . ($sv_cron_ok ? '' : ' (stale)')
                            : ' — awaiting first run'; ?>
                    <?php elseif ($sv_cron_supported): ?>
                        &#9888; not scheduled yet
                        <form method="post" action="" style="display:inline; margin-left:8px;">
                            <input type="hidden" name="action" value="register_cron">
                            <button type="submit" class="btn-smack" style="padding:2px 10px;">SCHEDULE IT</button>
                        </form>
                    <?php else: ?>
                        &#9888; this host won't let SnapSmack manage cron — add manually:
                        <code>*/10 * * * * php <?php echo htmlspecialchars(__DIR__); ?>/cron-smackverse.php</code>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>Followers</td>
                <td><?php echo (int)$sv_follower_count; ?></td>
            </tr>
        </table>
    </div>

    <!-- PROFILE ON THE FEDIVERSE -->
    <div class="box mb-20">
        <h3>YOUR PROFILE ON THE FEDIVERSE</h3>
        <p class="dim mb-20">
            Remote servers (Pixelfed, Mastodon) <strong>cache</strong> your profile — display
            name, bio and avatar — from the last time they saw it. Editing your bio or avatar
            on this site does not reach them on its own; the fediverse propagates a profile
            edit with a signed <em>Update</em>. The delivery cron auto-detects a change and
            pushes it to your followers within a few minutes — use this button to refresh
            every follower <strong>now</strong>.
        </p>
        <form method="post" action="">
            <input type="hidden" name="action" value="push_profile_update">
            <button type="submit" class="btn-smack">REFRESH PROFILE ON REMOTES</button>
        </form>
    </div>

    <!-- FOLLOWERS -->
    <div class="box mb-20">
        <h3>FOLLOWERS<?php echo $sv_follower_count ? ' (' . (int)$sv_follower_count . ')' : ''; ?></h3>
        <?php if (!$sv_followers): ?>
            <p class="dim">Nobody yet. Once you're enabled, search <code><?php echo htmlspecialchars($sv_address); ?></code> from any Mastodon or Pixelfed account and hit follow.</p>
        <?php else: ?>
            <table class="admin-table">
                <tr><th>WHO</th><th>ACTOR</th><th>SINCE</th></tr>
                <?php foreach ($sv_followers as $f): ?>
                <tr>
                    <td><?php echo htmlspecialchars($f['actor_handle'] ?? ''); ?></td>
                    <td><code><?php echo htmlspecialchars($f['actor_url']); ?></code></td>
                    <td><?php echo htmlspecialchars($f['followed_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <!-- DELIVERY QUEUE -->
    <div class="box mb-20">
        <h3>DELIVERY QUEUE</h3>
        <p class="dim mb-20">
            Outbound activity (Accepts + new-post deliveries), processed by the cron with
            retry backoff. Rows disappear on success; FAILED rows gave up after 8 tries and
            are kept for inspection.
        </p>
        <table class="admin-table" style="max-width:420px;">
            <tr><td>Queued</td><td><?php echo (int)$sv_q_queued; ?></td></tr>
            <tr><td>Failed</td><td><?php echo (int)$sv_q_failed; ?></td></tr>
            <tr><td>Last cron run</td><td><?php echo $sv_cron_last !== '' ? htmlspecialchars($sv_cron_last) : 'never'; ?></td></tr>
        </table>
    </div>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
