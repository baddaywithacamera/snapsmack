<?php
/**
 * SNAPSMACK - Hashtag Extraction and Sync
 *
 * Provides functions:
 *   snap_extract_tags($text)                   — returns array of tag slugs found in text
 *   snap_sync_tags($pdo, $image_id, $text)     — upserts snap_tags, replaces snap_image_tags
 *   snap_render_caption($text, $base_url)      — returns HTML with #tags converted to links
 *   snap_hex_to_color_family($slug)            — maps a 6-char hex slug to a colour name
 *
 * Tag rules: starts with a letter OR is a 6-char hex code starting with a digit.
 * 1–50 chars, a-z A-Z 0-9 underscore. Slugs are always lowercase.
 * Display form preserved as-entered on first use.
 *
 * Hex colour tags: #007a8b, #c25e31, #8c7d70 etc. are extracted and stored with
 * a color_family value (red/orange/yellow/green/teal/blue/purple/pink/grey/black/white)
 * computed via hex→HSL conversion. Colour-family search ("blue", "teal") matches images
 * tagged with any hex code belonging to that family.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// ── Extract ───────────────────────────────────────────────────────────────────

/**
 * Extract hashtag slugs from plain text.
 * Returns deduplicated array of lowercase slugs (no # prefix).
 */
function snap_extract_tags(string $text): array {
    // Two alternatives:
    //   1. Standard tag: starts with a letter, up to 50 chars (a-z A-Z 0-9 _)
    //   2. Hex colour code: digit-leading, exactly 6 hex chars (e.g. #007a8b, #8c7d70)
    // Letter-leading hex codes like #c25e31 are caught by alternative 1 and will be
    // classified as hex colours during sync via snap_hex_to_color_family().
    preg_match_all('/#([a-zA-Z][a-zA-Z0-9_]{0,49}|[0-9][0-9a-fA-F]{5})(?=[^a-zA-Z0-9_]|$)/u', $text . ' ', $matches);
    $slugs = [];
    foreach ($matches[1] as $tag) {
        $slug = strtolower($tag);
        if (!in_array($slug, $slugs, true)) {
            $slugs[] = $slug;
        }
    }
    return $slugs;
}

// ── Hex colour mapping ────────────────────────────────────────────────────────

/**
 * Map a 6-character hex colour slug to a colour family name.
 *
 * Returns one of: red, orange, yellow, green, teal, blue, purple, pink,
 *                 grey, black, white
 * Returns null if $slug is not exactly 6 hex characters.
 *
 * Algorithm: RGB → HSL, then hue-range + lightness/saturation bucketing.
 */
function snap_hex_to_color_family(string $slug): ?string {
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $slug)) {
        return null;
    }

    $r = hexdec(substr($slug, 0, 2)) / 255.0;
    $g = hexdec(substr($slug, 2, 2)) / 255.0;
    $b = hexdec(substr($slug, 4, 2)) / 255.0;

    $max   = max($r, $g, $b);
    $min   = min($r, $g, $b);
    $delta = $max - $min;

    // Lightness
    $l = ($max + $min) / 2.0;

    // Near-black and near-white
    if ($l < 0.15) return 'black';
    if ($l > 0.85) return 'white';

    // Low-saturation = grey (avoids divide-by-zero below)
    $s = ($delta < 0.001) ? 0.0 : $delta / (1.0 - abs(2.0 * $l - 1.0));
    if ($s < 0.20) return 'grey';

    // Hue in degrees [0, 360)
    if ($delta < 0.001) return 'grey';

    if ($max === $r) {
        $h = 60.0 * fmod(($g - $b) / $delta, 6.0);
    } elseif ($max === $g) {
        $h = 60.0 * (($b - $r) / $delta + 2.0);
    } else {
        $h = 60.0 * (($r - $g) / $delta + 4.0);
    }
    if ($h < 0.0) $h += 360.0;

    // Hue → colour family
    if ($h < 15.0 || $h >= 345.0) return 'red';
    if ($h < 45.0)                 return 'orange';
    if ($h < 65.0)                 return 'yellow';
    if ($h < 150.0)                return 'green';
    if ($h < 195.0)                return 'teal';
    if ($h < 260.0)                return 'blue';
    if ($h < 291.0)                return 'purple';
    return 'pink';
}


// ── Sync ──────────────────────────────────────────────────────────────────────

/**
 * Sync hashtags for a single image.
 *
 * 1. Extracts tags from $text.
 * 2. Upserts each tag into snap_tags (slug is unique key).
 * 3. Replaces all snap_image_tags rows for $image_id.
 * 4. Recounts use_count for affected tags (published images only).
 *
 * Silently no-ops if snap_tags table doesn't exist yet (migration not run).
 */
function snap_sync_tags(PDO $pdo, int $image_id, string $text): void {
    // Guard: bail silently if tables haven't been created yet
    try {
        $pdo->query("SELECT 1 FROM snap_tags LIMIT 0");
    } catch (PDOException $e) {
        return;
    }

    $slugs = snap_extract_tags($text);

    // Clear existing associations for this image
    $pdo->prepare("DELETE FROM snap_image_tags WHERE image_id = ?")->execute([$image_id]);

    if (empty($slugs)) {
        return;
    }

    // Detect whether the color_family column exists (added in 0.7.4c migration).
    // If not, fall back to the simpler INSERT that omits it.
    $has_color_family = false;
    try {
        $pdo->query("SELECT color_family FROM snap_tags LIMIT 0")->closeCursor();
        $has_color_family = true;
    } catch (PDOException $e) {
        // Column doesn't exist yet — pre-migration install
    }

    $tag_ids = [];
    foreach ($slugs as $slug) {
        if ($has_color_family) {
            // Compute colour family for hex codes (null for regular word tags)
            $color_family = snap_hex_to_color_family($slug);

            // Upsert tag — preserve first-seen display form; get the ID either way.
            // color_family is set on first insert; on duplicate we fill it in only if
            // it was previously null (handles tags added before this migration ran).
            $pdo->prepare(
                "INSERT INTO snap_tags (tag, slug, color_family) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                 color_family = COALESCE(color_family, VALUES(color_family))"
            )->execute([$slug, $slug, $color_family]);
        } else {
            // Pre-migration path: no color_family column yet
            $pdo->prepare(
                "INSERT INTO snap_tags (tag, slug) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
            )->execute([$slug, $slug]);
        }
        $tag_ids[] = (int)$pdo->lastInsertId();
    }

    // Insert image↔tag associations
    $ins = $pdo->prepare("INSERT IGNORE INTO snap_image_tags (image_id, tag_id) VALUES (?, ?)");
    foreach ($tag_ids as $tag_id) {
        $ins->execute([$image_id, $tag_id]);
    }

    // Recount use_count for all affected tags (published images only)
    $placeholders = implode(',', array_fill(0, count($tag_ids), '?'));
    $pdo->prepare(
        "UPDATE snap_tags t
         SET use_count = (
             SELECT COUNT(*)
             FROM snap_image_tags it
             JOIN snap_images i ON i.id = it.image_id
             WHERE it.tag_id = t.id
               AND i.img_status = 'published'
         )
         WHERE t.id IN ($placeholders)"
    )->execute($tag_ids);
}

// ── Backfill ──────────────────────────────────────────────────────────────────

/**
 * One-time backfill: assign color_family to any existing hex-colour tags that
 * were created before this feature existed (color_family IS NULL).
 *
 * Safe to call repeatedly — skips tags that already have a color_family.
 * Called from smack-update.php after the 0.7.4c migration runs.
 */
function snap_backfill_color_families(PDO $pdo): int {
    // Guard: bail silently if column doesn't exist yet
    try {
        $pdo->query("SELECT color_family FROM snap_tags LIMIT 0")->closeCursor();
    } catch (PDOException $e) {
        return 0;
    }

    // Fetch only potential hex slugs (exactly 6 chars) with no color_family yet
    $stmt = $pdo->query("SELECT id, slug FROM snap_tags WHERE color_family IS NULL AND CHAR_LENGTH(slug) = 6");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $upd = $pdo->prepare("UPDATE snap_tags SET color_family = ? WHERE id = ?");
    foreach ($rows as $row) {
        $family = snap_hex_to_color_family($row['slug']);
        if ($family !== null) {
            $upd->execute([$family, $row['id']]);
            $updated++;
        }
    }
    return $updated;
}


// ── Render ────────────────────────────────────────────────────────────────────

/**
 * Retrieve all tags for a given image.
 *
 * @return array  Array of ['tag' => ..., 'slug' => ..., 'use_count' => ...]
 */
function snap_get_tags(PDO $pdo, int $image_id): array {
    $stmt = $pdo->prepare("
        SELECT t.tag, t.slug, t.use_count
        FROM snap_tags t
        JOIN snap_image_tags it ON it.tag_id = t.id
        WHERE it.image_id = ?
        ORDER BY t.slug ASC
    ");
    $stmt->execute([$image_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render plain text with #hashtags converted to anchor links.
 * HTML-escapes the rest of the text. Safe to output directly.
 *
 * @param string $text      Raw plain-text caption
 * @param string $base_url  BASE_URL constant value
 * @param string $css_class CSS class on each <a> tag (default: snap-hashtag)
 */
function snap_render_caption(string $text, string $base_url, string $css_class = 'snap-hashtag'): string {
    // Escape HTML first so we never double-encode or create XSS
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Replace #tag occurrences with links (includes digit-leading hex codes like #007a8b)
    return preg_replace_callback(
        '/#([a-zA-Z][a-zA-Z0-9_]{0,49}|[0-9][0-9a-fA-F]{5})(?=[^a-zA-Z0-9_]|$)/',
        static function (array $m) use ($base_url, $css_class): string {
            $slug    = strtolower($m[1]);
            $display = '#' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $href    = htmlspecialchars($base_url . '?tag=' . rawurlencode($slug), ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '" class="' . $css_class . '">' . $display . '</a>';
        },
        $safe . ' '   // trailing space so end-of-string tags match the lookahead
    );
}

/**
 * Render caption with safe HTML tags allowed (lists, emphasis, links).
 * Removes dangerous tags, preserves safe ones, converts hashtags to links.
 * Safe to output directly — strip_tags() removes any malicious markup.
 *
 * @param string $text      Raw caption (may contain HTML)
 * @param string $base_url  BASE_URL constant value
 * @param string $css_class CSS class on hashtag links
 */
function snap_render_caption_html(string $text, string $base_url, string $css_class = 'snap-hashtag'): string {
    // Captions are plain text (Instagram captions / image descriptions). strip_tags()
    // is NOT safe here: it preserves attributes on whitelisted tags, so a crafted
    // caption like <a href="javascript:..."> or <p onmouseover=...> survives into the
    // page as live HTML — a stored-XSS vector (secaudit #024 UZ-11). Escape ALL markup
    // first, then re-introduce only the formatting WE generate: <br> line breaks here
    // and safe hashtag anchors in the callback below.
    //
    // Normalise pre-encoded input FIRST: Flickr imports and some composers store
    // captions already HTML-encoded (e.g. it&#39;s), so escaping again renders a
    // literal &#39; on the page. Fully decode entities (iteratively, to collapse
    // double-encoding), THEN escape once — the final htmlspecialchars still
    // neutralises any markup, so XSS safety is preserved.
    $decoded = $text; $prev = null;
    while ($decoded !== $prev) { $prev = $decoded; $decoded = html_entity_decode($decoded, ENT_QUOTES, 'UTF-8'); }
    $safe = htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    $safe = nl2br($safe);

    // Convert #hashtags to anchor links (includes digit-leading hex codes like #007a8b)
    return preg_replace_callback(
        '/#([a-zA-Z][a-zA-Z0-9_]{0,49}|[0-9][0-9a-fA-F]{5})(?=[^a-zA-Z0-9_]|$)/',
        static function (array $m) use ($base_url, $css_class): string {
            $slug    = strtolower($m[1]);
            $display = '#' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $href    = htmlspecialchars($base_url . '?tag=' . rawurlencode($slug), ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '" class="' . $css_class . '">' . $display . '</a>';
        },
        $safe . ' '
    );
}
// ===== SNAPSMACK EOF =====
