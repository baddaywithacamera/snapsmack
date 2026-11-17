<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SHIPPED CSS AND JS MUST NOT POINT AT A PROTECTED PATH.
 *
 * SS-1394. Sean reported the fediverse mark missing from the gateway brand
 * block on foundtextures.ca while the site reported 0.7.997D -- the version
 * that contains the file. Both halves were true. assets/css/ ships, so the
 * stylesheet arrived with background-image:url("../img/fediverse-mark-160.png")
 * on it; assets/img/ is a PROTECTED PATH, listed in protected_paths.json
 * beside img_uploads/ and media_assets/, because sites keep their own images
 * there and an update must never overwrite them. The consequence nobody had
 * written down is that the updater never DELIVERS anything new in there
 * either. The CSS referenced a file that could not arrive, and the only
 * symptom was a logo that was not there.
 *
 * THE RULE IS NOT OBVIOUS FROM EITHER FILE. Looking at the stylesheet, the
 * path is correct. Looking at the PNG, it is committed and tagged. You have to
 * know protected_paths.json exists and think about what protection implies in
 * the other direction. That is exactly the kind of rule that needs a test
 * rather than a memory -- and it is the third protected-path bite in one day:
 * core/constants.php needed the same reasoning for SS-1386's size constant.
 *
 * So: every url() in a shipped stylesheet or script is checked against the
 * protected list, and a reference into one fails naming both.
 */

$root = dirname(__DIR__);
$failures = [];

$protectedFile = $root . '/protected_paths.json';
if (!is_file($protectedFile)) {
    throw new RuntimeException('protected_paths.json is missing; the rule this '
        . 'test enforces cannot be read');
}
$protected = json_decode((string)file_get_contents($protectedFile), true);
$protected = is_array($protected['protected'] ?? null) ? $protected['protected'] : [];
if ($protected === []) {
    throw new RuntimeException('protected_paths.json lists nothing; this test '
        . 'would pass for the wrong reason');
}

// Only directory rules matter here: a single protected FILE is not something
// a stylesheet fetches.
$protectedDirs = [];
foreach ($protected as $rule) {
    $rule = trim((string)$rule);
    if ($rule !== '' && substr($rule, -1) === '/') {
        $protectedDirs[] = rtrim($rule, '/');
    }
}
if ($protectedDirs === []) {
    throw new RuntimeException('no protected directories found to check against');
}

/** Resolve a url() against the file that contains it, repo-relative. */
$resolve = static function (string $fromFile, string $url) use ($root): ?string {
    $url = trim($url, "'\" \t");
    if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|data:|\#)#i', $url)) {
        return null;                      // absolute, protocol-relative, data, fragment
    }
    $base = dirname(str_replace('\\', '/', substr($fromFile, strlen($root) + 1)));
    if ($url[0] === '/') {
        $path = ltrim($url, '/');
    } else {
        $path = $base . '/' . $url;
    }
    $parts = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') { array_pop($parts); continue; }
        $parts[] = $segment;
    }
    return implode('/', $parts);
};

$checked = 0;
$urls = 0;
foreach (['assets/css' => '*.css', 'assets/js' => '*.js'] as $dir => $glob) {
    foreach ((array)glob($root . '/' . $dir . '/' . $glob) as $file) {
        $checked++;
        $body = (string)@file_get_contents($file);
        if (!preg_match_all('/url\(\s*([^)]+?)\s*\)/i', $body, $m)) continue;
        foreach ($m[1] as $raw) {
            $target = $resolve($file, $raw);
            if ($target === null) continue;
            $urls++;
            foreach ($protectedDirs as $pdir) {
                if ($target === $pdir || strpos($target, $pdir . '/') === 0) {
                    $failures[] = basename($file) . ' references ' . $target
                        . ', which is under the protected path ' . $pdir . '/. '
                        . 'The stylesheet ships and that directory does not, so '
                        . 'the file never arrives on an updating site and the '
                        . 'only symptom is something missing on the page. Put '
                        . 'product assets somewhere the updater delivers.';
                }
            }
        }
    }
}

if ($checked < 5) {
    $failures[] = "only {$checked} shipped stylesheets/scripts were found, so "
        . 'this test is not reading the asset directories properly rather than '
        . 'the repository having almost none';
}
if ($urls < 1) {
    $failures[] = 'no relative url() references were found at all; the matcher '
        . 'is broken and this test would pass for the wrong reason';
}

if ($failures) {
    throw new RuntimeException("Shipped assets reference protected paths:\n  - "
        . implode("\n  - ", $failures));
}

echo "Shipped CSS/JS: {$urls} relative url() references, none under a protected path"
    . " ({$checked} files): passed.\n";
// ===== SNAPSMACK EOF =====
