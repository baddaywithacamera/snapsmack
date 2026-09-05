<?php
/**
 * Consent-directory curator for the FEDISTRUCTURE hub.
 *
 * Only @curator@photoblogs.fyi may run this worker. It consumes the public
 * fediverse.info JSON directory, never its HTML, and manages only follows it
 * created itself. One remote-account action is allowed every 15 minutes.
 */

function sc_curator_is_hub(array $settings): bool {
    return ($settings['site_mode'] ?? '') === 'fedistructure'
        && ($settings['node_role'] ?? '') === 'hub'
        && strtolower(sv_handle($settings)) === 'curator'
        && strtolower(sv_domain($settings)) === 'photoblogs.fyi';
}

function sc_curator_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_curator_directory (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        source varchar(80) NOT NULL DEFAULT 'fediverse.info:photography',
        source_id varchar(100) DEFAULT NULL,
        acct varchar(255) NOT NULL,
        actor_url varchar(500) DEFAULT NULL,
        follow_row_id int unsigned DEFAULT NULL,
        state varchar(32) NOT NULL DEFAULT 'discovered',
        seen_generation char(36) DEFAULT NULL,
        first_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        missing_since datetime DEFAULT NULL,
        last_checked_at datetime DEFAULT NULL,
        next_check_at datetime DEFAULT NULL,
        failure_count int unsigned NOT NULL DEFAULT 0,
        last_error varchar(500) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_curator_source_acct (source, acct),
        KEY idx_curator_work (state, next_check_at),
        KEY idx_curator_generation (source, seen_generation)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sc_curator_json_page(?string $cursor): array {
    $url = 'https://fediverse.info/api/_meta-api/explore/topic/list';
    if ($cursor !== null && $cursor !== '') $url .= '?cursor=' . rawurlencode($cursor);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['slugs' => ['photography']], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json',
            'User-Agent: PhotoBlogs-Curator/1.0 (+https://photoblogs.fyi/)'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $code !== 200) {
        throw new RuntimeException('directory HTTP ' . $code . ($err !== '' ? ': ' . $err : ''));
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !is_array($json['data'] ?? null)) {
        throw new RuntimeException('directory returned invalid JSON');
    }
    return $json;
}

function sc_curator_connected_hosts(PDO $pdo, array $settings): array {
    $hosts = [strtolower(sv_domain($settings)) => true];
    try {
        $q = $pdo->query("SELECT site_url FROM snap_multisite_nodes WHERE status='active'");
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $url) {
            $host = strtolower((string)(parse_url((string)$url, PHP_URL_HOST) ?: ''));
            if ($host !== '') $hosts[$host] = true;
        }
    } catch (Throwable $e) {}
    try {
        $q = $pdo->query("SELECT actor_url FROM snap_relay_subscribers WHERE state='active'");
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $url) {
            $host = strtolower((string)(parse_url((string)$url, PHP_URL_HOST) ?: ''));
            if ($host !== '') $hosts[$host] = true;
        }
    } catch (Throwable $e) {}
    return $hosts;
}

/** Fetch one directory page. A completed scan marks vanished accounts missing. */
function sc_curator_scan_page(PDO $pdo, array &$settings): array {
    $generation = (string)($settings['curator_scan_generation'] ?? '');
    $cursor = (string)($settings['curator_scan_cursor'] ?? '');
    $last_done = strtotime((string)($settings['curator_scan_completed_at'] ?? '')) ?: 0;
    $next_scan = strtotime((string)($settings['curator_next_scan_at'] ?? '')) ?: 0;
    if ($next_scan > time()) return [0, false];
    if ($generation === '' && $last_done > time() - 30 * 86400) return [0, false];
    if ($generation === '') {
        $generation = bin2hex(random_bytes(16));
        sv_set_setting($pdo, $settings, 'curator_scan_generation', $generation);
        $cursor = '';
    }
    $json = sc_curator_json_page($cursor !== '' ? $cursor : null);
    $up = $pdo->prepare("INSERT INTO snap_curator_directory
        (source,source_id,acct,state,seen_generation,last_seen_at,missing_since)
        VALUES ('fediverse.info:photography',?,?,'discovered',?,NOW(),NULL)
        ON DUPLICATE KEY UPDATE source_id=VALUES(source_id),seen_generation=VALUES(seen_generation),
          last_seen_at=NOW(),missing_since=NULL,
          state=IF(state IN ('missing','removed','invalid','excluded'),'discovered',state)");
    $count = 0;
    foreach ($json['data'] as $person) {
        $acct = strtolower(trim((string)($person['webfinger'] ?? $person['acct'] ?? '')));
        if ($acct === '' || substr_count($acct, '@') < 2) continue;
        if ($acct[0] !== '@') $acct = '@' . $acct;
        $up->execute([(string)($person['id'] ?? ''), substr($acct, 0, 255), $generation]);
        $count++;
    }
    $next = (string)($json['meta']['next_cursor'] ?? '');
    if ($next !== '') {
        sv_set_setting($pdo, $settings, 'curator_scan_cursor', $next);
        // 12 people per page, one page every three hours: the current 263-person
        // directory is consumed in roughly 66 hours instead of a burst.
        sv_set_setting($pdo, $settings, 'curator_next_scan_at', date('Y-m-d H:i:s', time() + 10800));
        return [$count, false];
    }
    $pdo->prepare("UPDATE snap_curator_directory SET state='missing',missing_since=COALESCE(missing_since,NOW())
        WHERE source='fediverse.info:photography' AND (seen_generation IS NULL OR seen_generation<>?)")
        ->execute([$generation]);
    sv_set_setting($pdo, $settings, 'curator_scan_cursor', '');
    sv_set_setting($pdo, $settings, 'curator_scan_generation', '');
    sv_set_setting($pdo, $settings, 'curator_next_scan_at', '');
    sv_set_setting($pdo, $settings, 'curator_scan_completed_at', date('Y-m-d H:i:s'));
    return [$count, true];
}

function sc_curator_remove_managed_follow(PDO $pdo, array $settings, array $row, string $state, string $reason): void {
    $fid = (int)($row['follow_row_id'] ?? 0);
    if ($fid > 0) {
        $q = $pdo->prepare("SELECT id FROM snap_ap_following WHERE id=? LIMIT 1");
        $q->execute([$fid]);
        if ($q->fetchColumn()) sv_unfollow_actor($pdo, $settings, $fid);
    }
    $pdo->prepare("UPDATE snap_curator_directory SET state=?,follow_row_id=NULL,last_checked_at=NOW(),last_error=? WHERE id=?")
        ->execute([$state, substr($reason, 0, 500), $row['id']]);
}

/** One follow, unfollow or health check. Manual follows are never selected. */
function sc_curator_one_action(PDO $pdo, array &$settings): array {
    $next = strtotime((string)($settings['curator_next_action_at'] ?? '')) ?: 0;
    if ($next > time()) return ['waiting', 'paced'];
    // A site that joins the hub after being curated must be removed too.
    $hosts = sc_curator_connected_hosts($pdo, $settings);
    $managed = $pdo->query("SELECT * FROM snap_curator_directory WHERE follow_row_id IS NOT NULL AND state IN ('following','followed') ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($managed as $candidate) {
        $host = strtolower((string)(parse_url((string)$candidate['actor_url'], PHP_URL_HOST) ?: ''));
        if ($host !== '' && isset($hosts[$host])) {
            sc_curator_remove_managed_follow($pdo, $settings, $candidate, 'excluded', 'joined hub');
            sv_set_setting($pdo, $settings, 'curator_next_action_at', date('Y-m-d H:i:s', time() + 900));
            return ['excluded', $candidate['acct']];
        }
    }
    $missing = $pdo->query("SELECT * FROM snap_curator_directory WHERE state='missing' AND follow_row_id IS NOT NULL ORDER BY missing_since LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    if ($missing) {
        sc_curator_remove_managed_follow($pdo, $settings, $missing, 'removed', 'no longer in consent directory');
        sv_set_setting($pdo, $settings, 'curator_next_action_at', date('Y-m-d H:i:s', time() + 900));
        return ['removed', $missing['acct']];
    }

    $row = $pdo->query("SELECT c.* FROM snap_curator_directory c
        WHERE c.state IN ('discovered','retrying') AND (c.next_check_at IS NULL OR c.next_check_at<=NOW())
          AND NOT EXISTS (
            SELECT 1 FROM snap_curator_directory recent
            WHERE recent.id<>c.id
              AND LOWER(SUBSTRING_INDEX(recent.acct,'@',-1))=LOWER(SUBSTRING_INDEX(c.acct,'@',-1))
              AND recent.last_checked_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)
          )
        ORDER BY c.first_seen_at,c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $parts = explode('@', ltrim((string)$row['acct'], '@'), 2);
        $host = strtolower($parts[1] ?? '');
        if ($host === '' || isset(sc_curator_connected_hosts($pdo, $settings)[$host])) {
            $pdo->prepare("UPDATE snap_curator_directory SET state='excluded',last_checked_at=NOW(),last_error='hub member' WHERE id=?")
                ->execute([$row['id']]);
            return ['excluded', $row['acct']];
        }
        // Resolve first so a pre-existing manual follow is never reset, claimed,
        // or later removed by the directory worker.
        $resolved = sv_webfinger_lookup((string)$row['acct']);
        if ($resolved !== null && $resolved !== '') {
            $manual = $pdo->prepare("SELECT id FROM snap_ap_following WHERE actor_url=? LIMIT 1");
            $manual->execute([$resolved]);
            if ($manual->fetchColumn()) {
                $pdo->prepare("UPDATE snap_curator_directory SET actor_url=?,state='manual',last_checked_at=NOW(),last_error='already followed manually' WHERE id=?")
                    ->execute([$resolved, $row['id']]);
                return ['manual', $row['acct']];
            }
        }
        [$ok, $message] = sv_follow_actor($pdo, $settings, $resolved ?: (string)$row['acct']);
        if ($ok) {
            $q = $pdo->prepare("SELECT id,actor_url,state FROM snap_ap_following WHERE actor_url=? ORDER BY id DESC LIMIT 1");
            $q->execute([$resolved]);
            $f = $q->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE snap_curator_directory SET actor_url=?,follow_row_id=?,state='following',failure_count=0,
                last_checked_at=NOW(),next_check_at=DATE_ADD(NOW(),INTERVAL 30 DAY),last_error=NULL WHERE id=?")
                ->execute([$f['actor_url'] ?? null, $f['id'] ?? null, $row['id']]);
        } else {
            $failures = (int)$row['failure_count'] + 1;
            $state = $failures >= 3 ? 'invalid' : 'retrying';
            $pdo->prepare("UPDATE snap_curator_directory SET state=?,failure_count=?,last_checked_at=NOW(),
                next_check_at=DATE_ADD(NOW(),INTERVAL ? DAY),last_error=? WHERE id=?")
                ->execute([$state, $failures, $failures, substr($message, 0, 500), $row['id']]);
        }
        sv_set_setting($pdo, $settings, 'curator_next_action_at', date('Y-m-d H:i:s', time() + 900));
        return [$ok ? 'following' : 'retrying', $row['acct']];
    }

    $row = $pdo->query("SELECT * FROM snap_curator_directory WHERE state IN ('following','followed')
        AND next_check_at IS NOT NULL AND next_check_at<=NOW() ORDER BY next_check_at,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $fq = $pdo->prepare("SELECT state FROM snap_ap_following WHERE id=? LIMIT 1");
        $fq->execute([(int)$row['follow_row_id']]);
        $follow_state = (string)($fq->fetchColumn() ?: 'missing');
        if ($follow_state === 'rejected' || $follow_state === 'missing') {
            if ($follow_state === 'rejected') {
                $pdo->prepare("DELETE FROM snap_ap_following WHERE id=?")->execute([(int)$row['follow_row_id']]);
            }
            $pdo->prepare("UPDATE snap_curator_directory SET state='rejected',follow_row_id=NULL,last_checked_at=NOW(),last_error=? WHERE id=?")
                ->execute(['remote rejected follow', $row['id']]);
            sv_set_setting($pdo, $settings, 'curator_next_action_at', date('Y-m-d H:i:s', time() + 900));
            return ['rejected', $row['acct']];
        }
        $doc = !empty($row['actor_url']) ? sv_fetch_ap((string)$row['actor_url'], $settings) : null;
        if (is_array($doc) && !empty($doc['id']) && !empty($doc['inbox'])) {
            $pdo->prepare("UPDATE snap_curator_directory SET state='followed',failure_count=0,last_checked_at=NOW(),
                next_check_at=DATE_ADD(NOW(),INTERVAL 30 DAY),last_error=NULL WHERE id=?")->execute([$row['id']]);
            return ['healthy', $row['acct']];
        }
        $failures = (int)$row['failure_count'] + 1;
        if ($failures >= 3) sc_curator_remove_managed_follow($pdo, $settings, $row, 'invalid', 'actor failed three monthly checks');
        else $pdo->prepare("UPDATE snap_curator_directory SET failure_count=?,last_checked_at=NOW(),next_check_at=DATE_ADD(NOW(),INTERVAL 1 DAY),last_error='actor unavailable' WHERE id=?")
            ->execute([$failures, $row['id']]);
        sv_set_setting($pdo, $settings, 'curator_next_action_at', date('Y-m-d H:i:s', time() + 900));
        return ['checked', $row['acct']];
    }
    return ['idle', ''];
}

function sc_curator_cron(PDO $pdo, array &$settings): array {
    if (($settings['curator_directory_enabled'] ?? '0') !== '1') return ['disabled', 0, false, ''];
    if (!sc_curator_is_hub($settings)) return ['identity-blocked', 0, false, 'Set this hub to @curator@photoblogs.fyi'];
    sc_curator_ensure_tables($pdo);
    $lock = $pdo->query("SELECT GET_LOCK('snapsmack_curator_directory',0)")->fetchColumn();
    if ((int)$lock !== 1) return ['busy', 0, false, 'another curator step is running'];
    try {
        [$found, $complete] = sc_curator_scan_page($pdo, $settings);
        [$action, $detail] = sc_curator_one_action($pdo, $settings);
        return [$action, $found, $complete, $detail];
    } catch (Throwable $e) {
        sv_set_setting($pdo, $settings, 'curator_last_error', substr($e->getMessage(), 0, 500));
        sv_set_setting($pdo, $settings, 'curator_next_scan_at', date('Y-m-d H:i:s', time() + 3600));
        return ['error', 0, false, $e->getMessage()];
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('snapsmack_curator_directory')"); } catch (Throwable $e) {}
    }
}
// ===== SNAPSMACK EOF =====
