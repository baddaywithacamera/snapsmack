<?php
/**
 * SNAPSMACK - Shared blogroll push helper
 *
 * The hub's blogroll (hub links + optional "My Blogs" mesh entries) is a
 * PUSHED SNAPSHOT: each spoke stores what it was last sent. Maintenance status
 * is dynamic, so a snapshot goes stale the moment a peer goes up or down. This
 * helper rebuilds and re-pushes the snapshot to every active, non-maintenance
 * spoke — filtering out any peer currently in maintenance — so the mesh
 * self-heals without the operator clicking "Push" by hand.
 *
 * Called from:
 *   - smack-multisite-blogroll.php  (the manual Push button)
 *   - smack-multisite.php           (operator maintenance toggle; heartbeat
 *                                    sweep when a spoke's maintenance flips)
 *
 * Render-time filtering (blogroll.php) handles a HUB's own page instantly;
 * this handles SPOKE snapshots, which can't self-filter (a spoke doesn't know
 * peers' live status).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** Normalise a URL for comparison: scheme-agnostic host+path, no trailing slash. */
function blogroll_norm_url(string $url): string {
    return strtolower(rtrim(trim($url), '/'));
}

/**
 * Build the hub's outbound blogroll entries: the hub's own snap_blogroll,
 * plus (when enabled) the "My Blogs" mesh entries — EXCLUDING any spoke in
 * maintenance mode, and the hub itself. Mirrors the manual-push builder in
 * smack-multisite-blogroll.php so both paths agree.
 */
function blogroll_build_entries(PDO $pdo, array $settings): array {
    $hub_entries = $pdo->query("
        SELECT b.peer_name, b.peer_url, b.peer_rss, b.peer_desc, c.cat_name AS category
        FROM snap_blogroll b
        LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
        ORDER BY c.cat_name ASC, b.peer_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $my_blogs_enabled = ($settings['blogroll_my_blogs_enabled'] ?? '0') === '1';
    $my_blogs_cat     = $settings['blogroll_my_blogs_cat'] ?? 'My Blogs';
    if (!$my_blogs_enabled) return $hub_entries;

    try {
        $spoke_nodes = $pdo->query("
            SELECT site_name, site_url, site_tagline, blogroll_desc, maintenance_mode
            FROM snap_multisite_nodes
            WHERE role = 'spoke' AND status = 'active'
            ORDER BY site_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $spoke_nodes = $pdo->query("
            SELECT site_name, site_url, NULL AS site_tagline, NULL AS blogroll_desc, maintenance_mode
            FROM snap_multisite_nodes
            WHERE role = 'spoke' AND status = 'active'
            ORDER BY site_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $my = [];
    foreach ($spoke_nodes as $sn) {
        if ((int)($sn['maintenance_mode'] ?? 0) === 1) continue;  // down → never linked
        $desc = trim($sn['blogroll_desc'] ?? '');
        if ($desc === '') $desc = trim($sn['site_tagline'] ?? '');
        $my[] = [
            'peer_name' => $sn['site_name'],
            'peer_url'  => rtrim($sn['site_url'], '/') . '/',
            'peer_rss'  => '',
            'peer_desc' => $desc,
            'category'  => $my_blogs_cat,
        ];
    }
    // Include the hub itself so spokes see every live network site.
    $hub_url  = rtrim($settings['site_url'] ?? '', '/') . '/';
    $hub_name = trim($settings['site_name'] ?? '');
    if ($hub_url !== '/' && $hub_name !== '') {
        $my[] = [
            'peer_name' => $hub_name,
            'peer_url'  => $hub_url,
            'peer_rss'  => '',
            'peer_desc' => trim($settings['site_tagline'] ?? ''),
            'category'  => $my_blogs_cat,
        ];
    }
    usort($my, fn($a, $b) => strcasecmp($a['peer_name'], $b['peer_name']));
    $existing = array_map('strtolower', array_column($hub_entries, 'peer_url'));
    foreach ($my as $mbe) {
        if (!in_array(strtolower($mbe['peer_url']), $existing)) array_unshift($hub_entries, $mbe);
    }
    return $hub_entries;
}

/** POST the built entries to one spoke, excluding that spoke's own URL. */
function blogroll_push_one(array $spoke, array $hub_entries): bool {
    $spoke_norm = blogroll_norm_url($spoke['site_url']);
    $entries = array_values(array_filter($hub_entries, function ($e) use ($spoke_norm) {
        return blogroll_norm_url($e['peer_url']) !== $spoke_norm;
    }));
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($spoke['site_url'], '/') . '/api.php?route=multisite/blogroll/sync',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['hub_url' => BASE_URL, 'entries' => $json]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ($spoke['api_key_local'] ?? ''),
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $code !== 200) return false;
    $d = json_decode($raw, true);
    return is_array($d) && ($d['ok'] ?? false);
}

/**
 * Rebuild and re-push the blogroll snapshot to every active, non-maintenance
 * spoke. Used by the auto-heal triggers. Returns ['pushed'=>n, 'failed'=>n].
 * A spoke in maintenance is skipped as a RECIPIENT too (it's down).
 */
function blogroll_push_to_all_spokes(PDO $pdo, array $settings): array {
    $spokes = $pdo->query("
        SELECT id, site_url, site_name, api_key_local, maintenance_mode
        FROM snap_multisite_nodes
        WHERE role = 'spoke' AND status = 'active'
        ORDER BY site_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!$spokes) return ['pushed' => 0, 'failed' => 0];

    $entries = blogroll_build_entries($pdo, $settings);
    $pushed = 0; $failed = 0;
    foreach ($spokes as $spoke) {
        if ((int)($spoke['maintenance_mode'] ?? 0) === 1) continue; // recipient is down
        if (blogroll_push_one($spoke, $entries)) $pushed++; else $failed++;
    }
    return ['pushed' => $pushed, 'failed' => $failed];
}
// ===== SNAPSMACK EOF =====
