<?php
/**
 * SNAPSMACK - SCHEDULE A PROMPT (admin surface)
 *
 * The photofri.day / artfri.day prompt scheduler, on its own page under the
 * CHALLENGE ME section. Sean enters the prompt word, picks the Photo-Friday,
 * adds any extra copy, and uploads the card. The tool builds the hashtag, files
 * the card as a hidden draft, and — when the drop time arrives — the challenge
 * cron publishes the card (federating it) and switches the qualifying hashtag.
 * All the work lives in core/photochallenge.php (pc_queue_prompt / pc_cancel_prompt
 * / pc_prompts_list); this page is only the form + list.
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
            'prompt'    => (string)($_POST['pc_prompt'] ?? ''),
            'friday'    => (string)($_POST['pc_friday'] ?? ''),
            'drop_mode' => (string)($_POST['pc_drop_mode'] ?? ''),
            'drop_at'   => (string)($_POST['pc_drop_at'] ?? ''),
            'extra'     => (string)($_POST['pc_extra'] ?? ''),
        ], $_FILES['pc_prompt_image'] ?? []);
        $msg_ok = !empty($res['ok']);
        $msg = ($msg_ok ? '' : 'Not queued — ') . $res['msg'];

    } elseif ($action === 'cancel_prompt') {
        $res = pc_cancel_prompt($pdo, $settings, (int)($_POST['prompt_id'] ?? 0));
        $msg_ok = !empty($res['ok']);
        $msg = $res['msg'];
    }

    // Re-read settings so the render below reflects the write.
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
}

$pc_on  = pc_enabled($settings);
$fed_on = sv_enabled($settings);
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Prefill the next Photo-Friday and both computed drop times (week-before + window-open).
$pc_prefix = pc_tag_prefix($settings);
$_now_utc  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$_add_days = (5 - (int)$_now_utc->format('N') + 7) % 7;          // 0..6 days to the next Friday
$next_friday = $_now_utc->modify("+{$_add_days} days")->format('Y-m-d');
$_def_win  = pc_window_for_friday($next_friday);
$def_window_open = $_def_win ? $_def_win['start'] : '';
$def_week_before = $_def_win
    ? (new DateTimeImmutable($_def_win['start'], new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d H:i:s')
    : '';
$prompts = pc_prompts_list($pdo, 40);

$page_title = 'SCHEDULE A PROMPT';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>SCHEDULE A PROMPT</h2>
    </div>

    <?php if ($msg): ?>
        <div class="alert <?php echo $msg_ok ? 'alert-success' : 'alert-warn'; ?>">&gt; <?php echo $esc($msg); ?></div>
    <?php endif; ?>

    <?php if (!$pc_on): ?>
        <div class="alert alert-warn">
            &gt; The photo challenge is <strong>OFF</strong>. You can still queue prompts here, but the drop
            and the qualifying board only come alive once you turn it on under
            <a href="smack-photochallenge.php">CHALLENGE ME &rarr; Contest &amp; Feed</a>.
        </div>
    <?php elseif (!$fed_on): ?>
        <div class="alert alert-warn">
            &gt; Fediverse federation is <strong>OFF</strong>. Prompts will queue, but the card can only drop
            to the fediverse once you enable federation on the
            <a href="smack-smackverse.php">FEDIVERSE &rarr; Federation</a> page.
        </div>
    <?php endif; ?>

    <!-- SCHEDULE A PROMPT -->
    <div class="box mb-20 pc-schedule">
        <p class="dim mb-20">
            Enter the prompt word, pick the Photo-Friday, add any extra copy, and upload the card. The tool
            builds the hashtag, files the card as a hidden draft, and drops it automatically at the time you
            choose &mdash; publishing the card to the fediverse and switching the qualifying hashtag to that
            week's tag. One prompt per Friday.
        </p>
        <form method="post" action="" enctype="multipart/form-data"
              data-pc-prefix="<?php echo $esc($pc_prefix); ?>">
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
                <label>ADDITIONAL TEXT <span class="dim">(optional)</span></label>
                <textarea name="pc_extra" id="pc_extra" rows="3" spellcheck="true"
                          placeholder="Anything extra to say on the card post — a note, a nudge, a credit."></textarea>
                <p class="dim">Added to the card's post under the word and hashtag, above the challenge link.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>CARD IMAGE</label>
                <input type="file" name="pc_prompt_image" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <p class="dim">The prompt card people see when it drops. JPG, PNG, WEBP or GIF.</p>
            </div>

            <div class="lens-input-wrapper">
                <label>DROP TIMING <span class="dim">(when the card posts &amp; the hashtag goes live)</span></label>
                <select name="pc_drop_mode" id="pc_drop_mode"
                        data-window-open="<?php echo $esc($def_window_open); ?>"
                        data-week-before="<?php echo $esc($def_week_before); ?>">
                    <option value="week_before" selected>A week before &mdash; a heads-up the Thursday before</option>
                    <option value="window_open">When the window opens &mdash; Thursday 10:00 UTC that week</option>
                    <option value="custom">Custom time&hellip;</option>
                </select>
                <p class="dim">This prompt will drop: <strong id="pc_drop_hint"><?php echo $esc($def_week_before); ?> UTC</strong>.
                    Times are UTC. &ldquo;A week before&rdquo; posts the word seven days ahead so people can plan.</p>
            </div>

            <div class="lens-input-wrapper" id="pc_custom_wrap">
                <label>CUSTOM DROP TIME <span class="dim">(only used with &ldquo;Custom time&rdquo; above)</span></label>
                <input type="datetime-local" name="pc_drop_at" id="pc_drop_at">
                <p class="dim">Set an exact moment (UTC) for the drop.</p>
            </div>

            <button type="submit" class="master-update-btn">QUEUE PROMPT</button>
        </form>

        <?php if ($prompts): ?>
            <h4 class="pc-sched-sub">SCHEDULED &amp; DROPPED</h4>
            <table class="pc-sched-list dim">
                <thead>
                    <tr><th>Photo-Friday</th><th>Prompt</th><th>Hashtag</th><th>Drops (UTC)</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($prompts as $p):
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
        <?php endif; ?>
    </div>

</div>

<script src="assets/js/smack-prompt-schedule.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
