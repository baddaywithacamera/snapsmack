<?php
/**
 * SNAPSMACK — photoblogs.fyi DIRECTORY VERIFY (spoke side)  [0.7.559]
 *
 * Public, read-only. The photoblogs.fyi hub fetches THIS url on a site before it
 * honours a directory register/remove, to prove the request genuinely came from
 * the site that owns the domain — and to read the listing straight from the
 * source. Because the hub publishes only what it reads here (never the POST body
 * of the register call), a forged submission for someone else's site_url cannot
 * inject content or delist them: the hub just re-reads that site's own truth.
 *
 * Returns the site's current listing intent + card data as JSON:
 *   { listed: bool, site_url, handle, name, description, topics, avatar_url,
 *     samples, software, version }
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the marker above.
 */

require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';                 // provides $pdo
require_once __DIR__ . '/core/photoblogs-directory.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$settings = [];
foreach ($pdo->query("SELECT setting_key, setting_val FROM snap_settings") as $row) {
    $settings[$row['setting_key']] = $row['setting_val'];
}

$payload = pbdir_payload($settings);        // the same card the site would submit
$payload['listed'] = pbdir_is_listed($settings);

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
// ===== SNAPSMACK EOF =====
