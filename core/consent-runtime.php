<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The consent banner, for strict pages.
 *
 * The owner, 29 October 2026, first-hand on a live site: "the accept-cookies
 * notice is gone", and then "put them back". Same class as Thomas the Bear.
 *
 * WHAT WAS WRONG. The banner's markup lived in core/consent-banner.php, which
 * was included from core/footer-scripts.php:30 -- the LEGACY render path.
 * index.php dispatches a strict skin to snapsmack_public_runtime() and exits
 * before footer-scripts.php is reached, so no strict page has emitted the
 * banner since the September conversion. Its engine and stylesheet were
 * stranded in the same place.
 *
 * WHAT THAT ACTUALLY BROKE, which is the opposite of what one might assume.
 * ss-engine-consent.js publishes window.snapConsent, and every consumer tests
 * it before touching storage:
 *
 *   ss-engine-comms.js:222,248      window.snapConsent && window.snapConsent.ok()
 *   ss-engine-photogram.js:418,446  window.snapConsent && window.snapConsent.ok()
 *
 * That pattern FAILS CLOSED. With the engine missing, window.snapConsent is
 * undefined, every one of those tests is falsy, and nothing is written to
 * localStorage or sessionStorage at all. So the missing banner did NOT create a
 * privacy hole -- it permanently switched OFF the preferences it was there to
 * gate, with no way for a visitor to turn them on. Photogram stopped
 * remembering a reader's place and the help hint stopped remembering it had
 * been seen.
 *
 * WHY THE MARKUP IS EMITTED FROM HERE RATHER THAN A SKIN. A strict skin holds
 * presentation and no executable code, and the banner needs a cookie test and
 * a database lookup for the privacy-policy link. Putting it in 30 layouts is
 * also the wrong rail: page components are named by function, never by skin.
 * So the CMS prepares it and the CMS prints it, through the registered-assets
 * component that every skin already renders -- exactly where
 * snapsmack_scrolltime_tag() already does the same thing.
 *
 * THE COPY IS THE ORIGINAL, WORD FOR WORD, from core/consent-banner.php. The
 * ids and classes are the originals too, because ss-engine-consent.js binds
 * #snap-consent-accept and #snap-consent-decline by id. Nothing here is new
 * public text and nothing here is new behaviour.
 */

/**
 * Everything the banner needs, worked out while the database is still to hand.
 *
 * Returns ['show' => bool, 'privacy_url' => string]. show is false once the
 * visitor has decided, which is the same test the legacy partial made.
 */
function snapsmack_consent_prepare(PDO $pdo, array $settings): array
{
    // Already decided. The cookie is the record of the choice, and
    // ss-engine-consent.js is what sets it.
    if (isset($_COOKIE['snap_consent'])) {
        return ['show' => false, 'privacy_url' => ''];
    }

    $base = defined('BASE_URL') ? (string)BASE_URL : '/';
    $privacy_url = '';

    // The built-in policy page first, then an active privacy static page.
    // Same order of preference as the legacy partial, and both lookups stay
    // optional because older installs have neither.
    try {
        $policy = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN ('privacy_policy_enabled', 'privacy_policy_content')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if ((string)($policy['privacy_policy_enabled'] ?? '0') === '1'
            && trim((string)($policy['privacy_policy_content'] ?? '')) !== '') {
            $privacy_url = $base . 'privacy-policy.php';
        }
    } catch (PDOException $e) { /* optional on older installs */ }

    if ($privacy_url === '') {
        try {
            $statement = $pdo->prepare(
                "SELECT slug FROM snap_pages
                 WHERE slug IN ('privacy', 'privacy-policy', 'cookies')
                   AND is_active = 1 LIMIT 1"
            );
            $statement->execute();
            $slug = $statement->fetchColumn();
            if ($slug) $privacy_url = $base . 'page/' . $slug;
        } catch (PDOException $e) { /* optional on very fresh installs */ }
    }

    return ['show' => true, 'privacy_url' => $privacy_url];
}

/**
 * The banner's markup, or '' when there is nothing to show.
 *
 * Takes what snapsmack_consent_prepare() worked out. It is a pure function of
 * that array so it can be rendered in a test without a database, which is how
 * the render-all-skins check exercises it.
 */
function snapsmack_consent_banner_html(array $consent): string
{
    if (empty($consent['show'])) return '';

    $privacy_url = (string)($consent['privacy_url'] ?? '');
    $link = $privacy_url === ''
        ? ''
        : "\n        <a href=\"" . htmlspecialchars($privacy_url, ENT_QUOTES) . "\">Privacy policy</a>.";

    // The original copy, unchanged. Do not reword this: it is a privacy notice
    // on the owner's own sites and it is not an agent's to rewrite.
    return '<div id="snap-consent-banner" role="dialog" aria-label="Privacy choice">'
        . "\n" . '    <span class="consent-text">'
        . "\n" . '        This site can remember display and navigation preferences on this device.'
        . "\n" . '        Limited first-party usage information is recorded whether or not preferences are allowed, for troubleshooting and to understand which content is viewed.'
        . "\n" . '        Raw IP addresses are not stored. There are no advertising trackers, no cross-site tracking, and no third-party analytics.'
        . $link
        . "\n" . '    </span>'
        . "\n" . '    <span class="consent-buttons">'
        . "\n" . '        <button id="snap-consent-accept" type="button">Allow preferences</button>'
        . "\n" . '        <button id="snap-consent-decline" type="button">Continue without</button>'
        . "\n" . '    </span>'
        . "\n" . '</div>' . "\n";
}

/**
 * Print the banner on a strict page. Called from the registered-assets
 * component, the one place all 30 renderable skins already reach.
 *
 * Reads what the runtime prepared. If the runtime did not run -- an admin
 * preview, a test rendering a component on its own -- there is nothing
 * prepared and nothing is printed, rather than a banner with no engine behind
 * it.
 */
function snapsmack_consent_banner_tag(): void
{
    static $emitted = false;
    if ($emitted) return;
    $consent = $GLOBALS['snapsmack_consent'] ?? null;
    if (!is_array($consent)) return;
    $html = snapsmack_consent_banner_html($consent);
    if ($html === '') return;
    $emitted = true;
    echo $html;
}

// ===== SNAPSMACK EOF =====
