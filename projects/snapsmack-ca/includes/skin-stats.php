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
    'portalproject.photoblogs.fyi'     => 'https://portalproject.photoblogs.fyi',
    'baddaywithacamera.ca'              => 'https://baddaywithacamera.ca',
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
    'allinthewrist.photoblogs.fyi'    => 'https://allinthewrist.photoblogs.fyi',
    'curbappeal.photoblogs.fyi'       => 'https://curbappeal.photoblogs.fyi',
];

if (!function_exists('ss_skin_stats_row')) {
    /**
     * One site's /stats.php response, reduced to what the cards and the fleet
     * card use.
     *
     * EXTRACTED SO IT CAN BE RUN. It lived inline in the curl loop, where no
     * test could reach it -- and a mutation proved that mattered: turning the
     * post_count mapping into (int)(... ?? 0) made every pre-998D site report
     * ZERO POSTS instead of "unknown", which counts as having answered and
     * would publish a partial fleet total as if it were complete. The summary
     * guard below cannot save you from a lie told upstream of it.
     */
    function ss_skin_stats_row(array $data): array {
        return [
            'site_name' => $data['site_name'] ?? '',
            // NAMED 'posts', COUNTS PHOTOGRAPHS. Kept for the skin cards.
            'posts' => (int)($data['posts'] ?? 0),
            // NULL, NOT ZERO, when absent: a site that has not updated to
            // 998D does not know its post count, and "I don't know" must not
            // read as "I have none".
            'post_count' => isset($data['post_count']) && is_numeric($data['post_count'])
                ? (int)$data['post_count'] : null,
            'views_30d' => (int)($data['views_30d'] ?? 0),
            'unique_30d' => (int)($data['unique_30d'] ?? 0),
            'views_all' => (int)($data['views_all'] ?? 0),
            'unique_all' => (int)($data['unique_all'] ?? 0),
            'version' => $data['version'] ?? '',
            'active_since' => $data['active_since'] ?? null,
        ];
    }
}

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
        $_skin_handle = curl_init($_skin_base_url . '/stats.php?t=' . (int)floor(time() / 900));   // defeat any edge cache: fresh at most every 15 min
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
                $_skin_demo_stats[$_skin_domain] = ss_skin_stats_row($_skin_data);
            }
        }
    }
    curl_multi_close($_skin_multi);

    if (!empty($_skin_demo_stats)) {
        @file_put_contents($_skin_stats_cache, json_encode($_skin_demo_stats, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

// ---------------------------------------------------------------------------
// FLEET SUMMARY for the homepage's "IT WORKS" card. SS-1386 round 2.
//
// Summed from the SAME per-site responses the skin cards already use, with the
// same cache, so the card costs nothing extra and cannot disagree with the
// cards below it.
//
// WHAT /stats.php ACTUALLY RETURNS, because the card copy asks for more than
// that: site_name, posts, views_30d, unique_30d, views_all, unique_all,
// browsing_time_ms, active_since, version. THERE IS NO photos FIELD. The
// endpoint's 'posts' is COUNT(*) FROM snap_images WHERE img_status =
// 'published' (stats.php:54) -- it IS the photograph count, which is why a
// skin card reading "207 photos" is correct today.
//
// So this exposes SITES and PHOTOS, the two numbers the live feed can
// actually support, and NOT a separate post count. Publishing posts and
// photos as different figures would need a new field on /stats.php and a
// deploy to all 28 sites, and on a SMACKTALK site the two genuinely differ.
// Making one number up, or hard-coding yesterday's hub figures, would put a
// wrong number on the front page and nothing on screen would show it was
// stale.
//
// FAILS SOFT, DELIBERATELY: null when no site reported, so the card renders
// without numbers rather than claiming the fleet holds zero photographs.
$_skin_fleet_summary = null;
$_skin_fleet_sites = 0;
$_skin_fleet_photos = 0;
$_skin_fleet_posts = 0;
$_skin_fleet_posts_reported = 0;
foreach ($_skin_demo_stats as $_skin_row) {
    if (!is_array($_skin_row)) continue;
    if (isset($_skin_row['post_count']) && is_numeric($_skin_row['post_count'])) {
        $_skin_fleet_posts_reported++;
        $_skin_fleet_posts += max(0, (int)$_skin_row['post_count']);
    }
    // A reporter with no count is still a site that answered; it contributes
    // to the site tally and nothing to the photographs.
    $_skin_fleet_sites++;
    $_skin_fleet_photos += max(0, (int)($_skin_row['posts'] ?? 0));
}
if ($_skin_fleet_sites > 0 && $_skin_fleet_photos > 0) {
    $_skin_fleet_summary = [
        'sites' => $_skin_fleet_sites,
        'photos' => $_skin_fleet_photos,
    ];
    // POSTS ONLY WHEN EVERY REPORTING SITE ANSWERED WITH ONE.
    //
    // post_count arrives with 998D. Until the whole fleet has updated, some
    // sites report it and some do not, and a partial sum is a WRONG NUMBER
    // THAT LOOKS RIGHT -- smaller than the truth, on the front page, with
    // nothing on screen to say it is incomplete. That is worse than the card
    // saying nothing about posts, so the card keeps its photographs-only
    // wording until every reporter can answer.
    if ($_skin_fleet_posts_reported === $_skin_fleet_sites
            && $_skin_fleet_posts > 0) {
        $_skin_fleet_summary['posts'] = $_skin_fleet_posts;
    }
}
unset($_skin_row, $_skin_fleet_sites, $_skin_fleet_photos,
      $_skin_fleet_posts, $_skin_fleet_posts_reported);

if (!function_exists('ss_skin_card_stats')) {
    function ss_skin_card_stats(string $domain, array $all): string {
        return htmlspecialchars(json_encode($all[$domain] ?? null, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    }
}

// ===== SNAPSMACK EOF =====
