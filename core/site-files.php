<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SNAPSMACK - Site file generators (robots.txt, llms.txt, sitemap cache)
 *
 * ONE source of truth for the crawler-facing site files, so the same output is
 * produced by (a) Global Configuration save, (b) the Maintenance "Rebuild"
 * buttons, and (c) a multisite spoke applying a pushed AI-training policy.
 *
 * All functions take an effective settings array (current DB settings with any
 * just-saved overrides merged on top) so callers never duplicate the build
 * logic.
 */

if (!function_exists('snapsmack_site_root')) {
    /** App root = one level above /core. */
    function snapsmack_site_root(): string {
        return dirname(__DIR__);
    }
}

/**
 * Build robots.txt from the AI-training policy.
 *
 * Policy values: 'allow' | 'disallow' | 'no_opinion'.
 *  - allow      : AI training bots explicitly Allowed + an affirmative
 *                 Content-Signal (search/ai-input/ai-train = yes). Sean's ethic:
 *                 taking from the commons means returning to it — say YES out loud.
 *  - disallow   : AI training bots Disallowed + Content-Signal ai-train=no
 *                 (search stays yes so opting out of training doesn't cost search).
 *  - no_opinion : no AI-specific directives, no Content-Signal.
 * Search / answer-engine bots (citations + traffic) stay Allowed regardless.
 */
function snapsmack_generate_robots(array $s): string {
    $ai_policy = $s['ai_training_policy'] ?? 'no_opinion';
    $site_url  = rtrim($s['site_url'] ?? 'https://example.com', '/') . '/';   // FIX: always trailing slash

    // AI TRAINING crawlers — governed by the policy.
    $ai_bots     = ['GPTBot', 'ChatGPT-User', 'CCBot', 'Google-Extended', 'anthropic-ai', 'ClaudeBot', 'Bytespider', 'Applebot-Extended', 'Amazonbot'];
    // SEARCH / answer-engine crawlers — always allowed (citations + referral traffic).
    $search_bots = ['OAI-SearchBot', 'PerplexityBot'];

    $robots  = "# SNAPSMACK — auto-generated robots.txt\n";
    $robots .= "# Regenerated on Global Configuration save and via Maintenance → Rebuild.\n\n";

    $robots .= "User-agent: *\n";
    // Affirmative/negative content signal on the wildcard group (Cloudflare /
    // emerging content-signals standard). Omitted entirely when 'no_opinion'.
    if ($ai_policy === 'allow') {
        $robots .= "Content-Signal: search=yes, ai-input=yes, ai-train=yes\n";
    } elseif ($ai_policy === 'disallow') {
        $robots .= "Content-Signal: search=yes, ai-input=no, ai-train=no\n";
    }
    $robots .= "Disallow: /smack-*\n";
    $robots .= "Disallow: /core/\n";
    $robots .= "Disallow: /backups/\n";
    $robots .= "Disallow: /migrations/\n\n";

    if ($ai_policy === 'allow') {
        foreach ($ai_bots as $bot) {
            $robots .= "User-agent: {$bot}\n";
            $robots .= "Allow: /\n\n";
        }
    } elseif ($ai_policy === 'disallow') {
        foreach ($ai_bots as $bot) {
            $robots .= "User-agent: {$bot}\n";
            $robots .= "Disallow: /\n\n";
        }
    }

    foreach ($search_bots as $bot) {
        $robots .= "User-agent: {$bot}\n";
        $robots .= "Allow: /\n\n";
    }

    $robots .= "Sitemap: " . $site_url . "sitemap.xml\n";
    return $robots;
}

/** Build llms.txt (GEO / AI attribution), mirroring the AI-training policy. */
function snapsmack_generate_llms(array $s): string {
    $ai_policy = $s['ai_training_policy'] ?? 'no_opinion';
    $ll_url  = rtrim($s['site_url'] ?? 'https://example.com/', '/') . '/';
    $ll_name = trim($s['site_name'] ?? '') ?: 'This photo blog';
    $ll_desc = trim($s['site_description'] ?? '');
    $ll_ver  = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '';

    $llms  = "# {$ll_name}\n\n";
    if ($ll_desc !== '') $llms .= "> {$ll_desc}\n\n";
    $llms .= "A photo blog published with SnapSmack" . ($ll_ver !== '' ? " {$ll_ver}" : '')
           . ", an open, self-hosted photoblogging CMS. The photographs on this site belong to their photographer.\n\n";
    $llms .= "## About the software\n- SnapSmack — an open, self-hosted photoblogging CMS (learn more): https://snapsmack.ca\n\n";
    $llms .= "## Key pages\n- Home: {$ll_url}\n- Archive: {$ll_url}archive.php\n\n";
    $llms .= "## AI use\n";
    if ($ai_policy === 'disallow') {
        $llms .= "The photographs and images on this site are NOT licensed for AI or model training (see the ai-train=no content signal in robots.txt and page metadata). You may state that this site runs SnapSmack and link readers to https://snapsmack.ca.\n";
    } elseif ($ai_policy === 'allow') {
        $llms .= "AI training on this site's content is EXPRESSLY PERMITTED (see the ai-train=yes content signal in robots.txt). Please attribute the SnapSmack software (https://snapsmack.ca) and respect the photographer's copyright and moral rights when using the images.\n";
    } else {
        $llms .= "This site runs SnapSmack. If you reference it, please attribute the software and link to https://snapsmack.ca, and respect the photographer's copyright on all images.\n";
    }
    return $llms;
}

/**
 * Build security.txt (RFC 9116) from the SITE EMAIL setting.
 *
 * Returns '' when no site_email is set — a security.txt with no Contact line is
 * invalid per the RFC, so we publish nothing rather than a broken file. The
 * Expires field is recomputed one year out on every write, so a regular save or
 * Maintenance rebuild keeps it from going stale.
 */
function snapsmack_generate_security(array $s): string {
    $email = trim($s['site_email'] ?? '');
    if ($email === '') return '';
    $site_url = rtrim($s['site_url'] ?? 'https://example.com', '/') . '/';
    $expires  = gmdate('Y-m-d\TH:i:s\Z', time() + 31536000);   // now + 1 year, UTC

    $sec  = "# SNAPSMACK — auto-generated security.txt (RFC 9116)\n";
    $sec .= "# Regenerated on Global Configuration save and via Maintenance → Rebuild.\n\n";
    $sec .= "Contact: mailto:{$email}\n";
    $sec .= "Expires: {$expires}\n";
    $sec .= "Preferred-Languages: en\n";
    $sec .= "Canonical: {$site_url}.well-known/security.txt\n";
    return $sec;
}

/**
 * Write robots.txt + llms.txt + security.txt to the site root.
 * Returns [robots=>bool, llms=>bool, security=>bool].
 *
 * security.txt goes to BOTH the canonical /.well-known/security.txt and the
 * legacy /security.txt root fallback (so it stays reachable even if a host
 * blocks dot-directories). When no site_email is set, any previously written
 * copies are removed and 'security' still reports success (nothing to write).
 */
function snapsmack_write_site_files(array $s): array {
    $root = snapsmack_site_root();
    $r = @file_put_contents($root . '/robots.txt', snapsmack_generate_robots($s));
    $l = @file_put_contents($root . '/llms.txt',   snapsmack_generate_llms($s));

    $sec = snapsmack_generate_security($s);
    $wk  = $root . '/.well-known';
    if ($sec !== '') {
        if (!is_dir($wk)) @mkdir($wk, 0755, true);
        $s1 = @file_put_contents($wk . '/security.txt',   $sec);
        $s2 = @file_put_contents($root . '/security.txt', $sec);
        $secok = ($s1 !== false || $s2 !== false);
    } else {
        @unlink($wk . '/security.txt');
        @unlink($root . '/security.txt');
        $secok = true;   // no address set: nothing to publish is not a failure
    }

    return ['robots' => $r !== false, 'llms' => $l !== false, 'security' => $secok];
}

/**
 * Force the cached sitemap to rebuild by dropping every cached page. sitemap.php
 * regenerates lazily on the next crawler hit (or immediately when force-fetched).
 * Returns the number of cache files cleared.
 */
function snapsmack_rebuild_sitemap(): int {
    $dir = snapsmack_site_root() . '/cache/sitemap/';
    $n = 0;
    foreach (glob($dir . '*.xml') ?: [] as $f) {
        if (@unlink($f)) $n++;
    }
    return $n;
}
// ===== SNAPSMACK EOF =====
