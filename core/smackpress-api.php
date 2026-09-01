<?php
/**
 * SNAPSMACK - SmackPress Migration API
 *
 * Authenticated JSON API for the SmackPress WordPress→SMACKTALK migration
 * workbench. All requests require a Bearer token issued from smack-api-keys.php.
 * No session required — key auth replaces session auth entirely.
 *
 * Routes (via api.php?route=smackpress/...):
 *   POST   smackpress/media/upload     — upload image, returns asset_id
 *   POST   smackpress/posts            — create or update longform post
 *   GET    smackpress/posts/{id}       — read back a post
 *   GET    smackpress/categories       — list all categories
 *   POST   smackpress/mosaics          — create mosaic panel
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 * (Pure-PHP file — no closing tag, so the marker is a PHP comment, not <?php.)
 */

header('Content-Type: application/json');

// --- AUTH ---
require_once __DIR__ . '/db.php';

function smackpress_ensure_key_type(PDO $pdo): void {
    // Defensive: add key_type column if this is a pre-SmackPress install
    try {
        $pdo->query("SELECT key_type FROM snap_ohsnap_keys LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN key_type VARCHAR(20) NOT NULL DEFAULT 'ohsnap' AFTER label");
    }
}

function smackpress_auth(PDO $pdo): bool {
    smackpress_ensure_key_type($pdo);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return false;
    }
    $hash = hash('sha256', $m[1]);
    // SECAUDIT 039 (sweep): enforce key EXPIRY — this handler checked only
    // is_active, so a lapsed SmackPress key kept working. Matches the pattern in
    // core/api-auth.php. NULL expires_at = legacy key. Falls back if absent.
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type = 'smackpress'
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$hash]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("
            SELECT id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type = 'smackpress'
            LIMIT 1
        ");
        $stmt->execute([$hash]);
    }
    $row = $stmt->fetch();
    if (!$row) return false;
    $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    return true;
}

function smackpress_error(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function smackpress_ok(array $data): void {
    echo json_encode(array_merge(['status' => 'ok'], $data));
    exit;
}

if (!smackpress_auth($pdo)) {
    smackpress_error(401, 'Invalid or missing API key.');
}

// --- ROUTE PARSING ---
$route = $_GET['route'] ?? '';
// strip leading 'smackpress/'
$sub = preg_replace('#^smackpress/?#', '', $route);
$method = $_SERVER['REQUEST_METHOD'];

// Require the longform helper functions from smack-post-long.php without
// rendering its HTML — include only if not already loaded.
if (!function_exists('smack_autop_long')) {
    // Inline minimal autop — mirrors smack-post-long.php logic
    function smack_autop_long(string $text): string {
        if (trim($text) === '') return '';
        if (preg_match('/^\s*<p/i', $text)) return $text;
        $text = preg_replace('/(\[img:[^\]]+\])\s*\n+/', '$1', $text);
        $text = preg_replace('/(\[mosaic:\d+\])\s*\n+/', '$1', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $chunks = preg_split('/\n\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chunks as &$chunk) {
            $trimmed = trim($chunk);
            if (preg_match('/^\[img:\s*\d+(?:\s*\|[^\]]*)*\]$/', $trimmed)) {
                $chunk = $trimmed;
            } elseif (preg_match('/^\[mosaic:\d+\]$/', $trimmed)) {
                $chunk = $trimmed;
            } elseif (preg_match('/^\[spacer:\s*\d+\]$/', $trimmed)) {
                $chunk = $trimmed;
            } else {
                $chunk = '<p>' . nl2br(htmlspecialchars($trimmed, ENT_NOQUOTES)) . '</p>';
            }
        }
        return implode("\n", $chunks);
    }
}

if (!function_exists('long_slugify')) {
    function long_slugify(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
        $s = preg_replace('/[\s-]+/', '-', $s);
        return trim($s, '-');
    }
}

if (!function_exists('smackpress_sanitize_html')) {
    /**
     * DOM-based allowlist sanitiser for imported WordPress content.
     *
     * WordPress post bodies carry whatever plugins injected — <script>, tracking
     * pixels, inline styles, iframes, event handlers. Importing that raw would run
     * it in every visitor's browser on the new site. This parses the HTML (regex
     * HTML sanitising is bypassable) and keeps ONLY known-safe tags + attributes:
     *   - script/style/iframe/object/embed/form/link/meta/svg/... removed WITH content
     *   - any other unknown tag is unwrapped (its safe text/children survive)
     *   - style / class / id / on* attributes dropped (no inline JS or CSS)
     *   - javascript:/vbscript:/data: URLs stripped from href/src
     *   - comments removed
     * On a parse failure it degrades to text-only — it never stores raw HTML.
     */
    function smackpress_sanitize_html(string $html): string {
        $html = trim($html);
        if ($html === '') return '';

        static $strip_with_content = [
            'script','style','iframe','object','embed','form','input','button',
            'select','option','textarea','link','meta','base','noscript','svg',
            'math','applet','frame','frameset','title','head','template','xml',
        ];
        static $allowed = [
            'p','br','hr','a','strong','b','em','i','u','s','strike','del','ins',
            'sub','sup','small','mark','abbr','cite','q','code','pre','kbd','samp',
            'var','time','h1','h2','h3','h4','h5','h6','blockquote','ul','ol','li',
            'dl','dt','dd','img','figure','figcaption','picture','source','table',
            'thead','tbody','tfoot','tr','th','td','caption','colgroup','col',
            'span','div','section','article','header','footer','main','aside','nav',
        ];
        static $attrs = [
            'a'        => ['href','title','target','rel'],
            'img'      => ['src','alt','title','width','height','loading'],
            'source'   => ['src','srcset','sizes','type','media'],
            'time'     => ['datetime'],
            'td'       => ['colspan','rowspan'],
            'th'       => ['colspan','rowspan','scope'],
            'col'      => ['span'],
            'colgroup' => ['span'],
        ];

        $dom  = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // NB: no LIBXML_HTML_NOIMPLIED — with the charset <meta> that leaves two
        // root nodes and the marker <div> lookup fails. Let libxml add html/body;
        // we only ever serialise the marker div's children, so the wrapper is moot.
        $ok   = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            . '<div data-smk-root="1">' . $html . '</div>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return '<p>' . htmlspecialchars(strip_tags($html), ENT_QUOTES) . '</p>';
        }

        $xpath = new DOMXPath($dom);
        $root  = $xpath->query('//div[@data-smk-root="1"]')->item(0);
        if (!$root) return '';

        // Strip dangerous tags (with their subtree) and all comments.
        $kill = [];
        foreach ($xpath->query('.//*', $root) as $el) {
            if (in_array(strtolower($el->nodeName), $strip_with_content, true)) $kill[] = $el;
        }
        foreach ($xpath->query('.//comment()', $root) as $c) $kill[] = $c;
        foreach ($kill as $node) { if ($node->parentNode) $node->parentNode->removeChild($node); }

        // Unwrap unknown tags, filter attributes on allowed ones. Reverse document
        // order so children are finished before their parents are unwrapped.
        $all = [];
        foreach ($xpath->query('.//*', $root) as $el) $all[] = $el;
        foreach (array_reverse($all) as $el) {
            if (!$el->parentNode) continue;
            $name = strtolower($el->nodeName);

            if (!in_array($name, $allowed, true)) {
                while ($el->firstChild) { $el->parentNode->insertBefore($el->firstChild, $el); }
                $el->parentNode->removeChild($el);
                continue;
            }

            $allow_for = $attrs[$name] ?? [];
            $remove    = [];
            if ($el->attributes) {
                foreach ($el->attributes as $attr) {
                    $an = strtolower($attr->nodeName);
                    if (!in_array($an, $allow_for, true)) { $remove[] = $attr->nodeName; continue; }
                    if (($an === 'href' || $an === 'src')
                        && preg_match('#^\s*(javascript|vbscript|data)\s*:#i', (string)$attr->nodeValue)) {
                        $remove[] = $attr->nodeName;
                    }
                }
            }
            foreach ($remove as $an) $el->removeAttribute($an);

            if ($name === 'a' && strtolower((string)$el->getAttribute('target')) === '_blank') {
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) { $out .= $dom->saveHTML($child); }
        return trim($out);
    }
}

if (!function_exists('snap_sync_tags')) {
    require_once __DIR__ . '/snap-tags.php';
}

// --- LOAD SETTINGS FOR BASE_URL ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$base_url = rtrim($settings['site_url'] ?? '', '/') . '/';

// =====================================================================
// ROUTE: POST smackpress/media/upload
// =====================================================================
if ($sub === 'media/upload' && $method === 'POST') {
    if (empty($_FILES['file'])) {
        smackpress_error(400, 'No file uploaded.');
    }
    // Ingest into the GALLERY (snap_images) via the shared pipeline so migrated
    // images render exactly like native SMACKTALK post images: usable inline as
    // [img:gID], as mosaic cells, and as a featured_image_id cover. Writing to the
    // Library (snap_assets) here would leave mosaics and covers blank, because the
    // renderer (core/parser.php) resolves mosaic + cover ids against snap_images. (0.7.398)
    if (!function_exists('snap_ingest_image')) {
        require_once __DIR__ . '/image-ingest.php';
    }
    $upload_opts = ['status' => 'published'];
    if (isset($_POST['caption_from_filename'])) {
        $upload_opts['caption_from_filename'] =
            ($_POST['caption_from_filename'] === '1' || $_POST['caption_from_filename'] === 'true');
    }
    $ingest = snap_ingest_image($pdo, $settings, $_FILES['file'], $upload_opts);
    if (empty($ingest['ok'])) {
        smackpress_error(500, $ingest['error'] ?? 'Image ingest failed.');
    }
    $image_id = (int) $ingest['id'];
    smackpress_ok([
        'image_id' => $image_id,
        'asset_id' => $image_id,   // back-compat alias for older callers
        'thumb'    => $ingest['thumb'] ?? '',
        'title'    => $ingest['title'] ?? '',
    ]);
}

/**
 * Populate a migrated SMACKTALK/longform post's IMAGE BUCKET (snap_bucket_items),
 * so images pulled out of a WordPress post belong to the post the same way a
 * natively-authored SMACKTALK post's photos do — mode-guard counts bucket photos
 * as editorial work, and the bucket is the per-post image set.
 *
 * Image set, in priority order:
 *   1. an explicit body list (`bucket_image_ids` or `image_ids`), if the caller sends one;
 *   2. otherwise every [img:NNN] referenced in the stored content.
 * The featured cover leads the bucket (position 0). IDs are snap_images ids.
 *
 * Guard: an empty set with NO explicit list is a no-op — a plain text edit must
 * never silently wipe a post's photos. An explicit empty list DOES clear it (intent).
 */
/**
 * Pure image-id collection for the bucket (no DB) — unit-testable.
 * Returns [$ids, $had_explicit_list]. $ids is deduped, first-seen order, positive,
 * with the featured cover leading. $had_explicit_list distinguishes "caller sent an
 * (even empty) list" from "we parsed the content".
 */
function smackpress_bucket_ids(array $body, string $content_html, ?int $featured): array {
    $explicit = null;
    foreach (['bucket_image_ids', 'image_ids'] as $k) {
        if (array_key_exists($k, $body) && is_array($body[$k])) { $explicit = $body[$k]; break; }
    }

    $ids = [];
    if ($explicit !== null) {
        foreach ($explicit as $id) $ids[] = (int)$id;
    } elseif (preg_match_all('/\[img:\s*g?\s*(\d+)/i', $content_html, $mm)) {
        // Matches both [img:gID] (SMACKPRESS's gallery form) and plain [img:ID];
        // the captured digits are the snap_images id in either case (see parser.php).
        foreach ($mm[1] as $id) $ids[] = (int)$id;
    }
    if ($featured) array_unshift($ids, (int)$featured);   // cover leads the bucket

    $seen = []; $clean = [];
    foreach ($ids as $id) { $id = (int)$id; if ($id > 0 && empty($seen[$id])) { $seen[$id] = true; $clean[] = $id; } }
    return [$clean, $explicit !== null];
}

function smackpress_save_bucket(PDO $pdo, int $post_id, array $body, string $content_html, ?int $featured): void {
    if ($post_id <= 0) return;
    require_once __DIR__ . '/bucket.php';
    list($clean, $had_explicit) = smackpress_bucket_ids($body, $content_html, $featured);
    // No images AND no explicit list -> leave the existing bucket untouched (a plain
    // text edit must not wipe a post's photos). An explicit empty list DOES clear it.
    if (!$clean && !$had_explicit) return;
    snap_bucket_save($pdo, $post_id, $clean);
}

// =====================================================================
// ROUTE: POST smackpress/posts — create or update longform post
// =====================================================================
if ($sub === 'posts' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');

    $post_id        = !empty($body['post_id']) ? (int)$body['post_id'] : null;
    $title          = trim($body['title'] ?? '');
    $slug           = trim($body['slug'] ?? '');
    $raw_content    = $body['content'] ?? $body['content_raw'] ?? '';
    $status         = in_array($body['status'] ?? '', ['published','draft']) ? $body['status'] : 'draft';
    $allow_comments = (int)($body['allow_comments'] ?? 0);
    // Cover: prefer featured_image_id (Gallery / snap_images). Legacy callers may
    // send featured_asset_id — since media/upload now ingests to the Gallery, that
    // id is also a snap_images id, so we treat it as the cover too.
    $featured_image = !empty($body['featured_image_id']) ? (int)$body['featured_image_id']
                    : (!empty($body['featured_asset_id']) ? (int)$body['featured_asset_id'] : null);
    $manual_tags    = trim($body['tags'] ?? '');
    $selected_cats  = array_map('intval', $body['cat_ids'] ?? []);
    if (empty($selected_cats) && !empty($body['category_id'])) {
        $selected_cats = [(int)$body['category_id']];   // single-category alias
    }
    $selected_albums= array_map('intval', $body['album_ids'] ?? []);
    $custom_date    = !empty($body['created_at']) ? $body['created_at']
                    : (!empty($body['date']) ? $body['date'] : null);

    if ($title === '') smackpress_error(422, 'Title is required.');

    $slug = $slug !== '' ? long_slugify($slug) : long_slugify($title);

    // Sanitise imported HTML (strip WP-plugin scripts/styles/iframes/handlers)
    // before it is ever stored — this content renders on the public site.
    $content_html = smackpress_sanitize_html(smack_autop_long($raw_content));

    if ($post_id) {
        // UPDATE
        $stmt = $pdo->prepare("SELECT id FROM snap_posts WHERE id = ? AND post_type = 'longform'");
        $stmt->execute([$post_id]);
        if (!$stmt->fetch()) smackpress_error(404, 'Post not found.');

        $sql = "UPDATE snap_posts SET title=?, slug=?, content=?, status=?, allow_comments=?, featured_image_id=?"
             . ($custom_date ? ", created_at=?" : "")
             . " WHERE id=? AND post_type='longform'";
        $params = [$title, $slug, $content_html, $status, $allow_comments, $featured_image];
        if ($custom_date) $params[] = $custom_date;
        $params[] = $post_id;
        $pdo->prepare($sql)->execute($params);

        $pdo->prepare("DELETE FROM snap_post_cat_map WHERE post_id=?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM snap_post_album_map WHERE post_id=?")->execute([$post_id]);
        foreach ($selected_cats   as $cid) $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id,cat_id) VALUES(?,?)")->execute([$post_id,$cid]);
        foreach ($selected_albums as $aid) $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id,album_id) VALUES(?,?)")->execute([$post_id,$aid]);
        snap_sync_tags($pdo, $post_id, $title . ' ' . $manual_tags);
        smackpress_save_bucket($pdo, $post_id, $body, $content_html, $featured_image);

        $post_url = $base_url . 'post/' . $slug;
        smackpress_ok(['post_id' => $post_id, 'slug' => $slug, 'url' => $post_url]);
    } else {
        // INSERT — ensure unique slug
        $base_slug = $slug; $n = 0;
        while (true) {
            $check = $pdo->prepare("SELECT id FROM snap_posts WHERE slug=?");
            $check->execute([$slug]);
            if (!$check->fetch()) break;
            $slug = $base_slug . '-' . (++$n);
        }
        $sql = "INSERT INTO snap_posts (title,slug,content,post_type,status,allow_comments,featured_image_id"
             . ($custom_date ? ",created_at" : "")
             . ") VALUES(?,?,?,'longform',?,?,?"
             . ($custom_date ? ",?" : "") . ")";
        $params = [$title, $slug, $content_html, $status, $allow_comments, $featured_image];
        if ($custom_date) $params[] = $custom_date;
        $pdo->prepare($sql)->execute($params);
        $new_id = (int)$pdo->lastInsertId();

        foreach ($selected_cats   as $cid) $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id,cat_id) VALUES(?,?)")->execute([$new_id,$cid]);
        foreach ($selected_albums as $aid) $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id,album_id) VALUES(?,?)")->execute([$new_id,$aid]);
        snap_sync_tags($pdo, $new_id, $title . ' ' . $manual_tags);
        smackpress_save_bucket($pdo, $new_id, $body, $content_html, $featured_image);

        $post_url = $base_url . 'post/' . $slug;
        smackpress_ok(['post_id' => $new_id, 'slug' => $slug, 'url' => $post_url]);
    }
}

// =====================================================================
// ROUTE: GET smackpress/posts/{id}
// =====================================================================
if (preg_match('#^posts/(\d+)$#', $sub, $m) && $method === 'GET') {
    $post_id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id,title,slug,status,created_at,featured_asset_id FROM snap_posts WHERE id=? AND post_type='longform'");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) smackpress_error(404, 'Post not found.');
    $post['url'] = $base_url . 'post/' . $post['slug'];
    smackpress_ok(['post' => $post]);
}

// =====================================================================
// ROUTE: GET smackpress/categories
// =====================================================================
if ($sub === 'categories' && $method === 'GET') {
    $cats = $pdo->query("SELECT id, name FROM snap_cats ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    smackpress_ok(['categories' => $cats]);
}

// =====================================================================
// ROUTE: POST smackpress/mosaics
// =====================================================================
if ($sub === 'mosaics' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');

    $title     = trim($body['title'] ?? 'Untitled Mosaic');
    $asset_ids = array_map('intval', $body['asset_ids'] ?? []);
    $gap       = max(0, min(20, (int)($body['gap'] ?? 4)));

    if (empty($asset_ids)) smackpress_error(422, 'asset_ids required.');

    $stmt = $pdo->prepare("INSERT INTO snap_mosaics (title, asset_ids, gap) VALUES (?, ?, ?)");
    $stmt->execute([$title, json_encode($asset_ids), $gap]);
    $mosaic_id = (int)$pdo->lastInsertId();
    smackpress_ok(['mosaic_id' => $mosaic_id, 'shortcode' => '[mosaic:' . $mosaic_id . ']']);
}

// =====================================================================
// ROUTE: POST smackpress/pages — create or update a static page (snap_pages)
// =====================================================================
if ($sub === 'pages' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');

    $page_id     = !empty($body['page_id']) ? (int)$body['page_id'] : null;
    $title       = trim($body['title'] ?? '');
    $slug        = trim($body['slug'] ?? '');
    $raw_content = $body['content'] ?? $body['content_raw'] ?? '';

    // status/is_active → snap_pages.is_active. Default ACTIVE: the page editor
    // (smack-pages.php) has no reactivate toggle, so an inactive page created
    // here could not be switched on from admin. Callers may still force
    // is_active=0 explicitly if they want it hidden.
    if (isset($body['is_active'])) {
        $is_active = $body['is_active'] ? 1 : 0;
    } elseif (isset($body['status'])) {
        $is_active = in_array($body['status'], ['published','publish','active'], true) ? 1 : 0;
    } else {
        $is_active = 1;
    }

    $image_asset  = trim($body['image_asset'] ?? '');
    $raw_size     = $body['image_size']  ?? 'full';
    $raw_align    = $body['image_align'] ?? 'center';
    $image_size   = in_array($raw_size,  ['full','medium','small'], true) ? $raw_size  : 'full';
    $image_align  = in_array($raw_align, ['center','left','right'],  true) ? $raw_align : 'center';
    $image_shadow = !empty($body['image_shadow']) ? 1 : 0;
    $menu_order   = (int)($body['menu_order'] ?? 0);

    if ($title === '') smackpress_error(422, 'Title is required.');

    $slug = $slug !== '' ? long_slugify($slug) : long_slugify($title);
    // Sanitise imported HTML (strip WP-plugin scripts/styles/iframes/handlers)
    // before it is ever stored — this content renders on the public site.
    $content_html = smackpress_sanitize_html(smack_autop_long($raw_content));

    if ($page_id) {
        // UPDATE
        $chk = $pdo->prepare("SELECT id FROM snap_pages WHERE id = ?");
        $chk->execute([$page_id]);
        if (!$chk->fetch()) smackpress_error(404, 'Page not found.');

        $pdo->prepare(
            "UPDATE snap_pages SET title=?, slug=?, content=?, image_asset=?, image_size=?, image_align=?, image_shadow=?, is_active=?, menu_order=? WHERE id=?"
        )->execute([$title, $slug, $content_html, $image_asset, $image_size, $image_align, $image_shadow, $is_active, $menu_order, $page_id]);
        $pid = $page_id;
    } else {
        // INSERT — ensure unique slug (snap_pages.slug is UNIQUE)
        $base_slug = $slug; $n = 0;
        while (true) {
            $c = $pdo->prepare("SELECT id FROM snap_pages WHERE slug = ?");
            $c->execute([$slug]);
            if (!$c->fetch()) break;
            $slug = $base_slug . '-' . (++$n);
        }
        $pdo->prepare(
            "INSERT INTO snap_pages (title, slug, content, image_asset, image_size, image_align, image_shadow, is_active, menu_order) VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$title, $slug, $content_html, $image_asset, $image_size, $image_align, $image_shadow, $is_active, $menu_order]);
        $pid = (int)$pdo->lastInsertId();
    }

    $page_url = $base_url . 'page.php?slug=' . rawurlencode($slug);
    smackpress_ok(['page_id' => $pid, 'slug' => $slug, 'url' => $page_url, 'is_active' => $is_active]);
}

// =====================================================================
// FALLBACK
// =====================================================================
smackpress_error(404, 'Unknown SMACKPRESS API endpoint.');
// ===== SNAPSMACK EOF =====
