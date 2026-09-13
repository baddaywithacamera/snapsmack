<?php
/**
 * SNAPSMACK - Consent Banner Partial
 *
 * Renders the storage consent banner on public pages. The banner only appears
 * if the visitor has not yet made a choice (no snap_consent cookie).
 *
 * Include this once per page, typically from core/footer-scripts.php.
 * The companion ss-engine-consent.js handles accept/decline actions.
 *
 * Privacy policy link prefers SnapSmack's built-in policy page, then falls
 * back to an active privacy static page.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Don't show if already decided
if (isset($_COOKIE['snap_consent'])) {
    return;
}

// Check if a privacy policy page exists
$privacy_url = '';
if (isset($pdo)) {
    $base = defined('BASE_URL') ? BASE_URL : '/';
    try {
        $policy = $pdo->query("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key IN ('privacy_policy_enabled', 'privacy_policy_content')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($policy['privacy_policy_enabled'] ?? '0') === '1' && trim($policy['privacy_policy_content'] ?? '') !== '') {
            $privacy_url = $base . 'privacy-policy.php';
        }
    } catch (PDOException $e) { /* optional on older installs */ }

    if ($privacy_url === '') {
        try {
            $pp_stmt = $pdo->prepare("SELECT slug FROM snap_pages WHERE slug IN ('privacy', 'privacy-policy', 'cookies') AND is_active = 1 LIMIT 1");
            $pp_stmt->execute();
            $pp_slug = $pp_stmt->fetchColumn();
            if ($pp_slug) $privacy_url = $base . 'page/' . $pp_slug;
        } catch (PDOException $e) { /* optional on very fresh installs */ }
    }
}
?>
<div id="snap-consent-banner" role="dialog" aria-label="Privacy choice">
    <span class="consent-text">
        This site can remember display and navigation preferences on this device.
        Limited first-party usage information is recorded whether or not preferences are allowed, for troubleshooting and to understand which content is viewed.
        Raw IP addresses are not stored. There are no advertising trackers, no cross-site tracking, and no third-party analytics.<?php if ($privacy_url): ?>
        <a href="<?php echo htmlspecialchars($privacy_url); ?>">Privacy policy</a>.<?php endif; ?>
    </span>
    <span class="consent-buttons">
        <button id="snap-consent-accept" type="button">Allow preferences</button>
        <button id="snap-consent-decline" type="button">Continue without</button>
    </span>
</div>
<?php // ===== SNAPSMACK EOF =====
