<?php
/**
 * SNAPSMACK - PHOTO CHALLENGE (admin surface)
 *
 * The control panel for the photofri.day / artfri.day profile that rides on top
 * of this install's single SMACKVERSE actor (core/photochallenge.php). It is a
 * thin admin page: flip the profile on, set the tag/timezone/scoring, watch the
 * roster and the live board, crown a week into the Hall of Fame, and prune dead
 * Hall-of-Fame links. All federation still belongs to SMACKVERSE — this page
 * never touches a key or an inbox, it only sets policy and reads what landed.
 *
 * Turning the challenge ON only matters once SMACKVERSE itself is enabled: the
 * follow=join / unfollow=leave hooks fire from the federation inbox, so with
 * federation off this is inert. The page says so rather than pretending.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';      // session + CSRF autovalidate + login gate + $pdo
require_once 'core/smackverse.php';      // sv_set_setting, sv_enabled, sv_* helpers
require_once 'core/photochallenge.php';  // pc_* policy layer

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
            'friday'  => (string)($_POST['pc_friday'] ?? ''),
            'drop_at' => (string)($_POST['pc_drop_at'] ?? ''),
        ], $_FILES['pc_prompt_image'] ?? []);
        $msg_ok = !empty($res['ok']);
        $msg = ($msg_ok ? '' : 'Not queued — ') . $res['msg'];

    } elseif ($action === 'cancel_prompt') {
        $res = pc_cancel_prompt($pdo, $settings, (int)($_POST['prompt_id'] ?? 0));
        $msg_ok = !empty($res['ok']);
        $msg = $res['msg'];

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
        $wm = (($_POST['pc_window_mode'] ?? 'weekly') === 'daily') ? 'daily' : 'weekly';
        $test_mode  = isset($_POST['pc_test_mode']) ? '1' : '0';
        $feed_enabled = isset($_POST['pc_feed_enabled']) ? '1' : '0';
        $feed_layout = (($_POST['pc_feed_layout'] ?? 'three') === 'masonry') ? 'masonry' : 'three';
        $test_allow = trim((string)($_POST['pc_test_allow'] ?? ''));
        sv_set_setting($pdo, $settings, 'photochallenge_tag', $tag);
        sv_set_setting($pdo, $settings, 'photochallenge_tz', $tz);
        sv_set_setting($pdo, $settings, 'photochallenge_boost_weight', $bw);
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
$prompts   = pc_prompts_list($pdo, 40);
$queued_prompts = array_values(array_filter($prompts, static fn(array $p): bool => ($p['status'] ?? '') === 'queued'));

$page_title = 'PHOTO CHALLENGE';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>PHOTO CHALLENGE &mdash; PHOTOFRI.DAY</h2>
    </div>

    <?php if ($msg): ?>
        <div class="alert <?php echo $msg_ok ? 'alert-success' : 'alert-warn'; ?>">&gt; <?php echo $esc($msg); ?></div>
    <?php endif; ?>

    <?php if (!$fed_on): ?>
        <div class="alert alert-warn">
            &gt; Fediverse federation is <strong>OFF</strong>. The challenge can be configured here, but
            follow-to-join and the board only come alive once you enable federation on the
            <a href="smack-smackverse.php">FEDIVERSE &rarr; FEDERATION</a> page.
        </div>
    <?php endif; ?>

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
                <?php $pc_wm = ($settings['photochallenge_window_mode'] ?? 'weekly') === 'daily' ? 'daily' : 'weekly'; ?>
                <select name="pc_window_mode">
                    <option value="weekly" <?php echo $pc_wm === 'weekly' ? 'selected' : ''; ?>>WEEKLY &mdash; Photo-Friday (Thu 10:00 &rarr; Sat 12:00 UTC)</option>
                    <option value="daily"  <?php echo $pc_wm === 'daily'  ? 'selected' : ''; ?>>DAILY (TEST) &mdash; rolling 24h, always open</option>
                </select>
                <p class="dim"><strong>Weekly</strong> is the real Photo-Friday cadence. <strong>Daily</strong> is a test/demo
                    mode &mdash; a rolling 24-hour window that is always open, so entries qualify any day. Switch back to
                    weekly before launch.</p>
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

    <!-- SCHEDULE A PROMPT -->
    <div class="box mb-20 pc-schedule" id="queue-contest-post">
        <h3>SCHEDULE A PROMPT</h3>
        <p class="dim mb-20">
            Enter the prompt word, pick the Photo-Friday, and upload the card. The tool builds the hashtag,
            files the card as a hidden draft, and drops it automatically at the time below &mdash; publishing the
            card to the fediverse and switching the qualifying hashtag to that week's tag. One prompt per Friday.
        </p>
        <form method="post" action="" enctype="multipart/form-data" data-pc-prefix="<?php echo $esc($pc_prefix); ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="queue_prompt">

            <div class="lens-input-wrapper">
                <label>PROMPT <span class="dim">(one word)</span></label>
                <input type="text" name="pc_prompt" id="pc_prompt" maxlength="60" required
                       autocomplete="off" placeholder="Belonging">
                <p class="dim">Hashtag: <span class="pc-hash-preview" id="pc_hash_preview">#<?php echo $esc($pc_prefix); ?>&hellip;</span>
                    &mdash; built for you from the word above.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>PHOTO-FRIDAY <span class="dim">(the 50-hour submission window)</span></label>
                <input type="date" name="pc_friday" id="pc_friday" value="<?php echo $esc($next_friday); ?>" required>
                <p class="dim">Submissions open <strong>Thu 10:00 UTC</strong> and close <strong>Sat 12:00 UTC</strong> that week.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>CARD IMAGE</label>
                <input type="file" name="pc_prompt_image" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <p class="dim">The prompt card people see when it drops. JPG, PNG, WEBP or GIF.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>DROPS AT <span class="dim">(when the card posts &amp; the hashtag goes live)</span></label>
                <input type="datetime-local" name="pc_drop_at" id="pc_drop_at" value="<?php echo $esc($def_drop_value); ?>">
                <p class="dim">Times are <strong>UTC</strong>. This matches the selected window opening
                    (<span id="pc_drop_hint"><?php echo $esc($def_drop_hint); ?></span>); change it only if the card should give an earlier heads-up.</p>
            </div>

            <button type="submit" class="master-update-btn">QUEUE PROMPT</button>
        </form>

        <section id="queued-contest-posts" aria-labelledby="queued-contest-posts-title">
            <h4 class="pc-sched-sub" id="queued-contest-posts-title">QUEUED POSTS</h4>
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
                                    <form method="post" action="" onsubmit="return confirm('Unschedule this prompt? Its card stays as a hidden draft.');">
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
    </div>

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

</div>

<script src="assets/js/smack-prompt-schedule.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
