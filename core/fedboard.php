<?php
/**
 * SNAPSMACK — FEDBOARD fleet Fediverse switcher.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

function fb_base_url(string $url): string {
    $url = rtrim(trim($url), '/');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') return '';
    return $url;
}

function fb_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_multisite_sso_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        destination ENUM('admin','fedboard') NOT NULL DEFAULT 'admin',
        requested_by VARCHAR(500) NOT NULL DEFAULT '',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_sso_token_hash (token_hash), KEY ix_sso_token_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_multisite_sso_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        direction ENUM('hub','spoke') NOT NULL,
        peer_url VARCHAR(500) NOT NULL DEFAULT '',
        destination ENUM('admin','fedboard') NOT NULL DEFAULT 'admin',
        outcome VARCHAR(40) NOT NULL,
        admin_user_id INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY ix_sso_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function fb_audit(PDO $pdo, string $direction, string $peer_url, string $destination,
                  string $outcome, ?int $admin_user_id = null): void {
    try {
        fb_ensure_tables($pdo);
        $pdo->prepare("INSERT INTO snap_multisite_sso_audit
            (direction,peer_url,destination,outcome,admin_user_id) VALUES (?,?,?,?,?)")
            ->execute([$direction, substr($peer_url, 0, 500), $destination, substr($outcome, 0, 40), $admin_user_id]);
    } catch (Throwable $e) { error_log('FEDBOARD audit failed: ' . $e->getMessage()); }
}

/** @return array<int,array<string,mixed>> */
function fb_roster(PDO $pdo, array $settings): array {
    $current_url = fb_base_url((string)($settings['site_url'] ?? ''));
    $current_name = trim((string)($settings['site_name'] ?? '')) ?: (parse_url($current_url, PHP_URL_HOST) ?: 'This site');
    $current_role = (($settings['multisite_role'] ?? '') === 'hub') ? 'hub' : 'spoke';
    $rows = [];
    try {
        $rows = $pdo->query("SELECT site_url,site_name,role,status,maintenance_mode,
                            software_version,smackverse_enabled,fedboard_sso_enabled FROM snap_multisite_nodes")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query("SELECT site_url,site_name,role,status FROM snap_multisite_nodes")
                        ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) { return []; }
    }

    $by_url = [];
    if ($current_url !== '') {
        $by_url[strtolower($current_url)] = [
            'site_url'=>$current_url, 'site_name'=>$current_name, 'role'=>$current_role,
            'status'=>'active', 'maintenance_mode'=>0, 'software_version'=>SNAPSMACK_VERSION_SHORT,
            'smackverse_enabled'=>($settings['smackverse_enabled'] ?? '0') === '1' ? 1 : 0,
            'fedboard_sso_enabled'=>1,
            'current'=>true,
        ];
    }
    foreach ($rows as $row) {
        $url = fb_base_url((string)($row['site_url'] ?? ''));
        if ($url === '') continue;
        $key = strtolower($url);
        if (isset($by_url[$key]) && !empty($by_url[$key]['current'])) continue;
        $row['site_url'] = $url;
        $row['site_name'] = trim((string)($row['site_name'] ?? '')) ?: (parse_url($url, PHP_URL_HOST) ?: $url);
        $row['current'] = false;
        $by_url[$key] = $row;
    }
    $out = array_values($by_url);
    usort($out, static function (array $a, array $b): int {
        $c = strnatcasecmp((string)$a['site_name'], (string)$b['site_name']);
        return $c !== 0 ? $c : strnatcasecmp((string)parse_url($a['site_url'], PHP_URL_HOST),
                                             (string)parse_url($b['site_url'], PHP_URL_HOST));
    });

    $hub_url = '';
    foreach ($out as $r) if (($r['role'] ?? '') === 'hub') { $hub_url = $r['site_url']; break; }
    foreach ($out as &$r) {
        $version = preg_replace('/^[^0-9]+/', '', (string)($r['software_version'] ?? ''));
        $r['available'] = !empty($r['current']) || (
            ($r['status'] ?? 'offline') === 'active'
            && empty($r['maintenance_mode'])
            && (($r['role'] ?? '') === 'hub' || !empty($r['smackverse_enabled']))
            && (($r['role'] ?? '') === 'hub' || !empty($r['fedboard_sso_enabled']))
            && $version !== '' && version_compare($version, '0.7.547', '>=')
        );
        $r['switch_url'] = '';
        if (empty($r['current']) && $r['available']) {
            if (($r['role'] ?? '') === 'hub') {
                $r['switch_url'] = rtrim($r['site_url'], '/') . '/pixel.php';
            } elseif ($hub_url !== '') {
                $r['switch_url'] = rtrim($hub_url, '/') . '/smack-multisite-sso.php?fedboard_site=' . rawurlencode($r['site_url']);
            }
        }
    }
    unset($r);
    return count($out) > 1 ? $out : [];
}

// ===== SNAPSMACK EOF =====
