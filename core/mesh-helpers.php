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
        "SELECT site_url, site_name, role
         FROM snap_multisite_nodes
         WHERE status = 'active'
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
                 roster_source, last_roster_seen_at, connected_at)
            VALUES (?, ?, ?, '', '', 'active', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                role                = VALUES(role),
                site_name           = VALUES(site_name),
                status              = 'active',
                roster_source       = VALUES(roster_source),
                last_roster_seen_at = VALUES(last_roster_seen_at)
        ");
    } else {
        $upsert = $pdo->prepare("
            INSERT INTO snap_multisite_nodes
                (role, site_url, site_name, api_key_local, api_key_remote, status, connected_at)
            VALUES (?, ?, ?, '', '', 'active', NOW())
            ON DUPLICATE KEY UPDATE
                role          = VALUES(role),
                site_name     = VALUES(site_name),
                status        = 'active'
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
                $p['role']      ?? 'peer',
                $url,
                $p['site_name'] ?? parse_url($url, PHP_URL_HOST),
                $hub_url,
                $now,
            ]);
        } else {
            $upsert->execute([
                $p['role']      ?? 'peer',
                $url,
                $p['site_name'] ?? parse_url($url, PHP_URL_HOST),
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
// ===== SNAPSMACK EOF =====
