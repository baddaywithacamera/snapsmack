<?php
/**
 * SNAPSMACK — CRON & JOBS
 *
 * One place to see and fix the scheduled jobs every SnapSmack site runs, from the
 * CMS itself — no cPanel, no SSH. Per job it shows the last run + status, whether
 * it is registered in the system crontab, and gives three buttons: RUN NOW (run it
 * on demand), REGISTER (install it in the crontab), and UNREGISTER. Uses the
 * existing core/cron-register.php helpers; every value is this site's own data.
 *
 * Previously each cron's register control lived on a different admin page
 * (fediverse → Fediverse admin, RSS → this page's old home, version → Updates),
 * so it was easy to leave RSS/fediverse unregistered and never notice. This unifies
 * them.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';
require_once 'core/cron-register.php';

/* The scheduled jobs EVERY site runs. Tag/schedule/script match the existing
   registrations in fediverse-admin-shared.php, smack-admin.php, smack-update.php. */
$CRON_JOBS = [
    [
        'key' => 'fediverse', 'label' => 'Fediverse delivery',
        'tag' => '# snapsmack-fediverse', 'schedule' => '*/10 * * * *',
        'human' => 'every 10 minutes', 'script' => 'cron-fediverse.php',
        'last_key' => 'fediverse_cron_last_run', 'status_key' => 'fediverse_cron_last_status',
        'blurb' => 'Sends this blog\'s posts out to the fediverse and pulls followed accounts in.',
    ],
    [
        'key' => 'rss', 'label' => 'RSS blogroll fetch',
        'tag' => '# snapsmack-rss-fetch', 'schedule' => '0 * * * *',
        'human' => 'hourly', 'script' => 'cron-rss-fetch.php',
        'last_key' => 'rss_last_run', 'status_key' => 'rss_last_status',
        'blurb' => 'Refreshes the blogroll from the peer feeds it follows.',
    ],
    [
        'key' => 'version', 'label' => 'Version / update check',
        'tag' => '# snapsmack-version-check', 'schedule' => '0 */6 * * *',
        'human' => 'every 6 hours', 'script' => 'cron-version-check.php',
        'last_key' => 'last_update_check', 'status_key' => 'version_check_last_status',
        'blurb' => 'Checks SMACK CENTRAL for a newer SnapSmack build and available skin updates.',
    ],
];

list($cron_supported, $php_cli) = cron_capability();

function cron_load_settings(PDO $pdo): array {
    try {
        return $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { return []; }
}
$settings = cron_load_settings($pdo);

/* ---- POST: run now / register / unregister -------------------------------- */
$flash = ''; $flash_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['cron_action'] ?? '');
    $jobkey = (string)($_POST['job'] ?? '');
    $job = null;
    foreach ($CRON_JOBS as $j) { if ($j['key'] === $jobkey) { $job = $j; break; } }

    if (!$job) {
        $flash = 'Unknown job.'; $flash_type = 'error';
    } else {
        $script_abs = realpath(__DIR__ . '/' . $job['script']);
        if ($action === 'run_now') {
            if (!$cron_supported || $php_cli === '') {
                $flash = 'This host can\'t run PHP jobs from the CMS (no command-line PHP found). Use the WEB CRON option below instead.';
                $flash_type = 'error';
            } elseif (!$script_abs) {
                $flash = 'Job script not found: ' . $job['script']; $flash_type = 'error';
            } else {
                if (cron_run_detached($php_cli, $script_abs)) {
                    $flash = strtoupper($job['label']) . ' started in the background. You can leave this page; refresh shortly to see its latest status.';
                    $flash_type = 'success';
                } else {
                    $flash = strtoupper($job['label']) . ' could not be started in the background. The scheduled run remains available.';
                    $flash_type = 'error';
                }
            }
        } elseif ($action === 'register') {
            if (!$cron_supported) {
                $flash = 'This host can\'t self-register cron. Use the WEB CRON option below.'; $flash_type = 'error';
            } else {
                list($ok, $msg) = cron_register_job($job['schedule'], $script_abs ?: (__DIR__ . '/' . $job['script']), $job['tag']);
                $flash = $msg; $flash_type = $ok ? 'success' : 'error';
            }
        } elseif ($action === 'remove') {
            list($ok, $msg) = cron_remove_job($job['tag']);
            $flash = $msg; $flash_type = $ok ? 'success' : 'error';
        }
        $settings = cron_load_settings($pdo); // refresh last-run after a run
    }
}

/* ---- helpers for the render ---------------------------------------------- */
function cron_age_text(?string $ts): string {
    if (!$ts) return 'never';
    $t = strtotime($ts);
    if ($t === false) return htmlspecialchars($ts);
    $secs = time() - $t;
    if ($secs < 0) $secs = 0;
    if ($secs < 90)         return 'just now';
    if ($secs < 3600)       return floor($secs / 60) . 'm ago';
    if ($secs < 86400)      return floor($secs / 3600) . 'h ago';
    return floor($secs / 86400) . 'd ago';
}

/* web-cron token (reuse existing if set; the endpoint core/fediverse-webcron.php
   uses it). Read-only here — we only display the ping URL, never mint. */
$webcron_token = trim((string)($settings['webcron_token'] ?? ''));
$site_url = rtrim((string)($settings['site_url'] ?? ''), '/');

$page_title = 'Cron & Jobs';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>CRON &amp; JOBS</h2>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert <?php echo $flash_type === 'success' ? 'alert-success' : 'alert-warn'; ?>">
            &gt; <?php echo htmlspecialchars($flash); ?>
        </div>
    <?php endif; ?>

    <div class="box mb-20">
        <p class="dim">
            The scheduled jobs this site runs. <strong>RUN NOW</strong> runs a job this second.
            <strong>REGISTER</strong> installs it in the server's crontab so it runs on schedule;
            <strong>UNREGISTER</strong> removes it. A job that has never run and isn't registered is
            the thing to fix — register it, or run it now to prove it works.
        </p>
        <?php if (!$cron_supported): ?>
            <p class="alert alert-warn">&gt; This host can't schedule cron from the CMS (no command-line PHP / crontab access). Use the <strong>WEB CRON</strong> option at the bottom — the jobs will still run.</p>
        <?php endif; ?>
    </div>

    <?php foreach ($CRON_JOBS as $job):
        $last       = (string)($settings[$job['last_key']] ?? '');
        $status     = (string)($settings[$job['status_key']] ?? '');
        $registered = cron_job_registered($job['tag']);
        $ever_ran   = $last !== '';
    ?>
    <div class="box mb-20">
        <h3><?php echo htmlspecialchars($job['label']); ?></h3>
        <p class="dim mb-10"><?php echo htmlspecialchars($job['blurb']); ?> Runs <?php echo htmlspecialchars($job['human']); ?> (<code><?php echo htmlspecialchars($job['schedule']); ?></code>).</p>
        <table class="data-table mb-10">
            <tbody>
                <tr>
                    <th>Last run</th>
                    <td>
                        <?php if ($ever_ran): ?>
                            &#10003; <?php echo htmlspecialchars(cron_age_text($last)); ?>
                            <span class="dim">(<?php echo htmlspecialchars($last); ?><?php echo $status !== '' ? ', ' . htmlspecialchars($status) : ''; ?>)</span>
                        <?php else: ?>
                            &#10007; never
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Scheduled</th>
                    <td>
                        <?php if ($registered): ?>
                            &#10003; registered in crontab
                        <?php else: ?>
                            &#10007; not registered &mdash; this job won't run on its own until you register it
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="job" value="<?php echo htmlspecialchars($job['key']); ?>">
            <button type="submit" name="cron_action" value="run_now" class="btn-smack">RUN NOW</button>
            <?php if (!$registered): ?>
                <button type="submit" name="cron_action" value="register" class="btn-smack">REGISTER</button>
            <?php else: ?>
                <button type="submit" name="cron_action" value="remove" class="btn-smack btn-smack--danger">UNREGISTER</button>
            <?php endif; ?>
        </form>
    </div>
    <?php endforeach; ?>

    <div class="box mb-20">
        <h3>WEB CRON (fallback)</h3>
        <p class="dim">
            If this host can't schedule cron (shared hosting with no crontab), point an
            external uptime pinger at the web-cron endpoint every few minutes and the due jobs
            run on each hit. The endpoint is <code>core/fediverse-webcron.php</code>.
        </p>
        <?php if ($webcron_token !== '' && $site_url !== ''): ?>
            <p>Ping URL:</p>
            <p><code><?php echo htmlspecialchars($site_url . '/core/fediverse-webcron.php?token=' . $webcron_token); ?></code></p>
        <?php else: ?>
            <p class="dim">No web-cron token is set yet. Set <code>webcron_token</code> in Settings to enable the ping URL.</p>
        <?php endif; ?>
    </div>

    <p class="dim">This page controls only this site's crons. Fleet-wide cron health is in the desktop CRONOMETER board.</p>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
