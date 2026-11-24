<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Which navigation item is the page you are standing on.
 *
 * SS-1563. The shared navigation renderer emitted links and submenu structure
 * and never said which one was current, so every skin that styles an active
 * item showed all of them inactive. It is not a theoretical gap: FIVE skins
 * already carry the rule and have nothing to apply it to --
 *
 *   ALFRED, TELEGRAM, TILEZ    .main-menu li.current-menu-item > a
 *   ONYX, HEURISTIC            a[aria-current="page"]
 *
 * Two conventions are in use, so core emits BOTH. Neither skin group is wrong
 * and neither should have to change.
 *
 * WHY THE MATCHING LIVES HERE AND NOT IN A LAYOUT. A strict skin may hold no
 * executable code -- no function call, no BASE_URL, no cast -- and the
 * security ratchet is right to refuse one. Working out which destination you
 * are on is route logic. So the CMS decides it and the skin prints the result,
 * which is the same division the rest of the prepared view model uses.
 *
 * THE MATCH IS EXACT, DELIBERATELY. A prefix match would make the Home link,
 * whose URL is "/", the current item on every page of the site. An item with
 * a query string (an album or a category destination) additionally requires
 * every one of ITS parameters to be present in the request, which lets
 * ?view=archive&album=3 match its own album and not the archive generally.
 *
 * A page with no matching menu link marks NOTHING, which is the correct
 * answer and is one of the four cases SS-1563 asks for.
 */

/**
 * Normalise a URL or request target down to what can be compared.
 *
 * Returns [path, params]. The path loses its scheme, host, fragment, trailing
 * slash and case; the root stays "/" rather than becoming "".
 *
 * @return array{0:string,1:array<string,string>}
 */
function snapsmack_navigation_normalise_target(string $target): array
{
    $target = trim($target);
    if ($target === '') return ['', []];

    // Strip the fragment first: it never reaches the server and must not be
    // compared. Then drop scheme and host if this is an absolute URL.
    $hash = strpos($target, '#');
    if ($hash !== false) $target = substr($target, 0, $hash);

    if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target)) {
        $parsed = parse_url($target);
        if (!is_array($parsed)) return ['', []];
        $path = (string)($parsed['path'] ?? '/');
        $query = (string)($parsed['query'] ?? '');
    } else {
        $mark = strpos($target, '?');
        $path = $mark === false ? $target : substr($target, 0, $mark);
        $query = $mark === false ? '' : substr($target, $mark + 1);
    }

    // rawurldecode so an owner page slug stored encoded matches the request
    // that arrives decoded, and the other way round.
    $path = rawurldecode($path);
    $path = '/' . ltrim($path, '/');
    if ($path !== '/') $path = rtrim($path, '/');
    $path = strtolower($path);

    $params = [];
    if ($query !== '') {
        parse_str($query, $parsed_params);
        foreach ($parsed_params as $k => $v) {
            if (is_array($v)) continue;
            $params[(string)$k] = (string)$v;
        }
    }
    return [$path, $params];
}

/**
 * How well this navigation URL matches the request. -1 is "not at all".
 *
 * The score is the number of parameters the item names and the request
 * confirms, so a MORE SPECIFIC item beats a less specific one on the same
 * path. That is not a refinement, it is required:
 *
 *   Home is "/" and an album is "/?view=archive&album=3". On the album page
 *   the request path is "/" too, so a plain yes/no match marked HOME as the
 *   current item on every album and category page on the site. Measured
 *   before this was scored: request "/?view=archive&album=3" returned
 *   current=Home. Now the album scores 2 and Home scores 0, and the album
 *   wins.
 *
 * Parameters the request carries and the item does not -- a page number, a
 * cache buster, a tracking tag -- are ignored, or paging to page two would
 * unmark the item you are standing on.
 */
function snapsmack_navigation_match_score(string $item_url, string $request): int
{
    [$want_path, $want_params] = snapsmack_navigation_normalise_target($item_url);
    [$have_path, $have_params] = snapsmack_navigation_normalise_target($request);
    // An empty path is a container heading, which has no destination and can
    // never be where you are. There was a separate guard above this line for
    // exactly that; mutating it away changed nothing, because normalising ''
    // gives '' and this check already covered it. Dead code that cannot fail
    // is not a safety net, so it is gone and the reason is written here.
    if ($want_path === '' || $have_path === '') return -1;
    if ($want_path !== $have_path) return -1;

    foreach ($want_params as $k => $v) {
        if (($have_params[$k] ?? null) !== $v) return -1;
    }
    return count($want_params);
}

/** Does this resolved navigation URL point at the request we are serving? */
function snapsmack_navigation_target_is_current(string $item_url, string $request): bool
{
    return snapsmack_navigation_match_score($item_url, $request) >= 0;
}

/**
 * Mark the current item, and every ancestor of it, in a prepared tree.
 *
 * Adds 'current' to exactly one item and 'current_ancestor' to its parents,
 * so a nested Menu Manager tree can show the open branch. Items are returned
 * with those keys always present, true or false, so a layout can read them
 * without testing whether the key exists.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function snapsmack_navigation_mark_current(array $items, string $request): array
{
    // PASS ONE: score every item, so the best match can be chosen before
    // anything is marked. Scoring and marking cannot be one pass, because
    // whether Home is current depends on whether a more specific item further
    // down the menu also matches.
    $best = -1;
    $score = static function (array $list) use (&$score, $request, &$best): void {
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $s = snapsmack_navigation_match_score((string)($item['url'] ?? ''), $request);
            if ($s > $best) $best = $s;
            if (is_array($item['children'] ?? null)) $score($item['children']);
        }
    };
    $score($items);

    // PASS TWO: mark the first item that scores the best, and its ancestors.
    $taken = false;
    $walk = static function (array $list) use (&$walk, $request, $best, &$taken): array {
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $children = is_array($item['children'] ?? null)
                ? $walk(array_values($item['children'])) : [];

            $child_is_current = false;
            foreach ($children as $c) {
                if (!empty($c['current']) || !empty($c['current_ancestor'])) {
                    $child_is_current = true;
                    break;
                }
            }

            // ONE CURRENT ITEM, WHATEVER THE MENU SAYS. An owner can list the
            // same destination twice -- photofri.day had two items both
            // reading HOME -- and nothing stops them. The first wins, so the
            // page can never announce two current locations.
            // A PARENT THAT IS ALSO THE DESTINATION BECOMES THE ANCESTOR, and
            // the ordering is what makes that true rather than a second test.
            // Children are walked above, before this line runs, so a current
            // child has already taken the mark and $taken stops the parent
            // claiming it. There WAS a !$child_is_current term here as well;
            // mutating it away changed no behaviour and the mutant survived,
            // because it can never be the deciding condition. It is written
            // down instead of left in the code pretending to do something.
            $mine = !$taken && $best >= 0
                && snapsmack_navigation_match_score((string)($item['url'] ?? ''), $request) === $best;
            if ($mine) $taken = true;

            $item['children'] = $children;
            // A PARENT THAT IS ALSO THE DESTINATION IS THE ANCESTOR, NOT THE
            // CURRENT ITEM. Marking both would put two current items in one
            // menu, and aria-current is singular: a screen reader announcing
            // two current pages is worse than announcing none.
            $item['current'] = $mine;
            $item['current_ancestor'] = $child_is_current;
            $out[] = $item;
        }
        return $out;
    };

    return $walk(array_values($items));
}

/**
 * What the browser asked for, as the CMS sees it.
 *
 * Kept apart from the matching above so the matching is a pure function that
 * a test can drive with any request it likes, rather than something that can
 * only be observed by faking a web server.
 *
 * REQUEST_URI AND NOTHING ELSE, DELIBERATELY. The first version fell back to
 * PATH_INFO, and tests/portfolio-route-resolver-regression.php refused it:
 * that test watches for any NEW reader of PATH_INFO outside
 * core/portfolio-route.php, because routing is one decision in one place and
 * a second reader is how two of them drift apart. It is right, and this is
 * not an exception to it -- working out which menu item you are standing on
 * is not a routing decision, and REQUEST_URI is simply what the browser
 * asked for. If it is absent, nothing is marked, which is the same answer
 * this gives for a page with no menu link: no claim rather than a wrong one.
 */
function snapsmack_navigation_request_target(): string
{
    return (string)($_SERVER['REQUEST_URI'] ?? '');
}

// ===== SNAPSMACK EOF =====
