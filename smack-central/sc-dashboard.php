<?php
/**
 * SMACK CENTRAL - Dashboard
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-dashboard.php';
$sc_page_title = 'Dashboard';

// Handle fleet pruning action
$fleet_prune_msg = null;
if (isset($_POST['prune_uid'])) {
    $prune_uid = preg_replace('/[^a-f0-9]/', '', strtolower($_POST['prune_uid']));
    if (strlen($prune_uid) === 32) {
        try {
            $del = sc_db()->prepare("DELETE FROM sc_phone_home WHERE uid = ?");
            $del->execute([$prune_uid]);
            $fleet_prune_msg = $del->rowCount() ? "Pruned UID {$prune_uid}." : "UID not found.";
        } catch (Exception $e) { $fleet_prune_msg = "Error: " . $e->getMessage(); }
    }
}

// Quick stats
$stats = ['installs' => 0, 'threads' => 0, 'replies' => 0, 'releases' => 0,
          'thomas_y' => 0, 'thomas_z' => 0, 'thomas_unique' => 0, 'thomas_sites' => 0];
$fleet_rows  = [];
$spoke_by_hub = []; // hub_uid => [ spoke rows ]
try {
    // Active installs: count distinct hub rows. WHERE role='hub' excludes spoke
    // ping rows (added in later schema). spoke_count on each hub row records how
    // many spokes that hub has, but each hub is one install regardless.
    $db = sc_db();
    $stats['installs'] = (int)$db->query(
        "SELECT COUNT(*) FROM sc_phone_home
         WHERE role = 'hub' AND last_seen >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
    )->fetchColumn();
    // Hub/standalone rows for the diagnostics table
    $fleet_rows = $db->query(
        "SELECT uid, version, track, spoke_count, role, hub_uid, first_seen, last_seen
         FROM sc_phone_home WHERE role = 'hub' ORDER BY last_seen DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    // Spoke rows grouped by hub_uid for accordion display
    $spoke_rows_raw = $db->query(
        "SELECT uid, version, track, hub_uid, last_seen
         FROM sc_phone_home WHERE role = 'spoke' ORDER BY last_seen DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($spoke_rows_raw as $sr) {
        if ($sr['hub_uid']) {
            $spoke_by_hub[$sr['hub_uid']][] = $sr;
        }
    }
} catch (Exception $e) {
    // role column may not exist yet — fall back to old query without role filter
    try {
        $db = sc_db();
        $stats['installs'] = (int)$db->query(
            "SELECT COUNT(*) FROM sc_phone_home WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        )->fetchColumn();
        $fleet_rows = $db->query(
            "SELECT uid, version, track, spoke_count, NULL AS role, NULL AS hub_uid, first_seen, last_seen FROM sc_phone_home ORDER BY last_seen DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) { /* sc_phone_home not yet created */ }
}
try {
    $fdb = sc_forum_db();
    $stats['threads']  = (int)$fdb->query("SELECT COUNT(*) FROM ss_forum_threads  WHERE is_deleted = 0")->fetchColumn();
    $stats['replies']  = (int)$fdb->query("SELECT COUNT(*) FROM ss_forum_replies  WHERE is_deleted = 0")->fetchColumn();
} catch (Exception $e) { /* forum tables may not exist yet */ }
try {
    $stats['releases'] = (int)sc_db()->query("SELECT COUNT(*) FROM sc_releases")->fetchColumn();
} catch (Exception $e) {}
try {
    // Thomas the Bear discover stats — network totals across all pinging installs.
    // sc_thomas is populated by releases/thomas-ping.php.
    $thomas = sc_db()->query(
        "SELECT COALESCE(SUM(y_presses),0), COALESCE(SUM(z_presses),0), COALESCE(SUM(unique_finders),0), COUNT(*) FROM sc_thomas"
    )->fetch(PDO::FETCH_NUM);
    $stats['thomas_y']      = (int)$thomas[0];
    $stats['thomas_z']      = (int)$thomas[1];
    $stats['thomas_unique'] = (int)$thomas[2];
    $stats['thomas_sites']  = (int)$thomas[3];
} catch (Exception $e) { /* sc_thomas not yet created */ }

$latest_release = null;
try {
    $latest_release = sc_db()->query("SELECT version_full, released_at FROM sc_releases WHERE is_latest = 1 LIMIT 1")->fetch();
} catch (Exception $e) {}

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">Dashboard</span>
  <?php if ($latest_release): ?>
  <span class="sc-dim">Latest release: <?php echo htmlspecialchars($latest_release['version_full']); ?>
    &nbsp;&bull;&nbsp;<?php echo htmlspecialchars($latest_release['released_at']); ?></span>
  <?php endif; ?>
</div>

<div class="sc-stat-grid">
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['installs']; ?></div>
    <div class="sc-stat__label">Active Installs</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['threads']; ?></div>
    <div class="sc-stat__label">Forum Threads</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['replies']; ?></div>
    <div class="sc-stat__label">Forum Replies</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['releases']; ?></div>
    <div class="sc-stat__label">Releases Shipped</div>
  </div>
</div>

<?php if ($stats['thomas_y'] > 0 || $stats['thomas_z'] > 0): ?>
<div class="sc-stat-grid" style="margin-top:8px;">
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_y']; ?></div>
    <div class="sc-stat__label">Bears Spawned</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_z']; ?></div>
    <div class="sc-stat__label">Story Reads</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_unique']; ?></div>
    <div class="sc-stat__label">Unique Finders</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_sites']; ?></div>
    <div class="sc-stat__label">Sites w/ Discovers</div>
  </div>
</div>
<?php endif; ?>

<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Quick Links</span></div>
  <div class="sc-box-body">
    <div class="sc-btn-row">
      <a href="sc-release.php" class="sc-btn">Release Packager</a>
      <a href="sc-forum.php"   class="sc-btn">Forum Admin</a>
    </div>
  </div>
</div>

<?php if (!empty($fleet_rows)): ?>
<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Fleet Diagnostics</span>
    <span class="sc-dim" style="font-size:0.8em;margin-left:8px;"><?php echo count($fleet_rows); ?> hub row(s) &mdash; active window counts <?php echo $stats['installs']; ?></span>
  </div>
  <div class="sc-box-body" style="padding:0;">
    <?php if ($fleet_prune_msg): ?>
      <div style="padding:8px 12px;background:var(--sc-accent-dim,#1a1a1a);font-size:0.85em;"><?php echo htmlspecialchars($fleet_prune_msg); ?></div>
    <?php endif; ?>
    <table style="width:100%;border-collapse:collapse;font-size:0.82em;font-family:monospace;">
      <thead>
        <tr style="border-bottom:1px solid var(--sc-border,#333);">
          <th style="padding:6px 10px;text-align:left;color:var(--sc-dim,#888);width:22px;"></th>
          <th style="padding:6px 10px;text-align:left;color:var(--sc-dim,#888);">UID (first 12)</th>
          <th style="padding:6px 10px;text-align:left;color:var(--sc-dim,#888);">Version</th>
          <th style="padding:6px 10px;text-align:left;color:var(--sc-dim,#888);">Track</th>
          <th style="padding:6px 10px;text-align:right;color:var(--sc-dim,#888);">Spokes</th>
          <th style="padding:6px 10px;text-align:right;color:var(--sc-dim,#888);">Counts as</th>
          <th style="padding:6px 10px;text-align:left;color:var(--sc-dim,#888);">Last Seen</th>
          <th style="padding:6px 10px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $now = new DateTime();
        $fleet_idx = 0;
        foreach ($fleet_rows as $row):
            $fleet_idx++;
            $age_days  = $now->diff(new DateTime($row['last_seen']))->days;
            $stale     = $age_days > 90;
            $row_style = $stale ? 'opacity:0.45;' : '';
            $counts_as = 1 + (int)$row['spoke_count'];
            $hub_spokes = $spoke_by_hub[$row['uid']] ?? [];
            $has_spokes = !empty($hub_spokes);
            $accord_id  = 'fleet-acc-' . $fleet_idx;

            // Compute display track: aggregate hub track + all linked spoke tracks
            $all_tracks     = array_merge([$row['track']], array_column($hub_spokes, 'track'));
            $unique_tracks  = array_unique($all_tracks);
            $track_label    = count($unique_tracks) > 1 ? 'mixed' : $unique_tracks[0];
            $track_counts   = array_count_values($all_tracks);
            $track_tooltip  = implode(' · ', array_map(fn($t, $c) => "{$c} {$t}", array_keys($track_counts), $track_counts));
        ?>
        <tr style="border-bottom:1px solid var(--sc-border,#222);<?php echo $row_style; ?>">
          <td style="padding:5px 6px 5px 10px;">
            <?php if ($has_spokes): ?>
            <button onclick="(function(b,r){r.style.display=r.style.display==='none'?'table-row':'none';b.textContent=r.style.display==='none'?'▶':'▼';})(this,document.getElementById('<?php echo $accord_id; ?>'))"
                    style="background:transparent;border:none;color:var(--sc-dim,#888);cursor:pointer;font-size:0.8em;padding:0;line-height:1;"
                    title="Show/hide <?php echo count($hub_spokes); ?> spoke(s)">▶</button>
            <?php endif; ?>
          </td>
          <td style="padding:5px 10px;" title="<?php echo htmlspecialchars($row['uid']); ?>"><?php echo htmlspecialchars(substr($row['uid'], 0, 12)); ?>&hellip;</td>
          <td style="padding:5px 10px;"><?php echo htmlspecialchars($row['version']); ?></td>
          <td style="padding:5px 10px;" title="<?php echo htmlspecialchars($track_tooltip); ?>">
            <?php if ($track_label === 'mixed'): ?>
              <span style="color:#e8a838;">mixed</span>
            <?php else: ?>
              <?php echo htmlspecialchars($track_label); ?>
            <?php endif; ?>
          </td>
          <td style="padding:5px 10px;text-align:right;"><?php echo (int)$row['spoke_count']; ?></td>
          <td style="padding:5px 10px;text-align:right;font-weight:bold;"><?php echo $counts_as; ?></td>
          <td style="padding:5px 10px;color:var(--sc-dim,#888);" title="First seen: <?php echo htmlspecialchars($row['first_seen']); ?>"><?php echo htmlspecialchars($row['last_seen']); ?> (<?php echo $age_days; ?>d ago)</td>
          <td style="padding:5px 10px;">
            <form method="post" style="margin:0;" onsubmit="return confirm('Prune this UID?');">
              <input type="hidden" name="prune_uid" value="<?php echo htmlspecialchars($row['uid']); ?>">
              <button type="submit" style="font-size:0.8em;padding:2px 8px;cursor:pointer;background:transparent;border:1px solid var(--sc-border,#444);color:var(--sc-dim,#888);border-radius:3px;">Prune</button>
            </form>
          </td>
        </tr>
        <?php if ($has_spokes): ?>
        <tr id="<?php echo $accord_id; ?>" style="display:none;background:var(--sc-accent-dim,#111);">
          <td colspan="8" style="padding:0 0 0 32px;">
            <table style="width:100%;border-collapse:collapse;font-size:0.85em;font-family:monospace;">
              <thead>
                <tr style="border-bottom:1px solid var(--sc-border,#2a2a2a);">
                  <th style="padding:4px 10px;text-align:left;color:var(--sc-dim,#666);">Spoke UID</th>
                  <th style="padding:4px 10px;text-align:left;color:var(--sc-dim,#666);">Version</th>
                  <th style="padding:4px 10px;text-align:left;color:var(--sc-dim,#666);">Track</th>
                  <th style="padding:4px 10px;text-align:left;color:var(--sc-dim,#666);">Last Seen</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($hub_spokes as $sp):
                    $sp_age = $now->diff(new DateTime($sp['last_seen']))->days;
                ?>
                <tr style="border-bottom:1px solid var(--sc-border,#1a1a1a);">
                  <td style="padding:3px 10px;" title="<?php echo htmlspecialchars($sp['uid']); ?>"><?php echo htmlspecialchars(substr($sp['uid'], 0, 12)); ?>&hellip;</td>
                  <td style="padding:3px 10px;"><?php echo htmlspecialchars($sp['version']); ?></td>
                  <td style="padding:3px 10px;"><?php echo htmlspecialchars($sp['track']); ?></td>
                  <td style="padding:3px 10px;color:var(--sc-dim,#666);"><?php echo htmlspecialchars($sp['last_seen']); ?> (<?php echo $sp_age; ?>d ago)</td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
