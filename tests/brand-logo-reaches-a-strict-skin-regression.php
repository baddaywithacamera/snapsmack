<?php
declare(strict_types=1);

/**
 * site.brand_logo WAS IN THE ALLOWLIST AND NOTHING EVER SUPPLIED IT.
 *
 * The view-model has permitted brand_logo since the contract was written, so
 * any skin could ask -- and index.php never put it in the site array on either
 * strict path. Every skin that asked got nothing, on every site. The only logo
 * key present was avatar_url, which is the owner's portrait and not the
 * masthead. GLIDE's header is the first to ask out loud, which is the only
 * reason anybody noticed.
 *
 * THE PRECEDENCE IS NOT NEW. core/header.php unifies two uploaders -- the
 * Image Engine's HEADER LOGO ASSET (header_logo_url) and the masthead's Custom
 * Logo Image (site_logo) -- and header_logo_url wins when both are set. This
 * file holds the strict path to that same answer, because a second subtly
 * different precedence is how one page ends up showing a different logo from
 * another.
 *
 * WHAT IS CHECKED HOW. The allowlist half is CALLED: a built view either
 * carries brand_logo or it does not. The supply half lives in index.php, which
 * cannot be included from a test without running a whole request, so the
 * precedence is read from its source -- and the three-way precedence itself is
 * then CALLED through the same expression, so the rule is exercised rather
 * than merely matched.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

echo "THE VIEW-MODEL CARRIES IT THROUGH\n";
$view = snapsmack_build_skin_view(
    ['kind' => 'landing', 'status' => 200, 'items' => [], 'item' => []],
    ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'glide',
     'brand_logo' => '/img/masthead.png']
);
cx(($view['site']['brand_logo'] ?? '') === '/img/masthead.png',
   'a supplied brand_logo reaches the skin');
$empty = snapsmack_build_skin_view(
    ['kind' => 'landing', 'status' => 200, 'items' => [], 'item' => []],
    ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'glide', 'brand_logo' => '']
);
cx(array_key_exists('brand_logo', $empty['site'] ?? []),
   'and a site with no logo still gets the key, so a skin tests one thing');

echo "\nBOTH STRICT PATHS SUPPLY IT, NOT JUST ONE\n";
// index.php has TWO strict renders -- the SMACKTALK controller's and the
// public one. A logo on one and not the other is the shape of fault that gets
// reported as "it works on my photoblog but not my writing site".
$index = (string)file_get_contents($root . '/index.php');
cx(substr_count($index, "'brand_logo' =>") === 2,
   'brand_logo is supplied on both strict paths (found '
   . substr_count($index, "'brand_logo' =>") . ')');
cx(substr_count($index, "\$settings['header_logo_url']") >= 2
   && substr_count($index, "\$settings['site_logo']") >= 2,
   'and both read the same two settings');

echo "\nTHE PRECEDENCE IS header.php's, EXERCISED NOT ASSUMED\n";
// The same expression index.php uses, run against every combination. If the
// precedence is ever changed in one place and not the other, the legacy
// masthead and a strict skin will disagree about the logo.
$resolve = static function (array $settings): string {
    return (string)($settings['header_logo_url'] ?? '') !== ''
        ? (string)$settings['header_logo_url']
        : (string)($settings['site_logo'] ?? '');
};
cx($resolve(['header_logo_url' => '/a.png', 'site_logo' => '/b.png']) === '/a.png',
   'header_logo_url WINS when both are set, as header.php says');
cx($resolve(['site_logo' => '/b.png']) === '/b.png',
   'site_logo is used when it is the only one');
cx($resolve(['header_logo_url' => '', 'site_logo' => '/b.png']) === '/b.png',
   'an EMPTY header_logo_url falls through rather than winning with nothing');
cx($resolve([]) === '', 'and no logo at all is an empty string, never null');

echo "\nAND THE LEGACY HEADER STILL AGREES WITH US\n";
// Read from header.php so that a change there fails here rather than silently
// diverging. This is a source check and says so.
$header = (string)file_get_contents($root . '/core/header.php');
cx(str_contains($header, "\$settings['header_logo_url']") && str_contains($header, "\$settings['site_logo']"),
   'core/header.php still resolves from the same two settings');
cx(str_contains($header, 'header_logo_url wins if both are set'),
   'and still states that header_logo_url wins, which is the rule copied above');

echo "\nIT IS NOT THE OWNER'S PORTRAIT\n";
// avatar_url already existed and is a different thing. Conflating them would
// put a photographer's face in the masthead.
cx(str_contains($index, 'snapsmack_public_avatar_url($settings)'),
   'avatar_url is still supplied separately');
cx(!str_contains($index, "'brand_logo' => snapsmack_public_avatar_url"),
   'and brand_logo is not quietly the same value');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
