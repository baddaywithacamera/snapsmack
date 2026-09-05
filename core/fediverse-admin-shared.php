<?php
/**
 * SNAPSMACK - FEDIVERSE admin : SHARED CONTROLLER (0.7.405 split)
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
if (!function_exists('sv_enabled')) { require_once __DIR__ . '/fediverse.php'; }
// Step-up gate (reauth_verify) is used by the SMACKCAST relay handler below,
// which runs BEFORE the two handlers that load reauth.php inline — so load it
// here or "Enable Relay" fatals with "Call to undefined function reauth_verify".
if (!function_exists('reauth_verify')) { require_once __DIR__ . '/reauth.php'; }
$sv_self = basename($_SERVER['SCRIPT_NAME'] ?? 'smack-fediverse-portal.php');

$msg = '';

// Settings snapshot (fediverse helpers read from this array).
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

$sc_is_hub_install = ($sv_settings['site_mode'] ?? '') === 'fedistructure'
    && ($sv_settings['node_role'] ?? '') === 'hub'
    && ($sv_settings['distribution_profile'] ?? '') === 'smackcast';

// The consent-directory curator is deliberately separate from the relay.
// Enabling or forcing a run can create outbound follows, so both require step-up.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sc_is_hub_install
    && in_array(($_POST['action'] ?? ''), ['curator_toggle','curator_run'], true)) {
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('CURATOR unchanged — ' . $ra['error'])); exit;
    }
    if (!function_exists('sc_curator_is_hub') || !sc_curator_is_hub($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('CURATOR blocked — this is not the photoblogs.fyi hub.')); exit;
    }
    if ($_POST['action'] === 'curator_toggle') {
        $enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
        $sv_setting_upsert('curator_directory_enabled', $enabled);
        header('Location: ' . $sv_self . '?msg=' . urlencode($enabled === '1'
            ? 'CURATOR enabled — the 2–3 day paced intake begins with the next cron run.'
            : 'CURATOR paused — existing follows are untouched.')); exit;
    }
    $sv_setting_upsert('curator_scan_completed_at', '');
    $sv_setting_upsert('curator_next_scan_at', '');
    $sv_setting_upsert('curator_next_action_at', '');
    $result = sc_curator_cron($pdo, $sv_settings);
    header('Location: ' . $sv_self . '?msg=' . urlencode('CURATOR run: ' . $result[0] . ' — ' . $result[1] . ' discovered; ' . $result[3])); exit;
}

// SMACKCAST consequential controls are step-up gated. Code installation never
// opens the relay; an operator must deliberately enable it and admit members.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sc_is_hub_install
    && in_array(($_POST['action'] ?? ''), ['smackcast_toggle','smackcast_member'], true)) {
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('SMACKCAST unchanged — ' . $ra['error'])); exit;
    }
    if ($_POST['action'] === 'smackcast_toggle') {
        $enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
        $sv_setting_upsert('smackcast_relay_enabled', $enabled);
        $sv_setting_upsert('smackcast_outbox_recovery_enabled', $enabled);
        header('Location: ' . $sv_self . '?msg=' . urlencode($enabled === '1' ? 'SMACKCAST relay enabled.' : 'SMACKCAST relay disabled.')); exit;
    }
    $id = max(0, (int)($_POST['subscriber_id'] ?? 0));
    $state = (string)($_POST['member_state'] ?? '');
    if ($id > 0 && in_array($state, ['active','blocked','left'], true)) {
        $pdo->beginTransaction();
        try {
            $rowq = $pdo->prepare("SELECT * FROM snap_relay_subscribers WHERE id=? FOR UPDATE");
            $rowq->execute([$id]);
            $member = $rowq->fetch(PDO::FETCH_ASSOC);
            if ($member) {
                $pdo->prepare("UPDATE snap_relay_subscribers SET state=? WHERE id=?")->execute([$state, $id]);
                if ($state === 'active') {
                    $follow = ['id'=>$member['follow_id'],'type'=>'Follow','actor'=>$member['actor_url'],
                        'object'=>'https://www.w3.org/ns/activitystreams#Public'];
                    $accept = ['@context'=>'https://www.w3.org/ns/activitystreams',
                        'id'=>sv_actor_url($sv_settings).'#relay-accept-'.hash('sha256',(string)$member['follow_id']),
                        'type'=>'Accept','actor'=>sv_actor_url($sv_settings),'object'=>$follow];
                    sv_queue_delivery($pdo, (string)$member['inbox_url'],
                        json_encode($accept, JSON_UNESCAPED_SLASHES), 'relay-accept:'.$id.':'.hash('sha256',(string)$member['follow_id']));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    header('Location: ' . $sv_self . '?msg=' . urlencode('SMACKCAST member state updated.')); exit;
}

// Active follower count is needed by both the handle guard and the display.
$sv_follower_count = 0;
try {
    $sv_follower_count = (int)$pdo->query(
        "SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1"
    )->fetchColumn();
} catch (PDOException $e) { /* table just created — zero */ }

// --- FREEZE A LEGACY DERIVED HANDLE INTO AN EXPLICIT ONE ---
// A blog that turned federation on before handles were mandatory answers under
// a handle auto-derived from its Site Name (e.g. "craptasti.ca" → craptasti_ca).
// That name was never chosen and silently drifts if the Site Name is edited —
// which strands every follower. The instant the owner opens the Fediverse admin,
// lock the handle followers ALREADY use in as an explicit, editable value, so the
// auto-derivation is never relied on again. Idempotent: only fires while nothing
// is saved yet, and writes the exact same string sv_handle() currently returns.
if (sv_enabled($sv_settings) && trim($sv_settings['fediverse_handle'] ?? '') === '') {
    $sv_frozen_handle = sv_handle($sv_settings);
    if ($sv_frozen_handle !== '') {
        $sv_setting_upsert('fediverse_handle', $sv_frozen_handle);
    }
}

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
        $sv_setting_upsert('fediverse_handle', $handle);
        header('Location: ' . $sv_self . '?msg=' . urlencode('Handle saved: @' . $handle . '@' . sv_domain($sv_settings)));
        exit;
    }
}

// --- SAVE PROFILE (federated display name / website / pronouns → actor doc) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    $sv_setting_upsert('fediverse_display_name', trim((string)($_POST['sv_display_name'] ?? '')));
    $sv_setting_upsert('fediverse_website',      trim((string)($_POST['sv_website'] ?? '')));
    $sv_setting_upsert('fediverse_pronouns',     trim((string)($_POST['sv_pronouns'] ?? '')));
    // The delivery cron's fingerprint check (sv_maybe_push_actor_update) auto-pushes
    // an Update(Actor) to followers within a tick; REFRESH PROFILE forces it now.
    header('Location: ' . $sv_self . '?msg=' . urlencode('Profile saved — display name, website and pronouns propagate to followers within a cron tick (or hit REFRESH PROFILE ON REMOTES to push now).'));
    exit;
}

// --- ENABLE FEDERATION (step-up: password + TOTP — grants a public surface) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_fediverse') {
    require_once 'core/reauth.php';
    $ra  = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    $ack = !empty($_POST['participation_ack']);
    if (!$ra['ok']) {
        $msg = 'FEDERATION NOT ENABLED — ' . $ra['error'];
    } elseif (!$ack) {
        // Informed consent: federating is joining a community, not spraying images
        // at it. No enable without acknowledging that participation is expected.
        $msg = 'FEDERATION NOT ENABLED — please read and check the participation acknowledgment. The fediverse is a community you take part in, not a place to dump images.';
    } elseif (trim($sv_settings['fediverse_handle'] ?? '') === '') {
        // A handle is the address the whole fediverse knows this blog by. It must
        // be a deliberate choice — never auto-minted from the blog title. Make the
        // owner pick one in the FEDIVERSE HANDLE box before the actor goes public.
        $msg = 'FEDERATION NOT ENABLED — choose your fediverse @handle first (the FEDIVERSE HANDLE box below), then enable. It is never auto-named from your blog title.';
    } else {
        $sv_setting_upsert('fediverse_enabled', '1');
        $sv_setting_upsert('fediverse_participation_ack', date('Y-m-d H:i:s'));
        sv_ensure_keys($pdo, $sv_settings);   // actor is followable immediately

        require_once 'core/cron-register.php';

        // Self-heal the .htaccess WebFinger rewrite so discovery works without
        // the user hand-editing Apache config. Falls back to the REPAIR tool.
        list($hok, ) = cron_ensure_webfinger_htaccess(dirname(__DIR__) . '/.htaccess');
        $sv_wf_note = $hok ? '' : ' NOTE: could not auto-add the WebFinger rule — run System Maintenance → REPAIR .htaccess.';

        // Auto-register the delivery cron so the user never touches a terminal.
        // Falls back to the checklist's manual line where the host forbids it.
        list($cok, ) = cron_register_job('*/10 * * * *',
            realpath(dirname(__DIR__) . '/cron-fediverse.php') ?: (dirname(__DIR__) . '/cron-fediverse.php'),
            '# snapsmack-fediverse');
        require_once __DIR__ . '/fediverse-kick.php';
        sv_kick_delivery();
        $sv_cron_note = $cok ? ' Delivery runs every 10 minutes.'
                             : ' NOTE: could not auto-schedule delivery on this host — see the checklist.';
        header('Location: ' . $sv_self . '?msg=' . urlencode('FEDIVERSE ENABLED — the blog now answers as @' . sv_handle($sv_settings) . '@' . sv_domain($sv_settings) . '.' . $sv_wf_note . $sv_cron_note));
        exit;
    }
}

// --- DISABLE FEDERATION (reduces access — no re-auth needed) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_fediverse') {
    $sv_setting_upsert('fediverse_enabled', '0');
    // Pull the delivery cron — no point running a sweep that self-exits.
    require_once 'core/cron-register.php';
    cron_remove_job('# snapsmack-fediverse');
    header('Location: ' . $sv_self . '?msg=' . urlencode('Fediverse disabled — all federation endpoints now 404, delivery task removed. Followers are kept and resume if you re-enable.'));
    exit;
}

// PUSH MODE (0.7.367): AUTO = the publish sweep federates new posts as they go
// live; MANUAL = nothing auto-fires, you stage + arrange the grid, then PUSH.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_push_mode') {
    $pm = (($_POST['push_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
    $sv_setting_upsert('fediverse_push_mode', $pm);
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
    sv_set_setting($pdo, $sv_settings, 'fediverse_actor_fp', sv_actor_profile_fingerprint($pdo, $sv_settings));
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
    sv_set_setting($pdo, $sv_settings, 'fediverse_rollcall', $rc_on);
    sv_set_setting($pdo, $sv_settings, 'fediverse_rollcall_topics',
        substr(trim((string)($_POST['rollcall_topics'] ?? 'photography')), 0, 200));
    $rc_n = sv_push_actor_update($pdo, $sv_settings);
    // Keep the fingerprint in step so the cron doesn't immediately re-push.
    sv_set_setting($pdo, $sv_settings, 'fediverse_actor_fp', sv_actor_profile_fingerprint($pdo, $sv_settings));
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
        header('Location: ' . $sv_self . '?msg=' . urlencode('Fediverse is off — nothing to resync.'));
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
            'REFRESH: %d post(s) queued (%d Update deliveries). The delivery cron rolls them out oldest-first ~%ds apart. Run `php cron-fediverse.php` for an immediate paced push.',
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
            'SEED: %d post(s) + %d comment(s) queued (%d deliveries) in EXACT grid order, carousels intact, captions + hashtags + comments included. The delivery cron rolls them out one at a time ~%ds apart so the remote profile rebuilds top-to-bottom. Run `php cron-fediverse.php` for an immediate paced push.',
            $rs_posts, $rs_comments, $rs_deliveries, $cadence
        );
    }
    header('Location: ' . $sv_self . '?msg=' . urlencode($msg_out));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push_one_follower') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('Fediverse is off — nothing was queued.'));
        exit;
    }
    $one_handle = trim((string)($_POST['follower_handle'] ?? ''));
    $one_count = max(1, min(500, (int)($_POST['follower_post_count'] ?? 12)));
    $one_mode = (($_POST['follower_push_mode'] ?? 'create') === 'update') ? 'update' : 'create';
    [$one_ok, $one_error, $one_notes, $one_queued] = sv_push_recent_to_follower(
        $pdo, $sv_settings, $one_handle, $one_count, $one_mode
    );
    $one_msg = $one_ok
        ? sprintf('SINGLE FOLLOWER: %d recent post(s), %d delivery job(s) queued only for %s.', $one_notes, $one_queued, $one_handle)
        : 'NOT QUEUED — ' . $one_error;
    header('Location: ' . $sv_self . '?msg=' . urlencode($one_msg));
    exit;
}

// RE-IMPRINT — bump the federation generation, retract the current Notes, and
// reseed everything under fresh ids so followers stuck in the old order re-ingest
// clean. The only lever that reaches an already-poisoned follower.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reimprint') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('Fediverse is off — nothing to re-imprint.'));
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

// Repair a remote ghost whose local Manage Posts row was deleted by an older
// build. Only this actor's canonical Note paths are accepted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retract_stale_note') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('Fediverse is off — the stale Note was not retracted.'));
        exit;
    }
    $note_path = ltrim(trim((string)($_POST['stale_note_path'] ?? '')), '/');
    if (!preg_match('#^ap/note/(?:p|i|l)/[1-9][0-9]*(?:~[2-9][0-9]*)?$#D', $note_path)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('NOT RETRACTED — enter a local Note path such as ap/note/p/1.'));
        exit;
    }
    $note_id = sv_base($sv_settings) . $note_path;
    $queued = sv_retract_note($pdo, $sv_settings, $note_id);
    header('Location: ' . $sv_self . '?msg=' . urlencode($queued > 0
        ? "RETRACTION QUEUED — {$note_id} will be deleted from {$queued} follower inbox(es) as the delivery queue drains."
        : 'NOT RETRACTED — there are no active follower inboxes to receive the Delete.'));
    exit;
}

// Manual re-try of cron auto-registration (button appears if the auto step
// didn't take but the host actually does support cron).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_cron') {
    require_once 'core/cron-register.php';
    list($cok, $cmsg) = cron_register_job('*/10 * * * *',
        realpath(dirname(__DIR__) . '/cron-fediverse.php') ?: (dirname(__DIR__) . '/cron-fediverse.php'),
        '# snapsmack-fediverse');
    require_once __DIR__ . '/fediverse-kick.php';
    sv_kick_delivery();
    header('Location: ' . $sv_self . '?msg=' . urlencode($cmsg));
    exit;
}

// RUN NOW: run the delivery + maintenance sweep immediately from the CMS, for
// hosts that block cron AND exec(). Also refreshes the FEDBOARD picker roster.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_jobs_now') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('Enable Fediverse first, then run the jobs.'));
        exit;
    }
    $r = sv_run_sweep($pdo, $sv_settings);
    if (!empty($r['busy'])) {
        $rjmsg = 'Fediverse jobs are already running — give it a moment and reload.';
    } else {
        $ros   = $r['roster'] ?? [];
        $rjmsg = sprintf(
            'Fediverse jobs ran: %d new post(s) swept, %d delivery(ies) sent, %d retrying. Site-picker roster: %d added, %d updated.',
            (int)($r['units'] ?? 0), (int)($r['sent'] ?? 0), (int)($r['failed'] ?? 0),
            (int)($ros['added'] ?? 0), (int)($ros['updated'] ?? 0)
        );
    }
    header('Location: ' . $sv_self . '?msg=' . urlencode($rjmsg));
    exit;
}

// JOIN / LEAVE the FEDIVERSE network relay.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relay_join') {
    if (!sv_enabled($sv_settings)) {
        header('Location: ' . $sv_self . '?msg=' . urlencode('Enable Fediverse first.'));
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

// ── SMACKCAST FLEET JOIN (hub-only) ─────────────────────────────────────────
// Review every connected multisite spoke's federation state, then join the
// ready ones to this hub's relay in one step-up-gated action. Review is
// read-only; the join re-verifies each spoke server-side at join time and
// never joins a blog with a blank or legacy-domain handle (the spoke's own
// relay-join endpoint refuses those too — belt and braces).

function sc_fleet_spokes(PDO $pdo): array {
    try {
        return $pdo->query(
            "SELECT id, site_url, site_name, api_key_local FROM snap_multisite_nodes
             WHERE role = 'spoke' AND status = 'active' AND api_key_local <> ''
             ORDER BY site_name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Same transport as ms_spoke_call (smack-multisite-comments.php), except a
 *  non-200 JSON body comes back too so the spoke's refusal REASON survives —
 *  null only when the spoke didn't answer at all. The default 6s suits the
 *  read-only status sweep; the JOIN call passes a longer window because the
 *  spoke does its own outbound relay work (actor fetch + signed deliveries)
 *  before it can answer, and 6s false-failed real joins. */
function sc_fleet_call(string $site_url, string $api_key, string $route, string $method = 'GET', array $post_data = [], int $timeout = 6): ?array {
    $url = rtrim($site_url, '/') . '/api.php?route=' . $route;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(1, $timeout),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
        ],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post_data);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** One spoke's review row: its status payload + the plain-English problems. */
function sc_fleet_status_row(array $node): array {
    $status = sc_fleet_call((string)$node['site_url'], (string)$node['api_key_local'], 'multisite/fediverse/status');
    $row = ['node' => $node, 'status' => $status, 'problems' => []];
    if (!is_array($status) || empty($status['ok'])) {
        $row['status']     = null;
        $row['problems'][] = 'No answer from the blog (down, or a build without the status endpoint)';
        return $row;
    }
    $row['problems'] = sv_fleet_join_problems($status);
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fleet_join') {
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        header('Location: ' . $sv_self . '?fleet_review=1&msg=' . urlencode('Fleet join refused — ' . $ra['error']));
        exit;
    }
    // The relay the fleet joins: this blog's own actor when this blog IS the
    // smackcast relay; otherwise the relay THIS hub points at (photoblogs.fyi by
    // default). A management hub does NOT need to be a relay member itself to push
    // its spokes onto the relay — each spoke sends its own signed Follow and is
    // re-verified at join time, so an unreachable target just fails that spoke.
    $fj_relay   = $sc_is_hub_install ? sv_actor_url($sv_settings) : sv_relay_actor_url($sv_settings);
    if (stripos($fj_relay, 'https://') !== 0) {
        header('Location: ' . $sv_self . '?fleet_review=1&msg=' . urlencode('Fleet join refused — no valid relay is configured for this hub.'));
        exit;
    }
    $fj_picked  = array_map('intval', (array)($_POST['spoke_ids'] ?? []));
    $fj_results = [];
    $fj_relay_host = strtolower((string)(parse_url($fj_relay, PHP_URL_HOST) ?: ''));
    // Pre-admission target: when this blog IS the relay the allowlist is local.
    // Otherwise the relay lives on another install and writing to THIS hub's
    // allowlist admits nobody — find the relay among this hub's connected nodes
    // so the domain can be admitted THERE.
    $fj_relay_node = null;
    if (!$sc_is_hub_install && $fj_relay_host !== '') {
        try {
            $fj_all_nodes = $pdo->query(
                "SELECT site_url, api_key_local FROM snap_multisite_nodes
                 WHERE status = 'active' AND api_key_local <> ''"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($fj_all_nodes as $fj_n) {
                if (strtolower((string)(parse_url((string)$fj_n['site_url'], PHP_URL_HOST) ?: '')) === $fj_relay_host) {
                    $fj_relay_node = $fj_n;
                    break;
                }
            }
        } catch (Throwable $e) { /* nodes table absent */ }
    }
    foreach (sc_fleet_spokes($pdo) as $fj_node) {
        if (!in_array((int)$fj_node['id'], $fj_picked, true)) continue;
        $fj_row  = sc_fleet_status_row($fj_node);   // fail-closed: re-verify at join time
        $fj_name = (string)($fj_node['site_name'] ?: $fj_node['site_url']);
        if ($fj_row['problems']) {
            $fj_results[] = ['site' => $fj_name, 'ok' => false, 'msg' => implode('; ', $fj_row['problems'])];
            continue;
        }
        // Already accepted on THIS relay: nothing to send. A stale form or a
        // double-submit must not re-join a member blog.
        $fj_st       = is_array($fj_row['status']) ? $fj_row['status'] : [];
        $fj_cur_host = strtolower((string)(parse_url((string)($fj_st['relay_url'] ?? ''), PHP_URL_HOST) ?: ''));
        if ($fj_cur_host === $fj_relay_host && !empty($fj_st['relay_joined'])
            && in_array(strtolower((string)($fj_st['join_state'] ?? '')), ['accepted', 'active'], true)) {
            $fj_results[] = ['site' => $fj_name, 'ok' => true, 'msg' => 'Already on the relay — nothing to do.'];
            continue;
        }
        // Pre-admit the spoke's domain so its Follow lands 'active' and the
        // Accept fires immediately — these are already mutually-authenticated
        // multisite spokes, and the operator just selected them behind
        // password+TOTP. Without this every join would sit pending.
        $fj_note = '';
        $fj_host = strtolower((string)(parse_url((string)$fj_node['site_url'], PHP_URL_HOST) ?: ''));
        if ($fj_host !== '') {
            if ($sc_is_hub_install) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO snap_relay_allowlist (domain, note) VALUES (?, 'fleet join')")
                        ->execute([$fj_host]);
                } catch (Throwable $e) { /* allowlist absent: admission stays pending */ }
            } elseif ($fj_relay_node !== null) {
                $fj_adm = sc_fleet_call((string)$fj_relay_node['site_url'], (string)$fj_relay_node['api_key_local'],
                                        'multisite/fediverse/relay-admit', 'POST', ['domain' => $fj_host]);
                if (!is_array($fj_adm) || empty($fj_adm['ok'])) {
                    $fj_note = ' (pre-admission on the relay not confirmed — the join may sit PENDING until approved there)';
                }
            } else {
                $fj_note = ' (the relay is not one of this hub\'s connected sites, so it could not be pre-admitted — the join may sit PENDING until approved on the relay)';
            }
        }
        $fj_r = sc_fleet_call((string)$fj_node['site_url'], (string)$fj_node['api_key_local'],
                              'multisite/fediverse/relay-join', 'POST', ['relay_url' => $fj_relay], 30);
        if (!is_array($fj_r)) {
            // The spoke went quiet past our wait. That usually means the join is
            // still finishing (its own relay fetch + signed deliveries), not that
            // it failed — ask where it stands before reporting a failure.
            $fj_after = sc_fleet_call((string)$fj_node['site_url'], (string)$fj_node['api_key_local'],
                                      'multisite/fediverse/status');
            if (is_array($fj_after) && !empty($fj_after['ok'])) {
                $fj_after_host  = strtolower((string)(parse_url((string)($fj_after['relay_url'] ?? ''), PHP_URL_HOST) ?: ''));
                $fj_after_state = strtolower((string)($fj_after['join_state'] ?? ''));
                if ($fj_after_host === $fj_relay_host && !empty($fj_after['relay_joined'])
                    && in_array($fj_after_state, ['pending', 'accepted', 'active'], true)) {
                    $fj_r = ['ok' => true, 'message' => 'Joined — the blog answered slowly, so this was confirmed by a follow-up check.'];
                }
            }
        }
        if (is_array($fj_r) && !empty($fj_r['ok'])) {
            $fj_results[] = ['site' => $fj_name, 'ok' => true, 'msg' => (string)($fj_r['message'] ?? 'Joined.') . $fj_note];
        } else {
            $fj_results[] = ['site' => $fj_name, 'ok' => false,
                             'msg' => (string)($fj_r['error'] ?? 'No answer — join NOT confirmed') . $fj_note];
        }
    }
    $_SESSION['sc_fleet_results'] = $fj_results;
    header('Location: ' . $sv_self . '?fleet_review=1&msg=' . urlencode('Fleet join finished — results below.'));
    exit;
}

// --- STATE FOR RENDER ---
$sv_on       = sv_enabled($sv_settings);
$sv_handle   = sv_handle($sv_settings);
$sv_dom      = sv_domain($sv_settings);
$sv_address  = '@' . $sv_handle . '@' . $sv_dom;
// The handle input shows the RAW stored value, never the domain-derived fallback,
// so the field is BLANK until the operator deliberately sets a handle. Leaving the
// domain pre-filled trained the wrong handle in over and over. Required to save.
$sv_handle_raw = trim((string)($sv_settings['fediverse_handle'] ?? ''));
$sv_has_key  = trim($sv_settings['fediverse_public_key'] ?? '') !== '';
$sv_key_fp   = $sv_has_key ? substr(hash('sha256', $sv_settings['fediverse_public_key']), 0, 16) : '';

// Webfinger + path-style AP rewrites present in .htaccess?
$sv_htaccess    = @file_get_contents(dirname(__DIR__) . '/.htaccess') ?: '';
$sv_rewrite_ok  = strpos($sv_htaccess, 'fediverse.php?ap=webfinger') !== false;
$sv_aproute_ok  = strpos($sv_htaccess, 'fediverse.php?appath=') !== false;

$sc_subscribers = [];
if ($sc_is_hub_install) {
    try {
        $sc_subscribers = $pdo->query("SELECT * FROM snap_relay_subscribers ORDER BY subscribed_at DESC LIMIT 200")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $sc_subscribers = []; }
}

// FLEET JOIN state for the portal: the panel shows on ANY hub with connected
// multisite spokes (that is where the spoke keys live — it need not be the
// smackcast relay itself). The review sweep (read-only, one status call per
// spoke) runs on ?fleet_review=1; join results ride the session across the
// post-join redirect and are shown once.
$sc_fleet_spoke_count  = count(sc_fleet_spokes($pdo));
$sc_fleet_relay_target = $sc_is_hub_install ? sv_actor_url($sv_settings) : sv_relay_actor_url($sv_settings);
// A management hub can push its spokes onto the relay it points at without being a
// relay member itself; only a valid https relay target is required.
$sc_fleet_join_allowed = stripos($sc_fleet_relay_target, 'https://') === 0;
$sc_fleet_review = null;
if ($sc_fleet_spoke_count > 0 && isset($_GET['fleet_review'])) {
    $sc_fleet_review = array_map('sc_fleet_status_row', sc_fleet_spokes($pdo));
}
$sc_fleet_results = $_SESSION['sc_fleet_results'] ?? [];
unset($_SESSION['sc_fleet_results']);

// Delivery cron health — registration state + last-run freshness.
require_once 'core/cron-register.php';
list($sv_cron_supported, )  = cron_capability();
$sv_cron_registered = cron_job_registered('# snapsmack-fediverse');
$sv_cron_last = trim($sv_settings['fediverse_cron_last_run'] ?? '');
$sv_cron_ok   = $sv_cron_last !== '' && (time() - strtotime($sv_cron_last)) < 3600;
// NOTE: the visitor-triggered web-cron was REMOVED in 0.7.639D — public page
// loads timed out behind it (Cloudflare 524 on SMACKONEOUT landings). Locked-
// down hosts are covered by the hub's authenticated CRONOMETER driver and the
// RUN FEDIVERSE JOBS NOW button; the status display below must never claim
// jobs run on visits.

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
