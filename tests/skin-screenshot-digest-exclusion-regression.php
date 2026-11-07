<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Screenshots do not count toward the skin content digest; runtime files do.
 *
 * Screenshots (screenshot-*.png) are catalog/preview assets -- Skin Gallery,
 * admin picker, the snapsmack.ca showcase -- never served to a site's visitors.
 * Changing or renaming one cannot make an installed skin version render
 * differently, so it must not force a version bump (the same reason manifest.json
 * is excluded from the digest). This let the screenshot-slot rename ship across
 * skins with no version bumps.
 *
 * The exclusion is SCOPED: only files matching the screenshot naming are skipped.
 * Any other image a stylesheet actually renders (a texture, a sprite) stays in
 * the digest, so a visitor-facing change still requires a bump. This test locks
 * both halves.
 */

$root = dirname(__DIR__);
require_once $root . '/tools/_build/generate-skin-version-baseline.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

cx(function_exists('snapsmack_skin_files_digest'), 'the skin content digest is available');

$tmp = sys_get_temp_dir() . '/scs-screenshot-' . uniqid();
mkdir($tmp, 0777, true);
file_put_contents("$tmp/layout.php", "<?php echo 'x';");
file_put_contents("$tmp/style.css", ".a{color:red}");
file_put_contents("$tmp/engine.js", "console.log(1)");
file_put_contents("$tmp/manifest.json", '{"version":"1.0.0"}');
file_put_contents("$tmp/screenshot-primary.png", "PNG-A");
file_put_contents("$tmp/texture.png", "TEX-A"); // a runtime image a stylesheet may render

$base = snapsmack_skin_files_digest($tmp);

// 1. A screenshot change or rename must NOT move the digest.
file_put_contents("$tmp/screenshot-primary.png", "PNG-B-different-bytes");
cx(snapsmack_skin_files_digest($tmp) === $base, 'changing a screenshot does not move the digest');
rename("$tmp/screenshot-primary.png", "$tmp/screenshot-secondary.png");
cx(snapsmack_skin_files_digest($tmp) === $base, 'renaming a screenshot (the slot rename) does not move the digest');

// 2. A runtime file change MUST move the digest (exclusion is scoped, not blanket).
file_put_contents("$tmp/style.css", ".a{color:blue}");
cx(snapsmack_skin_files_digest($tmp) !== $base, 'changing style.css moves the digest');
file_put_contents("$tmp/style.css", ".a{color:red}");

// 3. A NON-screenshot image change MUST move the digest.
file_put_contents("$tmp/texture.png", "TEX-B");
cx(snapsmack_skin_files_digest($tmp) !== $base, 'changing a non-screenshot image moves the digest');

array_map('unlink', glob("$tmp/*"));
@rmdir($tmp);

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
