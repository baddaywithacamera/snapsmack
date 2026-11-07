<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/public-controller.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/archive-controls.php';
require_once __DIR__ . '/consent-runtime.php';

/** Expand CMS-owned static-page shortcodes before the presentation-only skin sees them. */
function snapsmack_public_parse_page_content(PDO $pdo, array $response): array
{
    if (($response['kind'] ?? '') !== 'page' || !is_array($response['item'] ?? null)) return $response;
    $source = (string)($response['item']['content'] ?? '');
    // Strict public skins do not include core/meta.php. Static pages still use
    // the CMS-owned shortcode parser below, so carry the parser's existing
    // presentation assets through the registered-asset path. Without these,
    // [columns] emits the correct grid markup but browsers render it as plain
    // stacked divs (the failure seen on PIXHELLATED's Cameras page).
    $response['required_assets']['styles'] = array_values(array_unique(array_merge(
        (array)($response['required_assets']['styles'] ?? []),
        ['asset:public:page-static', 'asset:public:shortcodes', 'asset:public:columns']
    )));
    $response['required_assets']['scripts'] = array_values(array_unique(array_merge(
        (array)($response['required_assets']['scripts'] ?? []),
        ['smack-lightbox']
    )));
    if (stripos($source, '[board]') !== false || stripos($source, '[prompts]') !== false) {
        // A shortcode may require audited CMS assets, but its generated HTML
        // still passes through the normal sanitizer. Never allow page content
        // to smuggle arbitrary <link> elements into the document.
        $response['required_assets']['styles'] = array_values(array_unique(array_merge(
            $response['required_assets']['styles'], [
                'asset:public:photochallenge-board-layouts',
                'asset:public:photochallenge-board-embed',
            ]
        )));
    }
    $parser = new SnapSmack($pdo);
    $response['item']['content'] = $parser->parseContent($source);
    return $response;
}

/** Map an entry-point request into the central public controller's bounded vocabulary. */
function snapsmack_public_runtime_request(array $input): array
{
    if (trim((string)($input['query'] ?? '')) !== '') return [
        'route' => 'search', 'query' => $input['query'], 'page' => $input['page'] ?? 1,
    ];
    if (trim((string)($input['tag'] ?? '')) !== '') return ['route' => 'hashtag', 'slug' => $input['tag'], 'page' => $input['page'] ?? 1];
    $view = strtolower(trim((string)($input['view'] ?? '')));
    // 'blog' is here so that the image feed has an address of its own on a
    // site whose homepage is a static page. Without it, /blog fell through to
    // 'resolve', found no page or photograph with that slug, and returned 404,
    // while /blog.php rendered the static homepage -- the strict controller
    // never saw blog.php's $_SERVER['SNAPSMACK_FORCE_BLOG'] flag, which only
    // the legacy render path in index.php reads. Measured on foundtextures.ca:
    // / and /blog.php returned the same <h1> and the same body classes
    // (homepage-static page-slug-home), and /blog returned 404. Sean: "blog
    // link goes to landing page."
    $routes = ['archive', 'albums', 'collections', 'collection', 'photo', 'post', 'page', 'blogroll', 'blog'];
    // 'sort' belongs here with the rest. index.php has been passing it since
    // 0.7.903D and this function dropped it on the floor, so the collections
    // sort a visitor picked never reached the controller that honours it.
    if (in_array($view, $routes, true)) return ['route' => $view, 'slug' => $input['slug'] ?? '', 'id' => $input['id'] ?? 0,
        'page' => $input['page'] ?? 1, 'category_id' => $input['category_id'] ?? 0,
        'album_id' => $input['album_id'] ?? 0, 'sort' => $input['sort'] ?? '',
        'fragment' => $input['fragment'] ?? false];
    $slug = trim((string)($input['slug'] ?? ''));
    $aliases = is_array($input['route_aliases'] ?? null) ? $input['route_aliases'] : [];
    // A bare path that IS the name of a route is that route, alias or no alias.
    // The alias table is built from the site's menu, so a site with no archive
    // item had nothing mapping /archive to the archive -- which is why the
    // address fell back to a query string. The route names are a fixed, closed
    // list above; honouring them costs nothing and gives every site the plain
    // address.
    //
    // THE SENTENCE THAT USED TO END THIS COMMENT WAS FALSE. It claimed "a real
    // page whose slug collides keeps winning, because 'resolve' below still
    // runs for anything not in that list". It does not: this branch RETURNS
    // before the 'resolve' branch, so a page or photograph slugged with a
    // route name was already unreachable on every site -- silently, since
    // nothing 404s and the route simply answers instead. Corrected rather
    // than left, because the next reader would have trusted it.
    //
    // OWNER CONTENT WINS, which is Sean's standing instruction and the reason
    // this is a guard and not a new feature. $input['owner_slug_taken'] is
    // answered by snapsmack_public_runtime() below, which has the database.
    // Scoped to the archive deliberately: that is the view the ruling was
    // about, and widening it to albums, photo, post, page, blog, blogroll,
    // collection and collections would change eight more routes at a release
    // tip. The rest are logged, not quietly altered.
    if ($slug !== '' && in_array(strtolower($slug), $routes, true)
        && !(strtolower($slug) === 'archive' && !empty($input['owner_slug_taken']))) {
        return ['route' => strtolower($slug), 'page' => $input['page'] ?? 1,
            'category_id' => $input['category_id'] ?? 0, 'album_id' => $input['album_id'] ?? 0,
            'sort' => $input['sort'] ?? '', 'fragment' => $input['fragment'] ?? false];
    }
    if ($slug !== '' && isset($aliases[$slug]) && in_array($aliases[$slug], $routes, true)) {
        return ['route' => $aliases[$slug], 'page' => $input['page'] ?? 1,
            'category_id' => $input['category_id'] ?? 0, 'album_id' => $input['album_id'] ?? 0,
            'fragment' => $input['fragment'] ?? false];
    }
    if ($slug !== '') return ['route' => 'resolve', 'slug' => $slug, 'fragment' => $input['fragment'] ?? false];
    if ((int)($input['id'] ?? 0) > 0) return ['route' => 'photo', 'id' => $input['id'], 'fragment' => $input['fragment'] ?? false];
    return ['route' => 'landing', 'page' => $input['page'] ?? 1];
}

/**
 * The comment system, rendered on the CMS side of the strict boundary.
 *
 * core/community-component.php is the real thing: thread, form, guest fields,
 * avatars, likes, reactions, moderation state, fediverse replies. 24 skins
 * included it at v0.7.603D and none does today -- a strict template runs in a
 * closure holding only $view, so the include hits the component's own
 * `empty($img['id'])` guard and returns in silence. The include is still on
 * core/skin-security-policy.php's approved list, which is how we know it was
 * meant to stay.
 *
 * Measured 19 October 2026 across nine live sites, strict skins and legacy
 * alike: zero comment forms, and assets/css/ss-community.css loading on every
 * one of them. Complete code that nothing called.
 *
 * Rendered here because this is where the database handle and the settings
 * are, and handed to the comments component through snap_community_block().
 * The component file itself is untouched.
 */
function snapsmack_public_community_block(PDO $pdo, array $settings, array $item, array $skin_manifest): ?SnapTrustedHtml
{
    if (empty($item['id'])) return null;
    $html = (static function (PDO $pdo, array $settings, array $img, array $skin_manifest): string {
        ob_start();
        include __DIR__ . '/community-component.php';
        return (string)ob_get_clean();
    })($pdo, $settings, $item, $skin_manifest);
    return trim($html) === '' ? null : SnapTrustedHtml::__snapsmackCmsOnly($html);
}

/**
 * SLICE 3: THE PREVIOUS AND NEXT PAGE AS LINKS, WITH THE FILTER IN THEM.
 *
 * previous_page and next_page are numbers. Turning a number into a link means
 * knowing which route you are on and which filters are active, and a strict
 * skin that assembled its own query string would drop the filter the moment
 * anybody paged a filtered archive -- page 2 of "album: Doors" quietly becoming
 * page 2 of everything. Codex asked not to assemble query strings in a layout
 * and he is right: snap_route_url is allowlisted for skins, but the STATE to
 * put in it is the CMS's to know.
 *
 * THREE PAGED ROUTES, THREE DIFFERENT STATES, which is why this is one place
 * and not three skins:
 *   archive  -- category and album
 *   search   -- the query
 *   hashtag  -- the tag slug
 *
 * SEARCH HAS NO previous_page AND SAYS next_page = 0 rather than null when
 * there is no next. Both are left exactly as they are: a consumer testing
 * !empty() cannot tell 0 from null, but one testing === 0 would break, and
 * changing a shipped shape to tidy it is not worth that. The previous page for
 * search is derived from the page number instead, which is the same arithmetic
 * archive and hashtag already do for themselves.
 *
 * A FUNCTION RATHER THAN A BLOCK INSIDE THE RUNTIME, deliberately: inline, the
 * only way to test it would have been to read the source and believe it, and I
 * have spent today finding out what that is worth.
 */
function snapsmack_public_paging_urls(array $response): array
{
    // snap_route_url lives in skin-render-helpers.php. This file does not
    // require it and I will not depend on index.php having loaded it first: an
    // undefined function here is a fatal on every archive page on every site.
    if (!function_exists('snap_route_url')) {
        require_once __DIR__ . '/skin-render-helpers.php';
    }
    $kind = (string)($response['kind'] ?? '');
    if (!in_array($kind, ['archive', 'search', 'hashtag'], true)) return $response;

    $pageNow = max(1, (int)($response['page'] ?? 1));
    $state = [];
    if ($kind === 'archive') {
        $cat   = (int)($response['taxonomy']['category_id'] ?? 0);
        $album = (int)($response['taxonomy']['album_id'] ?? 0);
        if ($cat > 0)   $state['category'] = $cat;
        if ($album > 0) $state['album'] = $album;
    } elseif ($kind === 'search') {
        // 'q' is what the router reads -- index.php:431 is
        // $_GET['q'] -> runtime 'query'. Emitting the internal name produced a
        // parameter nothing read, so page two lost the search term.
        $q = (string)($response['query'] ?? '');
        if ($q !== '') $state['q'] = $q;
    } else {
        $tag = (string)($response['slug'] ?? '');
        if ($tag !== '') $state['slug'] = $tag;
    }

    // next_page is a page number on archive and hashtag and 0-or-a-number on
    // search, so read it as "a page after this one" and both shapes answer.
    $next = (int)($response['next_page'] ?? 0);
    $prev = (int)($response['previous_page'] ?? ($pageNow > 1 ? $pageNow - 1 : 0));

    // RAW, NOT ESCAPED, and that is the contract: a strict skin MUST wrap
    // every echo in an output helper -- core/skin-security-policy.php refuses
    // a layout that does not -- so a pre-escaped url arrives at the browser
    // double-escaped and the FILTER IS LOST on page two:
    //
    //   pre-escaped  /archive?category=street&amp;page=2
    //   skin escapes /archive?category=street&amp;amp;page=2
    //
    // These two values exist to be printed by a template, so they are built
    // with the raw helper and the template's own snap_escape_url() does the
    // single escape. snap_route_url_raw() still applies the same scheme
    // validation, so raw means un-escaped and never means unchecked.
    // RELATIVE as well as raw, per the coordinator's ruling: same-origin path
    // and query, no scheme, no host, no control characters. BASE_URL is
    // absolute on every install, so the raw builder alone would hand a skin
    // https://site.test/archive?... -- measured, not assumed.
    $response['next_url'] = $next > $pageNow
        ? snap_same_origin_path(snap_route_url_raw($kind, $state + ['page' => $next])) : null;
    // Page 2's previous is page 1, and page 1 is the route with NO page
    // parameter: a ?page=1 in a link works and still looks like a mistake.
    $response['previous_url'] = $prev >= 1
        ? snap_same_origin_path(snap_route_url_raw($kind, $prev > 1 ? $state + ['page' => $prev] : $state))
        : null;
    return $response;
}

function snapsmack_public_runtime(PDO $pdo, array $input, array $settings): array
{
    // OWNER CONTENT WINS over the reserved word 'archive'. Asked ONCE here,
    // with a real database handle, before anything is resolved or emitted --
    // and remembered for the rest of the request, so the router and the links
    // it prints cannot disagree about which address the archive owns. A
    // sitemap advertising /archive while /archive served the owner's page
    // would be worse than either behaviour alone.
    require_once __DIR__ . '/public-route-aliases.php';
    $input['owner_slug_taken'] = snapsmack_public_archive_slug_taken($pdo);
    $request = snapsmack_public_parse_request(snapsmack_public_runtime_request($input));
    $repository = new SnapPublicRepository($pdo);
    $response = snapsmack_public_controller($repository, $request, $settings);
    $response = snapsmack_public_parse_page_content($pdo, $response);
    // Comments are one feature with TWO switches: global_comments_enabled in
    // Settings, and community_comments_enabled on the community engine. Only
    // the first was consulted here, so turning the other one off hid the
    // comments while leaving the COMMENTS link in the photo navigation and
    // its "2" hotkey live -- a link that opens a pane the site will not fill.
    // If either switch says no, comments are off.
    //
    // It also only answered for a photograph. A post and a collection carry
    // the same navigation bar and were never gated at all.
    if (in_array(($response['kind'] ?? ''), ['photo', 'post', 'collection'], true)) {
        $response['comments_enabled'] = ((string)($settings['global_comments_enabled'] ?? '1') !== '0')
            && ((string)($settings['community_comments_enabled'] ?? '1') !== '0')
            && (($response['item']['allow_comments'] ?? '1') == '1');
    }
    // SLICE 3: PAGING URLS WITH THE FILTER ALREADY IN THEM.
    //
    // previous_page and next_page are numbers. Turning a number into a link
    // means knowing which route you are on and which filters are active, and a
    // strict skin that assembled its own query string would drop the filter the
    // moment anybody paged a filtered archive -- page 2 of "album: Doors"
    // quietly becoming page 2 of everything. Codex asked not to assemble query
    // strings in a layout and he is right: snap_route_url is allowlisted for
    // skins, but the STATE to put in it is the CMS's to know.
    //
    // THREE PAGED ROUTES, THREE DIFFERENT STATES, and that is why this is one
    // place rather than three skins:
    //   archive  -- category and album
    //   search   -- the query
    //   hashtag  -- the tag slug
    //
    // SEARCH HAD NO previous_page AT ALL and says next_page = 0 rather than
    // null when there is no next. Both are left exactly as they are: a consumer
    // testing !empty() cannot tell 0 from null, but one testing === 0 would
    // break, and changing a shipped shape to tidy it is not worth that. The
    // previous page for search is derived here instead, from the page number,
    // which is the same arithmetic archive and hashtag already do for
    // themselves.
    $response = snapsmack_public_paging_urls($response);

    // AND THE SAME QUESTION, PER TILE, FOR AN ARCHIVE.
    //
    // GLIDE's archive overlay opens an Info/Comments pane over a tile, so it
    // needs the two answers the single-photograph view has always had --
    // whether comments are open on THIS photograph, and how many there are --
    // for every item in the list. An archive item reached the skin with
    // neither, so an overlay could only always offer the pane or never.
    //
    // THE RULE IS NOT RE-DERIVED, IT IS THE SAME THREE CONDITIONS as the block
    // above: the global switch, the community-engine switch, and the
    // photograph's own allow_comments. Writing a second, subtly different
    // version of a permission test is how one page ends up disagreeing with
    // another about whether comments are on.
    //
    // ONE QUERY FOR THE WHOLE PAGE. approvedCommentCountsFor() takes every id
    // at once; fifty tiles do not mean fifty queries. The counts are only
    // fetched when both site-wide switches are on, because when they are off
    // the answer is zero for everything and the query would be waste.
    //
    // SEARCH IS IN THIS LIST NOW, and it is shaped differently: it returns
    // 'results' (a map, with the photographs under 'photographs') rather than
    // a flat 'items'. Codex found the gap wiring GLIDE's search -- "search
    // results lack prepared comment metadata, unlike archive items" -- and
    // said he would show only a post link when the state was unavailable. He
    // should not have to: the same tile in a search deserves the same two
    // answers as the same tile in an archive.
    //
    // Both shapes are enriched from ONE query. The ids are collected across
    // every list first, so a search page is still a single
    // approvedCommentCountsFor() call, and the permission test exists once --
    // writing a second, subtly different copy is how one page ends up
    // disagreeing with another about whether comments are on.
    $_lists = [];
    if (in_array(($response['kind'] ?? ''), ['archive', 'hashtag', 'albums', 'collections', 'landing'], true)
        && is_array($response['items'] ?? null) && $response['items'] !== []) {
        $_lists[] = ['items'];
    }
    if (($response['kind'] ?? '') === 'search' && is_array($response['results'] ?? null)) {
        foreach ($response['results'] as $_group => $_rows) {
            if (is_array($_rows) && $_rows !== []) $_lists[] = ['results', $_group];
        }
    }
    if (($response['kind'] ?? '') === 'landing') {
        foreach ($response['rows'] ?? [] as $_group => $_rows) {
            if ($_rows !== []) $_lists[] = ['rows', $_group];
        }
    }
    if ($_lists !== []) {
        $_siteComments = ((string)($settings['global_comments_enabled'] ?? '1') !== '0')
                      && ((string)($settings['community_comments_enabled'] ?? '1') !== '0');
        $_counts = [];
        if ($_siteComments) {
            $_ids = [];
            foreach ($_lists as $_path) {
                $_rows = count($_path) === 1 ? $response[$_path[0]] : $response[$_path[0]][$_path[1]];
                foreach ($_rows as $_it) {
                    if (is_array($_it) && (int)($_it['id'] ?? 0) > 0) $_ids[] = (int)$_it['id'];
                }
            }
            $_ids = array_values(array_unique($_ids));
            if ($_ids !== []) $_counts = $repository->approvedCommentCountsFor($_ids);
        }
        foreach ($_lists as $_path) {
            $_rows = count($_path) === 1 ? $response[$_path[0]] : $response[$_path[0]][$_path[1]];
            foreach ($_rows as $_i => $_it) {
                if (!is_array($_it)) continue;
                $_open = $_siteComments && (($_it['allow_comments'] ?? '1') == '1');
                $_rows[$_i]['comments_enabled'] = $_open;
                $_rows[$_i]['comment_count'] = (int)($_counts[(int)($_it['id'] ?? 0)] ?? 0);
            }
            if (count($_path) === 1) $response[$_path[0]] = $_rows;
            else $response[$_path[0]][$_path[1]] = $_rows;
        }
        unset($_siteComments, $_counts, $_ids, $_i, $_it, $_open, $_rows, $_path, $_group);
    }
    unset($_lists);
    // The community block rides along with that gate: same three kinds, same
    // response. A skin renders it wherever its own stylesheet already puts
    // comments, so this reaches all 26 of them without a skin edit -- and so
    // without a manifest bump, a repackage, or a per-site skin update. A CMS
    // deploy is the whole delivery. That is deliberate (OPAUDIT 025): a fix
    // needing 26 skin packages installed would have reached almost nobody.
    if (in_array(($response['kind'] ?? ''), ['photo', 'post', 'collection'], true)
        && !empty($response['comments_enabled'])) {
        $_community = snapsmack_public_community_block(
            $pdo, $settings,
            is_array($response['item'] ?? null) ? $response['item'] : [],
            is_array($input['skin_manifest'] ?? null) ? $input['skin_manifest'] : []
        );
        if ($_community !== null) $response['community'] = $_community;
    }
    // THE ARCHIVE'S CONTROLS. Resolved by the one shared resolver, so the
    // strict path and archive.php cannot disagree about which layout is
    // showing. The component already read response.archive_layouts; this is
    // the line that assigns it, which is why the T/M toggle has never rendered
    // on any skin.
    //
    // The engines that make the row work were HARDCODED AT THE BOTTOM OF
    // archive.php -- the filter engine, the toggle engine, and the calendar
    // engine when the owner enabled it. A strict skin runs none of that file,
    // so the row's stylesheet and its behaviour arrived only for the five
    // skins whose manifests happen to require those engines by name. Declaring
    // them here delivers both with the row that needs them: no manifest bump,
    // no repackage, no per-site skin update, exactly as the community block
    // and the shortcode stylesheets are delivered.
    if (in_array(($response['kind'] ?? ''), ['archive', 'hashtag'], true)) {
        $_archiveControls = snapsmack_archive_controls(
            $settings,
            is_array($input['skin_manifest'] ?? null) ? $input['skin_manifest'] : [],
            ['layout' => (string)($input['archive_layout_choice'] ?? '')],
            [
                'smack_archive_layout'   => (string)($input['archive_layout_cookie'] ?? ''),
                'smack_archive_calendar' => (string)($input['archive_calendar_cookie'] ?? ''),
            ]
        );
        $response['archive_layouts'] = snapsmack_archive_layout_links(
            $_archiveControls['layouts'],
            defined('BASE_URL') ? (string)BASE_URL : '/',
            is_array($input['route_aliases'] ?? null) ? $input['route_aliases'] : [],
            (string)($settings['site_mode'] ?? '')
        );
        $response['archive_calendar'] = $_archiveControls['calendar'];
        $response['archive_layout_active'] = $_archiveControls['active'];
        $response['required_assets']['scripts'] = array_values(array_unique(array_merge(
            (array)($response['required_assets']['scripts'] ?? []),
            // smack-archive-toggle carries page-archive.css and the filter
            // engine as companions, which is the whole row: the panel that
            // opens, the search field, and the docked T/M group measuring
            // from the content column instead of the window.
            ['smack-archive-toggle']
        )));
        if (!empty($_archiveControls['calendar']['enabled'])) {
            $response['required_assets']['scripts'] = array_values(array_unique(array_merge(
                $response['required_assets']['scripts'],
                ['smack-calendar']
            )));
        }
        unset($_archiveControls);
    }
    // Profile chrome is shared across every strict public page, not just the
    // landing response. GAME ON also needs its puzzle field on About,
    // Blogroll and other secondary pages so those pages retain the skin.
    if (!isset($response['photo_count']) && (($settings['site_mode'] ?? '') !== 'smacktalk')) {
        $response['photo_count'] = $repository->publishedPhotographCount();
    }
    // The other two numbers in the profile stats row. They have been blank on
    // every photoblog's mobile view since the conversion, because the skin
    // asked for fields the bounded response did not carry. Supplied
    // pre-formatted as well as raw: a template may not compute, so
    // number_format has to happen on this side or the thousands separator in
    // Sean's reference ("9,975") can never come back.
    if (!isset($response['comment_count']) && (($settings['site_mode'] ?? '') !== 'smacktalk')) {
        $response['comment_count'] = $repository->approvedCommentCount();
        $response['like_count']    = $repository->likeCount();
    }
    foreach (['photo_count', 'comment_count', 'like_count'] as $_countKey) {
        if (isset($response[$_countKey])) {
            $response[$_countKey . '_display'] = number_format((int)$response[$_countKey]);
        }
    }
    if (($settings['active_skin'] ?? '') === 'game-on' && !isset($response['puzzle_items'])) {
        $response['puzzle_items'] = array_map(
            'snapsmack_game_on_focus_item',
            $repository->gameOnPuzzlePhotographs()
        );
    }
    require_once __DIR__ . '/trusted-html.php';
    $trustItem = static function (array $item) use ($settings): array {
        foreach (['content', 'description', 'img_description'] as $field) {
            if (isset($item[$field]) && is_string($item[$field])) $item[$field] = snapsmack_photo_description_html($item[$field]);
        }
        // EXIF / Technical Specs is switched off in Solo Image Appearance.
        // Until 0.7.877D only core/meta.php honoured it, a file no strict
        // skin runs. The first repair emptied item['exif'] earlier in this
        // function -- and THIS line rebuilt it from the raw column a moment
        // later, so the table kept rendering. The switch has to be read where
        // the field is made, not before it.
        $exifOff = (($settings['exif_display_enabled'] ?? '1') !== '1');
        $exif = $exifOff ? [] : json_decode((string)($item['img_exif'] ?? ''), true);
        $item['exif'] = is_array($exif) ? $exif : [];
        if ($exifOff) { $item['technical'] = []; $item['img_exif'] = ''; }

        // A readable date. Twelve templates printed the raw database column,
        // so a photograph was stamped "2026-09-11 03:05:00" -- a timestamp
        // with seconds, on a photoblog -- and the site's own Date Format
        // setting was read by nothing on the public side at all. Formatted
        // once here; img_date stays untouched for the datetime attribute,
        // which must remain machine-readable.
        $stamp = (string)($item['img_date'] ?? $item['created_at'] ?? '');
        $when = $stamp !== '' ? strtotime($stamp) : false;
        $item['date_display'] = $when === false ? ''
            : date((string)($settings['date_format'] ?? 'F j, Y'), $when);
        $lat = filter_var($item['exif']['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($item['exif']['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $item['geo_url'] = ($lat !== false && $lon !== false && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180)
            ? 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat) . '&mlon=' . rawurlencode((string)$lon)
            : '';
        if (array_key_exists('image_asset', $item)) {
            $item['image_size'] = in_array(($item['image_size'] ?? ''), ['full', 'medium', 'small'], true)
                ? (string)$item['image_size'] : 'full';
            $item['image_align'] = in_array(($item['image_align'] ?? ''), ['left', 'center', 'right'], true)
                ? (string)$item['image_align'] : 'center';
            $item['image_shadow'] = !empty($item['image_shadow']);
        }
        unset($item['img_exif']);
        return $item;
    };
    // THE DOWNLOAD LINK, PREPARED HERE BECAUSE THIS IS WHERE THE DATABASE IS.
    //
    // core/download-overlay.php has not been run by anything since 592f1ae4,
    // "Complete photoblog strict runtime conversion" on 28 September, which
    // removed the include from every skin layout. The only occurrence left in
    // the repository is the usage example in that file's own header comment.
    // Measured on two live sites, two different skins: the word "download"
    // appears zero times on a photo page. foundtextures.ca is a free texture
    // library whose front page tells visitors to use a download link.
    //
    // It is prepared HERE rather than in a render helper because
    // tests/skin-render-helpers-regression.php refuses a helper that reaches
    // $pdo -- "Render helper owns forbidden authority" -- and the URL needs the
    // database for its HMAC salt (SECAUDIT 047). That guard is right, so the
    // runtime, which already holds $pdo, does the work and the renderer is
    // handed a finished link.
    //
    // The audited file is INCLUDED, not reimplemented: it rewrites Google
    // Drive, OneDrive and SharePoint share links and signs the local route.
    // Rewriting that from memory is how the token stops matching.
    if (is_array($response['item'] ?? null) && !empty($response['item']['id'])) {
        $img = $response['item'];
        $download_button = '';
        $download_url = '';
        $target = '';
        include __DIR__ . '/download-overlay.php';
        if ($download_button !== '' && $download_url !== '') {
            $response['item']['download_link'] = [
                'url' => (string)$download_url,
                'external' => $target !== '',
            ];
        }
    }

    // THOMAS, ON EVERY SKIN, FROM ONE PLACE.
    //
    // He was emitted by core/meta.php and core/footer-scripts.php. Both are
    // the legacy render path, and a strict skin reaches neither -- so he has
    // been absent from 28 of the 31 skins since the September conversion.
    // The three that still had him are the three that happen to declare him
    // in their own manifest, which was luck rather than design.
    //
    // Worse, the check meant to catch this read core/meta.php, a file strict
    // pages never load, so it passed the whole time he was gone. The test is
    // replaced with one that renders every skin.
    //
    // Declared HERE, at the one exit every kind passes through, so no skin
    // has to ask for him and no skin can forget. The inventory handle carries
    // his stylesheet with it, and the three manifests that already name him
    // collapse on array_unique.
    // THE F1 HELP MODAL AND THE CONSENT BANNER, same place and same reason.
    //
    // The owner, 29 October, first-hand on a live site: the F1 help modal is
    // gone and the accept-cookies notice is gone. Both were emitted only from
    // the legacy path -- core/footer.php:194 for the help engine,
    // core/footer-scripts.php:28-30 for the consent engine, stylesheet and
    // banner -- so no strict page has had either since September.
    //
    // THE F1 CHAIN NEEDS BOTH HALVES. ss-engine-comms.js:77 binds F1 and
    // delegates to window.snapPublicHelp when it exists, falling back to its
    // own smaller modal when it does not; ss-engine-public-help.js:186 states
    // plainly that it must NOT bind F1 itself, because binding it twice made
    // one press open and immediately close the modal. So the engine is
    // declared here and the key binding stays where it already is.
    //
    // ss-engine-consent.js publishes window.snapConsent, and every consumer
    // tests `window.snapConsent && window.snapConsent.ok()` before touching
    // storage. That fails CLOSED, so the missing engine did not leak anything
    // -- it switched the preferences off permanently with no way to turn them
    // on. See core/consent-runtime.php for the whole trace.
    $engines = snapsmack_public_engine_assets();
    $response['required_assets']['scripts'] = array_values(array_unique(array_merge(
        (array)($response['required_assets']['scripts'] ?? []),
        $engines['scripts']
    )));
    $response['required_assets']['styles'] = array_values(array_unique(array_merge(
        (array)($response['required_assets']['styles'] ?? []),
        $engines['styles']
    )));

    // Worked out here because this is the last point that still has the
    // database. The component that prints it has neither.
    $GLOBALS['snapsmack_consent'] = snapsmack_consent_prepare($pdo, $settings);

    if (is_array($response['item'] ?? null)) $response['item'] = $trustItem($response['item']);
    foreach (['items', 'photographs'] as $group) {
        if (!is_array($response[$group] ?? null)) continue;
        $response[$group] = array_map(static fn($item) => is_array($item) ? $trustItem($item) : $item, $response[$group]);
    }
    return $response;
}

/**
 * The engines EVERY public page gets, whichever controller rendered it.
 *
 * THIS IS A FUNCTION BECAUSE THE LIST USED TO LIVE INLINE ABOVE, AND ONE
 * CONTROLLER NEVER SAW IT. index.php has two render branches: a SMACKTALK
 * site goes through snapsmack_smacktalk_request() and everything else through
 * snapsmack_public_runtime(). Only the second one declared these, so on the
 * five skins whose manifest sets cms_controller=smacktalk -- ALFRED, STANLEY,
 * TELEGRAM, TILEZ, WRITING-WITH-IMPACT -- Thomas, the F1 help modal, the
 * cookie notice AND the keyboard engine were all absent.
 *
 * Measured on the owner's own live sites on 30 October 2026, both already on
 * 0.7.972D: baddaywithacamera.ca (TILEZ) served ss-engine-thomas.js ZERO
 * times, acolourlesslife.ca (CHAPLIN) served it once.
 *
 * The 972D claim that these reached "all 30 skins" was measured through this
 * function's path for all 30, which is not the same thing as measuring the
 * path each skin actually uses. That is the same mistake as a test that reads
 * markup the page never renders, and it is why this is now ONE list with TWO
 * callers instead of one list and one forgotten branch.
 *
 * Add an engine here and both controllers get it. Add one to a controller and
 * only that controller gets it -- which is how this happened.
 */
function snapsmack_public_engine_assets(): array
{
    return [
        'scripts' => ['smack-thomas',
         'asset:public:ss-engine-public-help',
         'asset:public:ss-engine-consent',
         // THE KEYBOARD ENGINE, which core/meta.php shipped to every skin
         // before the conversion. Afterwards only the 24 skins that happened
         // to declare smack-keyboard in their own manifest had it, so six lost
         // it outright: alfred, photogram, stanley, telegram, tilez,
         // writing-with-impact. Same fault as Thomas and the same cure.
         //
         // It is also the other half of the F1 chain -- ss-engine-comms.js:77
         // is what BINDS the key and hands off to window.snapPublicHelp -- so
         // on those six skins F1 did nothing at all, not even the fallback
         // modal. Restoring what was already shipped; no new behaviour.
         //
         // The 24 manifests that declare it stay as they are and collapse on
         // array_unique, exactly as the three Thomas manifests do.
         'smack-keyboard'],
        // The consent script and stylesheet share one registry handle, and the
        // style resolver falls through to the ':css' variant on its own.
        'styles' => ['asset:public:ss-engine-consent'],
    ];
}
// ===== SNAPSMACK EOF =====
