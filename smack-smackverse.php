<?php
/**
 * SNAPSMACK - SMACKVERSE Federation Admin
 *
 * The blog's fediverse control room. One blog = ONE actor — the site
 * itself, never per-user (hard design rule; see the spec). This page:
 *   - shows the blog's fediverse address (@handle@domain) with a live
 *     preview while editing
 *   - the FEDERATION SWITCH: enabling GRANTS a public attack surface →
 *     requires password + 2FA (step-up, core/reauth.php); disabling
 *     reduces access → no re-auth
 *   - readiness checklist (webfinger rewrite, signing key, delivery cron)
 *   - follower list + outbound delivery queue health
 *
 * Spec: _spec/smackverse-activitypub-spec-v0_1.md
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

// Settings snapshot (smackverse helpers read from this array).
$sv_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);

// Defensive: federation tables (canonical schema owns the real delivery).
sv_ensure_tables($pdo);

$sv_setting_upsert = function (string $key, string $val) use ($pdo, &$sv_settings) {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
        ->execute([$key, $val]);
    $sv_settings[$key] = $val;
};

// Active follower count is needed by both the handle guard and the display.
$sv_follower_count = 0;
try {
    $sv_follower_count = (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1"
    )->fetchColumn();
} catch (PDOException $e) { /* table just created — zero */ }

// --- SAVE HANDLE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_handle') {
    $raw = strtolower(trim($_POST['sv_handle'] ?? ''));
    $handle = trim(preg_replace('/[^a-z0-9_]+/', '_', $raw), '_');
    if ($handle === '' || strlen($handle) > 60) {
        $msg = 'HANDLE NOT SAVED — use 1-60 characters: letters, numbers, underscores.';
    } elseif (sv_enabled($sv_settings) && $sv_follower_count > 0 && empty($_POST['confirm_rename'])) {
        // Renaming a live actor STRANDS every follower (WebFinger identity breaks).
        $msg = 'HANDLE NOT SAVED — this blog has ' . $sv_follower_count
             . ' follower(s). Renaming strands them all. Tick the confirmation box if you really mean it.';
    } else {
        $sv_setting_upsert('smackverse_handle', $handle);
        header('Location: smack-smackverse.php?msg=' . urlencode('Handle saved: @' . $handle . '@' . sv_domain($sv_settings)));
        exit;
    }
}

// --- ENABLE FEDERATION (step-up: password + TOTP — grants a public surface) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_smackverse') {
    require_once 'core/reauth.php';
    $ra  = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    $ack = !empty($_POST['participation_ack']);
    if (!$ra['ok']) {
        $msg = 'FEDERATION NOT ENABLED — ' . $ra['error'];
    } elseif (!$ack) {
        // Informed consent: federating is joining a community, not spraying images
        // at it. No enable without acknowledging that participation is expected.
        $msg = 'FEDERATION NOT ENABLED — please read and check the participation acknowledgment. The fediverse is a community you take part in, not a place to dump images.';
    } else {
        $sv_setting_upsert('smackverse_enabled', '1');
        $sv_setting_upsert('smackverse_participation_ack', date('Y-m-d H:i:s'));
        sv_ensure_keys($pdo, $sv_settings);   // actor is followable immediately

        require_once 'core/cron-register.php';

        // Self-heal the .htaccess WebFinger rewrite so discovery works without
        // the user hand-editing Apache config. Falls back to the REPAIR tool.
        list($hok, ) = cron_ensure_webfinger_htaccess(__DIR__ . '/.htaccess');
        $sv_wf_note = $hok ? '' : ' NOTE: could not auto-add the WebFinger rule — run System Maintenance → REPAIR .htaccess.';

        // Auto-register the delivery cron so the user never touches a terminal.
        // Falls back to the checklist's manual line where the host forbids it.
        list($cok, ) = cron_register_job('*/10 * * * *',
            realpath(__DIR__ . '/cron-smackverse.php') ?: (__DIR__ . '/cron-smackverse.php'),
            '# snapsmack-smackverse');
        $sv_cron_note = $cok ? ' Delivery runs every 10 minutes.'
                             : ' NOTE: could not auto-schedule delivery on this host — see the checklist.';
        header('Location: smack-smackverse.php?msg=' . urlencode('SMACKVERSE ENABLED — the blog now answers as @' . sv_handle($sv_settings) . '@' . sv_domain($sv_settings) . '.' . $sv_wf_note . $sv_cron_note));
        exit;
    }
}

// --- DISABLE FEDERATION (reduces access — no re-auth needed) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_smackverse') {
    $sv_setting_upsert('smackverse_enabled', '0');
    // Pull the delivery cron — no point running a sweep that self-exits.
    require_once 'core/cron-register.php';
    cron_remove_job('# snapsmack-smackverse');
    header('Location: smack-smackverse.php?msg=' . urlencode('SMACKVERSE disabled — all federation endpoints now 404, delivery task removed. Followers are kept and resume if you re-enable.'));
    exit;
}

// PUSH MODE (0.7.367): AUTO = the publish sweep federates new posts as they go
// live; MANUAL = nothing auto-fires, you stage + arrange the grid, then PUSH.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_push_mode') {
    $pm = (($_POST['push_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
    $sv_setting_upsert('smackverse_push_mode', $pm);
    $msg_pm = $pm === 'manual'
        ? 'PUSH MODE = MANUAL. New posts, imports and batch uploads now WAIT — arrange the grid, then hit PUSH TO FEDIVERSE (Seed) to send them in order. Nothing auto-fires.'
        : 'PUSH MODE = AUTO. New posts federate automatically as they publish (the original behaviour).';
    header('Location: smack-smackverse.php?msg=' . urlencode($msg_pm));
    exit;
}

// RESYNC: re-federate the most recent posts to all active followers by pushing
// a signed Update per Note — same id, current render (cover + full carousel
// stack), replacing the remote's cached copy in place. Enqueued oldest-first,
// then drained at MEASURED CADENCE from a detached tail so the posts land on
// the remote one at a time, in chronological order, with no burst to shuffle
// same-second timestamps or truncate a stack.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_carousel') {
    $cc_ids   = array_filter(array_map('intval', preg_split('/[\s,]+/', trim((string)($_POST['cc_images'] ?? '')))));
    $cc_cover = (int)($_POST['cc_cover'] ?? 0);
    list($cc_ok, $cc_msg) = sv_convert_to_carousel($pdo, $sv_settings, $cc_ids, $cc_cover);
    header('Location: smack-smackverse.php?msg=' . urlencode($cc_msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resync') {
    if (!sv_enabled($sv_settings)) {
        header('Location: smack-smackverse.php?msg=' . urlencode('SMACKVERSE is off — nothing to resync.'));
        exit;
    }
    $rs_count = isset($_POST['resync_count']) ? max(1, min(500, (int)$_POST['resync_count'])) : null;
    $rs_mode  = (($_POST['resync_mode'] ?? 'create') === 'update') ? 'update' : 'create';
    // ENQUEUE ONLY — never drip inside a web request. The paced drain (with its
    // per-post sleeps) runs in the CLI delivery cron, which has no HTTP timeout;
    // draining here would hold a PHP worker for minutes and trip Cloudflare 524.
    $cadence = sv_delivery_cadence($sv_settings);
    if ($rs_mode === 'update') {
        // Refresh renders the followers ALREADY hold (same Note id, in place).
        list($rs_notes, $rs_deliveries) = sv_resync_recent($pdo, $sv_settings, $rs_count, 'update');
        if ($rs_notes === 0) {
            header('Location: smack-smackverse.php?msg=' . urlencode('REFRESH: nothing to do — no recent posts or no active followers.'));
            exit;
        }
        $msg_out = sprintf(
            'REFRESH: %d post(s) queued (%d Update deliveries). The delivery cron rolls them out oldest-first ~%ds apart. Run `php cron-smackverse.php` for an immediate paced push.',
            $rs_notes, $rs_deliveries, $cadence
        );
    } else {
        // SEED = full ordered rebuild: every post in EXACT grid order, carousels
        // intact, each post's caption + hashtags in its Note, and its approved
        // local comments queued as threaded replies right behind it.
        list($rs_posts, $rs_comments, $rs_deliveries) = sv_reseed_all($pdo, $sv_settings, $rs_count);
        if ($rs_posts === 0) {
            header('Location: smack-smackverse.php?msg=' . urlencode('SEED: nothing to do — no posts or no active followers.'));
            exit;
        }
        $msg_out = sprintf(
            'SEED: %d post(s) + %d comment(s) queued (%d deliveries) in EXACT grid order, carousels intact, captions + hashtags + comments included. The delivery cron rolls them out one at a time ~%ds apart so the remote profile rebuilds top-to-bottom. Run `php cron-smackverse.php` for an immediate paced push.',
            $rs_posts, $rs_comments, $rs_deliveries, $cadence
        );
    }
    header('Location: smack-smackverse.php?msg=' . urlencode($msg_out));
    exit;
}

// Manual re-try of cron auto-registration (button appears if the auto step
// didn't take but the host actually does support cron).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_cron') {
    require_once 'core/cron-register.php';
    list($cok, $cmsg) = cron_register_job('*/10 * * * *',
        realpath(__DIR__ . '/cron-smackverse.php') ?: (__DIR__ . '/cron-smackverse.php'),
        '# snapsmack-smackverse');
    header('Location: smack-smackverse.php?msg=' . urlencode($cmsg));
    exit;
}

// --- STATE FOR RENDER ---
$sv_on       = sv_enabled($sv_settings);
$sv_handle   = sv_handle($sv_settings);
$sv_dom      = sv_domain($sv_settings);
$sv_address  = '@' . $sv_handle . '@' . $sv_dom;
$sv_has_key  = trim($sv_settings['smackverse_public_key'] ?? '') !== '';
$sv_key_fp   = $sv_has_key ? substr(hash('sha256', $sv_settings['smackverse_public_key']), 0, 16) : '';

// Webfinger + path-style AP rewrites present in .htaccess?
$sv_htaccess    = @file_get_contents(__DIR__ . '/.htaccess') ?: '';
$sv_rewrite_ok  = strpos($sv_htaccess, 'smackverse.php?ap=webfinger') !== false;
$sv_aproute_ok  = strpos($sv_htaccess, 'smackverse.php?appath=') !== false;

// Delivery cron health — registration state + last-run freshness.
require_once 'core/cron-register.php';
list($sv_cron_supported, )  = cron_capability();
$sv_cron_registered = cron_job_registered('# snapsmack-smackverse');
$sv_cron_last = trim($sv_settings['smackverse_cron_last_run'] ?? '');
$sv_cron_ok   = $sv_cron_last !== '' && (time() - strtotime($sv_cron_last)) < 3600;

// Queue counts + followers.
$sv_q_queued = 0; $sv_q_failed = 0; $sv_followers = [];
try {
    $sv_q_queued  = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_deliveries WHERE status = 'queued'")->fetchColumn();
    $sv_q_failed  = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_deliveries WHERE status = 'failed'")->fetchColumn();
    $sv_followers = $pdo->query(
        "SELECT actor_handle, actor_url, followed_at FROM snap_ap_followers
         WHERE is_active = 1 ORDER BY followed_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* fresh install */ }

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>SMACKVERSE</h2>
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
                        <form method="post" action="smack-smackverse.php" style="display:inline; margin-left:8px;">
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

    <!-- IDENTITY -->
    <div class="box mb-20">
        <h3>FEDIVERSE HANDLE</h3>
        <p class="dim mb-20">
            The name this blog answers to. Letters, numbers, and underscores; the domain comes
            from your Site URL. <strong>Changing the handle after people follow you strands
            every follower</strong> — their apps know you by the old address.
        </p>
        <form method="post" action="smack-smackverse.php">
            <input type="hidden" name="action" value="save_handle">
            <div class="lens-input-wrapper">
                <label>HANDLE</label>
                <input type="text" id="sv-handle-input" name="sv_handle" maxlength="60"
                       value="<?php echo htmlspecialchars($sv_handle); ?>" autocomplete="off">
            </div>
            <p>Will answer as:
                <code id="sv-handle-preview" data-sv-domain="<?php echo htmlspecialchars($sv_dom); ?>"><?php echo htmlspecialchars($sv_address); ?></code>
            </p>
            <?php if ($sv_on && $sv_follower_count > 0): ?>
            <label style="display:block; margin-bottom:10px;">
                <input type="checkbox" name="confirm_rename" value="1">
                I understand this strands all <?php echo (int)$sv_follower_count; ?> follower(s).
            </label>
            <?php endif; ?>
            <button type="submit" class="btn-smack">SAVE HANDLE</button>
        </form>
    </div>

    <!-- FEDERATION SWITCH -->
    <div class="box mb-20">
        <h3>FEDERATION SWITCH</h3>
        <?php if ($sv_on): ?>
            <div class="alert alert-success">&#10003; SMACKVERSE is ON. The blog is discoverable and followable at <code><?php echo htmlspecialchars($sv_address); ?></code>.</div>
            <form method="post" action="smack-smackverse.php">
                <input type="hidden" name="action" value="disable_smackverse">
                <button type="submit" class="btn-smack">DISABLE FEDERATION</button>
            </form>
            <p class="dim" style="margin-top:10px;">
                Disabling 404s every federation endpoint immediately. Followers are kept and
                pick back up if you re-enable.
            </p>
        <?php else: ?>
            <p class="dim mb-20">
                Enabling opens public federation endpoints on this site (discovery documents
                plus a signature-verified inbox — rate-limited, and unverified requests change
                nothing). It is still a new public surface, so turning it ON requires your
                password and 2FA code. New posts start federating from the moment you enable;
                nothing already published is pushed out.
            </p>
            <form method="post" action="smack-smackverse.php">
                <input type="hidden" name="action" value="enable_smackverse">
                <div class="reauth-row">
                    <div class="lens-input-wrapper">
                        <label>PASSWORD</label>
                        <input type="password" name="reauth_password" autocomplete="off">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>2FA CODE (IF ENABLED)</label>
                        <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code">
                    </div>
                </div>
                <div class="lens-input-wrapper" style="margin-top:14px;">
                    <label style="display:flex; gap:10px; align-items:flex-start; cursor:pointer; font-weight:normal;">
                        <input type="checkbox" name="participation_ack" value="1" style="margin-top:3px; flex:0 0 auto;">
                        <span class="dim">
                            <strong>The fediverse is a community, not a broadcast channel.</strong> Federating means
                            you show up: read replies, answer the people who signal on your work, follow and boost
                            others. An account that only fires images out and never engages is spam &mdash; and
                            instances defederate it. I understand participation is expected: I'm here to take part,
                            not just push pictures.
                        </span>
                    </label>
                </div>
                <button type="submit" class="master-update-btn">ENABLE SMACKVERSE</button>
            </form>
        <?php endif; ?>
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
        <form method="post" action="smack-smackverse.php">
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
        <form method="post" action="smack-smackverse.php"
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
        <form method="post" action="smack-smackverse.php"
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

<script src="assets/js/ss-engine-smackverse-admin.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
