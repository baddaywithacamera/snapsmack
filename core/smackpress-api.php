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
 *   GET    smackpress/posts            — list longform posts for desktop editing
 *   POST   smackpress/posts            — create or update longform post
 *   GET    smackpress/posts/{id}       — read back a post
 *   GET    smackpress/categories       — list all categories
 *   GET    smackpress/mosaics          — report available mosaic editing features
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
require_once __DIR__ . '/signature-contract.php';  // the two-variant signature rules

function smackpress_ensure_key_type(PDO $pdo): void {
    // Defensive: add key_type column if this is a pre-SmackPress install
    try {
        $pdo->query("SELECT key_type FROM snap_ohsnap_keys LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN key_type VARCHAR(20) NOT NULL DEFAULT 'ohsnap' AFTER label");
    }
}

function smackpress_auth(PDO $pdo, string $key_type = 'smackpress'): ?array {
    smackpress_ensure_key_type($pdo);
    if (!in_array($key_type, ['smackpress', 'bloggerflogger'], true)) return null;
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return null;
    }
    $hash = hash('sha256', $m[1]);
    // SECAUDIT 039 (sweep): enforce key EXPIRY — this handler checked only
    // is_active, so a lapsed SmackPress key kept working. Matches the pattern in
    // core/api-auth.php. NULL expires_at = legacy key. Falls back if absent.
    try {
        $stmt = $pdo->prepare("
            SELECT id,user_id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type = '{$key_type}'
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$hash]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("
            SELECT id,user_id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type = '{$key_type}'
            LIMIT 1
        ");
        $stmt->execute([$hash]);
    }
    $row = $stmt->fetch();
    if (!$row) return null;
    $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    return ['key_id' => (int)$row['id'], 'user_id' => (int)($row['user_id'] ?? 0)];
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

/** How this API refuses a signature field that is none of the four states. */
function smackpress_signature_refuse(string $message): void {
    smackpress_error(422, $message);
}

$route = $_GET['route'] ?? '';
$blogger_flogger = str_starts_with($route, 'bloggerflogger');
$key_type = $blogger_flogger ? 'bloggerflogger' : 'smackpress';

$smackpress_actor = smackpress_auth($pdo, $key_type);
if (!$smackpress_actor) {
    smackpress_error(401, 'Invalid or missing API key.');
}

// --- ROUTE PARSING ---
// Strip the authenticated tool prefix. The route implementations below are a
// shared destination-domain surface, not shared authorization.
$sub = preg_replace('#^(smackpress|bloggerflogger)/?#', '', $route);
$method = $_SERVER['REQUEST_METHOD'];

// Require the longform helper functions from smack-post-long.php without
// rendering its HTML — include only if not already loaded.
if (!function_exists('smack_autop_long')) {
    // Inline minimal autop — mirrors smack-post-long.php logic
    function smack_autop_long(string $text): string {
        if (trim($text) === '') return '';
        // Imported block HTML may legitimately begin with a SnapSmack shortcode
        // (most commonly a page hero image) before its first paragraph.  Treat
        // that as authored HTML too; otherwise the paragraph/list markup after
        // the shortcode is escaped and printed literally on the public page.
        if (preg_match(
            '/^\s*(?:\[(?:img|mosaic|spacer):[^\]]+\]\s*)*'
            . '<(?:p|h[1-6]|ul|ol|blockquote|figure|div|section|article|header|main|aside|nav)\b/i',
            $text
        )) return $text;
        $text = preg_replace('/(\[img:[^\]]+\])\s*\n+/', '$1', $text);
        $text = preg_replace('/(\[mosaic:\d+(?:\s+width=\d{2,4})?\])\s*\n+/', '$1', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $chunks = preg_split('/\n\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chunks as &$chunk) {
            $trimmed = trim($chunk);
            if (preg_match('/^\[img:\s*\d+(?:\s*\|[^\]]*)*\]$/', $trimmed)) {
                $chunk = $trimmed;
            } elseif (preg_match('/^\[mosaic:\d+(?:\s+width=\d{2,4})?\]$/', $trimmed)) {
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

if (!function_exists('smackpress_reject_migration_residue')) {
    function smackpress_reject_migration_residue(string $value): void {
        $forbidden = [
            '#<\?(?:php|=)?#i',
            '#<!--\s*/?wp:#i',
            '#\[(?:gallery|caption|embed|audio|video|playlist|wp_[a-z0-9_-]+)\b#i',
            '#(?:^|["\'\s(])(?:https?:)?//[^"\'\s)]*/wp-(?:content|includes|admin)(?:/|\b)#i',
            '#(?:^|["\'\s(/])(?:wp-login\.php|xmlrpc\.php)(?:[?"\'\s)]|$)#i',
        ];
        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $value)) {
                smackpress_error(422, 'Imported content still contains WordPress executable or directory syntax.');
            }
        }
    }
}

if (!function_exists('smackpress_import_date')) {
    /**
     * Does this site have the portfolio designation columns yet? (SS-1185)
     *
     * ASKED WITH A SELECT, NOT INFORMATION_SCHEMA, and that is deliberate: the
     * INFORMATION_SCHEMA form is MySQL-only and reported every column absent on
     * any other engine, which is the exact mistake this codebase already paid
     * for once in the public read path. "SELECT <cols> ... WHERE 0" asks the
     * engine the same question in a way every engine answers.
     *
     * Answered once per request: an import posts many times and the schema
     * cannot change underneath it mid-run.
     */
    function smackpress_portfolio_ready(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $pdo->query('SELECT portfolio_post, primary_album_id, primary_category_id
                           FROM snap_posts WHERE 0');
            $ready = true;
        } catch (\Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    function smackpress_import_date(PDO $pdo, $value): ?string {
        $value = trim((string)$value);
        if ($value === '') return null;
        $tz_name = (string)($pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key='timezone' LIMIT 1"
        )->fetchColumn() ?: 'UTC');
        try { $tz = new DateTimeZone($tz_name); }
        catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        try {
            // An offset/Z in the value defines the instant. Legacy naive values
            // are interpreted in the destination site's configured timezone.
            $source = new DateTimeImmutable($value, $tz);
            return $source->setTimezone($tz)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            smackpress_error(422, 'Publication date is invalid.');
        }
        return null;
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

// BLOGGER FLOGGER is additive, resumable, and independently authenticated. Its
// destination receipts live on the site so losing a desktop checkpoint cannot
// duplicate an archive on the next run.
if ($blogger_flogger) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_import_map (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      tool_type VARCHAR(32) NOT NULL,
      source_site_id VARCHAR(255) NOT NULL,
      source_type VARCHAR(32) NOT NULL,
      source_id VARCHAR(500) NOT NULL,
      source_checksum CHAR(64) DEFAULT NULL,
      destination_type VARCHAR(32) NOT NULL,
      destination_id BIGINT UNSIGNED NOT NULL,
      imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      verified_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_import_source (tool_type,source_site_id(100),source_type,source_id(191)),
      KEY idx_import_destination (destination_type,destination_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function bloggerflogger_source(array $body): array {
    return [trim((string)($body['source_site_id'] ?? '')),
            trim((string)($body['source_type'] ?? '')),
            trim((string)($body['source_id'] ?? '')),
            preg_match('/^[a-f0-9]{64}$/i', (string)($body['source_checksum'] ?? ''))
                ? strtolower((string)$body['source_checksum']) : null];
}

function bloggerflogger_lookup(PDO $pdo, string $site, string $type, string $id): ?array {
    if ($site === '' || $type === '' || $id === '') return null;
    $q = $pdo->prepare("SELECT destination_type,destination_id,source_checksum,verified_at
                        FROM snap_import_map WHERE tool_type='bloggerflogger'
                          AND source_site_id=? AND source_type=? AND source_id=? LIMIT 1");
    $q->execute([$site, $type, $id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bloggerflogger_record(PDO $pdo, string $site, string $type, string $id,
                               ?string $checksum, string $dest_type, int $dest_id): void {
    if ($site === '' || $type === '' || $id === '' || $dest_id <= 0) {
        smackpress_error(422, 'Source identity is required for a resumable import.');
    }
    $q = $pdo->prepare("INSERT INTO snap_import_map
        (tool_type,source_site_id,source_type,source_id,source_checksum,destination_type,destination_id,verified_at)
        VALUES ('bloggerflogger',?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE verified_at=NOW()");
    $q->execute([$site, $type, $id, $checksum, $dest_type, $dest_id]);
}

function bloggerflogger_begin(PDO $pdo, array $source): void {
    if ($source[0] === '' || $source[1] === '' || $source[2] === '') {
        smackpress_error(422, 'Source identity is required for a resumable import.');
    }
    // Serialize one source record across clients, then make the destination write
    // and its receipt atomic. Connection loss releases the advisory lock.
    $lock_name = 'bf:' . hash('sha256', $source[0] . "\n" . $source[1] . "\n" . $source[2]);
    $q = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $q->execute([$lock_name]);
    if ((int)$q->fetchColumn() !== 1) smackpress_error(409, 'This source item is already being imported. Retry shortly.');
    $pdo->beginTransaction();
}

function bloggerflogger_finish(PDO $pdo): void {
    if ($pdo->inTransaction()) $pdo->commit();
}

if ($blogger_flogger) {
    $mode = (string)($settings['site_mode'] ?? '');
    $post_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn();
    $image_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn();
    $content_count = max($post_count, $image_count);
    $authorized_until = (int)($settings['import_authorized_until'] ?? 0);
    $authorized = $content_count <= 5 || $authorized_until > time();
    if ($sub === 'preflight' && $method === 'GET') {
        smackpress_ok(['site_mode' => $mode, 'compatible' => $mode === 'smacktalk',
            'content_count' => $content_count, 'import_authorized' => $authorized,
            'import_authorized_until' => $authorized_until]);
    }
    if ($mode !== 'smacktalk') {
        smackpress_error(409, 'BLOGGER FLOGGER imports into SMACKTALK sites only.');
    }
    if ($method === 'POST' && !$authorized) {
        smackpress_error(403, 'This populated site needs a one-hour import authorization from API Keys.');
    }
    if ($sub === 'import-map' && $method === 'GET') {
        $found = bloggerflogger_lookup($pdo, trim((string)($_GET['source_site_id'] ?? '')),
            trim((string)($_GET['source_type'] ?? '')), trim((string)($_GET['source_id'] ?? '')));
        smackpress_ok(['found' => (bool)$found, 'mapping' => $found]);
    }
}

// SMACKTHEMUP fail-closed (spec §5.2): it publishes only through SNAP SLAPPER's
// scoped publishing path, never the SMACKPRESS / COLD SNAP longform API. Refuse
// this whole API on a smackthemup site so no other tool's key can create or
// mutate content there. The scoped SLAPPER contract (smackthemup.publish) lands
// with the desktop slice; until then nothing legitimately writes here.
if (($settings['site_mode'] ?? '') === 'smackthemup') {
    smackpress_error(409, 'SMACKTHEMUP publishes from SNAP SLAPPER (desktop) only — this API is not available for this mode.');
}

// =====================================================================
// ROUTE: POST smackpress/media/upload
// =====================================================================
if (in_array($sub, ['media/upload', 'media'], true) && $method === 'POST') {
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
    // Per-image accessibility ALT — travels with the image (snap_images.img_alt).
    // snap_ingest_image sanitizes via snap_sanitize_alt.
    if (isset($_POST['alt'])) {
        $upload_opts['alt'] = (string)$_POST['alt'];
    }
    $source = $blogger_flogger ? bloggerflogger_source($_POST) : ['', '', '', null];
    if ($blogger_flogger) bloggerflogger_begin($pdo, $source);
    if ($blogger_flogger && ($prior = bloggerflogger_lookup($pdo, $source[0], $source[1], $source[2]))) {
        bloggerflogger_finish($pdo);
        smackpress_ok(['image_id' => (int)$prior['destination_id'], 'asset_id' => (int)$prior['destination_id'],
            'already_imported' => true]);
    }
    $ingest = snap_ingest_image($pdo, $settings, $_FILES['file'], $upload_opts);
    if (empty($ingest['ok'])) {
        smackpress_error(500, $ingest['error'] ?? 'Image ingest failed.');
    }
    $image_id = (int) $ingest['id'];
    if ($blogger_flogger) {
        bloggerflogger_record($pdo, $source[0], $source[1], $source[2], $source[3], 'media', $image_id);
        bloggerflogger_finish($pdo);
    }
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

/**
 * Put an imported post's photographs into its albums and categories. (SS-1185)
 *
 * WHY AN IMPORTED PORTFOLIO LOOKED EMPTY. SMACKPRESS mapped the POST into
 * albums and categories (snap_post_album_map / snap_post_cat_map) and stopped
 * there. The archive, the album covers and the category covers are all
 * IMAGE-keyed: they read snap_image_album_map and snap_image_cat_map. Nothing
 * in the import path ever wrote either, so a migrated site could have every
 * post in place and an archive with nothing in it. The sibling importers do
 * this already -- core/flkrfckr-api.php:577 is the shape copied here rather
 * than invented.
 *
 * OPT-IN, DEFAULT OFF. A longform essay's inline pictures are not always
 * gallery photographs, and silently filing every imported image into the
 * owner's archive is not a decision an importer should make for him. The
 * caller asks for it with map_images_to_taxonomy.
 *
 * NO "INSERT IGNORE". That is MySQL-only, and this codebase has already paid
 * once for MySQL-only SQL in a path that had to run on another engine: it
 * cannot be exercised in a test here, which is how the fault survived. An
 * existence check and a plain insert say the same thing on every engine and
 * can actually be proved.
 *
 * Returns counts so the caller can report what happened instead of assuming.
 */
function smackpress_map_images_to_taxonomy(PDO $pdo, array $image_ids,
                                           array $album_ids, array $cat_ids): array {
    $added = ['albums' => 0, 'categories' => 0];
    if (!$image_ids) return $added;

    foreach ([
        ['snap_image_album_map', 'album_id', $album_ids, 'albums'],
        ['snap_image_cat_map',   'cat_id',   $cat_ids,   'categories'],
    ] as [$table, $column, $ids, $key]) {
        if (!$ids) continue;
        $have = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE image_id = ? AND `{$column}` = ? LIMIT 1");
        $put  = $pdo->prepare("INSERT INTO `{$table}` (image_id, `{$column}`) VALUES (?, ?)");
        foreach ($image_ids as $image_id) {
            $image_id = (int)$image_id;
            if ($image_id <= 0) continue;
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id <= 0) continue;
                $have->execute([$image_id, $id]);
                if ($have->fetchColumn()) continue;      // already mapped
                $put->execute([$image_id, $id]);
                $added[$key]++;
            }
        }
    }
    return $added;
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
// ROUTE: GET smackpress/posts — desktop post picker
// =====================================================================
if ($sub === 'posts' && $method === 'GET') {
    $columns = 'id,title,slug,status,created_at,updated_at,featured_image_id';

    // ?slug=<exact> — ASK ABOUT ONE SLUG INSTEAD OF SEARCHING A LIST.
    //
    // COLD SNAP decides update-or-create by looking for its draft's slug among
    // the posts this endpoint returns, and the list is capped at 500. On a site
    // with more than 500 longform posts the answer was simply not in the page
    // it received, so an existing post could be missed and a duplicate created
    // -- and the client could not tell a real "no such slug" from "past the
    // cap". There is no offset either, so paging was not a way out.
    //
    // EXACT, not LIKE: the question is whether this slug is taken, and a
    // prefix match would answer about a different post. The slug is validated
    // rather than trusted, so a junk value is a 400 instead of a silent empty
    // answer that reads as "free".
    $slug_query = trim((string)($_GET['slug'] ?? ''));
    if ($slug_query !== '') {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,254}$/i', $slug_query)) {
            smackpress_error(400, 'slug must be a plain post slug: letters, '
                . 'digits, hyphen or underscore, up to 255 characters.');
        }
        $stmt = $pdo->prepare("SELECT {$columns} FROM snap_posts
                                WHERE post_type='longform' AND slug = ? LIMIT 1");
        $stmt->execute([$slug_query]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $posts = [];
        if ($row) {
            $row['id'] = (int)$row['id'];
            $row['featured_image_id'] = (int)($row['featured_image_id'] ?? 0);
            $posts[] = $row;
        }
        // 'exact' tells the client this answer is complete for that slug, so it
        // never has to wonder whether the cap hid something. An empty list here
        // means the slug is genuinely free.
        smackpress_ok(['posts' => $posts, 'exact' => true, 'slug' => $slug_query]);
    }

    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $stmt = $pdo->prepare("SELECT {$columns}
                            FROM snap_posts WHERE post_type='longform'
                        ORDER BY updated_at DESC, id DESC LIMIT " . $limit);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($posts as &$post) {
        $post['id'] = (int)$post['id'];
        $post['featured_image_id'] = (int)($post['featured_image_id'] ?? 0);
    }
    unset($post);
    // 'total' so a client can STOP GUESSING. Without it the only signal that
    // the list might be short of the truth was "I got exactly 500 back", which
    // is also what a site with exactly 500 posts looks like. Added with the
    // slug lookup because the same request -- is my post already here? -- is
    // what both are for.
    $total = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts WHERE post_type='longform'")
        ->fetchColumn();
    smackpress_ok(['posts' => $posts, 'total' => $total, 'limit' => $limit,
        'truncated' => $total > count($posts)]);
}

// =====================================================================
// ROUTE: POST smackpress/posts — create or update longform post
// =====================================================================
if ($sub === 'posts' && $method === 'POST') {
    $actor_user_id = (int)($smackpress_actor['user_id'] ?? 0);
    if ($actor_user_id < 1) {
        smackpress_error(403, 'This import key is not bound to a system user. Regenerate it with a default author.');
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');

    $source = $blogger_flogger ? bloggerflogger_source($body) : ['', '', '', null];
    if ($blogger_flogger && !empty($body['post_id'])) {
        smackpress_error(403, 'BLOGGER FLOGGER is additive and cannot update an existing post.');
    }
    if ($blogger_flogger) bloggerflogger_begin($pdo, $source);
    if ($blogger_flogger && ($prior = bloggerflogger_lookup($pdo, $source[0], $source[1], $source[2]))) {
        bloggerflogger_finish($pdo);
        smackpress_ok(['post_id' => (int)$prior['destination_id'], 'already_imported' => true]);
    }

    $post_id        = !empty($body['post_id']) ? (int)$body['post_id'] : null;
    $title          = trim($body['title'] ?? '');
    $slug           = trim($body['slug'] ?? '');
    $raw_content    = $body['content'] ?? $body['content_raw'] ?? '';
    $has_colophon   = array_key_exists('colophon', $body);
    $raw_colophon   = $body['colophon'] ?? '';
    $status         = in_array($body['status'] ?? '', ['published','draft']) ? $body['status'] : 'draft';
    $allow_comments = (int)($body['allow_comments'] ?? 0);
    // Cover: prefer featured_image_id (Gallery / snap_images). Legacy callers may
    // send featured_asset_id — since media/upload now ingests to the Gallery, that
    // id is also a snap_images id, so we treat it as the cover too.
    $featured_image = !empty($body['featured_image_id']) ? (int)$body['featured_image_id']
                    : (!empty($body['featured_asset_id']) ? (int)$body['featured_asset_id'] : null);
    // ── SIGNATURE, FOUR STATES ───────────────────────────────────────────
    // The two-variant contract: signature_image_id (legacy, follows light),
    // signature_light_image_id and signature_dark_image_id. Each field has
    // four states and they are NOT interchangeable:
    //
    //   omitted  preserve whatever is stored (a re-pull must not clear one)
    //   null     inherit the blog default
    //   0        explicit opt-out: this post carries no signature
    //   id       that image
    //
    // THE OLD LINE COLLAPSED TWO OF THEM. It read
    //     !empty($body['signature_image_id']) ? (int)... : null
    // so a client sending 0 got null. That was harmless while null meant "no
    // signature", and it is the opposite of the author's intent the moment
    // null means "inherit": asking for no signature would have produced the
    // blog's. The rules live in core/signature-contract.php, where a test can
    // run them -- this file reads a route and authenticates at the top level,
    // so nothing defined in it can be required by the suite.
    $signature_fields    = snap_signature_read($body, 'smackpress_signature_refuse');
    $has_signature_image = $signature_fields[SNAP_SIGNATURE_LEGACY_FIELD]['sent'];
    $signature_image     = $signature_fields[SNAP_SIGNATURE_LEGACY_FIELD]['value'];
    $manual_tags    = trim($body['tags'] ?? '');
    $selected_cats  = array_map('intval', $body['cat_ids'] ?? []);
    if (empty($selected_cats) && !empty($body['category_id'])) {
        $selected_cats = [(int)$body['category_id']];   // single-category alias
    }
    $selected_albums= array_map('intval', $body['album_ids'] ?? []);
    $custom_date    = smackpress_import_date($pdo, $body['created_at'] ?? ($body['date'] ?? null));

    // ── PORTFOLIO DESIGNATION (SS-1185) ──────────────────────────────────
    // What turns an imported post into /<album>/<category>/<slug>. Without it
    // a migrated portfolio page arrives undesignated and keeps a flat /slug,
    // which is what the whole three-segment URL scheme was built to avoid.
    //
    // ONLY WHAT THE CALLER SENT IS TOUCHED. Each field is keyed on
    // array_key_exists, not on being truthy, so re-pulling a post to fix its
    // text cannot silently clear a designation somebody set in the admin --
    // and sending an explicit null CAN clear it on purpose. Same shape the
    // colophon and signature image already use here.
    $has_portfolio_flag = array_key_exists('portfolio_post', $body);
    $has_primary_album  = array_key_exists('primary_album_id', $body);
    $has_primary_cat    = array_key_exists('primary_category_id', $body);
    $portfolio_flag     = !empty($body['portfolio_post']) ? 1 : 0;
    $primary_album      = !empty($body['primary_album_id'])    ? (int)$body['primary_album_id']    : null;
    $primary_cat        = !empty($body['primary_category_id']) ? (int)$body['primary_category_id'] : null;
    $wants_portfolio    = $has_portfolio_flag || $has_primary_album || $has_primary_cat;

    if ($wants_portfolio) {
        // REFUSED LOUDLY ON AN OLD SCHEMA RATHER THAN DROPPED QUIETLY. A site
        // that has not run the updater has no such columns. Accepting the post
        // and discarding the designation would import a whole portfolio that
        // silently has no portfolio addresses, and nobody would find out until
        // they clicked one.
        if (!smackpress_portfolio_ready($pdo)) {
            smackpress_error(409, 'This site does not have the portfolio columns yet. '
                . 'Run the updater on it, then import again. Nothing was saved.');
        }
        // AN ID THAT DOES NOT EXIST IS REFUSED, not stored. A primary album or
        // category pointing at nothing produces a post whose canonical path
        // cannot be built, so the resolver falls back to the flat slug and the
        // designation is a lie told quietly.
        if ($primary_album !== null) {
            $check = $pdo->prepare('SELECT id FROM snap_albums WHERE id = ? LIMIT 1');
            $check->execute([$primary_album]);
            if (!$check->fetchColumn()) {
                smackpress_error(422, 'primary_album_id ' . $primary_album . ' is not an album on this site.');
            }
        }
        if ($primary_cat !== null) {
            $check = $pdo->prepare('SELECT id FROM snap_categories WHERE id = ? LIMIT 1');
            $check->execute([$primary_cat]);
            if (!$check->fetchColumn()) {
                smackpress_error(422, 'primary_category_id ' . $primary_cat . ' is not a category on this site.');
            }
        }
    }

    if ($title === '') smackpress_error(422, 'Title is required.');

    $slug = $slug !== '' ? long_slugify($slug) : long_slugify($title);

    // Refuse migration residue before sanitising it. Sanitising remains the XSS
    // backstop; rejection makes a bypassed/modified desktop client fail closed.
    smackpress_reject_migration_residue((string)$raw_content);
    smackpress_reject_migration_residue((string)$raw_colophon);

    // Sanitise imported HTML (strip WP-plugin scripts/styles/iframes/handlers)
    // before it is ever stored — this content renders on the public site.
    $content_html = smackpress_sanitize_html(smack_autop_long($raw_content));
    $colophon_html = $raw_colophon !== ''
        ? smackpress_sanitize_html(smack_autop_long($raw_colophon))
        : null;

    // Every signature id the caller sent must be a real image. An id that is
    // not there is refused, not stored: a stored id pointing at nothing renders
    // as a missing picture on a published page.
    foreach (snap_signature_ids_to_verify($signature_fields) as $field_name => $image_id) {
        $image_check = $pdo->prepare("SELECT id FROM snap_images WHERE id=? LIMIT 1");
        $image_check->execute([$image_id]);
        if (!$image_check->fetchColumn()) {
            smackpress_error(422, "Signature image for {$field_name} was not found.");
        }
    }

    $signature_update = snap_signature_write_sql($signature_fields, false);
    $signature_sql    = $signature_update['sql'];
    $signature_params = $signature_update['params'];

    if ($post_id) {
        // UPDATE
        $stmt = $pdo->prepare("SELECT id,colophon,signature_image_id FROM snap_posts WHERE id = ? AND post_type = 'longform'");
        $stmt->execute([$post_id]);
        $existing_post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing_post) smackpress_error(404, 'Post not found.');
        if (!$has_colophon) $colophon_html = $existing_post['colophon'];
        if (!$has_signature_image) $signature_image = $existing_post['signature_image_id'];

        // Only the designation fields the caller actually sent are written, so
        // a re-pull of the text leaves an existing designation alone. (SS-1185)
        $portfolio_sql = '';
        $portfolio_params = [];
        if ($wants_portfolio) {
            if ($has_portfolio_flag) { $portfolio_sql .= ', portfolio_post=?';      $portfolio_params[] = $portfolio_flag; }
            if ($has_primary_album)  { $portfolio_sql .= ', primary_album_id=?';    $portfolio_params[] = $primary_album; }
            if ($has_primary_cat)    { $portfolio_sql .= ', primary_category_id=?'; $portfolio_params[] = $primary_cat; }
        }
        $sql = "UPDATE snap_posts SET title=?, slug=?, content=?, colophon=?, signature_image_id=?, status=?, allow_comments=?, featured_image_id=?, user_id=?"
             . $signature_sql
             . $portfolio_sql
             . ($custom_date ? ", created_at=?" : "")
             . " WHERE id=? AND post_type='longform'";
        $params = [$title, $slug, $content_html, $colophon_html, $signature_image, $status, $allow_comments, $featured_image, $actor_user_id];
        foreach ($signature_params as $p) $params[] = $p;
        foreach ($portfolio_params as $p) $params[] = $p;
        if ($custom_date) $params[] = $custom_date;
        $params[] = $post_id;
        $pdo->prepare($sql)->execute($params);

        $pdo->prepare("DELETE FROM snap_post_cat_map WHERE post_id=?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM snap_post_album_map WHERE post_id=?")->execute([$post_id]);
        foreach ($selected_cats   as $cid) $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id,cat_id) VALUES(?,?)")->execute([$post_id,$cid]);
        foreach ($selected_albums as $aid) $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id,album_id) VALUES(?,?)")->execute([$post_id,$aid]);
        snap_sync_tags($pdo, $post_id, $title . ' ' . $manual_tags);
        smackpress_save_bucket($pdo, $post_id, $body, $content_html, $featured_image);

        // Photographs into the post's albums and categories, when the caller
        // asked for it. The archive and the album/category covers are
        // image-keyed, so without this an imported portfolio has every post in
        // place and an empty archive. (SS-1185)
        $image_map = ['albums' => 0, 'categories' => 0];
        if (!empty($body['map_images_to_taxonomy'])) {
            require_once __DIR__ . '/bucket.php';
            $image_map = smackpress_map_images_to_taxonomy(
                $pdo, snap_bucket_ids($pdo, $post_id), $selected_albums, $selected_cats);
        }

        $post_url = $base_url . 'post/' . $slug;
        smackpress_ok(['post_id' => $post_id, 'slug' => $slug, 'url' => $post_url,
                       'images_mapped' => $image_map]);
    } else {
        // INSERT — ensure unique slug
        $base_slug = $slug; $n = 0;
        while (true) {
            $check = $pdo->prepare("SELECT id FROM snap_posts WHERE slug=?");
            $check->execute([$slug]);
            if (!$check->fetch()) break;
            $slug = $base_slug . '-' . (++$n);
        }
        // The designation travels with the insert rather than a follow-up
        // UPDATE, so a post is never briefly live without it. (SS-1185)
        $portfolio_cols = '';
        $portfolio_marks = '';
        $portfolio_params = [];
        if ($wants_portfolio) {
            if ($has_portfolio_flag) { $portfolio_cols .= ',portfolio_post';      $portfolio_marks .= ',?'; $portfolio_params[] = $portfolio_flag; }
            if ($has_primary_album)  { $portfolio_cols .= ',primary_album_id';    $portfolio_marks .= ',?'; $portfolio_params[] = $primary_album; }
            if ($has_primary_cat)    { $portfolio_cols .= ',primary_category_id'; $portfolio_marks .= ',?'; $portfolio_params[] = $primary_cat; }
        }
        // Same for the two signature variants: only what the caller sent is
        // written, so an omitted field takes the column default rather than a
        // value this code invented.
        $signature_insert        = snap_signature_write_sql($signature_fields, true);
        $signature_cols          = $signature_insert['sql'];
        $signature_marks         = $signature_insert['marks'];
        $signature_insert_params = $signature_insert['params'];
        $sql = "INSERT INTO snap_posts (title,slug,content,colophon,signature_image_id,post_type,status,allow_comments,featured_image_id,user_id"
             . $signature_cols
             . $portfolio_cols
             . ($custom_date ? ",created_at" : "")
             . ") VALUES(?,?,?,?,?,'longform',?,?,?,?"
             . $signature_marks
             . $portfolio_marks
             . ($custom_date ? ",?" : "") . ")";
        $params = [$title, $slug, $content_html, $colophon_html, $signature_image, $status, $allow_comments, $featured_image, $actor_user_id];
        foreach ($signature_insert_params as $p) $params[] = $p;
        foreach ($portfolio_params as $p) $params[] = $p;
        if ($custom_date) $params[] = $custom_date;
        $pdo->prepare($sql)->execute($params);
        $new_id = (int)$pdo->lastInsertId();

        foreach ($selected_cats   as $cid) $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id,cat_id) VALUES(?,?)")->execute([$new_id,$cid]);
        foreach ($selected_albums as $aid) $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id,album_id) VALUES(?,?)")->execute([$new_id,$aid]);
        snap_sync_tags($pdo, $new_id, $title . ' ' . $manual_tags);
        smackpress_save_bucket($pdo, $new_id, $body, $content_html, $featured_image);

        if ($blogger_flogger) {
            bloggerflogger_record($pdo, $source[0], $source[1], $source[2], $source[3], 'post', $new_id);
            bloggerflogger_finish($pdo);
        }

        // Photographs into the post's albums and categories, when the caller
        // asked for it. The archive and the album/category covers are
        // image-keyed, so without this an imported portfolio has every post in
        // place and an empty archive. (SS-1185)
        $image_map = ['albums' => 0, 'categories' => 0];
        if (!empty($body['map_images_to_taxonomy'])) {
            require_once __DIR__ . '/bucket.php';
            $image_map = smackpress_map_images_to_taxonomy(
                $pdo, snap_bucket_ids($pdo, $new_id), $selected_albums, $selected_cats);
        }

        $post_url = $base_url . 'post/' . $slug;
        smackpress_ok(['post_id' => $new_id, 'slug' => $slug, 'url' => $post_url,
                       'images_mapped' => $image_map]);
    }
}

// =====================================================================
// ROUTE: GET smackpress/posts/{id}
// =====================================================================
if (preg_match('#^posts/(\d+)$#', $sub, $m) && $method === 'GET') {
    $post_id = (int)$m[1];
    $stmt = $pdo->prepare("SELECT id,title,slug,content,colophon,signature_image_id,
                                  signature_light_image_id,signature_dark_image_id,
                                  status,allow_comments,created_at,
                                  featured_image_id
                             FROM snap_posts WHERE id=? AND post_type='longform'");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) smackpress_error(404, 'Post not found.');
    require_once __DIR__ . '/bucket.php';
    $bucket_ids = snap_bucket_ids($pdo, $post_id);
    if (!$bucket_ids && !empty($post['featured_image_id'])) {
        $bucket_ids = [(int)$post['featured_image_id']];
    }
    $post['bucket'] = [];
    if ($bucket_ids) {
        $ph = implode(',', array_fill(0, count($bucket_ids), '?'));
        $images = $pdo->prepare("SELECT id,img_file,img_alt,img_title,img_width,img_height,
                                        img_thumb_square,img_thumb_aspect
                                   FROM snap_images WHERE id IN ($ph)");
        $images->execute($bucket_ids);
        $by_id = [];
        foreach ($images->fetchAll(PDO::FETCH_ASSOC) as $image) $by_id[(int)$image['id']] = $image;
        foreach ($bucket_ids as $image_id) {
            if (!isset($by_id[$image_id])) continue;
            $image = $by_id[$image_id];
            $file = ltrim(str_replace('\\', '/', (string)$image['img_file']), '/');
            $thumb = ltrim(str_replace('\\', '/', (string)($image['img_thumb_aspect'] ?: $image['img_thumb_square'])), '/');
            $image['id'] = (int)$image['id'];
            $image['width'] = (int)($image['img_width'] ?? 0);
            $image['height'] = (int)($image['img_height'] ?? 0);
            $image['url'] = rtrim($base_url, '/') . '/' . $file;
            $image['thumb_url'] = rtrim($base_url, '/') . '/' . ($thumb ?: $file);
            $post['bucket'][] = $image;
        }
    }
    $cats = $pdo->prepare("SELECT cat_id FROM snap_post_cat_map WHERE post_id=? ORDER BY cat_id");
    $cats->execute([$post_id]);
    $albums = $pdo->prepare("SELECT album_id FROM snap_post_album_map WHERE post_id=? ORDER BY album_id");
    $albums->execute([$post_id]);
    $post['cat_ids'] = array_map('intval', $cats->fetchAll(PDO::FETCH_COLUMN));
    $post['album_ids'] = array_map('intval', $albums->fetchAll(PDO::FETCH_COLUMN));
    $post['tags'] = [];
    if (!empty($post['featured_image_id'])) {
        $tags = $pdo->prepare("SELECT t.tag FROM snap_image_tags it JOIN snap_tags t ON t.id=it.tag_id
                                WHERE it.image_id=? ORDER BY t.tag");
        $tags->execute([(int)$post['featured_image_id']]);
        $post['tags'] = array_values(array_filter(array_map('strval', $tags->fetchAll(PDO::FETCH_COLUMN))));
    }
    $post['id'] = (int)$post['id'];
    $post['featured_image_id'] = (int)($post['featured_image_id'] ?? 0);
    $post['signature_image_id'] = (int)($post['signature_image_id'] ?? 0);
    // The two variants go back UNCAST -- see snap_signature_response(), which
    // is where that rule and the reason for it live.
    $post = array_merge($post, snap_signature_response($post));
    // And the client is told plainly that inherit resolves to nothing yet,
    // rather than being left to wonder why a signature never appears.
    // Whether "inherit" can resolve depends on THIS blog's settings, so they
    // are read rather than assumed. A client that is told inherit resolves
    // when it does not would show the author a choice that silently does
    // nothing.
    $sig_settings = [];
    try {
        $sig_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                            ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        $sig_settings = [];   // no settings table on an older install: not resolvable
    }
    $post['signature_inherit_resolvable'] = snap_signature_inherit_is_resolvable($sig_settings);
    $post['signature_default_light_image_id'] = snap_signature_default_for($sig_settings, 'light');
    $post['signature_default_dark_image_id']  = snap_signature_default_for($sig_settings, 'dark');
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
// ROUTE: GET / POST smackpress/mosaics
// =====================================================================
// THE MOSAIC RULES LIVE IN core/mosaic-contract.php, where a test can require
// them. They used to be inline here, which made every one of them
// unexecutable by the suite: this file authenticates at the top level, so
// including it runs the API. Same correction as the signature contract.
require_once __DIR__ . '/mosaic-contract.php';

// =====================================================================
// ROUTE: GET smackpress/mosaics/{id} — one mosaic, as the desktop needs it
// =====================================================================
// SS-1612. COLD SNAP could CREATE a mosaic on a site and could not ASK a site
// what one of its mosaics was, so Open published painted a placeholder bar
// reading "Site mosaic 753" instead of the photographs. The data and the read
// logic both already existed -- core/parser.php reads this row to render the
// shortcode on the public page -- and nothing exposed it to the desktop.
//
// PATH FORM, not ?id=, to match pages/{id} below. The house style for "one
// thing by its id" in this file is a path segment.
if (preg_match('#^mosaics/(\d+)$#', $sub, $m) && $method === 'GET') {
    $mosaic = smackpress_mosaic_read($pdo, (int)$m[1]);
    if (!$mosaic) smackpress_error(404, 'Mosaic not found.');

    // EVERY MEMBER, not just the ones in this post's bucket. A mosaic may hold
    // photographs the post does not, and the desktop cannot paint what it
    // cannot resolve. These are snap_images.id, the same ids the published
    // bucket is keyed by, so the client needs no translation.
    $images = [];
    if ($mosaic['asset_ids']) {
        $in = implode(',', array_fill(0, count($mosaic['asset_ids']), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, img_file, img_title, img_alt, img_width, img_height
             FROM snap_images WHERE id IN ({$in})"
        );
        $stmt->execute($mosaic['asset_ids']);
        $by_id = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $by_id[(int)$row['id']] = [
                'id'     => (int)$row['id'],
                'file'   => (string)$row['img_file'],
                'title'  => (string)($row['img_title'] ?? ''),
                'alt'    => (string)($row['img_alt'] ?? ''),
                'width'  => (int)($row['img_width'] ?? 0),
                'height' => (int)($row['img_height'] ?? 0),
            ];
        }
        // IN AUTHORED ORDER, and a MISSING member is reported rather than
        // skipped. A mosaic naming an image that is gone is a fault the owner
        // needs to see; silently returning fewer photographs than the mosaic
        // claims would have the desktop save a shorter mosaic than it opened.
        foreach ($mosaic['asset_ids'] as $id) {
            $images[] = $by_id[$id] ?? ['id' => $id, 'missing' => true];
        }
    }

    $refs = smackpress_mosaic_references($pdo, $mosaic['id']);
    smackpress_ok([
        'mosaic' => $mosaic,
        'images' => $images,
        'references' => [
            'posts'  => count($refs['posts']),
            'pages'  => count($refs['pages']),
            'post_ids' => $refs['posts'],
            'shared' => (count($refs['posts']) + count($refs['pages'])) > 1,
        ],
        'capabilities' => [
            'presentation'   => smackpress_mosaic_weights_available($pdo),
            'mosaic_mirrors' => smackpress_mosaic_mirrors_available($pdo),
        ],
    ]);
}

if ($sub === 'mosaics' && $method === 'GET') {
    smackpress_ok([
        'mosaic_weights' => smackpress_mosaic_weights_available($pdo),
        'mosaic_mirrors' => smackpress_mosaic_mirrors_available($pdo),
    ]);
}

if ($sub === 'mosaics' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');

    // SS-1185 LIVES IN smackpress_mosaic_validate() NOW, with the rest of the
    // rules: the shuffle flag belongs to the MOSAIC and not the post, because a
    // post may hold several mosaics and each is its own gallery; it defaults OFF
    // so a client that says nothing gets the authored order; and asset_ids is
    // stored exactly as sent either way, because shuffling is presentation and
    // never rewrites what was saved. Six of the galleries migrating from the old
    // site were owner-random in WordPress and the import had nowhere to put that.
    $def = smackpress_mosaic_validate($body, $pdo, function (int $code, string $message): void {
        smackpress_error($code, $message);
    });
    $title           = $def['title'];
    $asset_ids       = $def['asset_ids'];
    $gap             = $def['gap'];
    $layout          = $def['layout'];
    $gallery_shuffle = $def['gallery_shuffle'];
    $weights         = $def['weights'];
    $mirrors         = $def['mirrors'];

    // THE COLUMN LIST IS BUILT, NOT BRANCHED. This was three hardcoded INSERT
    // statements for the three combinations of weights and mirrors. Adding
    // focus_positions and emphasis as optional columns too would have made
    // twelve. One list, appended to under the same conditions, keeps every
    // refusal exactly where it was and cannot miss a combination.
    $columns = ['title', 'asset_ids', 'gap', 'layout', 'gallery_shuffle'];
    $values  = [$title, json_encode($asset_ids), $gap, $layout, $gallery_shuffle];

    if ($mirrors && !smackpress_mosaic_mirrors_available($pdo)) {
        smackpress_error(409, 'Update the site schema before publishing mirrored mosaic blocks.');
    }
    if ($weights && !smackpress_mosaic_weights_available($pdo)) {
        smackpress_error(409, 'Update the site schema before publishing resized mosaic tiles.');
    }
    if ($mirrors) {
        $columns[] = 'mosaic_mirrors';
        $values[]  = json_encode($mirrors, JSON_THROW_ON_ERROR);
    }
    if ($weights) {
        $columns[] = 'mosaic_weights';
        $values[]  = json_encode((object)$weights, JSON_THROW_ON_ERROR);
    }
    // SS-1612: carried so a copy-on-edit of a shared mosaic is a real copy.
    // The schema check for these two is in the validator, which refuses 409
    // before we reach here.
    if ($def['focus'] !== []) {
        $columns[] = 'focus_positions';
        $values[]  = json_encode((object)$def['focus'], JSON_THROW_ON_ERROR);
    }
    if ($def['emphasis'] !== null) {
        $columns[] = 'emphasis';
        $values[]  = $def['emphasis'];
    }
    $stmt = $pdo->prepare('INSERT INTO snap_mosaics (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);
    $mosaic_id = (int)$pdo->lastInsertId();
    $receipt = ['mosaic_id' => $mosaic_id, 'shortcode' => '[mosaic:' . $mosaic_id . ']'];
    if ($weights) $receipt['mosaic_weights'] = (object)$weights;
    if ($mirrors) $receipt['mosaic_mirrors'] = $mirrors;
    // ECHOED BACK SO THE CLIENT CAN CHECK RATHER THAN HOPE. Codex said he would
    // read the returned copy before repointing the post's shortcode, which is
    // the right instinct: a copy that silently lost its framing must be
    // detectable by the thing that made it, not by the owner noticing his
    // photographs are cropped differently a week later.
    if ($def['focus'] !== [])     $receipt['focus_positions'] = (object)$def['focus'];
    if ($def['emphasis'] !== null) $receipt['emphasis'] = $def['emphasis'];
    smackpress_ok($receipt);
}

// =====================================================================
// ROUTE: PUT smackpress/mosaics/{id} — edit THIS mosaic, never create one
// =====================================================================
// SS-1612. Two guards, independent, both must pass, and NEITHER trusts the read
// the desktop did when it opened the post:
//
//   STALE REVISION    the mosaic changed on the site since it was opened
//   NOW SHARED        another post or page has come to reference it
//
// The second one is the one Codex asked for and he was right to. The owner's
// ruling is that editing a SHARED mosaic copies rather than changes it, so the
// desktop chooses its path from the reference count in the read. If a mosaic
// becomes shared in between, that count is stale, and updating in place on the
// strength of it would silently rewrite a gallery in somebody else's post. So
// the write re-counts for itself and refuses; the read's count is advisory and
// this check is authoritative.
//
// AND IT NEVER INSERTS. An id that does not exist is a 404. Creating a row here
// is how a save turns into a duplicate mosaic nobody asked for.
if (preg_match('#^mosaics/(\d+)$#', $sub, $m) && $method === 'PUT') {
    $mosaic_id = (int)$m[1];
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) smackpress_error(400, 'Invalid JSON body.');

    try {
        $stmt = $pdo->prepare('SELECT * FROM snap_mosaics WHERE id = ? LIMIT 1');
        $stmt->execute([$mosaic_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        smackpress_error(500, 'Could not read the mosaic.');
    }
    if (!$existing) smackpress_error(404, 'Mosaic not found.');

    $sent_revision = (string)($body['revision'] ?? '');
    if ($sent_revision === '') smackpress_error(422, 'revision required: read the mosaic first.');
    if (!hash_equals(smackpress_mosaic_revision($existing), $sent_revision)) {
        smackpress_error(409, 'This mosaic changed on the site since you opened it. '
            . 'Reopen it to see the current arrangement.');
    }

    // post_id names the post the desktop believes it is editing from. A
    // reference from ANY OTHER post or page means this mosaic is shared and
    // must be copied instead of changed.
    $post_id = (int)($body['post_id'] ?? 0);
    if ($post_id < 1) smackpress_error(422, 'post_id required: an update must say which post it edits from.');
    $refs = smackpress_mosaic_references($pdo, $mosaic_id);
    $others = array_values(array_diff($refs['posts'], [$post_id]));
    if ($others || $refs['pages']) {
        smackpress_error(409, 'This mosaic is now used by ' . (count($others) + count($refs['pages']))
            . ' other place(s). Save it as a new mosaic instead of changing this one.');
    }

    $def = smackpress_mosaic_validate($body, $pdo, function (int $code, string $message): void {
        smackpress_error($code, $message);
    });
    if ($def['mirrors'] && !smackpress_mosaic_mirrors_available($pdo)) {
        smackpress_error(409, 'Update the site schema before publishing mirrored mosaic blocks.');
    }
    if ($def['weights'] && !smackpress_mosaic_weights_available($pdo)) {
        smackpress_error(409, 'Update the site schema before publishing resized mosaic tiles.');
    }

    // gallery_shuffle IS ABSENT FROM THIS LIST ON PURPOSE. Shuffle is
    // presentation and asset_ids is the authored order; if the desktop could
    // write the flag, a shuffled VIEW could become the stored arrangement and
    // the owner's sequence would be destroyed by opening a post and saving it,
    // with nothing to say so. Turning shuffle on and off stays in the admin.
    $sets   = ['title = ?', 'asset_ids = ?', 'gap = ?', 'layout = ?'];
    $values = [$def['title'], json_encode($def['asset_ids']), $def['gap'], $def['layout']];
    foreach ([['mosaic_mirrors', $def['mirrors'] ? json_encode($def['mirrors'], JSON_THROW_ON_ERROR) : null],
              ['mosaic_weights', $def['weights'] ? json_encode((object)$def['weights'], JSON_THROW_ON_ERROR) : null],
              ['focus_positions', $def['focus'] ? json_encode((object)$def['focus'], JSON_THROW_ON_ERROR) : null],
              ['emphasis', $def['emphasis']]] as [$col, $val]) {
        // Only touch a column this install HAS, and only when the body spoke
        // about it. An absent key leaves the stored value alone; an explicitly
        // empty one clears it, which is how [] clears the mirrors.
        if (!array_key_exists($col === 'emphasis' ? 'emphasis' : $col, $body)) continue;
        if (!array_key_exists($col, $existing)) continue;
        $sets[]   = $col . ' = ?';
        $values[] = $val;
    }
    $values[] = $mosaic_id;
    try {
        $stmt = $pdo->prepare('UPDATE snap_mosaics SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    } catch (PDOException $e) {
        smackpress_error(500, 'Could not save the mosaic.');
    }

    $fresh = smackpress_mosaic_read($pdo, $mosaic_id);
    smackpress_ok(['mosaic' => $fresh, 'revision' => $fresh['revision'] ?? '']);
}

// =====================================================================
// ROUTE: GET smackpress/pages/{id} — read page media for idempotent repair
// =====================================================================
if (preg_match('#^pages/(\d+)$#', $sub, $m) && $method === 'GET') {
    $page_id = (int)$m[1];
    $stmt = $pdo->prepare(
        "SELECT id,title,slug,content,is_active,created_at FROM snap_pages WHERE id=? LIMIT 1"
    );
    $stmt->execute([$page_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$page) smackpress_error(404, 'Page not found.');

    $ids = [];
    if (preg_match_all('/\[img:\s*g?\s*(\d+)(?:\s*\|[^\]]*)?\]/i', (string)$page['content'], $matches)) {
        foreach ($matches[1] as $value) {
            $id = (int)$value;
            if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
        }
    }
    $images = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $image_stmt = $pdo->prepare(
            "SELECT id,img_title,img_alt,img_file FROM snap_images WHERE id IN ({$placeholders})"
        );
        $image_stmt->execute($ids);
        $by_id = [];
        foreach ($image_stmt->fetchAll(PDO::FETCH_ASSOC) as $image) {
            $image['id'] = (int)$image['id'];
            $by_id[$image['id']] = $image;
        }
        foreach ($ids as $id) if (isset($by_id[$id])) $images[] = $by_id[$id];
    }
    $page['id'] = (int)$page['id'];
    $page['is_active'] = (int)$page['is_active'];
    $page['bucket'] = $images;
    smackpress_ok(['page' => $page]);
}

// =====================================================================
// ROUTE: GET smackpress/pages — list static pages for idempotent repair
// =====================================================================
if ($sub === 'pages' && $method === 'GET') {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 500)));
    $stmt = $pdo->query(
        "SELECT id,title,slug,is_active,created_at FROM snap_pages "
        . "ORDER BY created_at DESC,id DESC LIMIT " . $limit
    );
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pages as &$page) {
        $page['id'] = (int)$page['id'];
        $page['is_active'] = (int)$page['is_active'];
    }
    unset($page);
    smackpress_ok(['pages' => $pages]);
}

// =====================================================================
// ROUTE: POST smackpress/pages — create or update a static page (snap_pages)
// =====================================================================
if ($sub === 'pages' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');

    $source = $blogger_flogger ? bloggerflogger_source($body) : ['', '', '', null];
    if ($blogger_flogger && !empty($body['page_id'])) {
        smackpress_error(403, 'BLOGGER FLOGGER is additive and cannot update an existing page.');
    }
    if ($blogger_flogger) bloggerflogger_begin($pdo, $source);
    if ($blogger_flogger && ($prior = bloggerflogger_lookup($pdo, $source[0], $source[1], $source[2]))) {
        bloggerflogger_finish($pdo);
        smackpress_ok(['page_id' => (int)$prior['destination_id'], 'already_imported' => true]);
    }

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
    $custom_date  = smackpress_import_date($pdo, $body['created_at'] ?? ($body['date'] ?? null));

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

        $stmt = $pdo->prepare(
            "UPDATE snap_pages SET title=?, slug=?, content=?, image_asset=?, image_size=?, image_align=?, image_shadow=?, is_active=?, menu_order=?"
            . ($custom_date ? ", created_at=?" : "") . " WHERE id=?"
        );
        $params = [$title, $slug, $content_html, $image_asset, $image_size, $image_align, $image_shadow, $is_active, $menu_order];
        if ($custom_date) $params[] = $custom_date;
        $params[] = $page_id;
        $stmt->execute($params);
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
        $sql = "INSERT INTO snap_pages (title, slug, content, image_asset, image_size, image_align, image_shadow, is_active, menu_order"
             . ($custom_date ? ", created_at" : "") . ") VALUES (?,?,?,?,?,?,?,?,?"
             . ($custom_date ? ",?" : "") . ")";
        $params = [$title, $slug, $content_html, $image_asset, $image_size, $image_align, $image_shadow, $is_active, $menu_order];
        if ($custom_date) $params[] = $custom_date;
        $pdo->prepare($sql)->execute($params);
        $pid = (int)$pdo->lastInsertId();
    }

    if ($blogger_flogger) {
        bloggerflogger_record($pdo, $source[0], $source[1], $source[2], $source[3], 'page', $pid);
        bloggerflogger_finish($pdo);
    }

    // Static pages on controller-backed SMACKTALK skins must enter through the
    // front controller so the active skin, footer, assets, and security model
    // are applied.  page.php remains a compatibility entry point for older
    // skins, but it is not the canonical URL returned to importers.
    $page_url = rtrim($base_url, '/') . '/' . rawurlencode($slug);
    smackpress_ok(['page_id' => $pid, 'slug' => $slug, 'url' => $page_url, 'is_active' => $is_active]);
}

// =====================================================================
// ROUTE: POST bloggerflogger/comments — additive imported comments
// =====================================================================
if ($blogger_flogger && $sub === 'comments' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) smackpress_error(400, 'Invalid JSON body.');
    $source = bloggerflogger_source($body);
    bloggerflogger_begin($pdo, $source);
    if ($prior = bloggerflogger_lookup($pdo, $source[0], $source[1], $source[2])) {
        bloggerflogger_finish($pdo);
        smackpress_ok(['comment_id' => (int)$prior['destination_id'], 'already_imported' => true]);
    }
    $post_id = (int)($body['post_id'] ?? 0);
    $author = trim((string)($body['author_name'] ?? '')) ?: 'Anonymous';
    $text = trim((string)($body['content'] ?? ''));
    $date = trim((string)($body['created_at'] ?? ''));
    $approved = !empty($body['is_approved']) ? 1 : 0;
    $spam = !empty($body['is_spam']) ? 1 : 0;
    if ($post_id <= 0 || $text === '') smackpress_error(422, 'A destination post and comment text are required.');
    $check = $pdo->prepare("SELECT id FROM snap_posts WHERE id=? AND post_type='longform'");
    $check->execute([$post_id]);
    if (!$check->fetch()) smackpress_error(404, 'Destination post not found.');
    $stmt = $pdo->prepare("INSERT INTO snap_comments
        (post_id,comment_author,comment_url,comment_email,comment_text,comment_date,is_approved,is_spam)
        VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$post_id, $author, trim((string)($body['author_url'] ?? '')) ?: null,
        trim((string)($body['author_email'] ?? '')) ?: null, $text,
        $date !== '' ? $date : date('Y-m-d H:i:s'), $approved, $spam]);
    $comment_id = (int)$pdo->lastInsertId();
    bloggerflogger_record($pdo, $source[0], $source[1], $source[2], $source[3], 'comment', $comment_id);
    bloggerflogger_finish($pdo);
    smackpress_ok(['comment_id' => $comment_id]);
}

// =====================================================================
// ROUTE: GET bloggerflogger/verify/{type}/{id}
// =====================================================================
if ($blogger_flogger && preg_match('#^verify/(post|page|comment|media)/(\d+)$#', $sub, $m)
    && $method === 'GET') {
    $type = $m[1]; $id = (int)$m[2];
    $queries = [
        'post' => "SELECT id,title,slug,status,created_at FROM snap_posts WHERE id=? AND post_type='longform'",
        'page' => "SELECT id,title,slug,is_active FROM snap_pages WHERE id=?",
        'comment' => "SELECT id,post_id,comment_author,comment_date,is_approved,is_spam FROM snap_comments WHERE id=?",
        'media' => "SELECT id,img_title,img_alt,img_original FROM snap_images WHERE id=?",
    ];
    $q = $pdo->prepare($queries[$type]); $q->execute([$id]);
    $record = $q->fetch(PDO::FETCH_ASSOC);
    if (!$record) smackpress_error(404, 'Imported destination record not found.');
    $pdo->prepare("UPDATE snap_import_map SET verified_at=NOW()
                   WHERE tool_type='bloggerflogger' AND destination_type=? AND destination_id=?")
        ->execute([$type, $id]);
    smackpress_ok(['destination_type' => $type, 'record' => $record]);
}

// =====================================================================
// FALLBACK
// =====================================================================
smackpress_error(404, 'Unknown SMACKPRESS API endpoint.');
// ===== SNAPSMACK EOF =====
