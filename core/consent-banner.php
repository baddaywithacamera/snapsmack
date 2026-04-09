<?php
/**
 * SNAPSMACK - Consent Banner Partial
 * Alpha v0.7.9c
 *
 * Renders the storage consent banner on public pages. The banner only appears
 * if the visitor has not yet made a choice (no snap_consent cookie).
 *
 * Include this once per page, typically from core/footer-scripts.php.
 * The companion ss-engine-consent.js handles accept/decline actions.
 *
 * Privacy policy link points to the site's "privacy" static page if it
 * exists, otherwise omitted. Blog owners create the page through the
 * static pages system (smack-pages.php).
 */

// Don't show if already decided
if (isset($_COOKIE['snap_consent'])) {
    return;
}

// Check if a privacy policy page exists
$privacy_url = '';
if (isset($pdo)) {
    $pp_stmt = $pdo->prepare("SELECT slug FROM snap_pages WHERE slug IN ('privacy', 'privacy-policy', 'cookies') AND is_active = 1 LIMIT 1");
    $pp_stmt->execute();
    $pp_slug = $pp_stmt->fetchColumn();
    if ($pp_slug) {
        $privacy_url = (defined('BASE_URL') ? BASE_URL : '/') . 'page/' . $pp_slug;
    }
}
?>
<div id="snap-consent-banner" role="dialog" aria-label="Storage consent">
    <span class="consent-text">
        This site uses browser storage for functional features (remembering preferences).
        No tracking or analytics.<?php if ($privacy_url): ?>
        <a href="<?php echo htmlspecialchars($privacy_url); ?>">Privacy policy</a>.<?php endif; ?>
    </span>
    <span class="consent-buttons">
        <button id="snap-consent-accept" type="button">Accept</button>
        <button id="snap-consent-decline" type="button">Decline</button>
    </span>
</div>
