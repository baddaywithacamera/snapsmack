<?php
/**
 * SMACK CENTRAL - SMACKATTACK Admin
 *
 * Network oversight dashboard: registered sites, coordination clusters,
 * suspended sites, top-scored fingerprints, and manual tools.
 */

require_once __DIR__ . '/sc-auth.php';

$msg  = '';
$err  = '';

try {
    $pdo = sc_enemy_db();
} catch (Throwable $e) {
    $err = 'SMACKATTACK DB unavailable: ' . htmlspecialchars($e->getMessage());
    $pdo = null;
}

// ── POST actions ──────────────────────────────────────────────────────────────

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'reinstate_site') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $pdo->prepare("UPDATE ste_sites SET weight_suspended = 0, status = 'active' WHERE id = ?")
            ->execute([$site_id]);
        $msg = "Site reinstated.";
    }

    if ($action === 'suspend_site') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $pdo->prepare("UPDATE ste_sites SET weight_suspended = 1 WHERE id = ?")
            ->execute([$site_id]);
        $msg = "Site weight suspended.";
    }

    if ($action === 'resolve_cluster') {
        $cluster_id = (int)($_POST['cluster_id'] ?? 0);
        // Un-quarantine reports in this cluster
        $pdo->prepare("
            UPDATE ste_reports SET is_quarantined = 0
            WHERE coordination_cluster_id = ?
        ")->execute([$cluster_id]);
        $pdo->prepare("UPDATE ste_coordination_clusters SET resolved = 1 WHERE id = ?")
            ->execute([$cluster_id]);
        // Recompute scores for affected fingerprints
        $fps = $pdo->prepare("
            SELECT DISTINCT fingerprint_id FROM ste_reports
            WHERE coordination_cluster_id = ?
        ");
        $fps->execute([$cluster_id]);
        foreach ($fps->fetchAll() as $row) {
            ste_recompute_score($pdo, $row['fingerprint_id']);
        }
        $msg = "Cluster resolved — quarantined reports reinstated and scores recomputed.";
    }

    if ($action === 'clear_fingerprint') {
        $fp_id = (int)($_POST['fp_id'] ?? 0);
        // Soft-clear: zero out score, mark green, remove from cache
        $pdo->prepare("UPDATE ste_fingerprints SET score = 0, colour_level = 'green' WHERE id = ?")->execute([$fp_id]);
        $pdo->prepare("DELETE FROM ste_score_cache WHERE fingerprint_id = ?")->execute([$fp_id]);
        $msg = "Fingerprint score cleared.";
    }

    if ($action === 'dismiss_style_cluster') {
        // Mark a pair of fingerprint IDs as a confirmed false positive
        $a = (int)($_POST['fp_a'] ?? 0);
        $b = (int)($_POST['fp_b'] ?? 0);
        if ($a && $b) {
            $sc_pdo      = sc_db();
            $dismissed   = json_decode(
                $sc_pdo->query("SELECT setting_val FROM sc_settings WHERE setting_key='ste_style_dismissed' LIMIT 1")->fetchColumn() ?: '[]',
                true
            ) ?: [];
            $pair        = [$a < $b ? $a : $b, $a < $b ? $b : $a];
            if (!in_array($pair, $dismissed, true)) $dismissed[] = $pair;
            $sc_pdo->prepare("INSERT INTO sc_settings (setting_key, setting_val) VALUES ('ste_style_dismissed', ?)
                              ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
                    ->execute([json_encode($dismissed)]);
            $msg = "Cluster dismissed — pair will not appear in future analysis runs.";
        }
    }

    if ($action === 'escalate_style_cluster') {
        // Escalate all fingerprints in a cluster to the highest colour among them
        $fp_ids_raw = $_POST['fp_ids'] ?? '';
        $fp_ids     = array_filter(array_map('intval', explode(',', $fp_ids_raw)));
        $target_col = $_POST['target_colour'] ?? '';
        $valid_cols = ['yellow','orange','red','black'];
        if ($fp_ids && in_array($target_col, $valid_cols, true)) {
            $placeholders = implode(',', array_fill(0, count($fp_ids), '?'));
            $pdo->prepare("UPDATE ste_fingerprints SET colour_level = ? WHERE id IN ({$placeholders})")
                ->execute(array_merge([$target_col], $fp_ids));
            $msg = "Escalated " . count($fp_ids) . " fingerprint(s) to " . strtoupper($target_col) . ".";
        }
    }
}

if ($pdo) require_once __DIR__ . '/sc-enemy-scoring.php';

// ── Style analysis helper ─────────────────────────────────────────────────────

/**
 * Pull all non-expired style vectors, compute pairwise cosine similarity,
 * return connected clusters above the 0.80 threshold.
 *
 * Returns [$clusters, $ran] where $clusters is an array of cluster arrays
 * and $ran is always true on success.
 */
function ste_style_run_analysis(PDO $pdo): array {
    // Load dismissed pairs from SC settings
    try {
        $sc_pdo    = sc_db();
        $dismissed = json_decode(
            $sc_pdo->query("SELECT setting_val FROM sc_settings WHERE setting_key='ste_style_dismissed' LIMIT 1")->fetchColumn() ?: '[]',
            true
        ) ?: [];
    } catch (Exception $e) {
        $dismissed = [];
    }

    // Pull vectors for non-green flagged fingerprints with valid style data
    $rows = $pdo->query("
        SELECT sv.fingerprint_id, sv.vector, sv.word_count, sv.comment_count,
               f.ban_type, f.ban_value, f.score, f.colour_level,
               GROUP_CONCAT(DISTINCT ss.site_url ORDER BY ss.site_url SEPARATOR ', ') AS reporting_sites
        FROM ste_style_vectors sv
        JOIN ste_fingerprints f ON f.id = sv.fingerprint_id
        JOIN ste_sites ss        ON ss.id = sv.site_id
        WHERE sv.expires_at >= CURDATE()
          AND f.colour_level != 'green'
        GROUP BY sv.fingerprint_id
        LIMIT 2000
    ")->fetchAll();

    if (count($rows) < 2) return [[], true];

    // Decode vectors
    $fps = [];
    foreach ($rows as $row) {
        $vec = json_decode($row['vector'], true);
        if (!is_array($vec) || count($vec) !== 25) continue;
        $fps[$row['fingerprint_id']] = array_merge($row, ['vec' => array_map('floatval', $vec)]);
    }

    $ids = array_keys($fps);
    $n   = count($ids);
    if ($n < 2) return [[], true];

    // Union-find
    $parent = array_combine($ids, $ids);
    $find   = function(int $x) use (&$parent, &$find): int {
        if ($parent[$x] !== $x) $parent[$x] = $find($parent[$x]);
        return $parent[$x];
    };
    $union  = function(int $a, int $b) use (&$parent, &$find): void {
        $ra = $find($a); $rb = $find($b);
        if ($ra !== $rb) $parent[$rb] = $ra;
    };

    // Edge data: keep highest similarity per pair for display
    $edges = [];

    for ($i = 0; $i < $n - 1; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $idA = $ids[$i]; $idB = $ids[$j];

            // Skip dismissed pairs
            $pair = [$idA < $idB ? $idA : $idB, $idA < $idB ? $idB : $idA];
            if (in_array($pair, $dismissed, true)) continue;

            $vA  = $fps[$idA]['vec'];
            $vB  = $fps[$idB]['vec'];
            $sim = ste_style_cosine($vA, $vB);

            if ($sim >= 0.80) {
                $union($idA, $idB);
                $key = $idA . '_' . $idB;
                $edges[$key] = ['a' => $idA, 'b' => $idB, 'sim' => $sim];
            }
        }
    }

    // Group into clusters
    $groups = [];
    foreach ($ids as $id) {
        $root = $find($id);
        $groups[$root][] = $id;
    }

    $clusters = [];
    foreach ($groups as $members) {
        if (count($members) < 2) continue;

        // Find max similarity within the cluster
        $max_sim = 0.0;
        $cluster_edges = [];
        for ($i = 0; $i < count($members) - 1; $i++) {
            for ($j = $i + 1; $j < count($members); $j++) {
                $a = $members[$i]; $b = $members[$j];
                $key = $a . '_' . $b;
                $rkey = $b . '_' . $a;
                $sim = $edges[$key]['sim'] ?? ($edges[$rkey]['sim'] ?? 0.0);
                if ($sim > 0) {
                    $cluster_edges[] = ['a' => $a, 'b' => $b, 'sim' => $sim];
                    $max_sim = max($max_sim, $sim);
                }
            }
        }

        // Determine confidence
        if ($max_sim >= 0.95)      $confidence = 'STRONG MATCH';
        elseif ($max_sim >= 0.90)  $confidence = 'LIKELY MATCH';
        else                       $confidence = 'POSSIBLE MATCH';

        // Target colour = highest colour in cluster
        $colour_order  = ['green'=>0,'yellow'=>1,'orange'=>2,'red'=>3,'black'=>4];
        $target_colour = 'yellow';
        foreach ($members as $mid) {
            $col = $fps[$mid]['colour_level'];
            if (($colour_order[$col] ?? 0) > ($colour_order[$target_colour] ?? 0)) {
                $target_colour = $col;
            }
        }

        $clusters[] = [
            'members'       => array_map(fn($mid) => $fps[$mid], $members),
            'edges'         => $cluster_edges,
            'max_sim'       => $max_sim,
            'confidence'    => $confidence,
            'target_colour' => $target_colour,
            'member_ids'    => implode(',', $members),
        ];
    }

    // Sort by confidence desc
    usort($clusters, fn($a, $b) => $b['max_sim'] <=> $a['max_sim']);

    return [$clusters, true];
}

function ste_style_cosine(array $a, array $b): float {
    $dot = $magA = $magB = 0.0;
    for ($i = 0; $i < 25; $i++) {
        $dot  += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }
    $denom = sqrt($magA) * sqrt($magB);
    return $denom > 0 ? round($dot / $denom, 4) : 0.0;
}

// ── Stats ─────────────────────────────────────────────────────────────────────

try { if (!$pdo) throw new PDOException($err ?: 'No DB connection.');
    $stats = [
        'sites_active'  => (int)$pdo->query("SELECT COUNT(*) FROM ste_sites WHERE status = 'active'")->fetchColumn(),
        'sites_opted_out' => (int)$pdo->query("SELECT COUNT(*) FROM ste_sites WHERE status = 'opted_out'")->fetchColumn(),
        'sites_suspended' => (int)$pdo->query("SELECT COUNT(*) FROM ste_sites WHERE weight_suspended = 1")->fetchColumn(),
        'total_fps'     => (int)$pdo->query("SELECT COUNT(*) FROM ste_fingerprints")->fetchColumn(),
        'total_reports' => (int)$pdo->query("SELECT COUNT(*) FROM ste_reports WHERE is_quarantined = 0")->fetchColumn(),
        'quarantined'   => (int)$pdo->query("SELECT COUNT(*) FROM ste_reports WHERE is_quarantined = 1")->fetchColumn(),
        'total_allows'  => (int)$pdo->query("SELECT COUNT(*) FROM ste_allow_votes")->fetchColumn(),
        'black_count'   => (int)$pdo->query("SELECT COUNT(*) FROM ste_fingerprints WHERE colour_level = 'black'")->fetchColumn(),
        'red_count'     => (int)$pdo->query("SELECT COUNT(*) FROM ste_fingerprints WHERE colour_level = 'red'")->fetchColumn(),
        'clusters_open'  => (int)$pdo->query("SELECT COUNT(*) FROM ste_coordination_clusters WHERE resolved = 0")->fetchColumn(),
        'style_vectors'  => (int)$pdo->query("SELECT COUNT(*) FROM ste_style_vectors WHERE expires_at >= CURDATE()")->fetchColumn(),
    ];

    $top_fps = $pdo->query("
        SELECT id, ban_type, ban_value, score, colour_level, report_count, allow_count, last_seen
        FROM ste_fingerprints
        WHERE colour_level != 'green'
        ORDER BY score DESC
        LIMIT 25
    ")->fetchAll();

    $sites = $pdo->query("
        SELECT s.*,
            ROUND(
                LEAST(1.0, LOG10(GREATEST(1, s.post_count) + 1) / 2.5)
                * LEAST(1.0, TIMESTAMPDIFF(DAY, s.registered_at, NOW()) / 90.0)
                * GREATEST(0.1, 1.0 - s.override_rate)
            , 4) AS computed_weight
        FROM ste_sites s
        ORDER BY computed_weight DESC, s.report_count DESC
        LIMIT 100
    ")->fetchAll();

    $clusters = $pdo->query("
        SELECT cl.*, f.ban_type, f.ban_value, f.score, f.colour_level
        FROM ste_coordination_clusters cl
        JOIN ste_fingerprints f ON f.id = cl.fingerprint_id
        WHERE cl.resolved = 0
        ORDER BY cl.detected_at DESC
        LIMIT 20
    ")->fetchAll();

    // ── Style analysis (run on demand only) ──────────────────────────────────
    $style_clusters  = [];
    $style_ran       = false;
    if (isset($_POST['action']) && $_POST['action'] === 'run_style_analysis') {
        [$style_clusters, $style_ran] = ste_style_run_analysis($pdo);
    }

    $db_ok = true;
} catch (PDOException $e) {
    $err   = "SMACKATTACK DB unavailable: " . htmlspecialchars($e->getMessage());
    $db_ok = false;
    $stats = $top_fps = $sites = $clusters = [];
    $style_clusters = []; $style_ran = false;
}

include __DIR__ . '/sc-layout-top.php';
?>

<style>
.ste-stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-bottom:24px; }
.ste-stat { background:var(--sc-surface,#1a1a2e); border:1px solid var(--sc-border,#333); border-radius:4px; padding:16px; text-align:center; }
.ste-stat .num { font-size:2em; font-weight:bold; color:var(--sc-accent,#cc2222); }
.ste-stat .lbl { font-size:0.78em; color:#888; margin-top:4px; text-transform:uppercase; letter-spacing:0.06em; }
.colour-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
.dot-green  { background:#4caf50; }
.dot-yellow { background:#ffc107; }
.dot-orange { background:#ff9800; }
.dot-red    { background:#f44336; }
.dot-black  { background:#111; border:1px solid #555; }
.ste-tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--sc-border,#333); padding-bottom:0; }
.ste-tab { padding:8px 18px; cursor:pointer; border:1px solid transparent; border-bottom:none; border-radius:4px 4px 0 0; font-size:0.85em; letter-spacing:0.05em; background:transparent; color:#888; }
.ste-tab.active { background:var(--sc-surface,#1a1a2e); border-color:var(--sc-border,#333); color:#fff; }
.ste-panel { display:none; } .ste-panel.active { display:block; }
</style>

<h2 style="margin-bottom:4px;">SMACKATTACK</h2>
<p style="color:#888; margin-bottom:24px;">Shield Tier 3 — Network Reputation &amp; GOBSMACKED</p>

<?php if ($msg): ?><div class="sc-msg"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="sc-error"><?php echo $err; ?></div><?php endif; ?>

<?php if ($db_ok): ?>

<div class="ste-stat-grid">
    <div class="ste-stat"><div class="num"><?php echo $stats['sites_active']; ?></div><div class="lbl">Active Sites</div></div>
    <div class="ste-stat"><div class="num"><?php echo $stats['total_fps']; ?></div><div class="lbl">Fingerprints</div></div>
    <div class="ste-stat"><div class="num"><?php echo $stats['total_reports']; ?></div><div class="lbl">Reports</div></div>
    <div class="ste-stat"><div class="num"><?php echo $stats['total_allows']; ?></div><div class="lbl">Allow Votes</div></div>
    <div class="ste-stat"><div class="num" style="color:#f44336"><?php echo $stats['black_count']; ?></div><div class="lbl">Black</div></div>
    <div class="ste-stat"><div class="num" style="color:#ff9800"><?php echo $stats['red_count']; ?></div><div class="lbl">Red</div></div>
    <div class="ste-stat"><div class="num" style="color:<?php echo $stats['clusters_open'] ? '#ffc107' : '#4caf50'; ?>"><?php echo $stats['clusters_open']; ?></div><div class="lbl">Open Clusters</div></div>
    <div class="ste-stat"><div class="num" style="color:<?php echo $stats['quarantined'] ? '#ffc107' : '#4caf50'; ?>"><?php echo $stats['quarantined']; ?></div><div class="lbl">Quarantined</div></div>
    <div class="ste-stat"><div class="num" style="color:#90caf9"><?php echo $stats['style_vectors']; ?></div><div class="lbl">GOBSMACKED</div></div>
</div>

<div class="ste-tabs">
    <button class="ste-tab active" onclick="steTab('fps')">TOP SCORES</button>
    <button class="ste-tab" onclick="steTab('sites')">SITES</button>
    <button class="ste-tab" onclick="steTab('clusters')">CLUSTERS <?php if($stats['clusters_open']): ?><span style="color:#ffc107">(<?php echo $stats['clusters_open']; ?>)</span><?php endif; ?></button>
    <button class="ste-tab" onclick="steTab('style')">GOBSMACKED <?php if($stats['style_vectors']): ?><span style="color:#90caf9">(<?php echo $stats['style_vectors']; ?>)</span><?php endif; ?></button>
    <button class="ste-tab" onclick="steTab('help')" style="margin-left:auto">HOW IT WORKS</button>
</div>

<!-- TOP FINGERPRINTS ────────────────────────────────────────────────────── -->
<div class="ste-panel active" id="panel-fps">
    <table class="sc-table" style="width:100%">
        <thead><tr>
            <th>Hash</th><th>Type</th><th>Score</th><th>Level</th>
            <th style="text-align:center">Reports</th><th style="text-align:center">Allows</th>
            <th>Last Seen</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($top_fps as $fp): ?>
        <tr>
            <td><code style="font-size:0.8em"><?php echo substr($fp['ban_value'], 0, 16); ?>…</code></td>
            <td><small><?php echo htmlspecialchars($fp['ban_type']); ?></small></td>
            <td><strong><?php echo number_format($fp['score'], 2); ?></strong></td>
            <td>
                <span class="colour-dot dot-<?php echo $fp['colour_level']; ?>"></span>
                <?php echo strtoupper($fp['colour_level']); ?>
            </td>
            <td style="text-align:center"><?php echo $fp['report_count']; ?></td>
            <td style="text-align:center"><?php echo $fp['allow_count']; ?></td>
            <td><small><?php echo date('Y-m-d', strtotime($fp['last_seen'])); ?></small></td>
            <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Clear this fingerprint score?')">
                    <input type="hidden" name="action" value="clear_fingerprint">
                    <input type="hidden" name="fp_id" value="<?php echo (int)$fp['id']; ?>">
                    <button type="submit" class="sc-btn sc-btn--sm sc-btn--dim">Clear</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($top_fps)): ?>
        <tr><td colspan="8" style="color:#888; text-align:center; padding:20px;">No flagged fingerprints yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- SITES ───────────────────────────────────────────────────────────────── -->
<div class="ste-panel" id="panel-sites">
    <table class="sc-table" style="width:100%">
        <thead><tr>
            <th>Site</th><th>Status</th><th style="text-align:center">Posts</th>
            <th style="text-align:center">Weight</th><th style="text-align:center">Reports</th>
            <th style="text-align:center">Override%</th><th>Registered</th><th>Last Seen</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sites as $s):
            $host   = parse_url($s['site_url'], PHP_URL_HOST) ?: $s['site_url'];
            $status_color = $s['status'] === 'active' && !$s['weight_suspended'] ? '#4caf50' : '#f44336';
            $status_label = $s['weight_suspended'] ? 'SUSPENDED' : strtoupper($s['status']);
        ?>
        <tr>
            <td><a href="<?php echo htmlspecialchars($s['site_url']); ?>" target="_blank" style="color:inherit"><?php echo htmlspecialchars($host); ?></a></td>
            <td><span style="color:<?php echo $status_color; ?>; font-size:0.8em"><?php echo $status_label; ?></span></td>
            <td style="text-align:center"><?php echo number_format($s['post_count']); ?></td>
            <td style="text-align:center"><strong><?php echo $s['computed_weight']; ?></strong></td>
            <td style="text-align:center"><?php echo $s['report_count']; ?></td>
            <td style="text-align:center"><?php echo round($s['override_rate'] * 100); ?>%</td>
            <td><small><?php echo date('Y-m-d', strtotime($s['registered_at'])); ?></small></td>
            <td><small><?php echo $s['last_seen_at'] ? date('Y-m-d', strtotime($s['last_seen_at'])) : '—'; ?></small></td>
            <td>
                <?php if ($s['weight_suspended']): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="reinstate_site">
                    <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                    <button type="submit" class="sc-btn sc-btn--sm">Reinstate</button>
                </form>
                <?php elseif ($s['status'] === 'active'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Suspend this site\'s report weight?')">
                    <input type="hidden" name="action" value="suspend_site">
                    <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                    <button type="submit" class="sc-btn sc-btn--sm sc-btn--dim">Suspend</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($sites)): ?>
        <tr><td colspan="9" style="color:#888; text-align:center; padding:20px;">No registered sites yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- COORDINATION CLUSTERS ───────────────────────────────────────────────── -->
<div class="ste-panel" id="panel-clusters">
    <?php if (empty($clusters)): ?>
    <p style="color:#4caf50; padding:20px 0">✓ No open coordination clusters. Network looks clean.</p>
    <?php else: ?>
    <table class="sc-table" style="width:100%">
        <thead><tr>
            <th>Fingerprint</th><th>Score</th><th>Level</th>
            <th style="text-align:center">Sites</th><th>Detected</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($clusters as $cl): ?>
        <tr>
            <td><code style="font-size:0.8em"><?php echo substr($cl['ban_value'], 0, 16); ?>…</code> <small style="color:#888"><?php echo $cl['ban_type']; ?></small></td>
            <td><?php echo number_format($cl['score'], 2); ?></td>
            <td><span class="colour-dot dot-<?php echo $cl['colour_level']; ?>"></span><?php echo strtoupper($cl['colour_level']); ?></td>
            <td style="text-align:center"><?php echo $cl['site_count']; ?></td>
            <td><small><?php echo date('Y-m-d H:i', strtotime($cl['detected_at'])); ?></small></td>
            <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Resolve this cluster and reinstate quarantined reports?')">
                    <input type="hidden" name="action" value="resolve_cluster">
                    <input type="hidden" name="cluster_id" value="<?php echo (int)$cl['id']; ?>">
                    <button type="submit" class="sc-btn sc-btn--sm">Resolve</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- STYLE ANALYSIS ──────────────────────────────────────────────────────── -->
<div class="ste-panel" id="panel-style">
    <p style="color:#aaa; font-size:0.88rem; margin-bottom:20px; max-width:680px;">
        Compares writing style vectors across all flagged fingerprints. Clusters fingerprints
        that appear to be the same person using different identities to evade a prior ban.
        Run this manually every week or two. Raw comment text is never stored here — only numeric feature vectors.
    </p>

    <?php if (!$style_ran): ?>
    <form method="post" action="sc-enemy-admin.php#panel-style">
        <input type="hidden" name="action" value="run_style_analysis">
        <button type="submit" class="sc-btn sc-btn-primary">Run GOBSMACKED Analysis (<?php echo $stats['style_vectors']; ?> vectors)</button>
    </form>
    <?php elseif (empty($style_clusters)): ?>
    <p style="color:#4caf50; padding:12px 0">
        ✓ Analysis complete — no suspicious clusters found across <?php echo $stats['style_vectors']; ?> vectors.
    </p>
    <?php else: ?>
    <p style="color:#ffc107; margin-bottom:20px; font-size:0.88rem;">
        Found <?php echo count($style_clusters); ?> cluster<?php echo count($style_clusters) !== 1 ? 's' : ''; ?> of fingerprints with similar writing style.
    </p>

    <?php foreach ($style_clusters as $ci => $cl):
        $conf_colour = match($cl['confidence']) {
            'STRONG MATCH' => '#f44336',
            'LIKELY MATCH' => '#ff9800',
            default        => '#ffc107',
        };
    ?>
    <div style="border:1px solid #333; border-radius:4px; padding:16px; margin-bottom:20px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
            <span style="color:<?php echo $conf_colour; ?>; font-family:monospace; font-size:0.82rem; font-weight:bold; letter-spacing:0.08em;">
                <?php echo $cl['confidence']; ?> — <?php echo number_format($cl['max_sim'] * 100, 1); ?>% similarity
            </span>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <form method="post" style="display:inline" onsubmit="return confirm('Escalate all fingerprints in this cluster to <?php echo strtoupper($cl['target_colour']); ?>?')">
                    <input type="hidden" name="action" value="escalate_style_cluster">
                    <input type="hidden" name="fp_ids" value="<?php echo htmlspecialchars($cl['member_ids']); ?>">
                    <input type="hidden" name="target_colour" value="<?php echo htmlspecialchars($cl['target_colour']); ?>">
                    <button type="submit" class="sc-btn sc-btn--sm" style="border-color:<?php echo $conf_colour; ?>">
                        Escalate All to <?php echo strtoupper($cl['target_colour']); ?>
                    </button>
                </form>
                <?php if (count($cl['members']) === 2):
                    $idA = $cl['members'][0]['fingerprint_id'];
                    $idB = $cl['members'][1]['fingerprint_id'];
                ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="dismiss_style_cluster">
                    <input type="hidden" name="fp_a" value="<?php echo (int)$idA; ?>">
                    <input type="hidden" name="fp_b" value="<?php echo (int)$idB; ?>">
                    <button type="submit" class="sc-btn sc-btn--sm sc-btn--dim">Dismiss</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <table class="sc-table" style="width:100%; font-size:0.82rem;">
            <thead><tr>
                <th>Hash</th><th>Type</th><th>Level</th>
                <th style="text-align:center">Score</th>
                <th>Reported by</th>
            </tr></thead>
            <tbody>
            <?php foreach ($cl['members'] as $m): ?>
            <tr>
                <td><code><?php echo substr($m['ban_value'], 0, 16); ?>…</code></td>
                <td><small><?php echo htmlspecialchars($m['ban_type']); ?></small></td>
                <td>
                    <span class="colour-dot dot-<?php echo $m['colour_level']; ?>"></span>
                    <?php echo strtoupper($m['colour_level']); ?>
                </td>
                <td style="text-align:center"><?php echo number_format($m['score'], 2); ?></td>
                <td><small style="color:#aaa"><?php echo htmlspecialchars($m['reporting_sites'] ?? '—'); ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($cl['edges']) > 0): ?>
        <div style="margin-top:10px; font-size:0.78rem; color:#666; font-family:monospace;">
            <?php foreach ($cl['edges'] as $edge): ?>
            <span style="margin-right:16px;">
                …<?php echo substr($cl['members'][array_search($edge['a'], array_column($cl['members'], 'fingerprint_id'))]['ban_value'] ?? '', 12, 8); ?>
                ↔
                …<?php echo substr($cl['members'][array_search($edge['b'], array_column($cl['members'], 'fingerprint_id'))]['ban_value'] ?? '', 12, 8); ?>
                <span style="color:<?php echo $conf_colour; ?>"><?php echo number_format($edge['sim'] * 100, 1); ?>%</span>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- HOW IT WORKS ────────────────────────────────────────────────────────── -->
<div class="ste-panel" id="panel-help" style="max-width:720px; line-height:1.65; font-size:0.9rem; color:#ccc;">
    <h3 style="margin-top:0; color:#fff;">How SMACKATTACK Works</h3>
    <p>SMACKATTACK is a voluntary network reputation system. Participating SnapSmack sites report bad fingerprints (trolls, spammers) to the central server. The server scores each fingerprint based on how many sites have reported it and how trustworthy those sites are, then makes the scores available to all participants.</p>

    <h4 style="color:#fff;">Threat Levels</h4>
    <p>Every fingerprint gets a colour based on its weighted score:</p>
    <ul style="list-style:none; padding:0;">
        <li style="margin-bottom:6px;"><span class="colour-dot dot-green"></span><strong>Green</strong> — Not flagged. Score = 0.</li>
        <li style="margin-bottom:6px;"><span class="colour-dot dot-yellow"></span><strong>Yellow</strong> — 1 strike. Score ≥ 1.0.</li>
        <li style="margin-bottom:6px;"><span class="colour-dot dot-orange"></span><strong>Orange</strong> — 2 strikes. Score ≥ 3.0.</li>
        <li style="margin-bottom:6px;"><span class="colour-dot dot-red"></span><strong>Red</strong> — 3 strikes. Score ≥ 6.0.</li>
        <li style="margin-bottom:6px;"><span class="colour-dot dot-black"></span><strong>Black</strong> — 4+ strikes. Score ≥ 10.0.</li>
    </ul>
    <p>Each blog owner sets their own auto-ban threshold. A site set to "orange" will silently reject any commenter at orange or higher. A site set to "red" gives more benefit of the doubt.</p>

    <h4 style="color:#fff;">Site Weight</h4>
    <p>Not all reports carry the same weight. A report from a well-established blog with hundreds of posts carries more authority than a brand new site with one post. Weight is computed from three factors multiplied together:</p>
    <ul>
        <li><strong>Post count</strong> — logarithmic curve: 10 posts ≈ 0.40, 100 posts ≈ 0.80, 300+ posts ≈ 1.0.</li>
        <li><strong>Age</strong> — linear ramp over 90 days from registration to full weight.</li>
        <li><strong>Approval ratio</strong> — if a site's allow-votes frequently overturn reports from other sites, its weight is reduced via the override_rate feedback loop.</li>
    </ul>

    <h4 style="color:#fff;">Community Allow Votes</h4>
    <p>When a blog owner decides a flagged commenter is legitimate and approves their comment, that's an allow-vote against the fingerprint. Allow votes reduce the fingerprint's score. This is how false positives get corrected — the community rolls them back organically without any central intervention.</p>

    <h4 style="color:#fff;">Sybil Resistance</h4>
    <p>Three mechanisms prevent gaming the system:</p>
    <ul>
        <li><strong>Velocity limit</strong> — a site can't file more than 20 reports per hour.</li>
        <li><strong>Coordination detection</strong> — if 5 or more sites report the same fingerprint within 10 minutes and have no prior co-reporting history, the reports are quarantined and a cluster is opened for review here.</li>
        <li><strong>Reporter feedback</strong> — sites whose reports get allow-voted down repeatedly lose reporting weight over time.</li>
    </ul>

    <h4 style="color:#fff;">Admin Actions</h4>
    <ul>
        <li><strong>Reinstate / Suspend site</strong> — temporarily zero out a site's weight if you suspect abuse, without removing them from the network.</li>
        <li><strong>Resolve cluster</strong> — review quarantined coordination clusters and either release the reports (real threat) or dismiss them (false alarm).</li>
        <li><strong>Clear fingerprint</strong> — wipe a fingerprint's score back to zero if a report was clearly bogus.</li>
    </ul>

    <h4 style="color:#fff;">Privacy</h4>
    <p>Only SHA-256 hashes of IP addresses, emails, and browser fingerprints are transmitted — never the originals. The hash cannot be reversed. Sites are identified by URL; no personal data about blog owners is collected.</p>

    <h4 style="color:#fff;">Score Decay</h4>
    <p>Scores decay with time. The half-life is 6 months — a fingerprint that earned a red score but goes quiet will fall back to orange in 6 months, yellow in a year, and green eventually. Persistent threats stay red because new reports keep refreshing the score.</p>
</div>

<?php endif; // db_ok ?>

<script>
function steTab(name) {
    document.querySelectorAll('.ste-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.ste-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    event.target.classList.add('active');
}
</script>

<?php include __DIR__ . '/sc-layout-bottom.php'; ?>
// EOF
