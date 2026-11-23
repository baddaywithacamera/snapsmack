<?php
declare(strict_types=1);

/**
 * SNAPSMACK — Social Dock appearance, resolved once for both render paths.
 *
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line MUST be the canonical PHP EOF marker.
 *
 * WHY THIS FILE EXISTS. The dock is drawn by two different paths and they
 * disagreed about who wins.
 *
 *   floating, core/social-dock.php:255
 *       $settings['social_dock_color_light'] ?? $dock['default_color_light'] ?? '#ffffff'
 *       owner setting first, then the skin's declared default.
 *
 *   strict, core/skin-view-model.php:158
 *       $dock['color_dark'] ?? $settings['social_dock_color_dark'] ?? '#1a1a1a'
 *       the SKIN first, and it never looked at default_color_dark at all.
 *
 * So a skin on the legacy keys silently overrode the owner on strict skins but
 * not on the floating dock, and a skin on the new default_* keys was honoured
 * on the floating dock and ignored on strict skins. Exactly backwards from
 * each other, in the one product.
 *
 * It showed up on used car parts, the only site in the fleet that renders a
 * dock. SCROLL declares the legacy block
 *     {"position":"inline","color_mode":"dark","icon_style":"outline","shadow":false}
 * The admin hid all four controls as "(controlled by the SCROLL skin)", and
 * the strict renderer looked for default_color_mode, did not find it, and fell
 * back to the owner's saved setting — which the owner could not set, because
 * the admin had hidden it on the skin's behalf. The skin asked for dark, the
 * admin said the skin decides, and the page rendered light.
 *
 * THE ORDER, ruled by the coordinator 2026-11-24: the owner's saved setting,
 * then the skin's default_* declaration, then the skin's legacy key, then the
 * built-in fallback. A skin declaration is a DEFAULT now, never an override —
 * which is also why the admin stopped locking the appearance controls and
 * locks only position, the one thing a skin genuinely owns.
 *
 * MANIFEST KEY DESCRIPTION — default_icon_style (legacy: icon_style): selects
 * the solid or outline artwork used by the floating Social Dock. Strict-skin
 * docks deliberately use the shared single outline sprite family, so this key
 * does not switch their artwork. Their colour, opacity and other resolved
 * appearance values still follow the owner-first order above.
 *
 * Both paths call this. Two implementations that agree by inspection is how
 * they came to disagree in the first place.
 */

if (!function_exists('snapsmack_social_dock_resolve')) {
    /**
     * One value, by the agreed order: setting, default_*, legacy, fallback.
     *
     * Presence is array_key_exists plus a non-empty string, NOT isset: a saved
     * shadow of '0' and a saved opacity of 0 are real owner choices and must
     * beat the skin, while a row that was never written or was cleared to ''
     * must fall through to it.
     */
    function snapsmack_social_dock_resolve(array $settings, array $dock,
                                           string $setting_key, string $name,
                                           mixed $fallback): mixed {
        if (array_key_exists($setting_key, $settings)
            && (string)$settings[$setting_key] !== '') {
            return $settings[$setting_key];
        }
        if (array_key_exists('default_' . $name, $dock)) {
            return $dock['default_' . $name];
        }
        // The legacy spelling, still declared by FULL MONTY, GLIDE and SCROLL.
        // Honoured as a DEFAULT so those skins keep their intended look until
        // they are migrated, without being able to override the owner again.
        if (array_key_exists($name, $dock)) {
            return $dock[$name];
        }
        return $fallback;
    }
}

if (!function_exists('snapsmack_social_dock_skin_owns')) {
    /**
     * Is this dock control genuinely the skin's to decide?
     *
     * ONLY POSITION. Everything else a skin declares is a DEFAULT, resolved
     * by snapsmack_social_dock_resolve() above with the owner's setting
     * winning. The admin asks this before hiding a control behind
     * "(controlled by the <SKIN> skin)".
     *
     * It used to ask array_key_exists($key, $dock), so any key a skin named
     * hid the matching control. SCROLL names color_mode, icon_style and
     * shadow, so on used car parts all four controls vanished while the
     * renderer went on using the owner's saved values -- values the owner
     * could no longer set. A control you cannot reach and a declaration that
     * does nothing, at the same time.
     *
     * Lives here rather than inline in the admin page so it can be driven by
     * a test. The inline version was a closure in a procedural page, which is
     * why the first version of this rule shipped only checked that a list
     * existed and not that anything consulted it.
     */
    function snapsmack_social_dock_skin_owns(string $key, array $dock): bool {
        return $key === 'position' && array_key_exists('position', $dock);
    }
}

if (!function_exists('snapsmack_social_dock_appearance')) {
    /**
     * Every appearance value the dock needs, resolved and validated.
     *
     * Returns mode, color, color_light, color_dark, icon_style, shadow,
     * opacity (0-100) and rgb. Each render path turns these into its own
     * presentation; what is shared here is WHO WINS, not how it is drawn.
     * icon_style switches floating-dock artwork only; strict skins always use
     * the single outline sprite family documented above.
     */
    function snapsmack_social_dock_appearance(array $settings, array $dock): array {
        $mode = (string) snapsmack_social_dock_resolve(
            $settings, $dock, 'social_dock_color_mode', 'color_mode', 'light');
        $mode = $mode === 'dark' ? 'dark' : 'light';

        $light = (string) snapsmack_social_dock_resolve(
            $settings, $dock, 'social_dock_color_light', 'color_light', '#ffffff');
        $dark = (string) snapsmack_social_dock_resolve(
            $settings, $dock, 'social_dock_color_dark', 'color_dark', '#1a1a1a');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $light)) $light = '#ffffff';
        if (!preg_match('/^#[0-9a-f]{6}$/i', $dark))  $dark  = '#1a1a1a';

        $style = (string) snapsmack_social_dock_resolve(
            $settings, $dock, 'social_dock_icon_style', 'icon_style', 'solid');
        $style = $style === 'outline' ? 'outline' : 'solid';

        $shadow = snapsmack_social_dock_resolve(
            $settings, $dock, 'social_dock_shadow', 'shadow', true);
        // '0' from a checkbox, false from JSON, 0 from anywhere: all mean off.
        $shadow = filter_var($shadow, FILTER_VALIDATE_BOOLEAN,
                             FILTER_NULL_ON_FAILURE) ?? true;

        $opacity = (int) snapsmack_social_dock_resolve(
            $settings, $dock, 'social_dock_opacity', 'opacity', 50);
        $opacity = max(0, min(100, $opacity));

        $color = $mode === 'dark' ? $dark : $light;
        $rgb = implode(',', [
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        ]);

        return [
            'mode' => $mode,
            'color' => $color,
            'color_light' => $light,
            'color_dark' => $dark,
            'icon_style' => $style,
            'shadow' => $shadow,
            'opacity' => $opacity,
            'rgb' => $rgb,
        ];
    }
}

// ===== SNAPSMACK EOF =====
