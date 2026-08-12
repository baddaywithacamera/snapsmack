<?php
/**
 * Live statistics for the SnapSmack skin showcase.
 * Results are cached for one hour so gallery pages do not hammer the demo sites.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the marker above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$_skin_demo_sites = [
    'unzucked.ca'                     => 'https://unzucked.ca',
    'photowalk.ing'                   => 'https://photowalk.ing',
    'hekeepsdroningon.ca'             => 'https://hekeepsdroningon.ca',
    'pixhellated.ca'                  => 'https://pixhellated.ca',
    'wateronthebrain.ca'              => 'https://wateronthebrain.ca',
    'foundtextures.ca'                => 'https://foundtextures.ca',
    'acolourlesslife.ca'              => 'https://acolourlesslife.ca',
    'foreverphotograph.ing'           => 'https://foreverphotograph.ing',
    'theschoolofhardnocks.ca'         => 'https://theschoolofhardnocks.ca',
    'fauxlaroid.fyi'                  => 'https://fauxlaroid.fyi',
    'lightafterdark.ca'               => 'https://lightafterdark.ca',
    'craptasti.ca'                    => 'https://craptasti.ca',
    'usedcarparts.photoblogs.fyi'     => 'https://usedcarparts.photoblogs.fyi',
];

$_skin_demo_stats = [];
$_skin_stats_cache = __DIR__ . '/stats-cache.json';
$_skin_stats_cache_ttl = 3600;

if (file_exists($_skin_stats_cache) && (time() - filemtime($_skin_stats_cache)) < $_skin_stats_cache_ttl) {
    $_skin_cached = json_decode(file_get_contents($_skin_stats_cache), true);
    if (is_array($_skin_cached)) {
        $_skin_demo_stats = $_skin_cached;
    }
}

$_skin_missing_stats = array_diff_key($_skin_demo_sites, $_skin_demo_stats);
if ((empty($_skin_demo_stats) || !empty($_skin_missing_stats)) && function_exists('curl_multi_init')) {
    $_skin_multi = curl_multi_init();
    $_skin_handles = [];

    foreach ($_skin_demo_sites as $_skin_domain => $_skin_base_url) {
        $_skin_handle = curl_init($_skin_base_url . '/stats.php');
        curl_setopt_array($_skin_handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'snapsmack.ca-gallery/1.0',
        ]);
        curl_multi_add_handle($_skin_multi, $_skin_handle);
        $_skin_handles[$_skin_domain] = $_skin_handle;
    }

    do {
        $_skin_status = curl_multi_exec($_skin_multi, $_skin_running);
        if ($_skin_running) {
            curl_multi_select($_skin_multi);
        }
    } while ($_skin_running && $_skin_status === CURLM_OK);

    foreach ($_skin_handles as $_skin_domain => $_skin_handle) {
        $_skin_body = curl_multi_getcontent($_skin_handle);
        $_skin_code = curl_getinfo($_skin_handle, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($_skin_multi, $_skin_handle);
        curl_close($_skin_handle);

        if ($_skin_code === 200 && $_skin_body) {
            $_skin_data = json_decode($_skin_body, true);
            if (is_array($_skin_data) && !isset($_skin_data['error'])) {
                $_skin_demo_stats[$_skin_domain] = [
                    'site_name' => $_skin_data['site_name'] ?? '',
                    'posts' => (int)($_skin_data['posts'] ?? 0),
                    'views_30d' => (int)($_skin_data['views_30d'] ?? 0),
                    'unique_30d' => (int)($_skin_data['unique_30d'] ?? 0),
                    'views_all' => (int)($_skin_data['views_all'] ?? 0),
                    'unique_all' => (int)($_skin_data['unique_all'] ?? 0),
                    'version' => $_skin_data['version'] ?? '',
                    'active_since' => $_skin_data['active_since'] ?? null,
                ];
            }
        }
    }
    curl_multi_close($_skin_multi);

    if (!empty($_skin_demo_stats)) {
        @file_put_contents($_skin_stats_cache, json_encode($_skin_demo_stats, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

if (!function_exists('ss_skin_card_stats')) {
    function ss_skin_card_stats(string $domain, array $all): string {
        return htmlspecialchars(json_encode($all[$domain] ?? null, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    }
}

// ===== SNAPSMACK EOF =====
