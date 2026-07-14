<?php
/**
 * SNAPSMACK - SMACKVERSE - Push & Tools
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

$page_title = 'SMACKVERSE - Push & Tools';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>SMACKVERSE &mdash; PUSH &amp; TOOLS</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="alert alert-warn">&gt; <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- PUSH MODE -->
    <?php $sv_pmode  = (($sv_settings['smackverse_push_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
          $sv_staged = function_exists('sv_staged_count') ? sv_staged_count($pdo) : 0; ?>
    <div class="box mb-20">
        <h3>PUSH MODE — <?php echo $sv_pmode === 'manual' ? 'MANUAL' : 'AUTO'; ?></h3>
        <?php if ($sv_staged > 0): ?>
        <p class="dim mb-20" style="font-weight:600;">
            &#9679; <?php echo (int)$sv_staged; ?> post<?php echo $sv_staged === 1 ? '' : 's'; ?>
            staged, not yet pushed (new or edited since last push).
        </p>
        <?php endif; ?>
        <p class="dim mb-20">
            <strong>AUTO:</strong> new posts federate to followers automatically as they publish.
            <br><br>
            <strong>MANUAL:</strong> nothing auto-fires. New posts, imports and batch uploads publish to the
            blog and WAIT — arrange them on the grid lighttable, then use <em>PUSH POSTS TO FOLLOWERS</em>
            (Seed) below to send them in your curated order. This is how the fediverse stays in your exact
            grid order and you never chase a wrong-order import that already went out.
        </p>
        <form method="post" action="">
            <input type="hidden" name="action" value="set_push_mode">
            <label class="dim" style="display:block; margin-bottom:12px;">
                Mode:
                <select name="push_mode" style="margin-left:6px;">
                    <option value="auto"   <?php echo $sv_pmode === 'auto'   ? 'selected' : ''; ?>>AUTO — federate on publish</option>
                    <option value="manual" <?php echo $sv_pmode === 'manual' ? 'selected' : ''; ?>>MANUAL — stage, arrange, then push</option>
                </select>
            </label>
            <button type="submit" class="btn-smack" <?php echo $sv_on ? '' : 'disabled'; ?>>SAVE PUSH MODE</button>
        </form>
    </div>

    <!-- RESYNC -->
    <div class="box mb-20">
        <h3>PUSH POSTS TO FOLLOWERS</h3>
        <p class="dim mb-20">
            Pixelfed never pulls your back catalogue — it only shows what you PUSH — so this is how your
            posts reach every follower. Two ways to push:
            <br><br>
            <strong>Seed / full rebuild (default):</strong> sends every post as a <em>Create</em>, in your
            EXACT grid order (carousels intact, caption + hashtags in each Note), and queues each post's
            approved comments as threaded replies right behind it. A follower who only got the capped
            follow-backfill (e.g. 12) gets the rest; posts they already have are harmlessly ignored. <em>This
            is how you get your whole library onto a follower, in order.</em> An Update can't do this — you
            can't update a post the remote never received (that was the old "stuck at 12" bug). For a clean
            rebuild, purge the profile on the remote first (unfollow so it drops the cached copies), then Seed.
            <br><br>
            <strong>Refresh existing renders:</strong> sends an <em>Update</em> per post — use only after a
            thumbnail/cover/frame change, to refresh posts the follower ALREADY holds (likes/replies survive).
            <br><br>
            Either way it rolls out one post at a time on the delivery cron (~10&nbsp;s apart), so a big
            number lands over a few minutes.
        </p>
        <form method="post" action=""
              onsubmit="return confirm('Push your recent posts to followers? Seed = create any they are missing; Refresh = update ones they already have.');">
            <input type="hidden" name="action" value="resync">
            <label class="dim" style="display:block; margin-bottom:12px;">
                Posts to push:
                <input type="number" name="resync_count" min="1" max="500"
                       value="<?php echo (int)($sv_settings['smackverse_backfill_count'] ?? 10); ?>"
                       style="width:90px; margin-left:6px;">
            </label>
            <label class="dim" style="display:block; margin-bottom:12px;">
                Mode:
                <select name="resync_mode" style="margin-left:6px;">
                    <option value="create">Seed / full rebuild — every post in grid order + carousels + comments (Create)</option>
                    <option value="update">Refresh existing renders (Update)</option>
                </select>
            </label>
            <button type="submit" class="btn-smack" <?php echo $sv_on ? '' : 'disabled'; ?>>PUSH</button>
        </form>
    </div>

    <!-- RE-IMPRINT -->
    <div class="box mb-20">
        <h3>RE-IMPRINT ORDER &mdash; fix stuck followers</h3>
        <p class="dim mb-20">
            The fediverse pins a post's date the first time a follower sees it &mdash; so a follower already
            holding your posts never re-sorts, no matter how many times you Seed. RE-IMPRINT is the fix: it
            stamps your current grid order into the dates, gives every post a <strong>new note id</strong>,
            retracts the old copies from your followers, and re-sends them fresh. Followers delete the stale
            posts and re-ingest them clean, in your exact grid order. <strong>Same account &mdash; your
            followers are kept.</strong> It's heavy on the delivery queue (a delete + a create per post), so it
            drains over a while. Use it after arranging the grid when a plain Seed didn't move the order.
        </p>
        <form method="post" action=""
              onsubmit="return confirm('RE-IMPRINT: give every post a new fediverse id, retract the old ones from followers, and re-send in your current grid order? Followers re-ingest clean. Safe (nothing is lost) but it sends a lot of deliveries.');">
            <input type="hidden" name="action" value="reimprint">
            <label class="dim" style="display:block; margin-bottom:12px;">
                Posts to re-imprint:
                <input type="number" name="reimprint_count" min="1" max="1000"
                       value="<?php echo (int)($sv_settings['smackverse_backfill_count'] ?? 10); ?>"
                       style="width:90px; margin-left:6px;">
            </label>
            <button type="submit" class="btn-smack" <?php echo $sv_on ? '' : 'disabled'; ?>>RE-IMPRINT ORDER</button>
        </form>
    </div>

    <!-- COMBINE INTO CAROUSEL -->
    <div class="box mb-20">
        <h3>COMBINE POSTS INTO A CAROUSEL</h3>
        <p class="dim mb-20">
            Separate posts that should be one carousel — and they already went out to followers as singles?
            Enter the image IDs in the order you want them and pick the cover. This groups them into one
            carousel post, then does the fediverse-legal cleanup: a <em>Delete</em> for each old single goes
            to your followers and a <em>Create</em> for the new carousel, paced on the delivery cron. The old
            IDs are tombstoned for good (never reused) — a deliberate one-shot, not an auto-reconcile.
            <strong>Double-check the IDs: the old single posts are deleted locally and cannot be undone.</strong>
        </p>
        <form method="post" action=""
              onsubmit="return confirm('Combine these images into ONE carousel? The old single posts are deleted locally and retracted from your followers. This cannot be undone.');">
            <input type="hidden" name="action" value="convert_carousel">
            <label class="dim" style="display:block; margin-bottom:12px;">
                Image IDs (comma-separated, in order):
                <input type="text" name="cc_images" placeholder="e.g. 412, 413, 414" style="width:240px; margin-left:6px;">
            </label>
            <label class="dim" style="display:block; margin-bottom:12px;">
                Cover image ID (blank = first):
                <input type="number" name="cc_cover" min="0" style="width:90px; margin-left:6px;">
            </label>
            <button type="submit" class="btn-smack">COMBINE INTO CAROUSEL</button>
        </form>
    </div>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
