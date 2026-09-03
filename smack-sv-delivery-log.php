<?php
/**
 * SNAPSMACK — FEDIVERSE DELIVERY LOG (read-only diagnostics)
 *
 * See WHAT is trying to go out to followers and WHY it is stuck, from the CMS
 * itself — no SSH, no reading MySQL by hand. The outbound queue (snap_ap_deliveries)
 * keeps every job that is still pending or failing (successful sends are deleted the
 * instant they land), so this page is exactly the troubleshooting view: per stuck
 * job it shows the target follower, which post it carries, the activity (Create /
 * Update / Delete), how many attempts, when the next retry is due, and the last
 * error the remote returned. A second panel lists recent posts with whether/when
 * they were pushed to followers, so you can spot a post that published on the blog
 * but never federated (the "staged, never pushed" case).
 *
 * Strictly READ-ONLY: it never sends, retries, deletes or edits anything. To act,
 * use RUN NOW on Cron & Jobs (drain the queue now) or PUSH / RE-IMPRINT on Push &
 * Tools. This page just tells you what you are looking at first.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';

/* ---- data load (all read-only) -------------------------------------------- */
try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $settings = []; }

$fedi_on   = ($settings['fediverse_enabled'] ?? '0') === '1';
$push_mode = ($settings['fediverse_push_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
$cron_last = (string)($settings['fediverse_cron_last_run'] ?? '');

/* Active followers + inbox → handle map, so a raw inbox URL reads as @user@host. */
$follower_count = 0;
$inbox_handle   = [];
try {
    $follower_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1")->fetchColumn();
    foreach ($pdo->query("SELECT actor_handle, inbox_url, shared_inbox_url FROM snap_ap_followers WHERE is_active = 1")
                 ->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $h = trim((string)($f['actor_handle'] ?? ''));
        foreach ([$f['inbox_url'] ?? '', $f['shared_inbox_url'] ?? ''] as $ib) {
            $ib = trim((string)$ib);
            if ($ib !== '' && $h !== '') $inbox_handle[$ib] = $h;
        }
    }
} catch (Throwable $e) { /* tables may not exist yet on a fresh install */ }

/* The outbound queue. Only pending/failed jobs live here — successful sends are
   deleted on delivery, so a short (or empty) list is the healthy state. */
$queue = [];
try {
    $queue = $pdo->query(
        "SELECT id, inbox_url, activity_json, attempts, next_try_at, status, last_error, created_at
           FROM snap_ap_deliveries
       ORDER BY (status='failed') DESC, attempts DESC, next_try_at ASC
          LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $queue = []; }

/* Pull the activity type and the note id this job carries out of the payload.
   Create/Update wrap the note as an object; Delete carries the id as a string.
   A plain regex over the JSON finds ap/note/<kind>/<id> in every shape, so we can
   name the post without decoding the whole graph. kind: p=post, l=longform, i=image. */
function dlog_describe(string $json): array {
    $type = 'activity';
    $decoded = json_decode($json, true);
    if (is_array($decoded) && isset($decoded['type']) && is_string($decoded['type'])) {
        $type = $decoded['type'];
    }
    $kind = ''; $ref_id = 0;
    if (preg_match('~ap/note/([pli])/(\d+)~', $json, $m)) {
        $kind = $m[1]; $ref_id = (int)$m[2];
    }
    return ['type' => $type, 'kind' => $kind, 'ref_id' => $ref_id];
}

/* Resolve a note kind+id to a human title, cached so a busy queue is one query
   per distinct post, not one per delivery row. */
$title_cache = [];
function dlog_title(PDO $pdo, array &$cache, string $kind, int $ref_id): string {
    if ($kind === '' || $ref_id <= 0) return '';
    $key = $kind . ':' . $ref_id;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $title = '';
    try {
        if ($kind === 'p' || $kind === 'l') {
            $st = $pdo->prepare("SELECT title, slug FROM snap_posts WHERE id = ? LIMIT 1");
            $st->execute([$ref_id]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string)$row['title']) !== '' ? (string)$row['title'] : (string)$row['slug'];
            }
        } elseif ($kind === 'i') {
            $st = $pdo->prepare("SELECT img_title FROM snap_images WHERE id = ? LIMIT 1");
            $st->execute([$ref_id]);
            $title = (string)($st->fetchColumn() ?: '');
        }
    } catch (Throwable $e) { $title = ''; }
    return $cache[$key] = $title;
}

$failed_count = 0; $queued_count = 0;
foreach ($queue as $q) { ($q['status'] === 'failed') ? $failed_count++ : $queued_count++; }

/* Recent posts and whether they went out. fedi_pushed_at is stamped when the sweep
   (or a manual push) federates a post; NULL on a fedi-enabled published post means
   it has NOT reached followers yet — the thing to catch. */
$posts = [];
try {
    $posts = $pdo->query(
        "SELECT id, title, slug, status, post_type, fedi_enabled, fedi_pushed_at, created_at, updated_at
           FROM snap_posts
          WHERE post_type IN ('single','carousel','panorama','longform')
       ORDER BY created_at DESC
          LIMIT 25"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $posts = []; }

/* Count stuck jobs per post so a post row can flag "3 deliveries retrying". */
$stuck_by_post = [];
foreach ($queue as $q) {
    $d = dlog_describe((string)$q['activity_json']);
    if (($d['kind'] === 'p' || $d['kind'] === 'l') && $d['ref_id'] > 0) {
        $stuck_by_post[$d['ref_id']] = ($stuck_by_post[$d['ref_id']] ?? 0) + 1;
    }
}

function dlog_age(?string $ts): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    if ($t === false) return htmlspecialchars($ts);
    $secs = time() - $t;
    if ($secs < 0)     return 'in ' . (abs($secs) < 3600 ? floor(abs($secs)/60) . 'm' : floor(abs($secs)/3600) . 'h');
    if ($secs < 90)    return 'just now';
    if ($secs < 3600)  return floor($secs / 60) . 'm ago';
    if ($secs < 86400) return floor($secs / 3600) . 'h ago';
    return floor($secs / 86400) . 'd ago';
}

function dlog_host(string $url): string {
    $h = parse_url($url, PHP_URL_HOST);
    return $h ?: $url;
}

$page_title = 'Fediverse — Delivery Log';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>FEDIVERSE &mdash; DELIVERY LOG</h2>
    </div>

    <div class="box mb-20">
        <p class="dim">
            What is trying to reach your followers and why it is stuck &mdash; read-only. The outbound
            queue keeps only jobs that are <strong>still pending or failing</strong>; a send that
            succeeds is removed the instant it lands, so an empty queue is the healthy state. To act on
            what you see here, use <a href="smack-cron.php">RUN NOW on Cron &amp; Jobs</a> to drain the
            queue now, or <a href="smack-sv-tools.php">PUSH / RE-IMPRINT on Push &amp; Tools</a>.
        </p>
        <table class="data-table mb-10">
            <tbody>
                <tr>
                    <th>Federation</th>
                    <td><?php echo $fedi_on ? '&#10003; on' : '&#10007; OFF &mdash; nothing federates while this is off'; ?></td>
                </tr>
                <tr>
                    <th>Push mode</th>
                    <td>
                        <?php echo $push_mode === 'auto'
                            ? 'AUTO &mdash; posts federate on publish'
                            : 'MANUAL &mdash; posts stage and WAIT for a deliberate PUSH'; ?>
                    </td>
                </tr>
                <tr>
                    <th>Active followers</th>
                    <td><?php echo (int)$follower_count; ?></td>
                </tr>
                <tr>
                    <th>Delivery cron last ran</th>
                    <td>
                        <?php echo $cron_last !== ''
                            ? htmlspecialchars(dlog_age($cron_last)) . ' <span class="dim">(' . htmlspecialchars($cron_last) . ')</span>'
                            : '&#10007; never'; ?>
                    </td>
                </tr>
                <tr>
                    <th>Queue right now</th>
                    <td>
                        <?php echo (int)$queued_count; ?> waiting,
                        <?php echo (int)$failed_count; ?> retrying/failed
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="box mb-20">
        <h3>OUTBOUND QUEUE &mdash; STUCK &amp; PENDING JOBS</h3>
        <?php if (!$queue): ?>
            <p class="dim">&#10003; The queue is empty. Nothing is waiting or failing &mdash; every send that
            was tried has landed. (This does not prove a specific post reached a specific follower; check the
            per-post panel below for what has been pushed.)</p>
        <?php else: ?>
        <p class="dim mb-10">Newest failures first. <strong>ERROR</strong> is the exact reason the remote gave
        on the last attempt &mdash; that is what to read when a post won't go out.</p>
        <div class="ox-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Target follower</th>
                    <th>Activity</th>
                    <th>Post</th>
                    <th>Attempts</th>
                    <th>Status</th>
                    <th>Next try</th>
                    <th>Queued</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queue as $q):
                    $d      = dlog_describe((string)$q['activity_json']);
                    $title  = dlog_title($pdo, $title_cache, $d['kind'], $d['ref_id']);
                    $target = $inbox_handle[$q['inbox_url']] ?? ('(' . dlog_host((string)$q['inbox_url']) . ')');
                    $post_label = $title !== ''
                        ? $title . ' <span class="dim">#' . (int)$d['ref_id'] . '</span>'
                        : ($d['ref_id'] > 0 ? '#' . (int)$d['ref_id'] : '<span class="dim">&mdash;</span>');
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($target); ?></td>
                    <td><?php echo htmlspecialchars($d['type']); ?></td>
                    <td><?php echo $post_label; ?></td>
                    <td><?php echo (int)$q['attempts']; ?></td>
                    <td><?php echo $q['status'] === 'failed' ? 'retrying/failed' : 'waiting'; ?></td>
                    <td><?php echo htmlspecialchars(dlog_age((string)$q['next_try_at'])); ?></td>
                    <td><?php echo htmlspecialchars(dlog_age((string)$q['created_at'])); ?></td>
                    <td><?php echo $q['last_error'] !== null && $q['last_error'] !== ''
                            ? htmlspecialchars((string)$q['last_error'])
                            : '<span class="dim">&mdash;</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="box mb-20">
        <h3>RECENT POSTS &mdash; DID THEY GO OUT?</h3>
        <p class="dim mb-10">
            <strong>Pushed</strong> is when a post was federated to followers. A published,
            federation-enabled post that shows <strong>&#10007; not pushed</strong> is stuck on the
            blog and never reached anyone &mdash; the case a PUSH (or, if it already went once, a
            RE-IMPRINT) fixes.
        </p>
        <?php if (!$posts): ?>
            <p class="dim">No posts yet.</p>
        <?php else: ?>
        <div class="ox-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Post</th>
                    <th>Status</th>
                    <th>Federation</th>
                    <th>Pushed</th>
                    <th>Published</th>
                    <th>Stuck jobs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $p):
                    $pt      = trim((string)$p['title']) !== '' ? (string)$p['title'] : (string)$p['slug'];
                    $fon     = (int)($p['fedi_enabled'] ?? 1) === 1;
                    $pub     = ($p['status'] ?? '') === 'published';
                    $pushed  = trim((string)($p['fedi_pushed_at'] ?? '')) !== '';
                    $stuck   = (int)($stuck_by_post[(int)$p['id']] ?? 0);
                    $flag_missed = ($pub && $fon && !$pushed);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($pt); ?> <span class="dim">#<?php echo (int)$p['id']; ?></span></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$p['status'])); ?></td>
                    <td><?php echo $fon ? 'on' : '<span class="dim">off</span>'; ?></td>
                    <td>
                        <?php if ($pushed): ?>
                            &#10003; <?php echo htmlspecialchars(dlog_age((string)$p['fedi_pushed_at'])); ?>
                        <?php elseif ($flag_missed): ?>
                            &#10007; not pushed
                        <?php else: ?>
                            <span class="dim">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars(dlog_age((string)$p['created_at'])); ?></td>
                    <td><?php echo $stuck > 0 ? (int)$stuck . ' retrying' : '<span class="dim">&mdash;</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <p class="dim">Read-only. This page never sends, retries or deletes &mdash; it only shows you the state
    so you can decide what to do on Cron &amp; Jobs or Push &amp; Tools.</p>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
