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


require_once 'core/auth.php';

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

$node_id = isset($_GET['sat']) ? (int)$_GET['sat'] : 0;
if (!$node_id) {
    header('Location: smack-multisite.php');
    exit;
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
    CURLOPT_POSTFIELDS     => '',
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
    sso_hub_fail("Could not reach spoke" . ($cerr ? ": $cerr" : " (HTTP $code)") . ".", $spoke);
}

$resp = json_decode($raw, true);
if (empty($resp['ok']) || empty($resp['sso_token'])) {
    $err_detail = $resp['error'] ?? 'Unknown response from spoke.';
    sso_hub_fail("Spoke refused SSO request: $err_detail", $spoke);
}

// Bounce the admin's browser to the spoke's SSO handler
$sso_url = rtrim($spoke['site_url'], '/') . '/sso.php?token=' . urlencode($resp['sso_token']);
header('Location: ' . $sso_url);
exit;

// ─────────────────────────────────────────────────────────────────────────────
// FAIL HANDLER — renders a clean error page
// ─────────────────────────────────────────────────────────────────────────────
function sso_hub_fail(string $reason, ?array $spoke): void {
    global $settings;
    $page_title = "Remote Login Failed";
    include 'core/admin-header.php';
    include 'core/sidebar.php';
    ?>
    <div class="main">
        <div class="header-row"><h2>REMOTE LOGIN FAILED</h2></div>
        <div class="box">
            <?php if ($spoke): ?>
                <h3><?php echo htmlspecialchars(strtoupper($spoke['site_name'])); ?></h3>
                <p style="color:var(--text-muted,#888); margin-bottom:5px;">
                    <a href="<?php echo htmlspecialchars($spoke['site_url']); ?>" target="_blank"
                       style="color:var(--accent,#aaa);"><?php echo htmlspecialchars($spoke['site_url']); ?></a>
                </p>
            <?php endif; ?>
            <div class="alert alert-error" style="margin-top:20px;">> <?php echo htmlspecialchars($reason); ?></div>
            <p style="color:var(--text-muted,#888); font-size:0.9rem; margin-top:15px;">
                The spoke may be offline, or it may be running an older version of SnapSmack that doesn't
                support SSO. You can still log in manually at
                <?php if ($spoke): ?>
                    <a href="<?php echo htmlspecialchars(rtrim($spoke['site_url'],'/') . '/login.php'); ?>"
                       target="_blank" style="color:var(--accent,#aaa);">
                        <?php echo htmlspecialchars(rtrim($spoke['site_url'],'/') . '/login.php'); ?>
                    </a>
                <?php else: ?>
                    the spoke's login page.
                <?php endif; ?>
            </p>
            <p style="margin-top:20px;">
                <a href="smack-multisite.php" class="btn-smack">BACK TO DASHBOARD</a>
            </p>
        </div>
    </div>
    <?php
    include 'core/admin-footer.php';
    exit;
}
// ===== SNAPSMACK EOF =====
