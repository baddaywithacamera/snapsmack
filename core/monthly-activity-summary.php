<?php
/**
 * SNAPSMACK — monthly activity email
 *
 * A hub sends one fleet summary on behalf of every connected site. A standalone
 * install sends its own summary. Spokes never send a duplicate message.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/mailer.php';

if (!function_exists('snap_monthly_period')) {
function snap_monthly_period(?DateTimeImmutable $now = null): array {
    $now = $now ?: new DateTimeImmutable('now');
    $start = $now->modify('first day of last month')->setTime(0, 0, 0);
    $end = $start->modify('first day of next month');
    return [$start->format('Y-m'), $start->format('Y-m-d'), $end->format('Y-m-d'), $start->format('F Y')];
}
}

if (!function_exists('snap_monthly_put_setting')) {
function snap_monthly_put_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([$key, $value]);
}
}

if (!function_exists('snap_monthly_local_row')) {
function snap_monthly_local_row(PDO $pdo, array $settings, string $from, string $until): array {
    $views = $visitors = $bots = 0;
    try {
        $q = $pdo->prepare("SELECT COALESCE(SUM(total_views),0), COALESCE(SUM(unique_visitors),0),
                                   COALESCE(SUM(bot_views),0)
                            FROM snap_stats_daily WHERE stat_date >= ? AND stat_date < ?");
        $q->execute([$from, $until]);
        [$views, $visitors, $bots] = array_map('intval', $q->fetch(PDO::FETCH_NUM) ?: [0, 0, 0]);
    } catch (Throwable $e) {}

    $posts = $images = $new_posts = $new_images = 0;
    try { $posts = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts WHERE status='published'")->fetchColumn(); } catch (Throwable $e) {}
    try { $images = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published'")->fetchColumn(); } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM snap_posts WHERE status='published' AND created_at >= ? AND created_at < ?");
        $q->execute([$from, $until]); $new_posts = (int)$q->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date >= ? AND img_date < ?");
        $q->execute([$from, $until]); $new_images = (int)$q->fetchColumn();
    } catch (Throwable $e) {}

    return [
        'name' => trim((string)($settings['site_name'] ?? '')) ?: (parse_url((string)($settings['site_url'] ?? ''), PHP_URL_HOST) ?: 'This site'),
        'url' => (string)($settings['site_url'] ?? ''), 'views' => $views, 'visitors' => $visitors,
        'bots' => $bots, 'posts' => $posts, 'images' => $images,
        'new_posts' => $new_posts, 'new_images' => $new_images, 'status' => 'online',
    ];
}
}

if (!function_exists('snap_monthly_public_url')) {
function snap_monthly_public_url(string $url): bool {
    $host = (string)parse_url($url, PHP_URL_HOST);
    if ($host === '' || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)) return false;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_filter([gethostbyname($host)]);
    if (!$ips || $ips[0] === $host && !filter_var($host, FILTER_VALIDATE_IP)) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}
}

if (!function_exists('snap_monthly_spoke_row')) {
function snap_monthly_spoke_row(array $node, string $from, string $until): array {
    $fallback = [
        'name' => trim((string)($node['site_name'] ?? '')) ?: (string)$node['site_url'],
        'url' => (string)$node['site_url'], 'views' => 0, 'visitors' => 0, 'bots' => 0,
        'posts' => (int)($node['post_count'] ?? 0), 'images' => (int)($node['image_count'] ?? 0),
        'new_posts' => null, 'new_images' => null, 'status' => 'offline',
    ];
    $days = max(35, (int)ceil((strtotime($until) - strtotime($from)) / 86400) + 7);
    $url = rtrim((string)$node['site_url'], '/') . '/api.php?route=multisite/stats/daily&days=' . $days . '&enriched=1'
         . '&period_start=' . rawurlencode($from) . '&period_end=' . rawurlencode($until);
    if (!function_exists('curl_init') || !snap_monthly_public_url($url)) return $fallback;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12, CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . (string)$node['api_key_local'], 'Accept: application/json']]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$raw || $code !== 200) return $fallback;
    $body = json_decode($raw, true);
    if (!is_array($body) || empty($body['ok'])) return $fallback;
    $views = $visitors = $bots = 0;
    foreach (($body['stats'] ?? []) as $day) {
        $date = (string)($day['stat_date'] ?? '');
        if ($date < $from || $date >= $until) continue;
        $views += (int)($day['total_views'] ?? 0); $visitors += (int)($day['unique_visitors'] ?? 0);
        $bots += (int)($day['bot_views'] ?? 0);
    }
    $fallback['views']=$views; $fallback['visitors']=$visitors; $fallback['bots']=$bots;
    $fallback['posts']=(int)($body['post_count'] ?? $fallback['posts']);
    $fallback['images']=(int)($body['image_count'] ?? $fallback['images']);
    $fallback['new_posts']=isset($body['period_post_count']) ? (int)$body['period_post_count'] : null;
    $fallback['new_images']=isset($body['period_image_count']) ? (int)$body['period_image_count'] : null;
    $fallback['status']='online';
    return $fallback;
}
}

if (!function_exists('snap_monthly_email_html')) {
function snap_monthly_email_html(string $label, array $rows): string {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $totals = ['views'=>0,'visitors'=>0,'bots'=>0,'posts'=>0,'images'=>0,'new_posts'=>0,'new_images'=>0];
    foreach ($rows as $r) foreach ($totals as $k => $_) if ($r[$k] !== null) $totals[$k] += (int)$r[$k];
    $html = '<div style="font:15px/1.5 Arial,sans-serif;color:#222;max-width:760px"><h1>SnapSmack activity — '.$h($label).'</h1>'
          . '<p><strong>'.number_format($totals['views']).'</strong> human views &nbsp;·&nbsp; <strong>'.number_format($totals['visitors']).'</strong> visitors &nbsp;·&nbsp; <strong>'.number_format($totals['new_posts']).'</strong> new posts &nbsp;·&nbsp; <strong>'.number_format($totals['new_images']).'</strong> new images</p>'
          . '<table style="border-collapse:collapse;width:100%"><thead><tr><th style="text-align:left;border-bottom:2px solid #222;padding:8px">Site</th><th style="text-align:right;border-bottom:2px solid #222;padding:8px">Views</th><th style="text-align:right;border-bottom:2px solid #222;padding:8px">Visitors</th><th style="text-align:right;border-bottom:2px solid #222;padding:8px">New</th><th style="text-align:right;border-bottom:2px solid #222;padding:8px">Library</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $name = $h($r['name']); $site = $r['url'] !== '' ? '<a href="'.$h($r['url']).'">'.$name.'</a>' : $name;
        if ($r['status'] !== 'online') $site .= ' <span style="color:#a33">(unavailable)</span>';
        $new = $r['new_posts'] === null ? '—' : number_format((int)$r['new_posts']).' posts / '.number_format((int)$r['new_images']).' images';
        $html .= '<tr><td style="border-bottom:1px solid #ddd;padding:8px">'.$site.'</td><td style="text-align:right;border-bottom:1px solid #ddd;padding:8px">'.number_format((int)$r['views']).'</td><td style="text-align:right;border-bottom:1px solid #ddd;padding:8px">'.number_format((int)$r['visitors']).'</td><td style="text-align:right;border-bottom:1px solid #ddd;padding:8px">'.$new.'</td><td style="text-align:right;border-bottom:1px solid #ddd;padding:8px">'.number_format((int)$r['posts']).' / '.number_format((int)$r['images']).'</td></tr>';
    }
    return $html.'</tbody></table><p style="color:#666">Library shows total published posts / images. Automated activity and known bots are excluded from views.</p></div>';
}
}

if (!function_exists('snap_monthly_activity_maybe_send')) {
function snap_monthly_activity_maybe_send(PDO $pdo, ?DateTimeImmutable $now = null): array {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    [$period, $from, $until, $label] = snap_monthly_period($now);
    $role = (string)($settings['multisite_role'] ?? '');
    if ($role === 'spoke') {
        snap_monthly_put_setting($pdo, 'monthly_activity_last_status', 'delegated to hub');
        return ['sent'=>false, 'status'=>'delegated', 'period'=>$period];
    }
    if ((string)($settings['monthly_activity_period'] ?? '') === $period) return ['sent'=>false, 'status'=>'already sent', 'period'=>$period];
    $to = trim((string)($settings['admin_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        snap_monthly_put_setting($pdo, 'monthly_activity_last_status', 'no valid admin email');
        return ['sent'=>false, 'status'=>'no recipient', 'period'=>$period];
    }
    $lock = false;
    try { $lock = (int)$pdo->query("SELECT GET_LOCK('snapsmack_monthly_activity', 0)")->fetchColumn() === 1; } catch (Throwable $e) { $lock = true; }
    if (!$lock) return ['sent'=>false, 'status'=>'busy', 'period'=>$period];
    try {
        $rows = [snap_monthly_local_row($pdo, $settings, $from, $until)];
        if ($role === 'hub') {
            try {
                $nodes = $pdo->query("SELECT site_url,site_name,api_key_local,post_count,image_count,status FROM snap_multisite_nodes WHERE role='spoke' AND status!='disconnected' ORDER BY site_name")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($nodes as $node) $rows[] = snap_monthly_spoke_row($node, $from, $until);
            } catch (Throwable $e) {}
        }
        $scope = $role === 'hub' ? 'fleet' : 'site';
        $ok = snapsmack_send_mail($to, 'SnapSmack '.$scope.' activity — '.$label, snap_monthly_email_html($label, $rows), ['pdo'=>$pdo,'settings'=>$settings,'html'=>true]);
        snap_monthly_put_setting($pdo, 'monthly_activity_last_run', date('Y-m-d H:i:s'));
        snap_monthly_put_setting($pdo, 'monthly_activity_last_status', $ok ? 'sent' : 'send failed');
        if ($ok) snap_monthly_put_setting($pdo, 'monthly_activity_period', $period);
        return ['sent'=>$ok, 'status'=>$ok?'sent':'failed', 'period'=>$period, 'sites'=>count($rows)];
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('snapsmack_monthly_activity')"); } catch (Throwable $e) {}
    }
}
}
// ===== SNAPSMACK EOF =====
