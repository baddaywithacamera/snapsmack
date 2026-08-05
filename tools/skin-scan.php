<?php
/**
 * SNAPSMACK — skin-scan.php
 *
 * Gallery skin content scanner. A gallery skin is code SnapSmack vouches for,
 * so it must not smuggle executable JavaScript that bypasses the vetted core
 * engine library (core/manifest-inventory.php + the skin-footer require_scripts
 * loader). Signing proves WHO built a skin; this proves it's CLEAN.
 *
 * Detects, per skin:
 *   - inline event handlers   (onclick=, onerror=, onload=, …)         [BLOCK]
 *   - inline <script> blocks  (code, not the sanctioned loader)        [BLOCK]
 *   - javascript: URIs                                                 [BLOCK]
 *   - external <script src>   (http/https/protocol-relative)          [BLOCK]
 *   - skin-local <script src> (a .js the skin ships itself)           [BLOCK]
 *   - bundled .js files        (JS that isn't a vetted core engine)    [BLOCK]
 *   - <iframe>/<object>/<embed>                                        [BLOCK]
 *   - direct core-engine <script src> (safe bytes, bypasses inventory) [WARN]
 *
 * The sanctioned loader — `echo '<script src="' . BASE_URL . $script['path']…`
 * in skin-footer.php — is recognised and allowed: its src is the core-owned
 * inventory path, never a value the skin controls.
 *
 * Usage:
 *   php tools/skin-scan.php [skins_root]        # scan every skin, group report
 *   php tools/skin-scan.php --skin <dir>        # scan one skin directory
 *   php tools/skin-scan.php --json [root]       # machine-readable findings
 * Exit code: 0 = clean (no BLOCK findings), 1 = at least one BLOCK finding.
 *
 * The scan function is includable so the Skin Packager can gate on it:
 *   require 'tools/skin-scan.php'; $f = snapsmack_scan_skin($dir);
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** File extensions whose CONTENT is scanned for markup nasties. */
const SNAPSMACK_SKINSCAN_MARKUP_EXT = ['php', 'phtml', 'html', 'htm', 'inc'];

/**
 * Scan one skin directory. Returns a list of findings, each:
 *   ['file' => relPath, 'line' => int, 'type' => str,
 *    'severity' => 'block'|'warn', 'excerpt' => str]
 */
function snapsmack_scan_skin(string $skinDir, array $vetted = []): array {
    $skinDir  = rtrim(str_replace('\\', '/', $skinDir), '/');
    $findings = [];
    if (!is_dir($skinDir)) {
        return [[
            'file' => '', 'line' => 0, 'type' => 'not-a-directory',
            'severity' => 'block', 'excerpt' => $skinDir,
        ]];
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($skinDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        $rel  = ltrim(substr($path, strlen($skinDir)), '/');
        // Gitignored / throwaway reference material is never packaged — skip it
        // (the packager works from the git archive, which omits these anyway).
        if (stripos($rel, 'gitignore') !== false) continue;
        $ext  = strtolower($file->getExtension());

        // Absolute rule: a skin ships NO JavaScript. All JS lives in assets/js/,
        // shared and registered, so a fix lands in one place. A per-skin copy is
        // redundant AND an attack surface (its flaws survive the shared fix).
        if ($ext === 'js') {
            $findings[] = [
                'file' => $rel, 'line' => 0, 'type' => 'bundled-js-file',
                'severity' => 'block',
                'excerpt' => 'skin ships its own JavaScript — must move to assets/js/',
            ];
            continue;
        }
        if (!in_array($ext, SNAPSMACK_SKINSCAN_MARKUP_EXT, true)) continue;

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) continue;
        foreach ($lines as $i => $line) {
            foreach (snapsmack_scan_line($line, $vetted) as $hit) {
                $findings[] = [
                    'file' => $rel, 'line' => $i + 1,
                    'type' => $hit['type'], 'severity' => $hit['severity'],
                    'excerpt' => trim($hit['excerpt']),
                ];
            }
        }
    }
    return $findings;
}

/** Basenames (lowercase) of every JS engine registered in the core inventory. */
function snapsmack_registered_engine_basenames(): array {
    $inv_path = dirname(__DIR__) . '/core/manifest-inventory.php';
    $names = [];
    if (is_file($inv_path)) {
        $inv = @include $inv_path;
        foreach (($inv['scripts'] ?? []) as $script) {
            if (!empty($script['path'])) {
                $names[] = strtolower(basename($script['path']));
            }
        }
    }
    return array_values(array_unique($names));
}

/**
 * Classify a single source line. Returns 0+ hits. Kept line-based on purpose:
 * skins are hand-authored templates, so nasties live on one line, and a
 * line-based scan gives a precise file:line the author can jump to.
 */
function snapsmack_scan_line(string $line, array $vetted = []): array {
    $hits = [];
    // Match on a comment-stripped copy so documentation that MENTIONS a nasty
    // (e.g. the "No direct <script> tags here" reminders in skin-footer.php) is
    // not itself flagged. The original line is still shown as the excerpt.
    $scan = snapsmack_decomment($line);
    $orig = $line;

    // 1. Inline event handlers: onclick=, onerror=, onload=, … (attribute form).
    if (preg_match('/\son[a-z]{2,}\s*=\s*["\']/i', $scan)) {
        $hits[] = ['type' => 'inline-event-handler', 'severity' => 'block',
                   'excerpt' => $orig];
    }

    // 2. javascript: URIs.
    if (preg_match('/javascript\s*:/i', $scan)) {
        $hits[] = ['type' => 'javascript-uri', 'severity' => 'block',
                   'excerpt' => $orig];
    }

    // 3. iframe / object / embed elements.
    if (preg_match('/<\s*(iframe|object|embed)\b/i', $scan, $m)) {
        $hits[] = ['type' => strtolower($m[1]) . '-element', 'severity' => 'block',
                   'excerpt' => $orig];
    }

    // 4. <script …> handling. Skip the sanctioned inventory loader, whose src is
    //    a core-owned path resolved from the manifest inventory, not a literal.
    if (preg_match('/<\s*script\b/i', $scan)) {
        $isLoader = (strpos($scan, "\$inventory['scripts']") !== false)
                 || (strpos($scan, "\$script['path']") !== false);
        if (!$isLoader) {
            $hit = snapsmack_classify_script($scan, $vetted);
            if ($hit) { $hit['excerpt'] = $orig; $hits[] = $hit; }
        }
    }

    return array_values(array_filter($hits));
}

/** Remove comment text so a nasty MENTIONED in a comment isn't flagged. */
function snapsmack_decomment(string $line): string {
    $line = preg_replace('/<!--.*?-->/', '', $line);   // whole HTML comment
    $line = preg_replace('/<!--.*$/',   '', $line);    // HTML comment to EOL
    $t = ltrim($line);
    if ($t !== '' && $t[0] === '*') return '';          // block-comment body line
    // PHP/JS // line comment, but not a URL's "://".
    $line = preg_replace('#(?<!:)//.*$#', '', $line);
    return $line;
}

/** Classify a non-loader <script> occurrence by its src (or lack of one). */
function snapsmack_classify_script(string $line, array $vetted = []): ?array {
    // Pull the src value if the tag carries one.
    if (preg_match('/<\s*script\b[^>]*\bsrc\s*=\s*["\']([^"\']*)["\']/i', $line, $m)) {
        $src  = trim($m[1]);
        $base = strtolower(basename(preg_replace('/[?#].*$/', '', $src)));
        // External (absolute or protocol-relative) — never allowed in a gallery skin.
        if (preg_match('#^(https?:)?//#i', $src)) {
            return ['type' => 'external-script', 'severity' => 'block',
                    'excerpt' => $line];
        }
        // Loading SHARED core JS from assets/js/ directly (rather than via the
        // require_scripts inventory loader): not redundant, safe bytes — WARN.
        if (strpos($src, 'assets/js/') !== false && strpos($src, 'skins/') === false) {
            return ['type' => 'direct-core-engine-tag', 'severity' => 'warn',
                    'excerpt' => $line];
        }
        // Anything else with a .js src is skin-shipped/relative JavaScript.
        return ['type' => 'skin-local-script', 'severity' => 'block',
                'excerpt' => $line];
    }
    // A <script> tag with no src on this line is an inline code block.
    return ['type' => 'inline-script-block', 'severity' => 'block',
            'excerpt' => $line];
}

// ── CLI ─────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    $json = false; $oneSkin = null; $root = null;
    for ($k = 0; $k < count($args); $k++) {
        if ($args[$k] === '--json') { $json = true; }
        elseif ($args[$k] === '--skin') { $oneSkin = $args[++$k] ?? null; }
        else { $root = $args[$k]; }
    }
    if ($root === null) {
        $root = dirname(__DIR__) . '/skins';
    }

    // Locked skins are reported but never auto-touched (Photogram / The Grid).
    $targets = [];
    if ($oneSkin !== null) {
        $targets[basename($oneSkin)] = $oneSkin;
    } else {
        foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $targets[basename($d)] = $d;
        }
    }
    ksort($targets);

    $vetted = snapsmack_registered_engine_basenames();
    $report = [];
    $totalBlock = 0; $totalWarn = 0;
    foreach ($targets as $slug => $dir) {
        $f = snapsmack_scan_skin($dir, $vetted);
        $report[$slug] = $f;   // keep clean skins in the report so they're counted
        foreach ($f as $hit) {
            if ($hit['severity'] === 'block') $totalBlock++; else $totalWarn++;
        }
    }

    if ($json) {
        echo json_encode(['block' => $totalBlock, 'warn' => $totalWarn,
                          'skins' => $report], JSON_PRETTY_PRINT), "\n";
        exit($totalBlock > 0 ? 1 : 0);
    }

    $dirtySkins = 0;
    foreach ($report as $slug => $f) {
        $blocks = array_filter($f, fn($h) => $h['severity'] === 'block');
        if ($blocks) $dirtySkins++;
        $tag = $blocks ? 'DIRTY' : (count($f) ? 'warn ' : 'clean');
        echo str_pad($tag, 6) . " $slug\n";
        foreach ($f as $hit) {
            $sev = strtoupper($hit['severity']);
            $loc = $hit['file'] . ($hit['line'] ? ':' . $hit['line'] : '');
            $ex  = strlen($hit['excerpt']) > 96
                 ? substr($hit['excerpt'], 0, 95) . '…' : $hit['excerpt'];
            echo "        [$sev] {$hit['type']}  $loc\n";
            if ($ex !== '') echo "               $ex\n";
        }
    }
    echo "\n" . str_repeat('─', 60) . "\n";
    echo "Scanned " . count($report) . " skins. "
       . "$dirtySkins with BLOCK findings ($totalBlock block, $totalWarn warn).\n";
    echo $totalBlock > 0
        ? "A gallery skin must have ZERO block findings to publish.\n"
        : "All clear for the gallery.\n";
    exit($totalBlock > 0 ? 1 : 0);
}
// ===== SNAPSMACK EOF =====
