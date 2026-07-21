<?php
/**
 * SNAPSMACK - SMACKVERSE admin : SHARED CONTROLLER (0.7.405 split)
 *
 * The federation control room was one giant page; it's now three focused pages
 * (Federation / Followers & Delivery / Push & Tools) that each include THIS file
 * for the settings load, every POST handler, and the STATE-FOR-RENDER vars.
 * Forms post to self; each handler redirects back via $sv_self so you land on the
 * page you submitted from. Pure-PHP include: emits no output (no closing tag).
 *
 * SNAPSMACK_EOF_HEADER  // ===== SNAPSMACK EOF =====
 */
if (!isset($pdo)) { require_once __DIR__ . '/auth-smack.php'; }
if (!function_exists('sv_enabled')) { require_once __DIR__ . '/smackverse.php'; }
$sv_self = basename($_SERVER['SCRIPT_NAME'] ?? 'smack-smackverse.php');

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
        header('Location: ' . $sv_self . '?msg=' . urlencode('Handle saved: @' . $handle . '@' . sv_domain($sv_settings)));
        exit;
    }
}

// --- SAVE PROFILE (federated display name / website / pronouns → actor doc) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    $sv_setting_upsert('smackverse_display_name', trim((string)($_POST['sv_display_name'] ?? '')));
    $sv_setting_upsert('smackverse_website',      trim((string)($_POST['sv_website'] ?? '')));
    $sv_setting_upsert('smackverse_pronouns',     trim((string)($_POST['sv_pronouns'] ?? '')));
    // The delivery cron's fingerprint check (sv_maybe_push_actor_update) auto-pushes
    // an Update(Actor) to followers within a tick; REFRESH PROFILE forces it now.
    header('Location: ' . $sv_self . '?msg=' . urlencode('Profile saved — display name, website and pronouns propagate to followers within a cron tick (or hit REFRESH PROFILE ON REMOTES to push now).'));
    exit;
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
        list($hok, ) = cron_ensure_webfinger_htaccess(dirname(__DIR__) . '/.htaccess');
        $sv_wf_note = $hok ? '' : ' NOTE: could not auto-add the WebFinger rule — run System Maintenance → REPAIR .htaccess.';

        // Auto-register the delivery cron so the user never touches a terminal.
        // Falls back to the checklist's manual line where the host forbids it.
        list($cok, ) = cron_register_job('*/10 * * * *',
            realpath(__DIR__ . '/cron-smackverse.php') ?: (__DIR__ . '/cron-smackverse.php'),
            '# snapsmack-smackverse');
        $sv_cron_note = $cok ? ' Delivery runs every 10 minutes.'
                             : ' NOTE: could not auto-schedule delivery on this host — see the checklist.';
        header('Location: ' . $sv_self . '?msg=' . urlencode('SMACKVERSE ENABLED — the blog now answers as @' . sv_handle($sv_settings) . '@' . sv_domain($sv_settings) . '.' . $sv_wf_note . $sv_cron_note));
        exit;
    }
}

// --- DISABLE FEDERATION (reduces access — no re-auth needed) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_smackverse') {
    $sv_setting_upsert('smackverse_enabled', '0');
    // Pull the delivery cron — no point running a sweep that self-exits.
    require_once 'core/cron-register.php';
    cron_remove_job('# snapsmack-smackverse');
    header('Location: ' . $sv_self . '?msg=' . urlencode('SMACKVERSE disabled — all federation endpoints now 404, delivery task removed. Followers are kept and resume if you re-enable.'));
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
    header('Location: ' . $sv_self . '?msg=' . urlencode($msg_pm));
    exit;
}

// REFRESH PROFILE ON REMOTES: push a signed Update(Actor) so followers' cached
// profile (display name, bio, avatar) refreshes NOW instead of waiting on the
// cron's auto-detect. AP spec: a profile edit propagates only via Update(Actor).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push_profile_update') {
    $ppn = sv_push_actor_update($pdo, $sv_settings);
    // Keep the fingerprint in step so the cron doesn't immediately re-push the
    // same state on its next run.
    sv_set_setting($pdo, $sv_settings, 'smackverse_actor_fp', sv_actor_profile_fingerprint($pdo, $sv_settings));
    $ppmsg = $ppn > 0
        ? "PROFILE UPDATE queued to {$ppn} follower inbox(es) — your name, bio and avatar refresh on the remotes as the delivery cron drains (~a minute or two)."
        : 'No active followers to update yet — remotes fetch your current profile the moment someone follows.';
    header('Location: ' . $sv_self . '?msg=' . urlencode($ppmsg));
    exit;
}

// ROLL CALL (0.7.439): fediverse.info people-directory opt-in. Saving writes the
// toggle + topics, and — because the directory reads the actor BIO — immediately
// pushes a signed Update(Actor) so the #fedi22 + topic tags land on (or leave)
// the remotes' cached profile. The listing itself is completed (or removed) by
// the admin on fediverse.info; we only ever change our own bio.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rollcall_save') {
    $rc_on = (($_POST['rollcall_enabled'] ?? '') === '1') ? '1' : '0';
    sv_set_setting($pdo, $sv_settings, 'smackverse_rollcall', $rc_on);
    sv_set_setting($pdo, $sv_settings, 'smackverse_rollcall_topics',
        substr(trim((string)($_POST['rollcall_topics'] ?? 'photography')), 0, 200));
    $rc_n = sv_push_actor_update($pdo, $sv_settings);
    // Keep the fingerprint in step so the cron doesn't immediately re-push.
    sv_set_setting($pdo, $sv_settings, 'smackverse_actor_fp', sv_actor_profile_fingerprint($pdo, $sv_settings));
    // The bio is live on OUR server the moment the settings are saved (the
    // directory fetches the actor doc fresh), so we can submit immediately.
    if ($rc_on === '1') {
        $rc_tags = '#' . implode(' #', sv_rollcall_tags($sv_settings));
        list($rc_ok, $rc_note) = sv_rollcall_submit($sv_settings, 'validate');
        $rc_msg = "ROLL CALL is ON — your fediverse bio now carries {$rc_tags}"
                . ($rc_n > 0 ? " (profile update queued to {$rc_n} follower inbox(es))" : '') . '. '
                . ($rc_ok
                    ? "Handle submitted to the directory — {$rc_note}. You're on the roll."
                    : "Auto-submit didn't take ({$rc_note}) — no harm done: paste your handle into the ADD ME box at fediverse.info/people (link below).");
    } else {
        list($rc_ok, $rc_note) = sv_rollcall_submit($sv_settings, 'remove');
        $rc_msg = 'ROLL CALL is OFF — the directory tags are out of your bio'
                . ($rc_n > 0 ? " (profile update queued to {$rc_n} follower inbox(es))" : '') . '. '
                . ($rc_ok
                    ? 'Delist request sent to the directory too.'
                    : "Delist auto-request didn't take ({$rc_note}) — their crawler drops tag-less bios on its own, or use the remove-me link at fediverse.info/people.");
    }
    header('Location: ' . $sv_self . '?msg=' . urlencode($rc_msg));
    exit;
}

// PIGGYBACK SEARCH ACCOUNT (0.7.373): store a read-only OAuth token on a trusted
// instance so the client can proxy that instance's authenticated /api/v2/search
// (account + full-text discovery). Storing a credential is step-up gated
// (password + 2FA), mirroring enable. The token is encrypted at rest.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_search_account') {
    require_once 'core/reauth.php';
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('SEARCH ACCOUNT NOT ADDED — ' . $ra['error']));
        exit;
    }
    list($sa_ok, $sa_msg) = sv_add_search_account(
        $pdo, $sv_settings,
        (string)($_POST['sa_host'] ?? ''),
        (string)($_POST['sa_username'] ?? ''),
        (string)($_POST['sa_token'] ?? '')
    );
    header('Location: ' . $sv_self . '?msg=' . urlencode($sa_msg));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_search_account') {
    sv_delete_search_account($pdo, (int)($_POST['sa_id'] ?? 0));
    header('Location: ' . $sv_self . '?msg=' . urlencode('Search account removed.'));
    exit;
}
// TEST a stored search account: decrypt its token and re-verify it live against
// the instance's verify_credentials (same one-shot check add() runs). Confirms a
// key is still valid after a rotation without re-pasting it. Read-only, no reauth.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_search_account') {
    $tr = function_exists('sv_test_search_account')
        ? sv_test_search_account($pdo, $sv_settings, (int)($_POST['sa_id'] ?? 0))
        : ['ok' => false, 'handle' => '', 'error' => 'Test unavailable.'];
    $tmsg = $tr['ok']
        ? 'Search account OK' . ($tr['handle'] !== '' ? ' — verified as @' . $tr['handle'] : '.')
        : 'Search account FAILED — ' . $tr['error'];
    header('Location: ' . $sv_self . '?msg=' . urlencode($tmsg));
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
    header('Location: ' . $sv_self . '?msg=' . urlencode($cc_msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resync') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('SMACKVERSE is off — nothing to resync.'));
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
            header('Location: ' . $sv_self . '?msg=' . urlencode('REFRESH: nothing to do — no recent posts or no active followers.'));
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
            header('Location: ' . $sv_self . '?msg=' . urlencode('SEED: nothing to do — no posts or no active followers.'));
            exit;
        }
        $msg_out = sprintf(
            'SEED: %d post(s) + %d comment(s) queued (%d deliveries) in EXACT grid order, carousels intact, captions + hashtags + comments included. The delivery cron rolls them out one at a time ~%ds apart so the remote profile rebuilds top-to-bottom. Run `php cron-smackverse.php` for an immediate paced push.',
            $rs_posts, $rs_comments, $rs_deliveries, $cadence
        );
    }
    header('Location: ' . $sv_self . '?msg=' . urlencode($msg_out));
    exit;
}

// RE-IMPRINT — bump the federation generation, retract the current Notes, and
// reseed everything under fresh ids so followers stuck in the old order re-ingest
// clean. The only lever that reaches an already-poisoned follower.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reimprint') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('SMACKVERSE is off — nothing to re-imprint.'));
        exit;
    }
    $ri_count = isset($_POST['reimprint_count']) ? max(1, min(1000, (int)$_POST['reimprint_count'])) : null;
    list($ri_ret, $ri_posts, $ri_deliv) = sv_reimprint($pdo, $sv_settings, $ri_count);
    $cadence = sv_delivery_cadence($sv_settings);
    header('Location: ' . $sv_self . '?msg=' . urlencode(sprintf(
        'RE-IMPRINT: retracted %d old Note(s) and re-seeded %d post(s) under fresh ids (%d deliveries) in your current grid order. Followers delete the stale copies and re-ingest clean — let the delivery cron drain (~%ds each). This is the fix for a follower stuck in the old order.',
        $ri_ret, $ri_posts, $ri_deliv, $cadence
    )));
    exit;
}

// Manual re-try of cron auto-registration (button appears if the auto step
// didn't take but the host actually does support cron).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_cron') {
    require_once 'core/cron-register.php';
    list($cok, $cmsg) = cron_register_job('*/10 * * * *',
        realpath(__DIR__ . '/cron-smackverse.php') ?: (__DIR__ . '/cron-smackverse.php'),
        '# snapsmack-smackverse');
    header('Location: ' . $sv_self . '?msg=' . urlencode($cmsg));
    exit;
}

// JOIN / LEAVE the SMACKVERSE network relay.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relay_join') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('Enable SMACKVERSE first.'));
    } else {
        list(, $rmsg) = sv_relay_join($pdo, $sv_settings);
        header('Location: ' . $sv_self . '?msg=' . urlencode($rmsg));
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relay_leave') {
    list(, $rmsg) = sv_relay_leave($pdo, $sv_settings);
    header('Location: ' . $sv_self . '?msg=' . urlencode($rmsg));
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
$sv_htaccess    = @file_get_contents(dirname(__DIR__) . '/.htaccess') ?: '';
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
// ===== SNAPSMACK EOF =====
