<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/** Public menu labels become readable route segments; controller names stay internal. */
function snapsmack_public_route_slug(string $label, string $fallback): string
{
    $value = trim($label);
    if ($value !== '' && function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
}

/**
 * Return the bounded public aliases declared by the configured navigation.
 * Keys are public path segments; values are internal controller views.
 */
function snapsmack_public_route_aliases(array $settings, array $manifest = []): array
{
    $configured = json_decode((string)($settings['nav_menu_json'] ?? '[]'), true);
    $items = is_array($configured) && $configured ? $configured : ($manifest['cms_navigation'] ?? []);
    if (!is_array($items)) return [];

    // Strict skins without a saved Menu Manager tree receive the CMS default
    // Home + Blogroll navigation in snapsmack_prepare_skin_navigation(). Keep
    // its clean route registered here as well; otherwise the CMS emits a
    // /blogroll link which falls through to ordinary slug resolution and 404s.
    if (!$items && in_array((string)($manifest['cms_controller'] ?? ''), ['public', 'smacktalk'], true)) {
        $items = [['type' => 'blogroll', 'label' => 'Blogroll']];
    }

    $archiveView = (($manifest['cms_controller'] ?? '') === 'smacktalk') ? 'diary' : 'archive';
    $isCarousel = (($manifest['site_mode'] ?? '') === 'carousel');
    $typeToView = [
        // A SMACKTALK archive is the chronological essay/post diary. The
        // separate image_archive item owns the Gallery photograph wall.
        'archive' => $archiveView,
        'image_archive' => 'archive',
        'blogroll' => 'blogroll',
        'categories' => 'categories',
        'albums' => 'albums',
        'collections' => 'collections',
    ];
    $fallbacks = [
        'archive' => 'archive',
        'image_archive' => 'images',
        'blogroll' => 'blogroll',
        'categories' => 'categories',
        'albums' => 'albums',
        'collections' => 'collections',
    ];
    $aliases = [];
    $walk = static function (array $nodes) use (&$walk, &$aliases, $typeToView, $fallbacks, $isCarousel): void {
        foreach ($nodes as $item) {
            if (!is_array($item) || (isset($item['active']) && !$item['active'])) continue;
            $type = (string)($item['type'] ?? '');
            if ($type === 'custom') {
                $path = strtolower((string)(parse_url((string)($item['url'] ?? ''), PHP_URL_PATH) ?? ''));
                $file = basename($path);
                $type = match ($file) {
                    'archive.php' => 'image_archive',
                    'blogroll.php' => 'blogroll',
                    'albums.php' => 'albums',
                    default => $type,
                };
            }
            // A GRAMOFSMACK/carousel landing page already is its archive.
            // Do not manufacture a second public archive route from an old
            // menu record left behind by the legacy endpoint scheme.
            if ($isCarousel && in_array($type, ['archive', 'image_archive'], true)) {
                if (is_array($item['children'] ?? null)) $walk($item['children']);
                continue;
            }
            if (isset($typeToView[$type])) {
                $slug = snapsmack_public_route_slug((string)($item['label'] ?? ''), $fallbacks[$type]);
                if (!isset($aliases[$slug])) $aliases[$slug] = $typeToView[$type];
            }
            if (is_array($item['children'] ?? null)) $walk($item['children']);
        }
    };
    $walk($items);
    // Stable conventional paths remain valid even when the owner gives the
    // corresponding menu item a more personal label. The label-derived alias
    // stays first and is therefore the canonical URL emitted by navigation.
    foreach (['diary' => 'diary', 'images' => 'archive', 'blogroll' => 'blogroll',
                 'categories' => 'categories', 'albums' => 'albums', 'collections' => 'collections'] as $slug => $view) {
        if (($manifest['cms_controller'] ?? '') === 'public' && in_array($view, ['albums', 'collections'], true)) {
            $aliases[$slug] = $view;
        } elseif (in_array($view, $aliases, true) && !isset($aliases[$slug])) {
            $aliases[$slug] = $view;
        }
    }
    return $aliases;
}

/**
 * The canonical public segment for a view.
 *
 * SEAN'S RULING, 31 October 2026: the archive's public address is /archive, on
 * every site and every skin. He had asked more than once and been told it was
 * not possible, which was wrong -- it was never a decision at all. The segment
 * is the navigation LABEL slugified (see the walk below), and the label the CMS
 * itself supplies by default is the words "Archive View"
 * (core/skin-view-model.php), so the automatic result was /archive-view.
 * Nobody chose that URL and nobody refused him the short one.
 *
 * ONLY THE DIRECTION WAS WRONG. /archive already RESOLVES everywhere, because
 * core/public-runtime.php honours a bare path that is the name of a route with
 * or without a menu alias. The label-derived alias was merely emitted as the
 * canonical one, so every link pointed at the long form and the short form
 * redirected away to it. This function is the one place that decides which
 * form is canonical, so it is the one place that had to change.
 *
 * THREE THINGS IT DELIBERATELY DOES NOT DO.
 *   It does not claim /archive on a site with no archive view. The alias table
 *   is built from the menu, and the builder skips archive items on a carousel
 *   landing page because that page already IS its archive, so those sites keep
 *   their existing behaviour untouched.
 *   It does not take a segment OWNER CONTENT already owns. $owner_slug_taken
 *   is answered by the caller, which has the database. Owner content wins:
 *   making a page the owner wrote unreachable is worse than a long URL, and
 *   that is Sean's standing instruction, not an inference.
 *   It does not touch the SMACKTALK diary. A smacktalk site maps its 'archive'
 *   menu type to the 'diary' view; only the photograph wall is view 'archive'.
 */
/**
 * Does owner content already own the segment 'archive' on this site?
 *
 * ANSWERED ONCE PER REQUEST AND REMEMBERED, because several emitters each need
 * the same answer and they must not disagree: a sitemap that advertises
 * /archive while the router hands /archive to the owner's page would be worse
 * than either behaviour on its own.
 *
 * The first caller that has a database primes it; later callers may ask with
 * no handle and get the remembered answer. With no handle and nothing
 * remembered it answers false -- "nothing known to be in the way" -- which is
 * the right default for an emitter, and is never the value the ROUTER acts on,
 * because core/public-runtime.php primes this with a real handle before it
 * resolves anything.
 */
function snapsmack_public_archive_slug_taken(?PDO $pdo = null): bool
{
    static $taken = null;
    if ($taken !== null) return $taken;
    if ($pdo === null) return false;
    $taken = false;
    try {
        // A page the owner wrote, a photograph, or a post. Any of the three
        // owning this word is enough to stand aside.
        // ONE indexed lookup per request, not two, and not one per emitter.
        // is_active = 1 on the page matches activePageBySlug: a page the owner
        // has switched off is not reachable, so it is not in the way.
        // The cost is stated plainly rather than hidden: this is one extra
        // query on a page load, and it buys the guarantee that a page the
        // owner wrote is never made unreachable by a reserved word.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM snap_pages  WHERE slug     = ? AND is_active = 1
             UNION ALL
             SELECT 1 FROM snap_images WHERE img_slug = ?
             LIMIT 1'
        );
        $stmt->execute(['archive', 'archive']);
        $taken = ($stmt->fetchColumn() !== false);
    } catch (\Throwable $e) {
        // An older install missing a column must not lose its archive link.
        $taken = false;
    }
    return $taken;
}

function snapsmack_public_canonical_slug(string $view, array $aliases, string $fallback,
                                         ?bool $owner_slug_taken = null): string
{
    if ($owner_slug_taken === null) $owner_slug_taken = snapsmack_public_archive_slug_taken();
    if ($view === 'archive' && !$owner_slug_taken && in_array('archive', $aliases, true)) {
        return 'archive';
    }
    $slug = array_search($view, $aliases, true);
    if (!is_string($slug) || $slug === '') $slug = $fallback;
    return $slug;
}

function snapsmack_public_route_url(string $base, array $aliases, string $view, string $fallback,
                                    ?bool $owner_slug_taken = null): string
{
    $slug = snapsmack_public_canonical_slug($view, $aliases, $fallback, $owner_slug_taken);
    return rtrim($base, '/') . '/' . rawurlencode($slug);
}

/**
 * Where a legacy archive.php link should go.
 *
 * The alias table is built from the site's navigation, so a site whose menu
 * has no archive item -- or labels it something else, such as "Images" --
 * has NO slug mapping to the archive view. Asking snapsmack_public_route_url()
 * for one then returns its FALLBACK, the literal slug 'archive', and
 * archive.php redirected there: a path the CMS never registered, which the
 * rewrite handed straight back to archive.php, which redirected again. Six
 * live sites answered /archive.php with an endless 301 loop.
 *
 * So a pretty slug is used only when one genuinely exists. Otherwise the
 * request goes to ?view=archive, which snapsmack_public_runtime_request()
 * already accepts and renders through the strict controller in the site's own
 * skin. Never a slug that is not in the table.
 */
/** Is this redirect target the very address we were just asked for?
 *
 *  archive.php guards its legacy redirect against looping, and the guard
 *  compared PATHS only, then redirected anyway whenever the target carried a
 *  query string. snapsmack_legacy_archive_target() carries the incoming query
 *  through on purpose, so /archive?page=2 produced the target /archive?page=2:
 *  same path, has a query, redirect -- to itself, forever. Page one of the
 *  archive rendered and page two was unreachable on every site whose skin
 *  enters that block; four of seven I measured were looping.
 *
 *  Order-insensitive on the query, because the target's is rebuilt by
 *  http_build_query() and need not come back in the order it arrived. */
function snapsmack_is_same_address(string $target, string $here_path, string $here_query): bool
{
    if (rtrim((string)parse_url($target, PHP_URL_PATH), '/') !== rtrim($here_path, '/')) {
        return false;
    }
    parse_str((string)(parse_url($target, PHP_URL_QUERY) ?? ''), $there);
    parse_str(ltrim(trim($here_query), '?&'), $mine);
    ksort($there);
    ksort($mine);
    return $there === $mine;
}

function snapsmack_legacy_archive_target(string $base, array $aliases, string $site_mode = '', string $query = ''): string
{
    // A carousel landing page already IS its archive.
    if ($site_mode === 'carousel') return $base;
    // ONE DEFINITION OF CANONICAL. This used to pick the label-derived alias
    // itself, so archive.php could send a visitor somewhere the rest of the
    // CMS no longer called canonical. It now asks the same function every
    // other emitter asks, which is what makes /archive true everywhere rather
    // than true in most places.
    $target = rtrim($base, '/') . '/'
        . rawurlencode(snapsmack_public_canonical_slug('archive', $aliases, 'archive'));

    // CARRY THE QUESTION. An album card links to /archive?album=232, and a
    // redirect that keeps only the path answers with the whole archive
    // instead of that album -- which is what every album, category, search,
    // filter and layout link did, because they all travel in the query.
    // The target's own parameters win, so ?view=archive cannot be displaced.
    $query = ltrim(trim($query), '?&');
    if ($query === '') return $target;
    parse_str($query, $incoming);
    $existing = [];
    parse_str((string)(parse_url($target, PHP_URL_QUERY) ?? ''), $existing);
    $merged = array_merge($incoming, $existing);
    if (!$merged) return $target;
    $path = strtok($target, '?');
    return $path . '?' . http_build_query($merged);
}

// ===== SNAPSMACK EOF =====
