<?php
declare(strict_types=1);

/**
 * SNAPSMACK - POCKET ALBUM cross-site sharing control
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE FAULT THIS CLOSES. The `pocket_album_share` setting shipped in 1004D as
 * a READ in core/pocket-album-api.php and nothing else. No control, no save
 * path, no writer anywhere in the product. The endpoint therefore answered 404
 * on every site in the fleet and could not be switched on by any means short
 * of an UPDATE by hand. Sean reported it on 2026-11-26: "cannot find pocket
 * album share option in config." There was nothing to find. That is the
 * four-controls-that-did-nothing fault class (SS-1013 / 1017 / 1086) with the
 * control missing outright rather than merely inert.
 *
 * It lives here, not inline in a page, so the key's spelling, the gate the
 * endpoint reads and the clamp the admin save applies all come from one file
 * and can be executed by a test. An inline save path nobody could run is how
 * the original gap got past the suite.
 *
 * The control sits on the Configuration page (smack-settings.php), which is
 * administrator-only through the SECAUDIT 047 denylist, because this widens
 * what the site hands to other sites. It is not a content setting.
 */

/**
 * The setting key. One spelling, in one place, used by every side.
 */
function snap_pocket_album_share_key(): string
{
    return 'pocket_album_share';
}

/**
 * Is cross-site album sharing switched on?
 *
 * Only 'on' enables it. Anything else -- absent, empty, '1', 'true', 'off' --
 * is off, because this widens what the site hands to strangers and a typo must
 * not open it.
 */
function snap_pocket_album_share_enabled(array $settings): bool
{
    return (string)($settings[snap_pocket_album_share_key()] ?? '') === 'on';
}

/**
 * Server-side check on a submitted value. Returns 'on' or 'off', never
 * anything else, whatever arrived.
 *
 * The Configuration page writes every posted settings[...] key to the database
 * as it stands, so a control whose value is not clamped here is a control that
 * stores whatever a crafted POST sends. This is that clamp, and it sits beside
 * the gate that reads the value so the two cannot disagree.
 */
function snap_pocket_album_share_sanitise($posted): string
{
    return (is_string($posted) && $posted === 'on') ? 'on' : 'off';
}

/**
 * The control, for the Configuration page's main settings form.
 *
 * A two-value SELECT, deliberately not a checkbox: an unticked box sends
 * nothing at all, so a checkbox needs a hidden presence marker to tell "off"
 * apart from "this form did not carry the control", and any other admin form
 * that lacked the marker would switch sharing off just by being saved. A
 * select always posts exactly one of its two values.
 */
function snap_pocket_album_share_render(array $settings): void
{
    $on  = snap_pocket_album_share_enabled($settings);
    $key = snap_pocket_album_share_key();
    $nl  = "\n";
    echo '<div class="lens-input-wrapper">' . $nl
       . '    <label>ALBUM SHARING <span class="field-tip" data-tip="Lets another SnapSmack'
       . ' site show one of your albums inside a page of its own, with the POCKET ALBUM'
       . ' shortcode. Your own albums show on your own site either way.">&#9432;</span></label>' . $nl
       . '    <select name="settings[' . $key . ']">' . $nl
       . '        <option value="off"' . ($on ? '' : ' selected')
       . '>OFF &mdash; no other site may read this site&rsquo;s albums</option>' . $nl
       . '        <option value="on"' . ($on ? ' selected' : '')
       . '>ON &mdash; other SnapSmack sites may read this site&rsquo;s albums</option>' . $nl
       . '    </select>' . $nl
       . '    <p class="field-hint">Off by default. With it off, a request from another site'
       . ' gets a 404. This does not affect albums shown on this site.</p>' . $nl
       . '</div>' . $nl;
}

// ===== SNAPSMACK EOF =====
