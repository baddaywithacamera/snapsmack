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
 * It lives here rather than inline in smack-albums.php for two reasons: an
 * inline save path cannot be executed by a test, which is how the original gap
 * got past one, and the reader and the writer must agree on the spelling of
 * the key. Both sides of the switch now come from this one file.
 */

/**
 * The setting key. One spelling, in one place, used by both sides.
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
 * Read the control out of a submitted form.
 *
 * Returns 'on' or 'off' when the form carried this control, and NULL when it
 * did not. NULL means "leave the stored value alone": an unticked checkbox and
 * a form that never had the checkbox look identical in $_POST, so the rendered
 * form posts a presence marker. Without it, saving any other admin form would
 * silently switch sharing off.
 */
function snap_pocket_album_share_read(array $post): ?string
{
    if (!isset($post['save_pocket_album_share'])) {
        return null;
    }
    return isset($post[snap_pocket_album_share_key()]) ? 'on' : 'off';
}

/**
 * Store the value. Refuses anything but the two it defined.
 */
function snap_pocket_album_share_save(PDO $pdo, string $value): void
{
    if ($value !== 'on' && $value !== 'off') {
        throw new InvalidArgumentException('pocket album sharing is on or off');
    }
    // Written as SELECT-then-UPDATE-or-INSERT rather than MySQL's ON DUPLICATE
    // KEY UPDATE so the save path can be executed by a test against a real
    // database. An inline save nobody could run is how the missing control got
    // past the suite in the first place.
    $key = snap_pocket_album_share_key();
    $sel = $pdo->prepare("SELECT 1 FROM snap_settings WHERE setting_key = ? LIMIT 1");
    $sel->execute([$key]);
    if ($sel->fetchColumn()) {
        $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?")
            ->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)")
            ->execute([$key, $value]);
    }
}

/**
 * The control itself. Prints the box; the caller owns the <form> and the CSRF
 * field, because this sits inside an existing admin form on its own page.
 */
function snap_pocket_album_share_render(array $settings): void
{
    $on = snap_pocket_album_share_enabled($settings);
    echo '<div class="box">' . "\n"
       . '    <h3>ALBUM SHARING</h3>' . "\n"
       . '    <input type="hidden" name="save_pocket_album_share" value="1">' . "\n"
       . '    <div class="option-group">' . "\n"
       . '        <label class="toggle-row">' . "\n"
       . '            <div>' . "\n"
       . '                <div class="toggle-label">LET OTHER SNAPSMACK SITES READ THIS SITE&rsquo;S ALBUMS</div>' . "\n"
       . '                <div class="toggle-desc">Off by default. Your own albums show on your own'
       . ' site either way &mdash; this only governs another site pulling an album of yours in with'
       . ' the POCKET ALBUM shortcode. With it off, that request gets a 404.</div>' . "\n"
       . '            </div>' . "\n"
       . '            <input type="checkbox" name="' . snap_pocket_album_share_key() . '" value="on"'
       . ($on ? ' checked' : '') . '>' . "\n"
       . '        </label>' . "\n"
       . '    </div>' . "\n"
       . '    <div class="lens-input-wrapper mt-20">' . "\n"
       . '        <button type="submit" class="master-update-btn">SAVE SHARING</button>' . "\n"
       . '    </div>' . "\n"
       . '</div>' . "\n";
}

// ===== SNAPSMACK EOF =====
