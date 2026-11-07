<?php
declare(strict_types=1);

/**
 * SNAPSMACK - the ONE resolver for /<album>/<category>/<slug>. (SS-1185)
 *
 * Sean's own example, labelled by him: "portfolio is the album name people is
 * the ctegory name and sports is the slug for the post. i like to use what
 * already exists." So "portfolio" is an ALBUM NAME, not a reserved prefix, and
 * the scheme is general: an existing album, an existing category, an existing
 * post slug. Nothing new is invented to carry it.
 *
 * A CORRECTION TO THE APPROVED DESIGN, measured rather than assumed. The ruling
 * was "ONE resolver called by BOTH index.php and core/public-controller.php".
 * core/public-controller.php does not parse paths at all -- it receives an
 * already-parsed ['route' => ..., 'slug' => ...] array, and the only place in
 * the product where a URL path becomes a slug is index.php:141. Measured with
 * grep for PATH_INFO across every PHP file: index.php and api.php, and api.php
 * is the JSON API, not the public site. So there is ONE call site, because
 * there is only one place that could be a call site. Wiring a second one into
 * the controller would have meant giving it a path to parse that it does not
 * receive -- a duplicated decision dressed up as coverage. Reported to the room
 * rather than quietly built to the letter of the ruling.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO, so the next reader does not take its
 * absence for an oversight:
 *
 *   It does not decide visibility. It maps three segments to a post SLUG and
 *   hands that slug to the resolution path that already exists, which applies
 *   the published/date/exclusion rules through SnapPublicRepository. A second
 *   copy of the visibility predicate is how surfaces drift apart; there is
 *   already a regression suite proving the one copy. An unpublished post
 *   reached this way 404s exactly as /its-slug 404s today.
 *
 *   It does not serve an album or an album+category page. A one- or two-segment
 *   path ALWAYS falls through to existing routing, unchanged. WEB's addition to
 *   the design says a 1- or 2-segment path that is not a known album or
 *   category must fall through and never 404 early; this goes further and falls
 *   through even when it IS one, because no album-by-slug page exists in this
 *   product (an album is addressed as ?album=N today) and inventing one is a
 *   product decision, not a routing one. Every existing one-segment URL on
 *   every one of the 25 sites therefore behaves today exactly as it did
 *   yesterday. Raised as an open question; not built unasked.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Split a request path into validated segments, or null if it cannot be one of
 * ours. Pure: no database, no globals.
 *
 * Codex asked for validation BEFORE any lookup and he is right -- a malformed
 * URL should not become three failed queries.
 *
 * THE ALLOWLIST IS THE SLUG ALPHABET, not "anything without a slash".
 * snap_slugify() in core/slug.php is Unicode-aware, so an album called "Café"
 * slugs to "café" and must still resolve; the web server has already decoded
 * percent-encoding by the time PATH_INFO reaches us, so that arrives as a UTF-8
 * letter. Underscore is allowed because snap_slugify preserves it only to turn
 * it into a hyphen -- a slug cannot contain one -- but legacy post slugs minted
 * by core/flkrfckr-api.php:195 CAN, since that implementation deletes
 * underscores instead of converting them. Refusing them here would make a
 * resolver that cannot reach rows already on disk.
 */
function snap_portfolio_route_parse(string $path): ?array {
    $trimmed = trim($path, '/');
    if ($trimmed === '') return null;

    $segments = explode('/', $trimmed);
    if (count($segments) > 3) return null;

    // ONE GUARD, NOT FOUR. This began as an early block refusing '..', a NUL,
    // '//' and any dot, followed by this allowlist. Mutation proof found three
    // of those four mutants BLIND -- not because the test was weak, but because
    // the allowlist below already refuses every one of them: '..' and
    // 'favicon.ico' contain a dot, which is not in the class; a NUL is not in
    // the class; '//' explodes to an empty segment, which '+' cannot match. Two
    // checks for one decision is the shape this whole file exists to avoid, and
    // the early block's only real effect was to make the allowlist look proved
    // when it was the thing doing the work. Deleted rather than kept as
    // "defence in depth", which here meant "unreachable and therefore
    // unprovable".
    //
    // snap_posts.slug is varchar(600); album_slug and cat_slug are
    // varchar(100). 600 is the ceiling that cannot reject a row already stored.
    foreach ($segments as $seg) {
        if (strlen($seg) > 600) return null;
        if (!preg_match('/^[\p{L}\p{N}_-]+$/u', $seg)) return null;
    }
    return $segments;
}

/**
 * The whole routing decision for one request path, in one call.
 *
 * This is what index.php calls, so index.php holds no part of the decision --
 * it applies the verdict. Returns one of:
 *
 *   ['action' => 'none']                           not ours. Carry on with
 *                                                  existing routing, unchanged.
 *   ['action' => 'serve',    'slug' => string]     treat the request as this
 *                                                  flat post slug.
 *   ['action' => 'redirect', 'path' => string]     301 to this path (no host,
 *                                                  no BASE_URL -- the caller
 *                                                  owns the prefix).
 */
function snap_portfolio_route_for_request(PDO $pdo, string $path): array {
    $segments = snap_portfolio_route_parse($path);
    if ($segments === null) return ['action' => 'none'];

    // SCHEMA TOLERANCE. The resolver reads columns this release adds --
    // snap_albums.album_slug, snap_categories.cat_slug, snap_posts.primary_album_id
    // and primary_category_id. core/schema-sync.php adds them, but it runs on
    // UPDATE, not on a public page view (see core/public-repository.php, "WHY
    // THESE TOLERATE A MISSING COLUMN"), so on a site whose files are new but
    // whose UPDATE has not run yet those columns are absent. This function runs on
    // EVERY public request that carries a slug; an uncaught "Unknown column" here
    // would be a 500 on every 3-segment URL during that window -- the fleet-wide
    // fault class recorded at core/threeacross-api.php:907. Measured against a
    // real 0.7.982D database (album_slug absent): the unguarded query threw
    // "Unknown column 'album_slug'". So ANY resolver error falls through to the
    // existing routing -- precisely the pre-SS-1185 behaviour, where the path
    // 404s or serves its flat slug, and never 500s.
    try {
        $verdict = snap_portfolio_route_resolve($pdo, $segments);
    } catch (Throwable $e) {
        return ['action' => 'none'];
    }
    if (($verdict['kind'] ?? '') !== 'post') return ['action' => 'none'];

    if (!empty($verdict['redirect'])) {
        return ['action' => 'redirect', 'path' => (string)$verdict['canonical']];
    }
    return ['action' => 'serve', 'slug' => (string)$verdict['slug']];
}

/**
 * Resolve validated segments. Verdicts:
 *
 *   ['kind' => 'post', 'slug' => string, 'post_id' => int,
 *    'canonical' => ?string, 'redirect' => bool]
 *   ['kind' => 'fallthrough']   everything else, including a three-segment path
 *                               whose album or category is unknown. There is no
 *                               honest 404 to issue here: the existing routing
 *                               has not had its turn yet, and it is the thing
 *                               that knows what else a path might mean.
 */
function snap_portfolio_route_resolve(PDO $pdo, array $segments): array {
    // One and two segments are not ours. See the file header.
    if (count($segments) !== 3) return ['kind' => 'fallthrough'];

    $album    = snap_portfolio_find_album($pdo, $segments[0]);
    $category = snap_portfolio_find_category($pdo, $segments[1]);
    if ($album === null || $category === null) return ['kind' => 'fallthrough'];

    // No visibility predicate here, deliberately -- see the file header. The
    // row is read for its designation only.
    $stmt = $pdo->prepare(
        'SELECT id, slug, primary_album_id, primary_category_id
           FROM snap_posts WHERE slug = ? LIMIT 1'
    );
    $stmt->execute([$segments[2]]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($post === false) return ['kind' => 'fallthrough'];

    // THE PATH IS A LOOKUP, NOT A LOCATION. The post is found on its slug; the
    // album and category segments are then CHECKED against its own primary
    // designation. A mismatch is a 301 to the canonical path, not a 404, so a
    // post moved between albums does not break a link someone already shared.
    $canonical = snap_portfolio_canonical_path($pdo, (int)$post['id']);
    $here      = '/' . implode('/', $segments);
    $matches   = (int)($post['primary_album_id'] ?? 0) === $album
              && (int)($post['primary_category_id'] ?? 0) === $category;

    return [
        'kind'      => 'post',
        'slug'      => (string)$post['slug'],
        'post_id'   => (int)$post['id'],
        'canonical' => $canonical,
        // LOOP GUARD. Only ask for a redirect when there is somewhere else to
        // go AND it differs from where we already are. Without both halves a
        // misconfiguration bounces a visitor between two addresses forever,
        // which is worse than serving the wrong one.
        'redirect'  => !$matches && $canonical !== null && $canonical !== $here,
    ];
}

/** An album id by slug, or null. Lowest id wins a duplicate, deterministically. */
function snap_portfolio_find_album(PDO $pdo, string $slug): ?int {
    $stmt = $pdo->prepare(
        "SELECT id FROM snap_albums WHERE album_slug = ? AND album_slug <> '' ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * A category id by slug, or null.
 *
 * LOWEST ID WINS A DUPLICATE, AND THAT IS NOT PEDANTRY. cat_slug has no unique
 * key -- snap_categories carries PRIMARY KEY (id) and nothing else -- and
 * smack-cats.php:80 mints the slug with an inline one-liner that has never
 * checked for a collision. Two categories CAN share a slug on a live site right
 * now. Adding a blind UNIQUE key as part of this work would mean an ALTER that
 * fails on exactly the sites that have the problem, so the resolver tolerates
 * it and is DETERMINISTIC rather than depending on whatever order the engine
 * returns rows in. The duplicates that already exist need a report and Sean's
 * decision, not a surprise migration.
 */
function snap_portfolio_find_category(PDO $pdo, string $slug): ?int {
    $stmt = $pdo->prepare(
        'SELECT id FROM snap_categories WHERE cat_slug = ? ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * The one canonical path for a post, or null when it has no designation.
 *
 * A post with no primary album or category keeps its flat /slug -- which is
 * every post on every site today. That is why the columns are nullable and why
 * null here means "unchanged behaviour", not "broken".
 */
function snap_portfolio_canonical_path(PDO $pdo, int $postId): ?string {
    $stmt = $pdo->prepare(
        'SELECT p.slug, a.album_slug, c.cat_slug
           FROM snap_posts p
           LEFT JOIN snap_albums a     ON a.id = p.primary_album_id
           LEFT JOIN snap_categories c ON c.id = p.primary_category_id
          WHERE p.id = ? LIMIT 1'
    );
    $stmt->execute([$postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;

    $album = (string)($row['album_slug'] ?? '');
    $cat   = (string)($row['cat_slug'] ?? '');
    if ($album === '' || $cat === '') return null;

    return '/' . $album . '/' . $cat . '/' . (string)$row['slug'];
}

// ===== SNAPSMACK EOF =====
