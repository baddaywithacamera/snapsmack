<?php
/**
 * SNAPSMACK - Hashtag Extraction and Sync
 * Alpha v0.7.4
 *
 * Provides three functions:
 *   snap_extract_tags($text)              — returns array of tag slugs found in text
 *   snap_sync_tags($pdo, $image_id, $text) — upserts snap_tags, replaces snap_image_tags
 *   snap_render_caption($text, $base_url)  — returns HTML with #tags converted to links
 *
 * Tag rules: starts with a letter, 1–50 chars, a-z A-Z 0-9 underscore.
 * Slugs are always lowercase. Display form preserved as-entered on first use.
 */

// ── Extract ───────────────────────────────────────────────────────────────────

/**
 * Extract hashtag slugs from plain text.
 * Returns deduplicated array of lowercase slugs (no # prefix).
 */
function snap_extract_tags(string $text): array {
    preg_match_all('/#([a-zA-Z][a-zA-Z0-9_]{0,49})(?=[^a-zA-Z0-9_]|$)/u', $text . ' ', $matches);
    $slugs = [];
    foreach ($matches[1] as $tag) {
        $slug = strtolower($tag);
        if (!in_array($slug, $slugs, true)) {
            $slugs[] = $slug;
        }
    }
    return $slugs;
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

    $tag_ids = [];
    foreach ($slugs as $slug) {
        // Upsert tag — preserve first-seen display form; get the ID either way
        $pdo->prepare(
            "INSERT INTO snap_tags (tag, slug) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        )->execute([$slug, $slug]);
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

// ── Render ────────────────────────────────────────────────────────────────────

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

    // Replace #tag occurrences with links
    return preg_replace_callback(
        '/#([a-zA-Z][a-zA-Z0-9_]{0,49})(?=[^a-zA-Z0-9_]|$)/',
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
    // Whitelist safe HTML tags: paragraph, break, emphasis, list, link, blockquote
    $allowed = '<p><br><strong><em><u><a><ul><ol><li><blockquote>';
    $safe = strip_tags($text, $allowed);

    // If the text has no block-level HTML tags, convert plain newlines to <br>
    // so posts written in plain text still display with proper line breaks.
    if (!preg_match('/<(p|ul|ol|blockquote)[\s>]/i', $safe)) {
        $safe = nl2br($safe);
    }

    // Convert #hashtags to anchor links
    return preg_replace_callback(
        '/#([a-zA-Z][a-zA-Z0-9_]{0,49})(?=[^a-zA-Z0-9_]|$)/',
        static function (array $m) use ($base_url, $css_class): string {
            $slug    = strtolower($m[1]);
            $display = '#' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $href    = htmlspecialchars($base_url . '?tag=' . rawurlencode($slug), ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '" class="' . $css_class . '">' . $display . '</a>';
        },
        $safe . ' '
    );
}
