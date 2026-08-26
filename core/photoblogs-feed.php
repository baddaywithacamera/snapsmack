<?php
/**
 * SNAPSMACK — photoblogs.fyi DISCOVERY FEED (hub side)
 *
 * The point of photoblogs.fyi is to send traffic BACK to the member blogs. This
 * feed is the visual front door: a grid of square thumbnails, one per recent
 * post across every directory-listed blog, each linking straight to that post on
 * the blog's own server. No text. Post 20 photos, get 20 squares — showing up is
 * rewarded — but a mild de-clump keeps any one blog from running back-to-back so
 * the wall stays a community mix, not one loud voice.
 *
 * Links are dofollow (rel="noopener" only): the directory PASSES its link equity
 * out to members on purpose. Thumbnails are hotlinked from each blog's own origin
 * — "your photos never leave your server" is the whole promise.
 *
 * A cron refreshes the cache (pbfeed_refresh); the page only reads it, so no
 * visitor ever waits on 24 outbound RSS fetches.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (!defined('PBFEED_MAX_PER_BLOG')) define('PBFEED_MAX_PER_BLOG', 24); // cap kept per blog
if (!defined('PBFEED_GRID_LIMIT'))   define('PBFEED_GRID_LIMIT', 300);  // squares rendered

/** Cache table: one row per (blog, post). */
function pbfeed_ensure_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS snap_feed_items (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            site_url   VARCHAR(255) NOT NULL,
            host       VARCHAR(255) NOT NULL DEFAULT '',
            blog_name  VARCHAR(160) NOT NULL DEFAULT '',
            post_url   VARCHAR(600) NOT NULL,
            image_url  VARCHAR(600) NOT NULL,
            title      VARCHAR(300) NOT NULL DEFAULT '',
            pub_date   DATETIME NULL,
            fetched_at DATETIME NULL,
            UNIQUE KEY post_uk (post_url),
            KEY site_idx (site_url),
            KEY pub_idx (pub_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * SSRF guard (mirrors cron-rss-fetch.php / SECAUDIT 047): http(s) only, and the
 * host must not resolve to a private/reserved/loopback address. site_url values
 * are member-supplied, so every outbound fetch is checked.
 */
function pbfeed_url_is_safe(string $url): bool {
    $p = @parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return false;
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') return false;
    $host = $p['host'];
    $ips  = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip']))   $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) { $h = @gethostbyname($host); if ($h && $h !== $host) $ips[] = $h; }
    }
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/** A media URL that will render inside an <img src> and a CSS-safe grid — reject
 *  non-http(s) and anything with markup/CSS-breakout characters. Returns '' if unsafe. */
function pbfeed_clean_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) return '';
    if (strpbrk($url, "'\"<>\\ \t\r\n") !== false) return '';
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/** Pull the first <img src> out of an RSS item description (SnapSmack rss.php puts
 *  the hero image there). Falls back to enclosure/media handled by the caller. */
function pbfeed_first_img(string $html): string {
    if (preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) return $m[1];
    return '';
}

/**
 * Refresh the cache from every ACTIVE directory listing's /rss.php.
 * Fetches are SSRF-guarded, TLS-verified, timed out, and redirect-capped. Only
 * rows for currently-listed blogs are kept, so a delisted blog drops out.
 * $log is an optional callable(string) for CLI progress.
 */
function pbfeed_refresh(PDO $pdo, ?callable $log = null): array {
    $say = $log ?: function () {};
    pbfeed_ensure_table($pdo);

    // Source of truth = the public directory members.
    $blogs = $pdo->query(
        "SELECT site_url, host, name FROM snap_directory_listings WHERE state = 'active'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $say('Feed refresh: ' . count($blogs) . ' active listings.');

    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'SnapSmack Feed Reader/1.0', 'ignore_errors' => true, 'max_redirects' => 1],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $upsert = $pdo->prepare(
        "INSERT INTO snap_feed_items (site_url, host, blog_name, post_url, image_url, title, pub_date, fetched_at)
         VALUES (?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            site_url=VALUES(site_url), host=VALUES(host), blog_name=VALUES(blog_name),
            image_url=VALUES(image_url), title=VALUES(title), pub_date=VALUES(pub_date), fetched_at=NOW()"
    );

    $live_urls = [];
    $items_total = 0;

    foreach ($blogs as $b) {
        $site = rtrim((string)$b['site_url'], '/');
        if ($site === '') continue;
        $live_urls[] = $site;
        $rss = $site . '/rss.php';
        if (!pbfeed_url_is_safe($rss)) { $say("  SKIP {$site}: RSS url blocked."); continue; }

        $raw = @file_get_contents($rss, false, $ctx);
        if (!$raw) { $say("  SKIP {$site}: could not fetch RSS."); continue; }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_clear_errors();
        if (!$xml || !isset($xml->channel->item)) { $say("  SKIP {$site}: no items."); continue; }

        $host = (string)parse_url($site, PHP_URL_HOST);
        $name = (string)($b['name'] ?? $host);
        $kept = 0;

        foreach ($xml->channel->item as $item) {
            if ($kept >= PBFEED_MAX_PER_BLOG) break;

            $post_url = pbfeed_clean_url((string)$item->link);
            if ($post_url === '') continue;

            // Image: enclosure/media first, else first <img> in the description.
            $img = '';
            if (isset($item->enclosure['url'])) $img = (string)$item->enclosure['url'];
            if ($img === '') {
                $media = $item->children('http://search.yahoo.com/mrss/');
                if (isset($media->content['url']))   $img = (string)$media->content['url'];
                elseif (isset($media->thumbnail['url'])) $img = (string)$media->thumbnail['url'];
            }
            if ($img === '') $img = pbfeed_first_img((string)$item->description);
            $img = pbfeed_clean_url($img);
            if ($img === '') continue; // no image → not a square

            $title = mb_substr(trim((string)$item->title), 0, 280);
            $ts = strtotime((string)$item->pubDate) ?: null;
            $pub = $ts ? date('Y-m-d H:i:s', $ts) : null;

            $upsert->execute([$site, $host, $name, $post_url, $img, $title, $pub]);
            $kept++; $items_total++;
        }
        $say("  OK {$site}: {$kept} items.");

        // Keep only the newest PBFEED_MAX_PER_BLOG for this blog.
        $pdo->prepare(
            "DELETE FROM snap_feed_items WHERE site_url = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM snap_feed_items WHERE site_url = ?
                    ORDER BY pub_date DESC, id DESC LIMIT " . (int)PBFEED_MAX_PER_BLOG . "
                ) keep
            )"
        )->execute([$site, $site]);
    }

    // Drop items for blogs no longer listed.
    if ($live_urls) {
        $ph = implode(',', array_fill(0, count($live_urls), '?'));
        $pdo->prepare("DELETE FROM snap_feed_items WHERE site_url NOT IN ($ph)")->execute($live_urls);
    } else {
        $pdo->exec("DELETE FROM snap_feed_items");
    }

    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('pbfeed_last_run', ?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([date('Y-m-d H:i:s')]);
    $say("Feed refresh done: {$items_total} items across " . count($blogs) . " blogs.");
    return ['blogs' => count($blogs), 'items' => $items_total];
}

/**
 * Mild de-clump: newest-first, but never place the same blog twice in a row.
 * Greedy — at each step take the newest remaining item whose host differs from the
 * one just placed; if every remaining item is from that same blog, allow it (we
 * nudge, we don't hard-scramble). Preserves recency, breaks runs. "Mild, not severe."
 */
function pbfeed_interleave(array $items): array {
    $out = [];
    $last = null;
    $pool = array_values($items);
    while ($pool) {
        $idx = null;
        foreach ($pool as $i => $it) { if (($it['host'] ?? '') !== $last) { $idx = $i; break; } }
        if ($idx === null) $idx = 0;             // all remaining are the last blog — allow
        $out[] = $pool[$idx];
        $last  = $pool[$idx]['host'] ?? null;
        array_splice($pool, $idx, 1);
    }
    return $out;
}

/** Read the cache, de-clump, and return the square-grid HTML (no text). */
function pbfeed_grid_html(PDO $pdo): string {
    pbfeed_ensure_table($pdo);
    $rows = $pdo->query(
        "SELECT host, blog_name, post_url, image_url, title
         FROM snap_feed_items ORDER BY pub_date DESC, id DESC LIMIT " . (int)PBFEED_GRID_LIMIT
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return '<p class="pbfeed-empty">The feed fills as member blogs publish. Nothing here yet — check back soon.</p>';
    }

    $rows = pbfeed_interleave($rows);
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $out = '<div class="pbfeed-grid">';
    foreach ($rows as $r) {
        $alt = trim(($r['blog_name'] ?? '') . ($r['title'] ? ' — ' . $r['title'] : ''));
        // dofollow on purpose (rel="noopener" only) — pass equity out to members.
        $out .= '<a class="pbfeed-sq" href="' . $h($r['post_url']) . '" target="_blank" rel="noopener">'
              . '<img src="' . $h($r['image_url']) . '" alt="' . $h($alt) . '" loading="lazy" decoding="async">'
              . '</a>';
    }
    $out .= '</div>';
    return $out;
}
// ===== SNAPSMACK EOF =====
