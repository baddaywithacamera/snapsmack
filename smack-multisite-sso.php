<?php
/**
 * SNAPSMACK - Multisite SSO Redirector
 *
 * Hub-side. Receives ?sat=NODE_ID from the admin clicking "Remote Login"
 * on the multisite dashboard. Calls the spoke's sso-token API endpoint,
 * then redirects the admin's browser to the spoke's sso.php with the
 * one-time token. The spoke validates the token and creates a session.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/fedboard.php';
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

$destination = 'admin';
$node_id = isset($_POST['sat']) ? (int)$_POST['sat'] : (isset($_GET['sat']) ? (int)$_GET['sat'] : 0);
$fedboard_site = fb_base_url((string)($_GET['fedboard_site'] ?? ''));

if ($fedboard_site !== '') {
    $destination = 'fedboard';
    // A spoke cannot possess the hub session's CSRF secret. For a same-tab
    // fleet switch, accept only a browser navigation whose HTTPS referrer is
    // the hub itself or an active registered fleet member. The requested target
    // is still resolved independently against this hub's authoritative table.
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $ref_origin = '';
    if ($ref !== '') {
        $ref_origin = fb_base_url((string)parse_url($ref, PHP_URL_SCHEME) . '://' . (string)parse_url($ref, PHP_URL_HOST));
    }
    $allowed_ref = $ref_origin !== '' && hash_equals(fb_base_url((string)($settings['site_url'] ?? '')), $ref_origin);
    if (!$allowed_ref && $ref_origin !== '') {
        $rq = $pdo->query("SELECT site_url FROM snap_multisite_nodes WHERE status='active'");
        foreach ($rq->fetchAll(PDO::FETCH_COLUMN) as $known) {
            if (hash_equals(fb_base_url((string)$known), $ref_origin)) { $allowed_ref = true; break; }
        }
    }
    if (!$allowed_ref) sso_hub_fail('FEDBOARD refused a switch that did not begin on this fleet.', null, true);

    $all = $pdo->query("SELECT id,site_url FROM snap_multisite_nodes
                        WHERE role='spoke' AND status='active' AND maintenance_mode=0")
               ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $candidate) {
        if (hash_equals(fb_base_url((string)$candidate['site_url']), $fedboard_site)) {
            $node_id = (int)$candidate['id']; break;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Existing Remote Login remains a GET link, but now carries normal admin CSRF.
    csrf_verify();
}
if (!$node_id) {
    sso_hub_fail($destination === 'fedboard'
        ? "That site is not an active member of this hub's fleet."
        : 'Spoke not found or not active.', null, $destination === 'fedboard');
}

// Load the spoke record
$spoke_stmt = $pdo->prepare("SELECT site_url, site_name, api_key_local FROM snap_multisite_nodes WHERE id = ? AND role = 'spoke' AND status = 'active'");
$spoke_stmt->execute([$node_id]);
$spoke = $spoke_stmt->fetch(PDO::FETCH_ASSOC);

if (!$spoke) {
    sso_hub_fail("Spoke not found or not active.", null);
}

// Call spoke to get a one-time SSO token
$url = rtrim($spoke['site_url'], '/') . '/api.php?route=multisite/auth/sso-token';
$ch  = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['destination' => $destination]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $spoke['api_key_local'],
        'Accept: application/json',
    ],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if (!$raw || $code !== 200) {
    fb_audit($pdo, 'hub', (string)$spoke['site_url'], $destination, 'unreachable', (int)($_SESSION['user_id'] ?? 0));
    sso_hub_fail(($destination === 'fedboard' ? $spoke['site_name'] . ' could not be reached. It may be temporarily offline.' : 'Could not reach spoke')
        . ($destination === 'admin' && $cerr ? ": $cerr" : ($destination === 'admin' ? " (HTTP $code)." : '')), $spoke, $destination === 'fedboard');
}

$resp = json_decode($raw, true);
if (empty($resp['ok']) || empty($resp['sso_token'])) {
    $err_detail = $resp['error'] ?? 'Unknown response from spoke.';
    fb_audit($pdo, 'hub', (string)$spoke['site_url'], $destination, 'refused', (int)($_SESSION['user_id'] ?? 0));
    $friendly = $destination === 'fedboard'
        ? (($code === 403) ? $spoke['site_name'] . ' has not allowed hub sign-in. Enable SSO in that site\'s Multisite settings or log in manually.'
                           : 'FEDBOARD could not open ' . $spoke['site_name'] . '. Your current site and account have not changed.')
        : "Spoke refused SSO request: $err_detail";
    sso_hub_fail($friendly, $spoke, $destination === 'fedboard');
}

// Bounce the admin's browser to the spoke's SSO handler
fb_audit($pdo, 'hub', (string)$spoke['site_url'], $destination, 'issued', (int)($_SESSION['user_id'] ?? 0));
$sso_url = rtrim($spoke['site_url'], '/') . '/sso.php?token=' . urlencode($resp['sso_token']);
header('Referrer-Policy: no-referrer');
header('Location: ' . $sso_url);
exit;

// ─────────────────────────────────────────────────────────────────────────────
// FAIL HANDLER — renders a clean error page
// ─────────────────────────────────────────────────────────────────────────────
function sso_hub_fail(string $reason, ?array $spoke, bool $fedboard = false): void {
    global $settings;
    $page_title = $fedboard ? 'FEDBOARD Could Not Switch Sites' : 'Remote Login Failed';
    include 'core/admin-header.php';
    include 'core/sidebar.php';
    ?>
    <div class="main">
        <div class="header-row"><h2><?php echo $fedboard ? 'FEDBOARD COULD NOT SWITCH SITES' : 'REMOTE LOGIN FAILED'; ?></h2></div>
        <div class="box">
            <?php if ($spoke): ?>
                <h3><?php echo htmlspecialchars(strtoupper($spoke['site_name'])); ?></h3>
                <p style="color:var(--text-muted,#888); margin-bottom:5px;">
                    <a href="<?php echo htmlspecialchars($spoke['site_url']); ?>" target="_blank"
                       style="color:var(--accent,#aaa);"><?php echo htmlspecialchars($spoke['site_url']); ?></a>
                </p>
            <?php endif; ?>
            <div class="alert alert-error" style="margin-top:20px;"><?php echo htmlspecialchars($reason); ?></div>
            <p style="color:var(--text-muted,#888); font-size:0.9rem; margin-top:15px;">
                The spoke may be offline, or it may be running an older version of SnapSmack that doesn't
                support SSO. You can still log in manually at
                <?php if ($spoke): ?>
                    <a href="<?php echo htmlspecialchars(rtrim($spoke['site_url'],'/') . '/snap-in'); ?>"
                       target="_blank" style="color:var(--accent,#aaa);">
                        <?php echo htmlspecialchars(rtrim($spoke['site_url'],'/') . '/snap-in'); ?>
                    </a>
                <?php else: ?>
                    the spoke's login page.
                <?php endif; ?>
            </p>
            <p style="margin-top:20px;">
                <a href="<?php echo $fedboard ? 'pixel.php' : 'smack-multisite.php'; ?>" class="btn-smack"><?php echo $fedboard ? 'RETURN TO HUB FEDBOARD' : 'BACK TO DASHBOARD'; ?></a>
            </p>
        </div>
    </div>
    <?php
    include 'core/admin-footer.php';
    exit;
}
// ===== SNAPSMACK EOF =====
