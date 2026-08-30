<?php
/**
 * SNAPSMACK - Smack in the Middle (Mesh Mode) helpers
 *
 * Shared functions for mesh-aware multisite endpoints:
 *
 *   ms_resolve_peer($pdo, $bearer) — look up which peer is making the
 *     current request. Returns the snap_multisite_nodes row or null.
 *   ms_peer_allows($peer_row, $kind) — has the local install opted in
 *     to receiving inbound traffic of this kind from this peer?
 *   ms_build_roster($pdo, $exclude_url) — assemble the canonical roster
 *     this install knows about, omitting the requesting peer (so callers
 *     don't see themselves in their own roster).
 *   ms_ingest_roster($pdo, $hub_url, $peers) — receive a roster from
 *     a hub and reconcile it into local snap_multisite_nodes. Inserts
 *     new peers (discovery data only — no keys), updates known peers'
 *     status, prunes peers that have left the network. Locally-registered
 *     hub rows
 *     (roster_source = 'self') are never touched.
 *
 * Permission kinds: 'crosspost', 'blogroll', 'stats_query'.
 *   These map to the boolean columns added in migration 054.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


/**
 * Returns true if the URL is safe to fetch from hub-originated requests.
 * Rejects private/loopback/reserved IPs to prevent SSRF attacks where a
 * compromised hub sends internal-network URLs for the spoke to fetch.
 */
function ms_is_safe_remote_url(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return false;
    $host = parse_url($url, PHP_URL_HOST) ?? '';
    if ($host === '') return false;
    if (in_array(strtolower($host), ['localhost', 'ip6-localhost', 'ip6-loopback', '::1', '0.0.0.0'], true)) return false;
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (string)@gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function ms_resolve_peer(PDO $pdo, string $bearer): ?array
{
    if ($bearer === '') return null;
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_multisite_nodes
         WHERE api_key_local = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$bearer]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ms_peer_allows(array $peer_row, string $kind): bool
{
    $col = 'accepts_' . $kind;
    if (!array_key_exists($col, $peer_row)) return false;
    return (int)$peer_row[$col] === 1;
}

function ms_build_roster(PDO $pdo, string $exclude_url = ''): array
{
    // SECURITY: the roster is DISCOVERY DATA ONLY (names/URLs/roles). It must
    // NEVER carry api_key_local — that is the hub->spoke credential, and
    // broadcasting it let one leaked spoke key compromise the whole fleet.
    $exclude_norm = preg_replace('~^https?://~i', '', rtrim($exclude_url, '/'));
    $rows = $pdo->query(
        "SELECT site_url, site_name, role, status, maintenance_mode,
                software_version, fediverse_enabled, fedboard_sso_enabled
         FROM snap_multisite_nodes
         ORDER BY site_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $r_norm = preg_replace('~^https?://~i', '', rtrim($r['site_url'], '/'));
        if ($exclude_norm !== '' && $r_norm === $exclude_norm) continue;
        $out[] = [
            'site_url'  => $r['site_url'],
            'site_name' => $r['site_name'],
            'role'      => $r['role'],
            'status'              => $r['status'],
            'maintenance_mode'    => (int)$r['maintenance_mode'],
            'software_version'    => $r['software_version'],
            'fediverse_enabled'  => (int)$r['fediverse_enabled'],
            'fedboard_sso_enabled'=> (int)$r['fedboard_sso_enabled'],
        ];
    }
    return $out;
}

function ms_ingest_roster(PDO $pdo, string $hub_url, array $peers): array
{
    $now = date('Y-m-d H:i:s');
    $seen_urls = [];
    $added     = 0;
    $updated   = 0;

    // Graceful fallback: if migration 054 hasn't run on this install,
    // roster_source / last_roster_seen_at columns won't exist yet.
    $has_roster_cols = false;
    try {
        $test = $pdo->query("SELECT roster_source FROM snap_multisite_nodes LIMIT 0");
        $has_roster_cols = ($test !== false);
    } catch (PDOException $e) { /* columns missing */ }

    // SECURITY: roster peers are discovery rows only. We store NO key for them
    // (api_key_local stays ''); a spoke never needs a sibling's inbound key.
    if ($has_roster_cols) {
        $upsert = $pdo->prepare("
            INSERT INTO snap_multisite_nodes
                (role, site_url, site_name, api_key_local, api_key_remote, status,
                 maintenance_mode, software_version, fediverse_enabled, fedboard_sso_enabled,
                 roster_source, last_roster_seen_at, connected_at)
            VALUES (?, ?, ?, '', '', ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                role                = VALUES(role),
                site_name           = VALUES(site_name),
                status              = VALUES(status),
                maintenance_mode    = VALUES(maintenance_mode),
                software_version    = VALUES(software_version),
                fediverse_enabled  = VALUES(fediverse_enabled),
                fedboard_sso_enabled= VALUES(fedboard_sso_enabled),
                roster_source       = VALUES(roster_source),
                last_roster_seen_at = VALUES(last_roster_seen_at)
        ");
    } else {
        $upsert = $pdo->prepare("
            INSERT INTO snap_multisite_nodes
                (role, site_url, site_name, api_key_local, api_key_remote, status,
                 maintenance_mode, software_version, fediverse_enabled, fedboard_sso_enabled, connected_at)
            VALUES (?, ?, ?, '', '', ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                role               = VALUES(role),
                site_name          = VALUES(site_name),
                status             = VALUES(status),
                maintenance_mode   = VALUES(maintenance_mode),
                software_version   = VALUES(software_version),
                fediverse_enabled   = VALUES(fediverse_enabled),
                fedboard_sso_enabled = VALUES(fedboard_sso_enabled)
        ");
    }

    // SECURITY self-heal: the OLD roster broadcast sibling api_key_local values
    // and spokes stored them. Blank any key previously learned from THIS hub's
    // roster — siblings must hold no inbound key. The local hub row is
    // roster_source='self'/NULL and is left untouched.
    if ($has_roster_cols && $hub_url !== '') {
        $pdo->prepare("UPDATE snap_multisite_nodes SET api_key_local = '' WHERE roster_source = ?")
            ->execute([$hub_url]);
    }

    foreach ($peers as $p) {
        $url = trim($p['site_url']     ?? '');
        if ($url === '') continue;
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

        $row_before = $pdo->prepare(
            "SELECT id FROM snap_multisite_nodes WHERE site_url = ? LIMIT 1"
        );
        $row_before->execute([$url]);
        $exists = (bool)$row_before->fetchColumn();

        if ($has_roster_cols) {
            $upsert->execute([
                in_array(($p['role'] ?? ''), ['hub','spoke'], true) ? $p['role'] : 'spoke',
                $url,
                $p['site_name'] ?? parse_url($url, PHP_URL_HOST),
                $p['status'] ?? 'offline',
                !empty($p['maintenance_mode']) ? 1 : 0,
                (string)($p['software_version'] ?? ''),
                !empty($p['fediverse_enabled']) ? 1 : 0,
                !empty($p['fedboard_sso_enabled']) ? 1 : 0,
                $hub_url,
                $now,
            ]);
        } else {
            $upsert->execute([
                in_array(($p['role'] ?? ''), ['hub','spoke'], true) ? $p['role'] : 'spoke',
                $url,
                $p['site_name'] ?? parse_url($url, PHP_URL_HOST),
                $p['status'] ?? 'offline',
                !empty($p['maintenance_mode']) ? 1 : 0,
                (string)($p['software_version'] ?? ''),
                !empty($p['fediverse_enabled']) ? 1 : 0,
                !empty($p['fedboard_sso_enabled']) ? 1 : 0,
            ]);
        }

        if ($exists) { $updated++; } else { $added++; }
        $seen_urls[] = $url;
    }

    // Prune: any peer previously learned from THIS hub but not in the new
    // roster has left the network. Keep self-registered rows (roster_source='self')
    // and rows learned from other hubs untouched.
    $pruned = 0;
    if ($hub_url !== '' && $has_roster_cols) {
        if ($seen_urls) {
            $placeholders = implode(',', array_fill(0, count($seen_urls), '?'));
            $params = array_merge([$hub_url], $seen_urls);
            $del = $pdo->prepare(
                "DELETE FROM snap_multisite_nodes
                 WHERE roster_source = ?
                   AND site_url NOT IN ($placeholders)"
            );
            $del->execute($params);
            $pruned = $del->rowCount();
        } else {
            $del = $pdo->prepare(
                "DELETE FROM snap_multisite_nodes WHERE roster_source = ?"
            );
            $del->execute([$hub_url]);
            $pruned = $del->rowCount();
        }
    }

    return ['added' => $added, 'updated' => $updated, 'pruned' => $pruned];
}

/**
 * Spoke -> hub roster pull. Calls this spoke's hub heartbeat endpoint and
 * ingests the returned peer roster into snap_multisite_nodes, so the FEDBOARD
 * site-switcher fills WITHOUT anyone loading the Multisite admin page (which was
 * the only trigger before). No-op on a hub, or with no hub row / no outbound key.
 * Discovery data only — ms_ingest_roster stores no sibling keys.
 *
 * @return array{added:int,updated:int,pruned:int,ok:bool}
 */
function ms_spoke_pull_roster(PDO $pdo, array $settings): array
{
    $none = ['added' => 0, 'updated' => 0, 'pruned' => 0, 'ok' => false];
    if (($settings['multisite_role'] ?? '') === 'hub') {
        return $none;
    }
    $stamp = static function (bool $ok, string $error = '') use ($pdo): void {
        $values = [
            'fedboard_roster_pull_attempt' => (string)time(),
            'fedboard_roster_pull_error'   => $error,
        ];
        if ($ok) $values['fedboard_roster_pull_last_success'] = date('Y-m-d H:i:s');
        try {
            $q = $pdo->prepare("INSERT INTO snap_settings (setting_key,setting_val) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)");
            foreach ($values as $key => $value) $q->execute([$key, $value]);
        } catch (Throwable $e) {}
    };
    if (!function_exists('curl_init')) {
        $stamp(false, 'cURL is unavailable');
        return $none;
    }
    try {
        $hub = $pdo->query("SELECT * FROM snap_multisite_nodes WHERE role = 'hub' LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $none;
    }
    if (!$hub || trim((string)($hub['api_key_remote'] ?? '')) === '') {
        $stamp(false, 'Hub connection or outbound key is missing');
        return $none;
    }
    $hub_url = rtrim((string)$hub['site_url'], '/');
    $ch = curl_init();
    curl_setopt_array($ch, [
        // multisite/ping is the endpoint that returns mesh.peers (multisite/heartbeat
        // does NOT — it only carries version/jobs/backup fields). Reading peers off
        // heartbeat silently returned an empty roster, so the FEDBOARD picker never
        // filled from a sweep. This is the same endpoint smack-multisite.php pings.
        CURLOPT_URL            => $hub_url . '/api.php?route=multisite/ping',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $hub['api_key_remote'],
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $code !== 200) {
        $stamp(false, 'Hub roster request returned HTTP ' . $code);
        return $none;
    }
    $hb = json_decode((string)$raw, true);
    if (empty($hb['ok'])) {
        $stamp(false, 'Hub roster response was invalid');
        return $none;
    }
    try {
        $pdo->prepare("UPDATE snap_multisite_nodes SET last_seen_at = NOW(), status = 'active' WHERE id = ?")
            ->execute([$hub['id']]);
    } catch (Throwable $e) {
    }
    if (empty($hb['mesh']['peers']) || !is_array($hb['mesh']['peers'])) {
        $stamp(true);
        return ['added' => 0, 'updated' => 0, 'pruned' => 0, 'ok' => true];
    }
    $r = ms_ingest_roster($pdo, $hub_url, $hb['mesh']['peers']);
    $r['ok'] = true;
    $stamp(true);
    return $r;
}

// snap_multisite_nodes.last_backup_status is enum('ok','failed','unknown'), but a
// spoke reports SMACKBACK words like 'clean'/'partial' — which TRUNCATE and (under
// strict SQL mode) fatal the whole UPDATE. Map, don't trust. Guarded so it can also
// live in smack-multisite.php without a redeclare, whichever loads first.
if (!function_exists('ms_norm_backup_status')) {
    function ms_norm_backup_status($s): string {
        $s = strtolower(trim((string)$s));
        if ($s === 'failed') return 'failed';
        if ($s === 'ok' || $s === 'clean' || $s === 'partial') return 'ok';
        return 'unknown';
    }
}

/**
 * Hub -> spoke, single node: fetch this spoke's live heartbeat and write its
 * vitals (including the fediverse rollup counters) back into snap_multisite_nodes.
 * Same call + column set as the "ping" action in smack-multisite.php, factored
 * out so a fleet-wide "pull current" (the stats page button) can loop it without
 * duplicating the update. Returns true on a clean 200 heartbeat, false otherwise
 * (and marks the node offline on an unreachable/error response).
 *
 * $node must carry at least id, site_url and api_key_local.
 */
function ms_pull_heartbeat(PDO $pdo, array $node): bool
{
    $url = rtrim((string)($node['site_url'] ?? ''), '/');
    if ($url === '') return false;
    // SSRF guard, when the caller's helper is loaded (stats/multisite pages both define it).
    if (function_exists('snap_is_private_url') && snap_is_private_url($url)) return false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url . '/api.php?route=multisite/heartbeat',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ($node['api_key_local'] ?? ''),
            'Accept: application/json',
        ],
    ]);
    $hb_raw  = curl_exec($ch);
    $hb_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hb = ($hb_raw && $hb_code === 200) ? json_decode((string)$hb_raw, true) : null;
    if (!is_array($hb) || empty($hb['ok'])) {
        $pdo->prepare("UPDATE snap_multisite_nodes SET status = 'offline' WHERE id = ?")
            ->execute([(int)$node['id']]);
        return false;
    }

    $backup_status = ms_norm_backup_status($hb['last_backup_status'] ?? 'unknown');

    $pdo->prepare("
        UPDATE snap_multisite_nodes SET
            software_version   = ?, post_count       = ?, image_count      = ?,
            pending_comments   = ?, last_backup_at   = ?, last_backup_size = ?,
            last_backup_dest   = ?, last_backup_status = ?, disk_usage_bytes = ?,
            site_tagline       = ?, update_track     = ?, installed_skins  = ?,
            active_skin        = ?, fediverse_enabled = ?, fedboard_sso_enabled = ?,
            fediverse_followers = ?, fediverse_following = ?, fediverse_likes = ?,
            fediverse_boosts   = ?, fediverse_replies = ?,
            last_seen_at = NOW(), status = 'active'
        WHERE id = ?
    ")->execute([
        preg_replace('/^[^0-9]+/', '', $hb['version'] ?? '') ?: null,
        $hb['post_count']       ?? 0,
        $hb['image_count']      ?? 0,
        $hb['pending_comments'] ?? 0,
        $hb['last_backup_at']   ?? null,
        $hb['last_backup_size'] ?? null,
        $hb['last_backup_dest'] ?? null,
        $backup_status,
        $hb['disk_usage_bytes'] ?? null,
        $hb['site_tagline']     ?? null,
        $hb['update_track']     ?? 'stable',
        isset($hb['installed_skins']) && is_array($hb['installed_skins'])
            ? json_encode($hb['installed_skins']) : null,
        (string)($hb['active_skin'] ?? ''),
        (int)($hb['fediverse_enabled'] ?? 0),
        (int)($hb['fedboard_sso_enabled'] ?? 0),
        (int)($hb['fediverse_followers'] ?? 0),
        (int)($hb['fediverse_following'] ?? 0),
        (int)($hb['fediverse_likes'] ?? 0),
        (int)($hb['fediverse_boosts'] ?? 0),
        (int)($hb['fediverse_replies'] ?? 0),
        (int)$node['id'],
    ]);
    return true;
}
// ===== SNAPSMACK EOF =====
