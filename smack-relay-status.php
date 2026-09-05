<?php
/**
 * SNAPSMACK - FEDIVERSE - Relay Status
 *
 * Spoke-side, read-only diagnostic for the SnapSmack network relay. Answers at a
 * glance: is this blog reachable-to and from the relay, is it actually joined
 * (not just optimistically), is the join/post delivery stuck, and is fan-out
 * arriving. Every value is either from this blog's own DB or a live probe of the
 * relay actor — no cross-database access to the relay's own store.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
require_once 'core/auth-smack.php';
require_once 'core/fediverse.php';

$page_title = 'Fediverse - Relay Status';
include 'core/admin-header.php';
include 'core/sidebar.php';

/* ---- gather state (all read-only, all defensive) ---------------------- */
$settings = [];
try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $settings = []; }

$relay_url_set = trim($settings['photoblogs_relay_url'] ?? '');
$relay_actor   = function_exists('sv_relay_actor_url') ? sv_relay_actor_url($settings) : $relay_url_set;
$relay_host    = $relay_actor !== '' ? (parse_url($relay_actor, PHP_URL_HOST) ?: '') : '';
$joined_flag   = (string)($settings['photoblogs_relay_joined'] ?? '') === '1';
$fedi_on       = (string)($settings['fediverse_enabled'] ?? '') === '1';
/* This blog may BE the network relay (SMACKCAST hub). A relay does not point at,
   reach, or join another relay — the spoke questions below don't apply to it. */
$is_relay_host = ($settings['distribution_profile'] ?? '') === 'smackcast';

/* live reachability probe of the relay actor (this is the check that would
   have caught tonight's "the server can't reach the relay" silently-failed join) */
// A relay host must NOT probe an external relay: with no relay URL set it would
// fetch the compiled-in default (the retired standalone box), whose actor still
// answers under its old name — which is exactly how "SMACKVERSE Relay" leaked
// back onto this page. This blog IS the relay; there is nothing external to reach.
$probe = ['ok' => false, 'inbox' => '', 'name' => '', 'error' => ''];
if (!$is_relay_host && $relay_actor !== '' && function_exists('sv_fetch_ap')) {
    try {
        $doc = sv_fetch_ap($relay_actor, $settings);
        if (is_array($doc) && !empty($doc['inbox'])) {
            $probe['ok']    = true;
            $probe['inbox'] = (string)$doc['inbox'];
            $probe['name']  = (string)($doc['name'] ?? ($doc['preferredUsername'] ?? ''));
        } else {
            $probe['error'] = 'The relay URL responded but is not an ActivityPub actor (no inbox). Wrong URL, or the relay is down.';
        }
    } catch (Throwable $e) {
        $probe['error'] = $e->getMessage();
    }
}

/* the real join record: a following row for the relay actor */
$follow_row = null;
if ($relay_actor !== '') {
    try {
        $st = $pdo->prepare("SELECT state, followed_at FROM snap_ap_following WHERE actor_url = ? LIMIT 1");
        $st->execute([$relay_actor]);
        $follow_row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $follow_row = null; }
}
$join_state = $follow_row['state'] ?? '';
$join_ok    = in_array(strtolower($join_state), ['accepted', 'active'], true);

/* outbound deliveries aimed at the relay host (queued / failing Follows + posts) */
$out_rows = [];
if ($relay_host !== '') {
    try {
        $st = $pdo->prepare("SELECT status, attempts, last_error, created_at
                               FROM snap_ap_deliveries
                              WHERE inbox_url LIKE ?
                           ORDER BY id DESC LIMIT 8");
        $st->execute(['%' . $relay_host . '%']);
        $out_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $out_rows = []; }
}

/* inbound fan-out: posts this blog discovered via the relay actor */
$in_count = null; $in_latest = '';
if ($relay_actor !== '') {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) c, MAX(t.published) m
                               FROM snap_ap_timeline_membership m
                               JOIN snap_ap_timeline t ON t.id = m.timeline_id
                              WHERE m.discovered_via_actor = ?");
        $st->execute([$relay_actor]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $in_count  = (int)$r['c'];
            $in_latest = (string)($r['m'] ?? '');
        }
    } catch (Throwable $e) { $in_count = null; }
}

/* plain-English verdict */
if ($is_relay_host) {
    $verdict = ['ok', 'This blog IS the SnapSmack network relay. Other blogs join it — it does not join itself, so there is nothing to configure here.'];
} elseif (!$fedi_on) {
    $verdict = ['warn', 'Federation is OFF on this blog. Turn it on in the Portal first — the relay needs a live actor to talk to.'];
} elseif ($relay_url_set === '') {
    $verdict = ['warn', 'No relay is configured. This blog is not pointed at a relay yet.'];
} elseif (!$probe['ok']) {
    $verdict = ['warn', 'The relay is NOT reachable from this server: ' . $probe['error'] . ' Nothing can be delivered — joins and posts will silently fail until this resolves.'];
} elseif (!$join_ok) {
    $verdict = ['warn', 'The relay is reachable, but this blog is NOT confirmed joined (state: ' . ($join_state !== '' ? $join_state : 'no join record') . '). The join is queued or failing — check Outbound below.'];
} else {
    $rx = ($in_count === null) ? 'unknown' : ($in_count . ' post' . ($in_count === 1 ? '' : 's'));
    $verdict = ['ok', 'Connected — joined and active on the relay. Posts received from the network: ' . $rx . '.'];
}
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>FEDIVERSE &mdash; RELAY STATUS</h2>
    </div>

    <div class="alert <?php echo $verdict[0] === 'ok' ? 'alert-success' : 'alert-warn'; ?>">
        &gt; <?php echo htmlspecialchars($verdict[1]); ?>
    </div>

    <!-- CONNECTION -->
    <div class="box mb-20">
        <h3>CONNECTION</h3>
        <table class="data-table">
            <tbody>
                <tr>
                    <th>Federation</th>
                    <td><?php echo $fedi_on ? '&#10003; ON' : '&#10007; OFF'; ?></td>
                </tr>
                <tr>
                    <th>Relay URL</th>
                    <td><code><?php
                        echo $is_relay_host
                            ? 'this blog is the relay'
                            : ($relay_url_set !== '' ? htmlspecialchars($relay_url_set) : '(not set — using default)');
                    ?></code></td>
                </tr>
                <tr>
                    <th>Relay reachable</th>
                    <td>
                        <?php if ($is_relay_host): ?>
                            &#10003; this blog is the relay &mdash; it does not reach out to another
                        <?php elseif ($probe['ok']): ?>
                            &#10003; yes &mdash; <?php echo htmlspecialchars($probe['name'] !== '' ? $probe['name'] : $relay_host); ?>
                            (inbox <code><?php echo htmlspecialchars($probe['inbox']); ?></code>)
                        <?php else: ?>
                            &#10007; no &mdash; <?php echo htmlspecialchars($probe['error'] !== '' ? $probe['error'] : 'no relay configured'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Joined</th>
                    <td>
                        <?php if ($is_relay_host): ?>
                            &#10003; this blog is the relay &mdash; it does not join itself
                        <?php elseif ($join_ok): ?>
                            &#10003; active<?php echo !empty($follow_row['followed_at']) ? ' since ' . htmlspecialchars($follow_row['followed_at']) : ''; ?>
                        <?php elseif ($join_state !== ''): ?>
                            &#9888; <?php echo htmlspecialchars($join_state); ?> (not yet confirmed by the relay)
                        <?php elseif ($joined_flag): ?>
                            &#9888; the blog is flagged joined, but there is no confirmed follow record &mdash; the join did not complete
                        <?php else: ?>
                            &#10007; not joined
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- OUTBOUND -->
    <div class="box mb-20">
        <h3>OUTBOUND TO RELAY</h3>
        <p class="dim mb-10">The last activities this blog tried to send to the relay (the join Follow, then posts). Repeated failures here mean the join or fan-out is stuck.</p>
        <?php if ($is_relay_host): ?>
            <p class="dim">This blog is the relay itself, so it sends no join Follow. Member blogs' joins and posts arrive on the relay's own inbox, not through this outbound queue.</p>
        <?php elseif (empty($out_rows)): ?>
            <p class="dim">Nothing has been queued to the relay. If you clicked JOIN and see nothing here, the join never fired &mdash; the blog could not reach the relay to send the Follow.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>When</th><th>Status</th><th>Attempts</th><th>Last error</th></tr></thead>
                <tbody>
                <?php foreach ($out_rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($r['created_at'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></td>
                        <td><?php echo (int)($r['attempts'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars(mb_substr((string)($r['last_error'] ?? ''), 0, 120)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- INBOUND -->
    <div class="box mb-20">
        <h3>INBOUND FROM RELAY</h3>
        <?php if ($in_count === null): ?>
            <p class="dim">Fan-out count unavailable on this install.</p>
        <?php elseif ($in_count === 0): ?>
            <p class="dim">No posts have arrived from the relay yet. Once you are active and other blogs post, their public posts show up here and in your reader.</p>
        <?php else: ?>
            <p>&#10003; <strong><?php echo (int)$in_count; ?></strong> post<?php echo $in_count === 1 ? '' : 's'; ?> received from the network<?php echo $in_latest !== '' ? ', most recent ' . htmlspecialchars($in_latest) : ''; ?>.</p>
        <?php endif; ?>
    </div>

    <p class="dim">Read-only. To join or leave, use <a href="smack-fediverse-portal.php">Portal &rarr; Fediverse Network</a>.</p>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
