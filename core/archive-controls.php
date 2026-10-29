<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * THE ARCHIVE'S OWN CONTROLS, resolved once for both render paths.
 *
 * Sean, 28 October 2026: "Where are my thumbs, masonry, and calendar controls
 * i had?" and then "have thumbs, masonry, and calendar been removed from other
 * skins?" Measured across the thirty skins the same night: the whole control
 * row existed in exactly one place -- the 'archive-filters' component -- and
 * the T/M toggle inside it had never rendered on any skin at all, because the
 * component reads response.archive_layouts and NOTHING ASSIGNED IT. The
 * calendar reached the strict path in no form whatever.
 *
 * WHY IT WENT. All of this resolution lived inside archive.php, between its
 * redirects and its thumbnail arithmetic -- settings, skin locks, the ?layout=
 * override, the cookie, the legacy value tolerance, the two calendar flags.
 * A strict skin never runs archive.php: index.php dispatches it to
 * snapsmack_public_runtime() hundreds of lines earlier and exits. Twenty-eight
 * of the thirty skins are strict.
 *
 * So this is not new behaviour. It is archive.php's resolution lifted out
 * whole, so that there is ONE answer to "which layout is showing and what may
 * the visitor switch to" and both paths read it. Two implementations is how
 * the paths came to disagree in the first place; the regression asserts that
 * this one and archive.php's still agree, input for input.
 *
 * It returns DATA ONLY -- modes, labels, flags. No URLs: the archive's real
 * address differs per site and per route alias, and the caller that knows it
 * builds the links. No markup: that is the component's job.
 */
function snapsmack_archive_controls(array $settings, array $manifest, array $query = [], array $cookies = []): array
{
    $features = is_array($manifest['features'] ?? null) ? $manifest['features'] : [];
    $requires = is_array($manifest['require_scripts'] ?? null) ? $manifest['require_scripts'] : [];

    // --- WHAT THE SKIN ALLOWS -------------------------------------------
    // A skin may lock itself to one grid: masonry_supported=>false is
    // thumbs-only, thumbs_supported=>false is masonry-only (GALLERIA). A
    // single-mode skin then offers no toggle, because there is nothing to
    // toggle to.
    $modes = [];
    if (($features['thumbs_supported'] ?? true) !== false)  $modes[] = 'thumbs';
    if (($features['masonry_supported'] ?? true) !== false) $modes[] = 'masonry';
    if (!$modes) $modes = ['thumbs']; // never zero

    // --- THE OWNER'S DEFAULT --------------------------------------------
    // The manifest's preferred default applies only when the site has NO
    // stored setting, which is archive.php's own `?? $skin_layout_fallback`.
    // Reading it when the setting is merely empty would hand a two-grid skin
    // a different default on one path than on the other, which is the exact
    // class of divergence this file exists to end.
    $default = $settings['archive_layout'] ?? null;
    if ($default === null) {
        $default = !empty($features['archive_layout_default'])
            ? (string)$features['archive_layout_default']
            : 'square';
    }
    $default = (string)$default;
    // Pre-0.7.79 four-way values. square, cropped and croppedwithcalendar were
    // all thumbnail grids; only masonry is the wall.
    if (in_array($default, ['square', 'cropped', 'croppedwithcalendar'], true)) $default = 'thumbs';
    if (!in_array($default, ['thumbs', 'masonry'], true)) $default = 'thumbs';
    // Snap it into what this skin actually offers, so a masonry-only skin
    // defaults to masonry rather than to an unavailable 'thumbs'.
    if (!in_array($default, $modes, true)) $default = $modes[0];

    // --- THE VISITOR'S CHOICE: url, then cookie, then the default -------
    $override = (string)($query['layout'] ?? '');
    $forceCalendarOpen = false;
    if ($override === 'croppedwithcalendar') { $override = 'thumbs'; $forceCalendarOpen = true; }
    elseif (in_array($override, ['square', 'cropped'], true)) $override = 'thumbs';
    if ($override === '' && isset($cookies['smack_archive_layout'])) {
        $stored = (string)$cookies['smack_archive_layout'];
        if (in_array($stored, ['square', 'cropped', 'croppedwithcalendar'], true)) $stored = 'thumbs';
        if (in_array($stored, ['thumbs', 'masonry'], true)) $override = $stored;
    }
    $active = in_array($override, $modes, true) ? $override : $default;

    // --- THUMB STYLE ----------------------------------------------------
    // Independent of layout: it is the shape of a tile when the layout is
    // thumbs. A skin may force it (HIP TO BE SQUARE) and that wins over the
    // per-site setting, so a square-identity skin never drifts to cropped.
    $thumbStyle = (string)($settings['archive_thumb_style'] ?? 'cropped');
    if (!in_array($thumbStyle, ['square', 'cropped'], true)) $thumbStyle = 'cropped';
    if (in_array(($features['archive_thumb_style'] ?? ''), ['square', 'cropped'], true)) {
        $thumbStyle = (string)$features['archive_thumb_style'];
    }

    // --- THE CALENDAR ---------------------------------------------------
    // Two owner switches, plus the visitor's last state. The button is inert
    // without its engine, so whether the skin carries it travels with the
    // flags rather than being guessed at by the component.
    $calendarEnabled = !empty($settings['archive_calendar_enabled']);
    $calendarOpen = false;
    if ($calendarEnabled) {
        if ($forceCalendarOpen) $calendarOpen = true;
        elseif (isset($cookies['smack_archive_calendar'])) $calendarOpen = ($cookies['smack_archive_calendar'] === 'open');
        else $calendarOpen = !empty($settings['archive_calendar_default_open']);
    }
    $calendarEngine = in_array('smack-calendar', $requires, true)
                   || in_array('croppedwithcalendar', (array)($features['archive_layouts'] ?? []), true);

    // --- THE TOGGLE -----------------------------------------------------
    $offerToggle = (isset($settings['archive_show_layout_toggle'])
                    ? !empty($settings['archive_show_layout_toggle'])
                    : true)
                 && count($modes) > 1;

    $labels = ['thumbs' => 'T', 'masonry' => 'M'];
    $titles = ['thumbs' => 'Thumbs Layout', 'masonry' => 'Masonry / Justified Layout'];
    $layouts = [];
    if ($offerToggle) {
        foreach ($modes as $mode) {
            $layouts[] = [
                'mode'   => $mode,
                'label'  => $labels[$mode],
                'title'  => $titles[$mode],
                'active' => $mode === $active,
            ];
        }
    }

    return [
        'layouts'      => $layouts,
        'modes'        => $modes,
        'active'       => $active,
        'default'      => $default,
        'offer_toggle' => $offerToggle,
        'thumb_style'  => $thumbStyle,
        'calendar'     => [
            'enabled' => $calendarEnabled,
            'open'    => $calendarOpen,
            'engine'  => $calendarEngine,
        ],
    ];
}

/**
 * The query keys a layout link must DROP.
 *
 * Switching from thumbs to masonry keeps you on the archive; it must not keep
 * you inside a search, a filter or a date range, because those narrow the set
 * and the visitor clicked a layout, not a filter. archive.php has always
 * stripped exactly these six; the strict path stripped none, so the engine's
 * pushState and the server's own link disagreed about where the toggle goes.
 */
function snapsmack_archive_layout_link_drops(): array
{
    return ['q', 'cat', 'album', 'date', 'from', 'to'];
}

/**
 * Give each resolved layout the address it switches to.
 *
 * Done here, once, rather than in each component that draws the row: the
 * archive's real address is the site's own route alias, and the component that
 * drew its own link got the literal /archive -- the unregistered path that
 * sent six sites into a redirect loop. Each link is the bare archive plus
 * ?layout=, which arrives there with none of the keys above still attached.
 */
function snapsmack_archive_layout_links(array $layouts, string $base, array $aliases, string $siteMode = ''): array
{
    require_once __DIR__ . '/public-route-aliases.php';
    $linked = [];
    foreach ($layouts as $layout) {
        if (!is_array($layout) || ($layout['mode'] ?? '') === '') continue;
        $layout['url'] = snapsmack_legacy_archive_target(
            $base, $aliases, $siteMode,
            'layout=' . rawurlencode((string)$layout['mode'])
        );
        $linked[] = $layout;
    }
    return $linked;
}

// ===== SNAPSMACK EOF =====
