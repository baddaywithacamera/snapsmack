<?php
/**
 * SNAPSMACK - PHOTO CHALLENGE (admin surface)
 *
 * The control panel for the photofri.day / artfri.day profile that rides on top
 * of this install's single FEDIVERSE actor (core/photochallenge.php). It is a
 * thin admin page: flip the profile on, set the tag/timezone/scoring, watch the
 * roster and the live board, crown a week into the Hall of Fame, and prune dead
 * Hall-of-Fame links. All federation still belongs to FEDIVERSE — this page
 * never touches a key or an inbox, it only sets policy and reads what landed.
 *
 * Turning the challenge ON only matters once FEDIVERSE itself is enabled: the
 * follow=join / unfollow=leave hooks fire from the federation inbox, so with
 * federation off this is inert. The page says so rather than pretending.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';      // session + CSRF autovalidate + login gate + $pdo
require_once 'core/fediverse.php';      // sv_set_setting, sv_enabled, sv_* helpers
require_once 'core/photochallenge.php';  // pc_* policy layer

$pc_admin_view = isset($pc_admin_view) && in_array($pc_admin_view, ['dashboard', 'queue', 'queued'], true)
    ? $pc_admin_view
    : 'dashboard';

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
try { pc_ensure_tables($pdo); } catch (Throwable $e) { /* fresh install */ }

$msg = '';
$msg_ok = true;   // false renders the notice as a warning instead of a success

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF already enforced in auth-smack
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'queue_prompt') {
        $res = pc_queue_prompt($pdo, $settings, [
            'prompt'  => (string)($_POST['pc_prompt'] ?? ''),
            'caption' => (string)($_POST['pc_caption'] ?? ''),
            'alt'     => (string)($_POST['pc_alt'] ?? ''),
            'friday'  => (string)($_POST['pc_friday'] ?? ''),
            'drop_at' => (string)($_POST['pc_drop_at'] ?? ''),
        ], $_FILES['pc_prompt_image'] ?? []);
        $msg_ok = !empty($res['ok']);
        $msg = ($msg_ok ? '' : 'Not queued — ') . $res['msg'];

    } elseif ($action === 'update_prompt') {
        $res = pc_update_prompt($pdo, $settings, (int)($_POST['prompt_id'] ?? 0), [
            'prompt'  => (string)($_POST['pc_prompt'] ?? ''),
            'caption' => (string)($_POST['pc_caption'] ?? ''),
            'alt'     => (string)($_POST['pc_alt'] ?? ''),
            'friday'  => (string)($_POST['pc_friday'] ?? ''),
            'drop_at' => (string)($_POST['pc_drop_at'] ?? ''),
        ]);
        $msg_ok = !empty($res['ok']);
        $msg = ($msg_ok ? '' : 'Not updated — ') . $res['msg'];

    } elseif ($action === 'cancel_prompt') {
        $res = pc_cancel_prompt($pdo, $settings, (int)($_POST['prompt_id'] ?? 0));
        $msg_ok = !empty($res['ok']);
        $msg = $res['msg'];

    } elseif ($action === 'extend_window_24') {
        $mode = (string)($settings['photochallenge_window_mode'] ?? 'weekly');
        if (!in_array($mode, ['weekly','extended'], true)) {
            $msg_ok = false;
            $msg = 'This round is already in KEEP OPEN or DAILY mode; it has no fixed closing time to extend.';
        } else {
            $old_win = pc_window($settings);
            try {
                $new_end = (new DateTimeImmutable((string)$old_win['end'], new DateTimeZone('UTC')))->modify('+24 hours');
                sv_set_setting($pdo,$settings,'photochallenge_open_since',(string)$old_win['start']);
                sv_set_setting($pdo,$settings,'photochallenge_open_week_key',(string)$old_win['week_key']);
                sv_set_setting($pdo,$settings,'photochallenge_extended_until',$new_end->format('Y-m-d H:i:s'));
                sv_set_setting($pdo,$settings,'photochallenge_window_mode','extended');
                $msg = 'Current round extended 24 hours. New closing time: ' . $new_end->format('M j, Y H:i') . ' UTC.';
            } catch (Throwable $e) {
                $msg_ok = false;
                $msg = 'The current round could not be extended.';
            }
        }

    } elseif ($action === 'save_settings') {
        $enabled = isset($_POST['pc_enabled']) ? '1' : '0';
        $tag = ltrim(strtolower(trim((string)($_POST['pc_tag'] ?? 'photofri'))), '#');
        $tag = preg_replace('/[^a-z0-9_]/', '', $tag);
        if ($tag === '') $tag = 'photofri';
        $tz  = trim((string)($_POST['pc_tz'] ?? ''));
        if ($tz !== '') {
            try { new DateTimeZone($tz); }
            catch (Throwable $e) { $tz = ''; $msg = 'Unknown timezone — left blank (server default is used).'; }
        }
        $bw = (string)max(0, (int)($_POST['pc_boost_weight'] ?? 1));
        $wm = (string)($_POST['pc_window_mode'] ?? 'weekly');
        if (!in_array($wm, ['weekly','daily','open','extended'], true)) $wm = 'weekly';
        $test_mode  = isset($_POST['pc_test_mode']) ? '1' : '0';
        $feed_enabled = isset($_POST['pc_feed_enabled']) ? '1' : '0';
        $feed_layout = (($_POST['pc_feed_layout'] ?? 'three') === 'masonry') ? 'masonry' : 'three';
        $test_allow = trim((string)($_POST['pc_test_allow'] ?? ''));
        sv_set_setting($pdo, $settings, 'photochallenge_tag', $tag);
        sv_set_setting($pdo, $settings, 'photochallenge_tz', $tz);
        sv_set_setting($pdo, $settings, 'photochallenge_boost_weight', $bw);
        if (in_array($wm,['open','extended'],true) && !in_array(($settings['photochallenge_window_mode'] ?? 'weekly'),['open','extended'],true)) {
            $old_win = pc_window($settings);
            sv_set_setting($pdo,$settings,'photochallenge_open_since',(string)$old_win['start']);
            sv_set_setting($pdo,$settings,'photochallenge_open_week_key',(string)$old_win['week_key']);
        }
        if ($wm === 'extended') {
            $raw_until=trim((string)($_POST['pc_extended_until'] ?? ''));
            try { $until=(new DateTimeImmutable($raw_until,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')); }
            catch (Throwable $e) { $until=false; }
            if (!$until || $until <= new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
                $wm='weekly'; $msg='Extension not saved — choose a future closing date and time.'; $msg_ok=false;
            } else sv_set_setting($pdo,$settings,'photochallenge_extended_until',$until->format('Y-m-d H:i:s'));
        }
        sv_set_setting($pdo, $settings, 'photochallenge_window_mode', $wm);
        sv_set_setting($pdo, $settings, 'photochallenge_test_allow', $test_allow);
        sv_set_setting($pdo, $settings, 'photochallenge_test_mode', $test_mode);
        sv_set_setting($pdo, $settings, 'photochallenge_feed_enabled', $feed_enabled);
        sv_set_setting($pdo, $settings, 'photochallenge_feed_layout', $feed_layout);
        pc_sync_feed_menu($pdo, $settings, $feed_enabled === '1');
        sv_set_setting($pdo, $settings, 'photochallenge_enabled', $enabled);   // flip last
        if ($msg === '') $msg = $enabled === '1' ? 'Photo challenge ON. Settings saved.' : 'Settings saved (challenge OFF).';
        if ($test_mode === '1') $msg .= ' TESTING WHITELIST is ON — only listed handles qualify, and boosts go only to those whitelisted accounts, never your real followers.';

    } elseif ($action === 'crown_week') {
        $n = max(1, min(10, (int)($_POST['pc_places'] ?? 3)));
        $placed = pc_finalize_week($pdo, $settings, null, $n);
        $msg = $placed > 0
            ? "Crowned {$placed} place(s) for the current window into the Hall of Fame."
            : 'No rankable entries in this window — nothing crowned.';

    } elseif ($action === 'recover_entries') {
        $r=pc_recover_tagged_entries($pdo,$settings,40);
        $msg="Recovery checked {$r['found']} tagged post(s) across {$r['actors']} participant outbox(es): {$r['recovered']} admitted and queued, {$r['already']} already present, {$r['failed']} logged for review, {$r['outside']} outside this window."
            . ($r['scan_errors'] ? " {$r['scan_errors']} participant account(s) could not be read and are shown as a scan warning." : '');

    } elseif ($action === 'hof_toggle') {
        pc_hof_set_active($pdo, (int)($_POST['hof_id'] ?? 0), (string)($_POST['to'] ?? '') === '1');
        $msg = 'Hall of Fame updated.';

    } elseif ($action === 'horsconcours') {
        pc_set_horsconcours($pdo, (string)($_POST['actor_url'] ?? ''), (string)($_POST['to'] ?? '') === '1');
        $msg = 'Participant updated.';
    } elseif ($action === 'participant_state') {
        require_once 'core/reauth.php';
        $ra = reauth_verify($pdo,(string)($_POST['reauth_password'] ?? ''),(string)($_POST['reauth_totp'] ?? ''));
        if (!$ra['ok']) $msg = 'Participant unchanged — ' . $ra['error'];
        else {
            pc_set_participant_state($pdo,$settings,(string)($_POST['actor_url'] ?? ''),(string)($_POST['state'] ?? 'left'));
            $msg = 'Participant state updated.';
        }
    } elseif ($action === 'block_domain') {
        require_once 'core/reauth.php';
        $ra = reauth_verify($pdo,(string)($_POST['reauth_password'] ?? ''),(string)($_POST['reauth_totp'] ?? ''));
        if (!$ra['ok']) $msg = 'Domain unchanged — ' . $ra['error'];
        else {
            pc_block($pdo,$settings,'domain',(string)($_POST['domain'] ?? ''),(string)($_POST['reason'] ?? ''));
            $msg = 'Domain blocked and matching participants withdrawn.';
        }
    } elseif ($action === 'rescan_participants') {
        // Recover entries missed during an inbound outage — pull each participant's
        // recent posts and run the live admit+boost path. No one has to re-post.
        $r = pc_rescan_participants($pdo, $settings);
        $msg = "Rescan done: crawled {$r['actors']} participant(s), examined {$r['posts']} recent post(s), "
             . "recovered {$r['recovered']} qualifying entr(y/ies)"
             . ($r['errors'] > 0 ? " ({$r['errors']} unreachable — will heal as their servers retry)" : '')
             . '. Re-run any time; it never double-counts.';
    }

    // Re-read settings so the render below reflects the write.
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
}

$pc_on    = pc_enabled($settings);
$fed_on   = sv_enabled($settings);
$win      = pc_window($settings);
$test_on  = (string)($settings['photochallenge_test_mode'] ?? '0') === '1';
$test_dry = 0;
if ($test_on) {
    try {
        $tq = $pdo->prepare("SELECT COUNT(*) FROM pc_admissions WHERE week_key=? AND status='active' AND boost_state='test'");
        $tq->execute([$win['week_key']]);
        $test_dry = (int)$tq->fetchColumn();
    } catch (Throwable $e) { $test_dry = 0; }
}
$counts   = pc_participant_counts($pdo);
$roster   = pc_participants($pdo, 300);
$ranked   = $pc_on ? pc_board_ranked($pdo, $settings, $win, 60) : [];
$hof      = pc_hof_list($pdo, 100);
$entry_failures = pc_entry_failures($pdo, 50);
$entry_dm_drafts = pc_failed_entry_dm_drafts($pdo, $settings, 25);
$recovery_results = pc_latest_recovery_results($pdo, 100);
$board_url = rtrim(sv_base($settings), '/') . '/photochallenge-board.php';
$hof_url   = rtrim(sv_base($settings), '/') . '/photochallenge-hof.php';
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// --- SCHEDULE A PROMPT: prefill the next Photo-Friday + its window-open time ---
$pc_prefix = pc_tag_prefix($settings);
$_now_utc  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$_add_days = (5 - (int)$_now_utc->format('N') + 7) % 7;          // 0..6 days to the next Friday
$next_friday = $_now_utc->modify("+{$_add_days} days")->format('Y-m-d');
$_def_win  = pc_window_for_friday($next_friday);
$def_drop_hint = $_def_win ? $_def_win['start'] . ' UTC' : '';   // shown as the default drop time
$def_drop_value = $_def_win ? str_replace(' ', 'T', substr((string)$_def_win['start'], 0, 16)) : '';
$window_start_value = $def_drop_value;
$prompts   = pc_prompts_list($pdo, 40);
$queued_prompts = array_values(array_filter($prompts, static fn(array $p): bool => ($p['status'] ?? '') === 'queued'));
$edit_prompt = null;
if ($pc_admin_view === 'queue' && (int)($_GET['edit'] ?? 0) > 0) {
    $edit_prompt = pc_prompt_editable($pdo, (int)$_GET['edit']);
    if (!$edit_prompt && $msg === '') {
        $msg_ok = false;
        $msg = 'That queued prompt is no longer editable.';
    }
}
if ($edit_prompt) {
    $next_friday = (string)$edit_prompt['friday'];
    $_def_win = pc_window_for_friday($next_friday);
    $def_drop_value = str_replace(' ', 'T', substr((string)$edit_prompt['drop_at'], 0, 16));
    $def_drop_hint = (string)$edit_prompt['drop_at'] . ' UTC';
    $window_start_value = $_def_win ? str_replace(' ', 'T', substr((string)$_def_win['start'], 0, 16)) : '';
}

$pc_page_titles = [
    'dashboard' => 'PHOTO CHALLENGE',
    'queue'     => 'QUEUE CONTEST POST',
    'queued'    => 'QUEUED CONTEST POSTS',
];
$page_title = $pc_page_titles[$pc_admin_view];
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2><?php echo $esc($pc_page_titles[$pc_admin_view]); ?> &mdash; PHOTOFRI.DAY</h2>
    </div>

    <?php if ($msg): ?>
        <div class="alert <?php echo $msg_ok ? 'alert-success' : 'alert-warn'; ?>">&gt; <?php echo $esc($msg); ?></div>
    <?php endif; ?>

    <?php if (!$fed_on): ?>
        <div class="alert alert-warn">
            &gt; Fediverse federation is <strong>OFF</strong>. The challenge can be configured here, but
            follow-to-join and the board only come alive once you enable federation on the
            <a href="smack-fediverse-portal.php">FEDIVERSE &rarr; FEDERATION</a> page.
        </div>
    <?php endif; ?>

    <?php if ($pc_admin_view === 'dashboard'): ?>
    <!-- SWITCH + SETTINGS -->
    <div class="box mb-20">
        <h3>CHALLENGE SWITCH</h3>
        <p class="dim mb-20">
            When ON, every fediverse account that <strong>follows this blog is entered as a participant</strong>
            and followed back, so their <code>#<?php echo $esc(pc_tag($settings)); ?></code> photos flow onto the
            board; unfollowing leaves. No participant image is ever stored &mdash; the board only points home to
            the origin post. Turning this on does not open any new public surface (federation already did); it
            just changes what this blog's actor does with a follow.
        </p>
        <form method="post" action="">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_settings">

            <label style="display:flex; gap:10px; align-items:flex-start; cursor:pointer; margin-bottom:18px;">
                <input type="checkbox" name="pc_enabled" value="1" <?php echo $pc_on ? 'checked' : ''; ?> style="margin-top:3px; flex:0 0 auto;">
                <span><strong>RUN THE PHOTO CHALLENGE ON THIS BLOG</strong>
                    <span class="dim">(follow = join, unfollow = leave)</span></span>
            </label>

            <div class="lens-input-wrapper">
                <label>CHALLENGE HASHTAG</label>
                <input type="text" name="pc_tag" maxlength="60"
                       value="<?php echo $esc(pc_tag($settings)); ?>" autocomplete="off">
                <p class="dim">Entries must carry <code>#<?php echo $esc(pc_tag($settings)); ?></code>. Letters, numbers, underscore; no leading #.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>QUALIFYING WINDOW</label>
                <?php $pc_wm = in_array(($settings['photochallenge_window_mode'] ?? 'weekly'), ['weekly','daily','open','extended'], true) ? $settings['photochallenge_window_mode'] : 'weekly';
                $pc_until=(string)($settings['photochallenge_extended_until'] ?? '');
                if ($pc_until==='') { $base=pc_window(array_merge($settings,['photochallenge_window_mode'=>'weekly'])); $pc_until=(new DateTimeImmutable($base['end'],new DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d\\TH:i'); }
                else $pc_until=str_replace(' ','T',substr($pc_until,0,16)); ?>
                <select name="pc_window_mode">
                    <option value="weekly" <?php echo $pc_wm === 'weekly' ? 'selected' : ''; ?>>WEEKLY &mdash; Photo-Friday (Thu 10:00 &rarr; Sat 12:00 UTC)</option>
                    <option value="daily"  <?php echo $pc_wm === 'daily'  ? 'selected' : ''; ?>>DAILY (TEST) &mdash; rolling 24h, always open</option>
                    <option value="open" <?php echo $pc_wm === 'open' ? 'selected' : ''; ?>>KEEP OPEN &mdash; accept entries until I close it</option>
                    <option value="extended" <?php echo $pc_wm === 'extended' ? 'selected' : ''; ?>>EXTEND UNTIL &mdash; close automatically</option>
                </select>
                <label for="pc_extended_until">EXTENDED CLOSING TIME (UTC)</label>
                <input type="datetime-local" name="pc_extended_until" id="pc_extended_until" value="<?php echo $esc($pc_until); ?>">
                <p class="dim"><strong>Weekly</strong> is the real Photo-Friday cadence. <strong>Daily</strong> is a test/demo
                    mode &mdash; a rolling 24-hour window that is always open, so entries qualify any day. Switch back to
                    weekly before launch. <strong>Keep open</strong> keeps this same round open until you change this setting.</p>
            </div>

            <div class="lens-input-wrapper">
                <label class="pc-inline-check">
                    <input type="checkbox" name="pc_feed_enabled" value="1" <?php echo pc_feed_enabled($settings) ? 'checked' : ''; ?>>
                    <span><strong>ENABLE PUBLIC FEED PAGE</strong></span>
                </label>
                <p class="dim">Publishes the challenge feed at <code>/board</code>. Menu Manager adds a built-in
                    <strong>FEED</strong> item to the public navigation; move it there if you want a different position.</p>
                <label for="pc_feed_layout">FEED LAYOUT</label>
                <?php $pc_feed_layout = (($settings['photochallenge_feed_layout'] ?? 'three') === 'masonry') ? 'masonry' : 'three'; ?>
                <select name="pc_feed_layout" id="pc_feed_layout">
                    <option value="three" <?php echo $pc_feed_layout === 'three' ? 'selected' : ''; ?>>THREE ACROSS</option>
                    <option value="masonry" <?php echo $pc_feed_layout === 'masonry' ? 'selected' : ''; ?>>MASONRY</option>
                </select>
            </div>

            <div class="lens-input-wrapper">
                <label>DISPLAY TIMEZONE</label>
                <input type="text" name="pc_tz" maxlength="64"
                       value="<?php echo $esc((string)($settings['photochallenge_tz'] ?? '')); ?>"
                       placeholder="e.g. America/Edmonton — blank = server default" autocomplete="off">
                <p class="dim">Used for admin display only. Qualification always uses the global 50-hour window:
                    Thursday 10:00 UTC through Saturday 12:00 UTC.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>BOOST WEIGHT (SCORING)</label>
                <input type="number" name="pc_boost_weight" min="0" max="20" step="1"
                       value="<?php echo (int)pc_boost_weight($settings); ?>" style="width:90px;">
                <p class="dim">Score = likes + boosts &times; this weight. Set 0 to rank on likes alone.</p>
            </div>

            <?php
                $pc_test_on = (string)($settings['photochallenge_test_mode'] ?? '0') === '1';
                $pc_test_allow = (string)($settings['photochallenge_test_allow'] ?? '');
            ?>
            <div class="lens-input-wrapper">
                <label>TESTING WHITELIST</label>
                <label class="pc-inline-check">
                    <input type="checkbox" name="pc_test_mode" value="1" <?php echo $pc_test_on ? 'checked' : ''; ?>>
                    <span><strong>ONLY BOOST &amp; SCORE THE HANDLES BELOW</strong> (test mode)</span>
                </label>
                <textarea name="pc_test_allow" rows="4" spellcheck="false" autocomplete="off"
                          placeholder="One handle per line, e.g. @sean@photofri.day"><?php echo $esc($pc_test_allow); ?></textarea>
                <p class="dim">While this is ON, only <code>#<?php echo $esc(pc_tag($settings)); ?></code> photos
                    from the listed authors qualify, and each boost is <strong>sent only to those whitelisted
                    accounts</strong> &mdash; never Public, never your real followers. So you see the boost land on
                    your test account while everyone else sees nothing. The test account must
                    <strong>follow this blog</strong> to receive the boost. Everyone else's entries are ignored. Turn
                    it OFF to go back to real, everyone-qualifying operation with boosts to all followers. A line
                    matches on the full <code>user@host</code> handle, the bare username, the domain, or any part of
                    the actor URL. ON with an empty list admits nobody.</p>
            </div>

            <button type="submit" class="master-update-btn">SAVE CHALLENGE SETTINGS</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($pc_admin_view === 'queue' || $pc_admin_view === 'queued'): ?>
    <!-- SCHEDULE A PROMPT -->
    <div class="box mb-20 pc-schedule" id="queue-contest-post">
        <?php if ($pc_admin_view === 'queue'): ?>
        <h3>SCHEDULE A PROMPT</h3>
        <p class="dim mb-20">
            Enter the prompt word, pick the Photo-Friday, and upload the card. The tool builds the hashtag,
            files the card as a hidden draft, and drops it automatically at the time below &mdash; publishing the
            card to the fediverse and switching the qualifying hashtag to that week's tag. One prompt per Friday.
        </p>
        <form method="post" action="" enctype="multipart/form-data" data-pc-prefix="<?php echo $esc($pc_prefix); ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $edit_prompt ? 'update_prompt' : 'queue_prompt'; ?>">
            <?php if ($edit_prompt): ?><input type="hidden" name="prompt_id" value="<?php echo (int)$edit_prompt['id']; ?>"><?php endif; ?>

            <div class="lens-input-wrapper">
                <label>PROMPT <span class="dim">(one word)</span></label>
                <input type="text" name="pc_prompt" id="pc_prompt" maxlength="60" required
                       autocomplete="off" placeholder="Belonging" value="<?php echo $esc((string)($edit_prompt['prompt'] ?? '')); ?>">
                <p class="dim">Hashtag: <span class="pc-hash-preview" id="pc_hash_preview">#<?php echo $esc($pc_prefix); ?>&hellip;</span>
                    &mdash; built for you from the word above.</p>
            </div>

            <fieldset class="pc-date-plan">
                <legend>DATES &mdash; THESE CONTROL DIFFERENT THINGS</legend>
                <div class="pc-date-plan__item">
                    <label for="pc_drop_at"><strong>1. PROMPT POST</strong> &mdash; CHOOSE WHEN THE CARD PUBLISHES (UTC)</label>
                    <input type="datetime-local" name="pc_drop_at" id="pc_drop_at" value="<?php echo $esc($def_drop_value); ?>">
                    <p class="dim">Set this date. The prompt card publishes then, and the boosting window below is filled in
                        automatically to <strong>exactly one week later</strong>. Times are UTC. Default:
                        <span id="pc_drop_hint"><?php echo $esc($def_drop_hint); ?></span>.</p>
                </div>
                <div class="pc-date-plan__item">
                    <label for="pc_window_start"><strong>2. BOOSTING WINDOW STARTS AT</strong> &mdash; ONE WEEK AFTER THE PROMPT (UTC)</label>
                    <input type="datetime-local" id="pc_window_start" value="<?php echo $esc($window_start_value); ?>" readonly required>
                    <input type="hidden" name="pc_friday" id="pc_friday" value="<?php echo $esc($next_friday); ?>">
                    <p class="dim" id="pc_friday_hint">Filled in automatically &mdash; exactly one week after the prompt above.
                        Tagged participant posts may be boosted from this <strong>Thursday 10:00 UTC</strong> until
                        <strong>Saturday 12:00 UTC</strong>; the contest Friday is stored automatically.</p>
                </div>
            </fieldset>

            <div class="lens-input-wrapper">
                <label>CARD IMAGE</label>
                <?php if ($edit_prompt): ?>
                    <div class="pc-file-picker">
                        <a class="pc-file-picker__button" href="smack-swap.php?id=<?php echo (int)$edit_prompt['image_id']; ?>">REPLACE IMAGE</a>
                        <span class="pc-file-picker__name"><?php echo $esc(basename((string)($edit_prompt['img_file'] ?? 'Current prompt card'))); ?></span>
                    </div>
                <?php else: ?>
                <div class="pc-file-picker">
                    <label class="pc-file-picker__button" for="pc_prompt_image">CHOOSE FILE</label>
                    <span class="pc-file-picker__name" id="pc_prompt_image_name">No file chosen</span>
                    <input type="file" name="pc_prompt_image" id="pc_prompt_image"
                           class="file-input-hidden" accept="image/jpeg,image/png,image/webp,image/gif" required>
                </div>
                <?php endif; ?>
                <p class="dim">The prompt card people see when it drops. JPG, PNG, WEBP or GIF.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>CAPTION <span class="dim">(optional additional information)</span></label>
                <textarea name="pc_caption" id="pc_caption" rows="5" maxlength="5000"
                          placeholder="Add context, instructions, credit, or anything else that should appear with the prompt card."><?php echo $esc((string)($edit_prompt['caption'] ?? '')); ?></textarea>
                <p class="dim">Published with the card. The prompt hashtag and participation link are added automatically.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>ALT TEXT <span class="dim">(accessibility description)</span></label>
                <input type="text" name="pc_alt" id="pc_alt" maxlength="500"
                       placeholder="Describe what is visible in the prompt card." value="<?php echo $esc((string)($edit_prompt['alt'] ?? '')); ?>">
            </div>

            <button type="submit" class="master-update-btn"><?php echo $edit_prompt ? 'SAVE QUEUED POST' : 'QUEUE PROMPT'; ?></button>
        </form>
        <?php endif; ?>

        <?php if ($pc_admin_view === 'queued'): ?>
        <section id="queued-contest-posts" aria-labelledby="queued-contest-posts-title">
            <h3 id="queued-contest-posts-title">QUEUED POSTS</h3>
        <?php if ($queued_prompts): ?>
            <table class="pc-sched-list dim">
                <thead>
                    <tr><th>Photo-Friday</th><th>Prompt</th><th>Hashtag</th><th>Drops (UTC)</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($queued_prompts as $p):
                        $st = (string)$p['status'];
                        $st_label = $st === 'queued' ? 'QUEUED' : ($st === 'live' ? 'LIVE' : 'DONE'); ?>
                        <tr>
                            <td><?php echo $esc($p['friday']); ?></td>
                            <td><strong><?php echo $esc($p['prompt']); ?></strong></td>
                            <td><code>#<?php echo $esc($p['tag_display'] ?: $p['tag']); ?></code></td>
                            <td><?php echo $esc($p['drop_at']); ?></td>
                            <td><span class="pc-badge pc-badge--<?php echo $esc($st); ?>"><?php echo $st_label; ?></span></td>
                            <td class="pc-sched-act">
                                <?php if ($st === 'queued'): ?>
                                    <a class="btn-smack" href="smack-photochallenge-queue.php?edit=<?php echo (int)$p['id']; ?>">EDIT</a>
                                    <form method="post" action="" onsubmit="return confirm('Unschedule this prompt? Its card stays as a hidden draft.');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="cancel_prompt">
                                        <input type="hidden" name="prompt_id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn-smack">CANCEL</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="dim"><em>No contest posts are queued yet.</em></p>
        <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($pc_admin_view === 'dashboard'): ?>
    <!-- LIVE STATE -->
    <div class="box mb-20">
        <h3>THIS WINDOW &mdash; <?php echo $esc($win['label']); ?>
            <span class="pc-state" style="margin-left:10px; font-family:'Courier New',monospace; color:<?php echo $win['open'] ? '#2ecc71' : '#e0b000'; ?>;">
                <?php echo $win['open'] ? 'OPEN' : 'CLOSED'; ?>
            </span>
        </h3>
        <p class="dim">
            Week key <code><?php echo $esc($win['week_key']); ?></code> &middot;
            participants: <strong><?php echo (int)$counts['active']; ?></strong> active,
            <?php echo (int)$counts['left']; ?> left, <?php echo (int)$counts['blocked']; ?> blocked.
        </p>
        <p class="dim">Closes: <strong><?php echo $esc($win['end']); ?> UTC</strong></p>
        <?php if (in_array(($settings['photochallenge_window_mode'] ?? 'weekly'), ['weekly','extended'], true)): ?>
        <form method="post" action="" style="margin:12px 0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="extend_window_24">
            <button type="submit" class="btn btn-primary">EXTEND 24 HOURS</button>
            <span class="dim">Keeps this same round and moves its closing time forward exactly one day.</span>
        </form>
        <?php endif; ?>
        <?php if ($test_on): ?>
        <p class="dim">
            <strong>TESTING WHITELIST is ON.</strong>
            <?php echo (int)$test_dry; ?> entr<?php echo $test_dry === 1 ? 'y' : 'ies'; ?> this window
            <strong>boosted to your whitelisted test account(s) only</strong> &mdash; your real followers got nothing.
        </p>
        <?php endif; ?>
        <p class="dim">
            Public pages:
            <a href="<?php echo $esc($board_url); ?>" target="_blank" rel="noopener">the board</a> &middot;
            <a href="<?php echo $esc($hof_url); ?>" target="_blank" rel="noopener">the Hall of Fame</a>.
        </p>
    </div>

    <div class="box mb-20">
        <h3>RECOVER MISSED ENTRIES</h3>
        <p class="dim">Checks both the live hashtag and every active participant&rsquo;s own outbox, imports qualifying posts, and queues their boosts. Anything it cannot process is retained in the failed-entry log. &ldquo;Queued&rdquo; does not claim that every remote server has displayed it yet.</p>
        <form method="post" action="" style="display:inline-block;">
            <?php csrf_field(); ?><input type="hidden" name="action" value="recover_entries">
            <button type="submit" class="btn-smack">FIND AND RECOVER</button>
        </form>
        <p class="dim"><strong>No messages are sent from this page.</strong> Suggested DMs appear below for review only.</p>
        <?php if ($recovery_results): ?>
            <h4>LATEST RECOVERY RECEIPT</h4>
            <table class="dim" style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead><tr><th style="text-align:left;">POST</th><th style="text-align:left;">PUBLISHED</th><th style="text-align:left;">WHAT HAPPENED</th><th style="text-align:left;">DETAIL</th></tr></thead>
                <tbody><?php foreach ($recovery_results as $item): ?>
                    <tr class="border-b">
                        <td class="p-8-6"><?php if (preg_match('#^https?://#i',(string)$item['post_url'])): ?><a href="<?php echo $esc($item['post_url']); ?>" target="_blank" rel="noopener"><?php echo $esc($item['actor_handle'] ?: 'post'); ?></a><?php else: ?><?php echo $esc($item['actor_handle'] ?: $item['object_id']); ?><?php endif; ?></td>
                        <td class="p-8-6"><?php echo $esc((string)$item['published']); ?> UTC</td>
                        <td class="p-8-6"><strong><?php echo $esc(str_replace('_',' ',(string)$item['reason'])); ?></strong></td>
                        <td class="p-8-6"><?php echo $esc((string)$item['last_error']); ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
        <?php if ($entry_dm_drafts): ?>
            <h4>DM DRAFTS &mdash; REVIEW ONLY</h4>
            <?php foreach ($entry_dm_drafts as $draft): ?>
                <div style="margin:10px 0; padding:10px; border:1px solid #444;">
                    <strong><?php echo $esc($draft['handle'] ?: $draft['recipient']); ?></strong>
                    <p style="white-space:pre-wrap;"><?php echo $esc($draft['body']); ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($entry_failures): ?>
        <table class="dim" style="width:100%; border-collapse:collapse; margin-top:16px;">
            <thead><tr><th style="text-align:left;">ENTRY</th><th style="text-align:left;">PROBLEM</th><th style="text-align:left;">STATE</th><th>LAST SEEN</th></tr></thead>
            <tbody><?php foreach ($entry_failures as $failure): ?>
                <tr class="border-b">
                    <td class="p-8-6"><?php if (preg_match('#^https?://#i',(string)$failure['post_url'])): ?><a href="<?php echo $esc($failure['post_url']); ?>" target="_blank" rel="noopener"><?php echo $esc($failure['actor_handle'] ?: 'post'); ?></a><?php else: ?><?php echo $esc($failure['actor_handle'] ?: $failure['object_id']); ?><?php endif; ?></td>
                    <td class="p-8-6"><?php echo $esc(str_replace('_',' ',(string)$failure['reason'])); ?><?php if ($failure['last_error']): ?><br><small><?php echo $esc($failure['last_error']); ?></small><?php endif; ?></td>
                    <td class="p-8-6"><?php echo $esc($failure['state']); ?></td>
                    <td class="p-8-6" style="text-align:right;"><?php echo $esc($failure['last_seen_at']); ?> UTC</td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- CROWN THE WEEK -->
    <div class="box mb-20">
        <h3>CROWN THIS WINDOW</h3>
        <p class="dim mb-20">
            Picks the top entries by score (likes + weighted boosts), skipping boosts, hors-concours and
            image-less posts, and writes them to the Hall of Fame. Idempotent per week &mdash; re-crowning
            the same window overwrites its places cleanly. Ranking needs engagement data to have landed
            (that arrives once the Like/Announce inbox hooks are live &mdash; see the build handoff); until
            then the board is chronological and crowning simply takes the newest.
        </p>
        <?php if ($ranked): ?>
            <p class="dim">Current standings (top of this window):</p>
            <ol style="margin:8px 0 18px 22px; line-height:1.7;">
                <?php foreach (array_slice($ranked, 0, 8) as $r): if (($r['rank'] ?? 0) < 1) continue; ?>
                    <li>
                        <strong><?php echo $esc($r['handle']); ?></strong>
                        <span class="dim">&mdash; score <?php echo (int)$r['score']; ?>
                            (<?php echo (int)$r['likes']; ?> likes, <?php echo (int)$r['boosts']; ?> boosts)</span>
                        <?php /* SECAUDIT 047: scheme-guard federation URL */
                              $__u = (string)($r['url'] ?? ''); $__safe = preg_match('#^https?://#i', $__u) ? $__u : ''; ?>
                        <?php if ($__safe !== ''): ?>
                            &middot; <a href="<?php echo $esc($__safe); ?>" target="_blank" rel="noopener">entry</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="dim mb-20"><em>No rankable entries in this window yet.</em></p>
        <?php endif; ?>
        <form method="post" action="" onsubmit="return confirm('Crown the current window into the Hall of Fame?');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="crown_week">
            <label class="dim">Places to crown:
                <input type="number" name="pc_places" min="1" max="10" value="3" style="width:70px; margin-left:6px;">
            </label>
            <button type="submit" class="btn-smack ml-10">CROWN</button>
        </form>
    </div>

    <!-- HALL OF FAME -->
    <div class="box mb-20">
        <h3>HALL OF FAME</h3>
        <?php if (!$hof): ?>
            <p class="dim">Empty. Crown a window to populate it.</p>
        <?php else: ?>
            <table class="dim" style="width:100%; border-collapse:collapse;">
                <?php foreach ($hof as $h): ?>
                    <tr class="border-b">
                        <td style="padding:8px 6px; white-space:nowrap;">
                            <code><?php echo $esc($h['week_key']); ?></code> #<?php echo (int)$h['place']; ?>
                        </td>
                        <td class="p-8-6">
                            <strong><?php echo $esc($h['handle']); ?></strong>
                            <?php /* SECAUDIT 047: scheme-guard federation URL */
                                  $__pu = (string)($h['post_url'] ?? ''); $__psafe = preg_match('#^https?://#i', $__pu) ? $__pu : ''; ?>
                            <?php if ($__psafe !== ''): ?>
                                &middot; <a href="<?php echo $esc($__psafe); ?>" target="_blank" rel="noopener">post</a>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 6px; text-align:right; white-space:nowrap;">
                            <form method="post" action="" style="display:inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="hof_toggle">
                                <input type="hidden" name="hof_id" value="<?php echo (int)$h['id']; ?>">
                                <input type="hidden" name="to" value="<?php echo ((int)$h['active'] === 1) ? '0' : '1'; ?>">
                                <button type="submit" class="btn-smack">
                                    <?php echo ((int)$h['active'] === 1) ? 'HIDE' : 'SHOW'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <!-- ROSTER -->
    <div class="box">
        <h3>PARTICIPANTS</h3>
        <form method="post" style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;">
            <input type="hidden" name="action" value="block_domain">
            <input type="text" name="domain" placeholder="example.social" required>
            <input type="text" name="reason" placeholder="Reason">
            <input type="password" name="reauth_password" placeholder="Password" required>
            <input type="text" name="reauth_totp" placeholder="2FA">
            <button class="btn-smack" type="submit">BLOCK DOMAIN</button>
        </form>
        <?php if (!$roster): ?>
            <p class="dim">No participants yet. They join by following this blog once the challenge is ON.</p>
        <?php else: ?>
            <table class="dim" style="width:100%; border-collapse:collapse;">
                <?php foreach ($roster as $p): $hc = (int)$p['horsconcours'] === 1; ?>
                    <tr class="border-b">
                        <td class="p-8-6">
                            <strong><?php echo $esc($p['handle'] ?: $p['actor_url']); ?></strong>
                            <?php if ($hc): ?><span class="dim" title="hors concours — shown, never ranked"> · hc</span><?php endif; ?>
                        </td>
                        <td class="p-8-6"><span class="dim"><?php echo $esc($p['state']); ?></span></td>
                        <td class="p-8-6">
                            <form method="post" style="display:flex;gap:5px;flex-wrap:wrap;">
                                <input type="hidden" name="action" value="participant_state">
                                <input type="hidden" name="actor_url" value="<?php echo $esc($p['actor_url']); ?>">
                                <select name="state"><option value="active">Active</option><option value="blocked">Block</option><option value="left">Remove</option></select>
                                <input type="password" name="reauth_password" placeholder="Password" required style="width:100px;">
                                <input type="text" name="reauth_totp" placeholder="2FA" style="width:70px;">
                                <button class="btn-smack" type="submit">APPLY</button>
                            </form>
                        </td>
                        <td style="padding:8px 6px; text-align:right; white-space:nowrap;">
                            <form method="post" action="" style="display:inline;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="horsconcours">
                                <input type="hidden" name="actor_url" value="<?php echo $esc($p['actor_url']); ?>">
                                <input type="hidden" name="to" value="<?php echo $hc ? '0' : '1'; ?>">
                                <button type="submit" class="btn-smack">
                                    <?php echo $hc ? 'RANK AGAIN' : 'HORS CONCOURS'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php if ($pc_admin_view === 'queue'): ?>
<script src="assets/js/smack-prompt-schedule.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php endif; ?>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
