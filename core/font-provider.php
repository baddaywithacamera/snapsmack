<?php
/**
 * Shared local-font provider.
 *
 * Release packages deliberately omit the 22 MB font library so the installer
 * stays small enough for shared hosting. A site therefore pulls the faces it
 * actually uses from Smack Central ONCE and then serves them itself.
 *
 * Everything that touches a font file goes through here — font.php, the skin
 * installer and the settings save path — so the cache location and the fetch
 * rules cannot drift into two implementations.
 */

/** @return array<string,array<string,string>> the audited local font inventory. */
function snapsmack_font_inventory(): array {
    static $fonts = null;
    if ($fonts === null) {
        $inventory = include __DIR__ . '/manifest-inventory.php';
        $fonts = is_array($inventory['local_fonts'] ?? null) ? $inventory['local_fonts'] : [];
    }
    return $fonts;
}

/**
 * Where a fetched face lives.
 *
 * data/cache/fonts, beside the page cache, NOT the system temp directory.
 * Shared hosts wipe temp aggressively and sometimes hand each process its own,
 * so a temp-cached font is refetched over and over and every one of those
 * fetches is a chance for the masthead to fall back to a default face.
 * A font fetched into the site's own data directory is fetched once, ever.
 */
function snapsmack_font_cache_dir(): string {
    $preferred = dirname(__DIR__) . '/data/cache/fonts';
    if (is_dir($preferred) || @mkdir($preferred, 0775, true) || is_dir($preferred)) {
        if (is_writable($preferred)) return $preferred;
    }
    // A host that will not let us write there still gets working fonts, just
    // without the durability.
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
}

/** The canonical relative path for a family, or '' when it is not allow-listed. */
function snapsmack_font_relative_path(string $family): string {
    $fonts = snapsmack_font_inventory();
    if (!isset($fonts[$family]) || !is_array($fonts[$family])) return '';
    $relative = (string)($fonts[$family]['file'] ?? '');
    if (!preg_match('#^assets/fonts/[A-Za-z0-9 ._-]+/[A-Za-z0-9 ._-]+\.(?:ttf|otf|woff2?)$#i', $relative)) {
        return '';
    }
    return $relative;
}

/** Upstream URL for a family's canonical asset. */
function snapsmack_font_source_url(string $relative): string {
    $segments = array_map('rawurlencode', explode('/', $relative));
    return 'https://snapsmack.ca/sc-assets/'
        . preg_replace('#^assets/#', '', implode('/', $segments));
}

/** Cache file for a family. */
function snapsmack_font_cache_file(string $family, string $relative): string {
    $extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
    return snapsmack_font_cache_dir() . DIRECTORY_SEPARATOR
        . 'snapsmack-font-' . hash('sha256', snapsmack_font_source_url($relative))
        . '.' . $extension;
}

/** A face that already sits in this installation, or '' if it is not here yet. */
function snapsmack_font_local_copy(string $family): string {
    $relative = snapsmack_font_relative_path($family);
    if ($relative === '') return '';
    // A full checkout has the real library; prefer it over any fetch.
    $bundled = dirname(__DIR__) . '/' . $relative;
    if (is_file($bundled)) return $bundled;
    $cached = snapsmack_font_cache_file($family, $relative);
    return is_file($cached) && filesize($cached) > 0 ? $cached : '';
}

/**
 * Fetch a family from Smack Central into the cache.
 *
 * Returns true when the face is afterwards available locally. Already-present
 * faces short-circuit, so this is safe to call on every skin install.
 */
function snapsmack_font_fetch(string $family): bool {
    $relative = snapsmack_font_relative_path($family);
    if ($relative === '') return false;
    if (snapsmack_font_local_copy($family) !== '') return true;

    $sourceUrl = snapsmack_font_source_url($relative);
    $bytes = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($sourceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'SnapSmack font provider',
        ]);
        $candidate = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 200 && is_string($candidate) && $candidate !== '') $bytes = $candidate;
    }

    if ($bytes === false && (bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 15, 'follow_location' => 0,
                       'user_agent' => 'SnapSmack font provider'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $candidate = @file_get_contents($sourceUrl, false, $context);
        $statusLine = is_array($http_response_header ?? null)
            ? (string)($http_response_header[0] ?? '') : '';
        if (is_string($candidate) && $candidate !== ''
            && preg_match('#\s200(?:\s|$)#', $statusLine)) {
            $bytes = $candidate;
        }
    }

    if ($bytes === false) return false;
    $target = snapsmack_font_cache_file($family, $relative);
    return @file_put_contents($target, $bytes, LOCK_EX) !== false;
}

/**
 * Pull down every face a site actually uses, before a visitor needs it.
 *
 * Called when a skin is installed or updated and when settings are saved, so
 * the first visitor never waits on Smack Central and a Smack Central outage
 * cannot change the typeface on a site that is already running.
 *
 * @param iterable<string> $families
 * @return array{warmed:int,missing:string[]}
 */
function snapsmack_font_warm(iterable $families): array {
    $warmed = 0;
    $missing = [];
    $done = [];
    foreach ($families as $family) {
        $family = trim((string)$family);
        if ($family === '' || isset($done[$family])) continue;
        $done[$family] = true;
        if (snapsmack_font_relative_path($family) === '') continue;
        if (snapsmack_font_fetch($family)) $warmed++;
        else $missing[] = $family;
    }
    return ['warmed' => $warmed, 'missing' => $missing];
}

/** Families a site's saved presentation options actually select. */
function snapsmack_font_families_in_use(array $options): array {
    $fonts = snapsmack_font_inventory();
    $selected = [];
    foreach ($options as $value) {
        if (!is_scalar($value)) continue;
        $family = trim((string)$value);
        if ($family !== '' && isset($fonts[$family])) $selected[$family] = true;
    }
    return array_keys($selected);
}

// ===== SNAPSMACK EOF =====
