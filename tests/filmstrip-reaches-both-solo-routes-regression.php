<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * GALLERIA's solo page is reached by TWO controller routes, and the filmstrip
 * was wired to one of them.
 *
 * Every photo link on every site is a bare root slug, so the live route is
 * 'resolve'. The 'photo' branch is reached only by ?view=photo or a numeric id,
 * which nothing links to. 909D added the filmstrip to 'photo' and 920D fixed
 * its switch, also in 'photo'; the page Sean actually opens never went through
 * either. He reported it four times across three builds. The earlier tests
 * grepped for the filmstrip union and passed, because the union was there --
 * on the branch nobody uses.
 *
 * So this asserts two things no source grep for "filmstrip" can: that a bare
 * slug really routes to 'resolve', and that EVERY plain solo-page response in
 * the controller carries a filmstrip. Add a third route later and this fails.
 */

define('BASE_URL', 'https://example.test/');
define('SNAPSMACK_SKIN_RENDER', true);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/public-runtime.php';

function filmstrip_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 1. The route a real photo link takes. snap_route_url('photo') builds a bare
//    root slug, and that is what the runtime has to resolve.
$request = snapsmack_public_runtime_request(['slug' => 'last-light-lower-gray-pond-2026-07-08-1783632105']);
filmstrip_check(
    ($request['route'] ?? '') === 'resolve',
    "A bare photo slug no longer routes to 'resolve'; this test is pinned to the wrong route."
);

// 2. Every plain solo-page response carries a filmstrip. The skin-specific
//    responses (game-on, instant-camera, grid-modal) have their own layouts and
//    no filmstrip; the plain one is GALLERIA's.
$controller = (string)file_get_contents(dirname(__DIR__) . '/core/public-controller.php');
// (?<!function ) so the function's own declaration is not read as a call site.
preg_match_all('/(?<!function )snapsmack_photo_response\(/', $controller, $callSites, PREG_OFFSET_CAPTURE);
filmstrip_check(
    count($callSites[0]) >= 3,
    'The controller no longer has all three routes that can serve a solo photo '
    . "('resolve', 'photo', and a latest-post homepage); re-check which routes can serve one."
);
foreach ($callSites[0] as $site) {
    $tail = substr($controller, $site[1], 260);
    filmstrip_check(
        str_contains($tail, "'filmstrip' =>"),
        'A plain solo-page response omits the filmstrip. Both routes that serve a photo must '
        . 'union it in, or GALLERIA loses its filmstrip on whichever route the site links to.'
    );
}

// 3. The gate lives in one place, so a fix cannot land on one route again.
filmstrip_check(
    str_contains($controller, 'function snapsmack_filmstrip_for('),
    'The filmstrip gate is no longer a shared function; it was duplicated per route before, '
    . 'which is how one copy got fixed and the live one did not.'
);
filmstrip_check(
    substr_count($controller, "\$settings['htbs_show_filmstrip']") === 1,
    'The htbs_show_filmstrip gate is read in more than one place again.'
);

// 4. Default-on semantics, which 920D established: off only on an explicit 0.
filmstrip_check(
    (bool)preg_match(
        '/htbs_show_filmstrip\'\]\s*\?\?\s*\'1\'\)\s*!==\s*\'0\'/',
        $controller
    ),
    'The filmstrip switch is no longer default-on; a stored empty string would read "Yes" and behave as No.'
);

fwrite(STDOUT, "Filmstrip-reaches-both-solo-routes regression passed.\n");

// ===== SNAPSMACK EOF =====
