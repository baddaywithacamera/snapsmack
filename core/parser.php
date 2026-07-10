<?php
/**
 * SNAPSMACK - Content Parser and Asset Router
 *
 * Parses shortcodes in content and converts them to rich HTML.
 *
 * Supported shortcodes:
 *   [img:ID|size|align]              — inline image from media library or posts
 *   [columns=N] ... [col] ... [/columns] — multi-column grid layout
 *   [dropcap]X[/dropcap]            — decorative first-letter dropcap
 *   [spacer:N]                       — vertical gap (1–100 pixels)
 *   [post_count]                     — total published images
 *   [site_name]                      — site name from settings
 *   [site_url]                       — site URL from settings
 *   [current_year]                   — four-digit current year
 *   [years_since year="" month="" day=""] — years elapsed since a date
 *   [random_image]                   — renders a random published image
 *   [latest_image]                   — renders the most recent published image
 *   [archive_link]                   — anchor to archive (blank if disabled)
 *   [gallery_link]                   — anchor to floating gallery (blank if disabled)
 *   [newest_post]                    — date of most recent post
 *   [oldest_post]                    — date of first post
 *   [embed:key]                      — named HTML embed from Smack Your Scripts Up!
 *
 * SMACKONEOUT + SMACKTALK only (not carousel/GRAMOFSMACK):
 *   [lede]text[/lede]                — large grey introductory paragraph
 *   [callout]text[/callout]          — red left-border info/warning box
 *   [kicker]text[/kicker]            — small uppercase label above a heading
 *   [dict word="" phon="" pos=""]def[/dict] — full-width dictionary interstitial
 *   [list bullet="check|arrow"]a|b|c[/list] — styled list, pipe-separated items
 *   [btn href="" style="primary|secondary"]label[/btn] — call-to-action button
 *   [card-grid cols="N" canvas="light|dark"]...[card label="" title="" tagline=""]...[/card]...[/card-grid]
 *   [accent-grid cols="N"]...[accent-card title=""]...[/accent-card]...[/accent-grid]
 *   [feature-box title=""]item one|item two|item three[/feature-box]
 *   [bio img="" name="" role=""]text[/bio] — portrait + bio copy
 *
 * Auto-paragraph: double newlines (\n\n) become <p> tags automatically.
 * Single newlines within a paragraph become <br>.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


class SnapSmack {
    private $pdo;
    private $config = [];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }

    // --- CONFIGURATION LOADER ---
    // Load site settings from the database into a config array.
    // Fails silently if the table is unavailable.
    private function loadConfig() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_val FROM snap_settings");
            $this->config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            $this->config = [];
        }
    }

    // =========================================================================
    //  PUBLIC API
    // =========================================================================

    /**
     * Main content parser — runs the full pipeline on raw textarea content.
     *
     * Pipeline order:
     *   1. Columns (extract and protect column blocks first)
     *   2. Auto-paragraph (wrap plain text in <p> tags)
     *   3. Dropcap shortcodes
     *   4. Image shortcodes
     */
    public function parseContent($content) {
        if (empty($content)) return "";

        // --- PHASE 1: COLUMNS ---
        // Extract column blocks before auto-paragraph so the [columns] wrapper
        // doesn't get wrapped in <p> tags. Column INNER content gets its own
        // auto-paragraph pass.
        $content = $this->parseColumns($content);

        // --- PHASE 1b: LAYOUT SHORTCODES ---
        // Must run before autoParagraph to avoid <p><div> nesting issues.
        // SMACKONEOUT + SMACKTALK only — parser methods exist on all installs
        // but these shortcodes only appear in non-carousel content.
        $content = $this->parseCardGrids($content);
        $content = $this->parseAccentGrids($content);
        $content = $this->parseFeatureBoxes($content);
        $content = $this->parseBios($content);

        // --- PHASE 2: AUTO-PARAGRAPH ---
        // Convert double newlines to <p> tags for any remaining top-level text.
        $content = $this->autoParagraph($content);

        // --- PHASE 3: DROPCAP ---
        $content = $this->parseDropcap($content);

        // --- PHASE 4: IMAGE SHORTCODES ---
        $content = $this->parseImages($content);

        // --- PHASE 5: DATA SHORTCODES ---
        // Simple value shortcodes ([post_count], [site_name], etc.)
        $content = $this->parseDataShortcodes($content);

        // --- PHASE 6: SPACER SHORTCODES ---
        $content = $this->parseSpacers($content);

        // --- PHASE 6c: PROSE SHORTCODES ---
        // Run after autoParagraph — inline/block replacements that don't
        // conflict with <p> wrapping.
        $content = $this->parseLede($content);
        $content = $this->parseCallout($content);
        $content = $this->parseKicker($content);
        $content = $this->parseDictPull($content);
        $content = $this->parseSnapList($content);
        $content = $this->parseSnapBtn($content);

        // --- PHASE 7: BLOCK NESTING CLEANUP ---
        // When [img:] shortcodes sit inside the same <p> as text (either from
        // old saves or same-line authoring), Phase 2 wraps everything in <p>
        // and Phase 4 converts the shortcode to a <div>, creating invalid
        // <p><div>...</div>text</p>. Split the div out and re-wrap leftovers.
        $content = $this->cleanBlockNesting($content);

        // --- PHASE 8: MOSAIC SHORTCODES ---
        // [mosaic:ID] shortcodes expand to data-attribute divs rendered by
        // ss-engine-mosaic.js. Runs after block-nesting cleanup so the <div>
        // it emits is already outside any wrapping <p>.
        $content = $this->parseMosaics($content);

        return $content;
    }

    // =========================================================================
    //  AUTO-PARAGRAPH
    // =========================================================================

    /**
     * Convert double-newline-separated text into <p> blocks.
     *
     * Rules:
     *   - Double newline (\n\n) = new paragraph
     *   - Single newline within a paragraph = <br>
     *   - Content already wrapped in block-level HTML is left alone
     *   - Shortcode blocks ([columns], etc.) are left alone
     */
    private function autoParagraph($content) {
        // Protect existing block-level HTML and processed column blocks
        // by splitting around them and only wrapping the text fragments.
        //
        // Top-level block elements only. Child-only elements (li, td, th,
        // tr, thead, tbody) are always nested inside their parent block and
        // must NOT appear in this list — otherwise the non-greedy .*? will
        // match <ul>...<li>...</li> as a single "block" and leave the rest
        // of the list orphaned as text that gets incorrectly wrapped in <p>.
        // NOTE: <p> IS included because the user's content may already
        // contain <p> tags from the editor — these must be passed through,
        // not wrapped in another <p>.
        $block_tags = 'p|h[1-6]|div|blockquote|ul|ol|table|figure|figcaption|pre|form|section|article|aside|nav|header|footer';

        // Split content into segments: block-level HTML vs. text.
        // Backreference \2 ensures the closing tag matches the opening tag,
        // so <ul>...<li>...</li>...<li>...</li>...</ul> is captured as one
        // unbroken block instead of stopping at the first </li>.
        $segments = preg_split(
            '/(<(' . $block_tags . ')[\s>].*?<\/\2>|<hr\s*\/?>)/si',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $output = '';
        foreach ($segments as $segment) {
            $trimmed = trim($segment);
            if ($trimmed === '') continue;

            // The backreference capture group (\2) produces bare tag-name
            // segments like "ul", "h3" etc. — skip those.
            if (preg_match('/^(?:' . $block_tags . ')$/i', $trimmed)) continue;

            // If this segment starts with a block-level tag, pass it through
            if (preg_match('/^<(?:' . $block_tags . ')[\s>]/i', $trimmed)) {
                $output .= $segment;
                continue;
            }

            // If this segment is a processed column block (div.snapsmack-columns), pass through
            if (str_starts_with($trimmed, '<div class="snapsmack-columns')) {
                $output .= $segment;
                continue;
            }

            // Otherwise, wrap text chunks in <p> tags
            $paragraphs = preg_split('/\n\s*\n/', $trimmed);
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if ($para === '') continue;

                // Convert single newlines to <br>
                $para = nl2br($para, false);

                $output .= '<p>' . $para . '</p>' . "\n";
            }
        }

        return $output;
    }

    // =========================================================================
    //  COLUMN SHORTCODE
    // =========================================================================

    /**
     * Parse [mosaic:ID] shortcodes into the <div class="snap-mosaic" data-mosaic="…">
     * container that ss-engine-mosaic.js reads and packs into a justified tiled
     * gallery. A mosaic (snap_mosaics) stores an ordered JSON id list (column name
     * `asset_ids`, kept for back-compat) — these are GALLERY image ids (snap_images),
     * NOT Library assets: mosaic images are POST content and live in the Gallery.
     *
     * Each id resolves to {src, full, width, height, alt, id} — the shape the engine
     * expects. snap_images already stores dimensions (img_width/img_height) and an
     * aspect thumbnail, so tiles load the light thumb while the lightbox opens the
     * full image (engine reads `full` for data-lightbox-src, falling back to `src`).
     * Order is preserved.
     */
    private function parseMosaics($content) {
        return preg_replace_callback('/\[mosaic:\s*(\d+)\s*\]/i', function ($m) {
            $id = (int)$m[1];

            try {
                $stmt = $this->pdo->prepare("SELECT asset_ids, gap FROM snap_mosaics WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $mosaic = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                return ''; // table absent or query failed — drop the shortcode
            }
            if (!$mosaic) return '';

            $image_ids = json_decode($mosaic['asset_ids'] ?? '[]', true);
            if (!is_array($image_ids) || empty($image_ids)) return '';
            $gap = max(0, min(20, (int)($mosaic['gap'] ?? 4)));

            $base = defined('BASE_URL') ? BASE_URL : (rtrim($this->config['site_url'] ?? '/', '/') . '/');

            // Fetch the Gallery images once, then walk the id list to preserve order.
            $ph   = implode(',', array_fill(0, count($image_ids), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT id, img_title, img_file, img_thumb_aspect, img_width, img_height
                 FROM snap_images WHERE id IN ($ph)"
            );
            $stmt->execute(array_map('intval', $image_ids));
            $by_id = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) { $by_id[(int)$r['id']] = $r; }

            $images = [];
            foreach ($image_ids as $iid) {
                $iid = (int)$iid;
                if (!isset($by_id[$iid])) continue;
                $full_rel = ltrim((string)$by_id[$iid]['img_file'], '/');
                if ($full_rel === '') continue;
                $thumb_rel = !empty($by_id[$iid]['img_thumb_aspect'])
                    ? ltrim((string)$by_id[$iid]['img_thumb_aspect'], '/')
                    : $full_rel;

                $item = [
                    'src'  => $base . $thumb_rel,   // light aspect thumb for the tile
                    'full' => $base . $full_rel,    // full image for the lightbox
                    'alt'  => (string)($by_id[$iid]['img_title'] ?? ''),
                    'id'   => $iid,
                ];
                $w = (int)($by_id[$iid]['img_width'] ?? 0);
                $h = (int)($by_id[$iid]['img_height'] ?? 0);
                if ($w > 0 && $h > 0) { $item['width'] = $w; $item['height'] = $h; }
                $images[] = $item;
            }
            if (empty($images)) return '';

            return '<div class="snap-mosaic" data-mosaic="'
                . htmlspecialchars(json_encode($images), ENT_QUOTES)
                . '" data-gap="' . $gap . '"></div>';
        }, $content);
    }

    /**
     * Parse [columns=N] ... [col] ... [/columns] blocks.
     *
     * Content between [columns=N] and [/columns] is split on [col] markers.
     * Each segment becomes a grid cell. The outer wrapper gets a CSS class
     * for the requested column count (cols-2, cols-3, cols-4).
     *
     * Inner content of each column is run through autoParagraph + parseImages
     * so images and text formatting work inside columns.
     */
    private function parseColumns($content) {
        return preg_replace_callback(
            '/\[columns=(\d+)\](.*?)\[\/columns\]/si',
            function ($matches) {
                $count = max(2, min(4, (int) $matches[1])); // clamp 2-4
                $inner = trim($matches[2]);

                // Split on [col] markers
                $cells = preg_split('/\[col\]/i', $inner);

                $html = '<div class="snapsmack-columns cols-' . $count . '">' . "\n";
                foreach ($cells as $cell) {
                    $cell_content = trim($cell);
                    // Run inner content through paragraph + image parsing
                    $cell_content = $this->autoParagraph($cell_content);
                    $cell_content = $this->parseImages($cell_content);
                    $html .= '  <div class="snapsmack-col">' . $cell_content . '</div>' . "\n";
                }
                $html .= '</div>';

                return $html;
            },
            $content
        );
    }

    // =========================================================================
    //  DROPCAP SHORTCODE
    // =========================================================================

    /**
     * Parse [dropcap]X[/dropcap] into a styled span.
     *
     * The .dropcap class is styled by the active skin's manifest (dropcap_style
     * option with custom-framing property). If the manifest option is set to
     * "none", the span renders as a normal inline character.
     */
    private function parseDropcap($content) {
        return preg_replace(
            '/\[dropcap\](.*?)\[\/dropcap\]/si',
            '<span class="dropcap">$1</span>',
            $content
        );
    }

    // =========================================================================
    //  SPACER SHORTCODE
    // =========================================================================

    /**
     * Parse [spacer:N] (vertical) and [hspacer:N] (horizontal) gap shortcodes.
     *
     * Accepts pixel values 1–100. Values outside range are clamped.
     * Renders as an empty div with an explicit height.
     */
    private function parseSpacers($content) {
        return preg_replace_callback(
            '/\[(h?)spacer:\s*(\d+)\]/i',
            function ($matches) {
                $horizontal = strtolower($matches[1]) === 'h';
                $px = max(1, min(100, (int) $matches[2]));
                if ($horizontal) {
                    // Inline horizontal gap — e.g. between two inline images.
                    return '<span class="snap-spacer-h" style="display:inline-block;width:'
                        . $px . 'px" aria-hidden="true"></span>';
                }
                return '<div class="snap-spacer" style="height:' . $px . 'px" aria-hidden="true"></div>';
            },
            $content
        );
    }

    // =========================================================================
    //  BLOCK NESTING CLEANUP
    // =========================================================================

    /**
     * Fix invalid <p><div>...</div></p> nesting created when image shortcodes
     * inside a paragraph get expanded into block-level elements.
     *
     * Splits block-level snap-inline-frame divs out of their parent <p> and
     * re-wraps any leftover text in new <p> tags.
     */
    private function cleanBlockNesting($content) {
        // Match any <p> that contains a snap-inline-frame div
        $content = preg_replace_callback(
            '/<p>(.*?<div class="snap-inline-frame[^"]*">.*?<\/div><\/div>.*?)<\/p>/si',
            function ($matches) {
                $inner = $matches[1];

                // Split around the frame div(s)
                $parts = preg_split(
                    '/(<div class="snap-inline-frame[^"]*">.*?<\/div><\/div>)/si',
                    $inner,
                    -1,
                    PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                );

                $output = '';
                foreach ($parts as $part) {
                    $trimmed = trim($part);
                    if ($trimmed === '' || $trimmed === '<br>' || $trimmed === '<br/>') continue;

                    if (str_starts_with($trimmed, '<div class="snap-inline-frame')) {
                        // Block-level frame — output as-is, no <p> wrapper
                        $output .= $trimmed . "\n";
                    } else {
                        // Leftover text — strip leading/trailing <br> and re-wrap
                        $trimmed = preg_replace('/^(<br\s*\/?>)+|(<br\s*\/?>)+$/i', '', $trimmed);
                        $trimmed = trim($trimmed);
                        if ($trimmed !== '') {
                            $output .= '<p>' . $trimmed . '</p>' . "\n";
                        }
                    }
                }

                return $output;
            },
            $content
        );

        // General block-in-paragraph cleanup. autoParagraph cannot protect
        // NESTED block elements (a [columns] wrapper holds inner column divs),
        // so the wrapper leaks into <p> tags — e.g. <p><div class="snapsmack-
        // columns">. Unwrap any block element from a surrounding paragraph and
        // drop the empty / <br>-only paragraphs that get left behind.
        $block = 'div|figure|table|ul|ol|blockquote|section|article|header|footer|nav|aside|h[1-6]|pre|form|hr';
        // Drop a <p> (plus leading whitespace/<br>) sitting right before a block tag.
        $content = preg_replace('#<p>(?:\s|<br\s*/?>)*(?=</?(?:' . $block . ')\b)#i', '', $content);
        // Drop a </p> (plus trailing whitespace/<br>) sitting right after a block tag.
        $content = preg_replace('#(</?(?:' . $block . ')\b[^>]*>)(?:\s|<br\s*/?>)*</p>#i', '$1', $content);
        // Remove now-empty or <br>-only paragraphs.
        $content = preg_replace('#<p>\s*(?:<br\s*/?>\s*)*</p>#i', '', $content);

        return $content;
    }

    // =========================================================================
    //  DATA SHORTCODES
    // =========================================================================

    /**
     * Parse data shortcodes that insert dynamic values into page content.
     *
     * Simple replacements ([post_count], [site_name], etc.) plus a few
     * that need DB queries ([random_image], [latest_image], date lookups).
     */
    private function parseDataShortcodes($content) {
        $base = defined('BASE_URL') ? BASE_URL : (rtrim($this->config['site_url'] ?? '/', '/') . '/');

        // --- [post_count] ---
        $content = preg_replace_callback('/\[post_count\]/i', function () {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published'");
                return (string) $stmt->fetchColumn();
            } catch (PDOException $e) { return '0'; }
        }, $content);

        // --- [site_name] ---
        $content = str_ireplace('[site_name]', htmlspecialchars($this->config['site_name'] ?? ''), $content);

        // --- [site_url] ---
        $content = str_ireplace('[site_url]', htmlspecialchars($this->config['site_url'] ?? ''), $content);

        // --- [current_year] ---
        $content = str_ireplace('[current_year]', date('Y'), $content);

        // --- [years_since year="YYYY" month="M" day="D"] ---
        $content = preg_replace_callback(
            '/\[years_since\s+year=["\']?(\d{4})["\']?(?:\s+month=["\']?(\d{1,2})["\']?)?(?:\s+day=["\']?(\d{1,2})["\']?)?\s*\]/i',
            function ($m) {
                $year  = (int) $m[1];
                $month = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
                $day   = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;
                $origin = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
                $now    = new DateTime();
                return (string) $origin->diff($now)->y;
            },
            $content
        );

        // --- [newest_post] ---
        $content = preg_replace_callback('/\[newest_post\]/i', function () {
            try {
                $stmt = $this->pdo->query("SELECT img_date FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1");
                $date = $stmt->fetchColumn();
                return $date ? date('F j, Y', strtotime($date)) : '';
            } catch (PDOException $e) { return ''; }
        }, $content);

        // --- [oldest_post] ---
        $content = preg_replace_callback('/\[oldest_post\]/i', function () {
            try {
                $stmt = $this->pdo->query("SELECT img_date FROM snap_images WHERE img_status = 'published' ORDER BY img_date ASC LIMIT 1");
                $date = $stmt->fetchColumn();
                return $date ? date('F j, Y', strtotime($date)) : '';
            } catch (PDOException $e) { return ''; }
        }, $content);

        // --- [archive_link] ---
        $content = preg_replace_callback('/\[archive_link\]/i', function () use ($base) {
            $layout = $this->config['archive_layout'] ?? 'square';
            if ($layout === 'none') return '';
            return '<a href="' . htmlspecialchars($base . 'archive.php') . '">Archive</a>';
        }, $content);

        // --- [gallery_link] ---
        $content = preg_replace_callback('/\[gallery_link\]/i', function () use ($base) {
            $enabled = ($this->config['show_wall_link'] ?? '0') === '1';
            if (!$enabled) return '';
            return '<a href="' . htmlspecialchars($base . 'gallery-wall.php') . '">Floating Gallery</a>';
        }, $content);

        // --- [random_image] ---
        $content = preg_replace_callback('/\[random_image\]/i', function () use ($base) {
            try {
                $stmt = $this->pdo->query("SELECT id, img_file, img_title, img_thumb_aspect FROM snap_images WHERE img_status = 'published' ORDER BY RAND() LIMIT 1");
                $img = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$img) return '';
                $src = !empty($img['img_thumb_aspect']) ? $img['img_thumb_aspect'] : $img['img_file'];
                return sprintf(
                    '<div class="snap-inline-frame align-center"><div class="ip-ascii-frame-inner"><img src="%s" alt="%s" loading="lazy" data-lightbox-src="%s" style="cursor:zoom-in"></div></div>',
                    htmlspecialchars($base . ltrim($src, '/')),
                    htmlspecialchars($img['img_title'] ?? 'Random image'),
                    htmlspecialchars($base . ltrim($img['img_file'], '/'))
                );
            } catch (PDOException $e) { return ''; }
        }, $content);

        // --- [latest_image] ---
        $content = preg_replace_callback('/\[latest_image\]/i', function () use ($base) {
            try {
                $stmt = $this->pdo->query("SELECT id, img_file, img_title, img_thumb_aspect FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1");
                $img = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$img) return '';
                $src = !empty($img['img_thumb_aspect']) ? $img['img_thumb_aspect'] : $img['img_file'];
                return sprintf(
                    '<div class="snap-inline-frame align-center"><div class="ip-ascii-frame-inner"><img src="%s" alt="%s" loading="lazy" data-lightbox-src="%s" style="cursor:zoom-in"></div></div>',
                    htmlspecialchars($base . ltrim($src, '/')),
                    htmlspecialchars($img['img_title'] ?? 'Latest image'),
                    htmlspecialchars($base . ltrim($img['img_file'], '/'))
                );
            } catch (PDOException $e) { return ''; }
        }, $content);

        // --- [embed:key] ---
        // Pulls named HTML snippets from the custom_embed_codes setting.
        // Blocks are defined in Smack Your Scripts Up! as [key:name] ... HTML ...
        $content = preg_replace_callback('/\[embed:([a-zA-Z0-9_-]+)\]/i', function ($m) {
            $key = strtolower($m[1]);
            $raw = $this->config['custom_embed_codes'] ?? '';
            if ($raw === '') return '';

            // Parse [key:name] blocks from the stored blob.
            $blocks = preg_split('/^\[key:([a-zA-Z0-9_-]+)\]\s*$/mi', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $embeds = [];
            for ($i = 0, $len = count($blocks); $i < $len - 1; $i += 2) {
                $embeds[strtolower(trim($blocks[$i]))] = trim($blocks[$i + 1]);
            }

            return $embeds[$key] ?? '';
        }, $content);

        return $content;
    }

    // =========================================================================
    //  IMAGE SHORTCODE
    // =========================================================================

    /**
     * Parse [img:ID|size|align] shortcodes into <img> tags.
     *
     * Looks up the asset in snap_assets first, falls back to snap_images.
     * Supports size variants (small/wall/full) and alignment (left/center/right).
     */
    private function parseImages($content) {
        return preg_replace_callback(
            '/\[img:\s*(g)?\s*(\d+)(?:\s*\|\s*(small|wall|full))?(?:\s*\|\s*(left|center|right))?\s*\]/i',
            function ($matches) {
                $gallery = !empty($matches[1]);   // optional 'g' prefix ([img:gID]) forces Gallery
                $id      = $matches[2];
                $size    = $matches[3] ?? 'full';
                $align   = $matches[4] ?? 'center';

                $asset = false;

                if ($gallery) {
                    // --- GALLERY-FORCED LOOKUP (snap_images only) ---
                    // [img:gID] unambiguously targets a POST image (the Gallery),
                    // sidestepping the id-space collision with snap_assets: both
                    // tables number from 1, so a plain [img:ID] would resolve the
                    // Library asset of the same id first. snap_images has no border
                    // columns; defaults leave it borderless.
                    $stmt = $this->pdo->prepare("SELECT img_file as path, img_title as name FROM snap_images WHERE id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    $asset = $stmt->fetch();
                } else {
                    // --- ASSET LOOKUP (PRIORITY 1) ---
                    // Try media assets first (smack-media.php uploads). The global
                    // per-asset border (width + colour) rides along on this existing
                    // SELECT — zero extra queries, applied everywhere [img:ID] renders.
                    // Fallback SELECT (no border cols) protects blogs where the schema
                    // sync hasn't added the columns yet — avoids an Unknown-column fatal.
                    try {
                        $stmt = $this->pdo->prepare("SELECT asset_path as path, asset_name as name, asset_border_width as bw, asset_border_color as bc FROM snap_assets WHERE id = ? LIMIT 1");
                        $stmt->execute([$id]);
                        $asset = $stmt->fetch();
                    } catch (\PDOException $e) {
                        $stmt = $this->pdo->prepare("SELECT asset_path as path, asset_name as name FROM snap_assets WHERE id = ? LIMIT 1");
                        $stmt->execute([$id]);
                        $asset = $stmt->fetch();
                    }

                    // --- FALLBACK TO SNAP_IMAGES (PRIORITY 2) ---
                    // snap_images has no border columns; defaults leave it borderless.
                    if (!$asset) {
                        $stmt = $this->pdo->prepare("SELECT img_file as path, img_title as name FROM snap_images WHERE id = ? LIMIT 1");
                        $stmt->execute([$id]);
                        $asset = $stmt->fetch();
                    }
                }

                if (!$asset) return "";

                // --- GLOBAL BORDER (per-asset) ---
                $bw = max(0, min(10, (int)($asset['bw'] ?? 0)));
                $bc = $asset['bc'] ?? '#000000';
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$bc)) {
                    $bc = '#000000';
                }
                $border_css = $bw > 0 ? sprintf('border:%dpx solid %s;', $bw, $bc) : '';

                // Determine base URL from environment or config
                $base     = defined('BASE_URL') ? BASE_URL : (rtrim($this->config['site_url'] ?? '/', '/') . '/');
                $raw_path = ltrim($asset['path'], '/');

                // --- PATH RESOLUTION ---
                $filename = basename($raw_path);
                $folder   = str_replace($filename, '', $raw_path);

                if ($size === 'small' && strpos($raw_path, 'uploads/') !== false) {
                    $final_path = $folder . 'thumbs/t_' . $filename;
                } elseif ($size === 'wall' && strpos($raw_path, 'uploads/') !== false) {
                    $final_path = $folder . 'thumbs/wall_' . $filename;
                } else {
                    $final_path = $raw_path;
                }

                $full_src     = $base . $final_path;
                $full_url     = $base . $raw_path;   // always original file, never a thumb
                $classes      = "snap-framed-img asset-$size align-$align";

                return sprintf(
                    '<div class="snap-inline-frame align-%s"><div class="ip-ascii-frame-inner"><img src="%s" class="%s" alt="%s" loading="lazy" data-lightbox-src="%s" style="cursor:zoom-in;%s"></div></div>',
                    $align,
                    $full_src,
                    $classes,
                    htmlspecialchars($asset['name']),
                    htmlspecialchars($full_url),
                    $border_css
                );
            },
            $content
        );
    }

    // =========================================================================
    //  ATTRIBUTE PARSER HELPER
    // =========================================================================

    /**
     * Parse key="value" or key='value' pairs from a shortcode tag string.
     * Returns an associative array. Keys are lowercased.
     */
    private function parseAttrs(string $tag_str): array {
        $attrs = [];
        preg_match_all('/(\w[\w-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag_str, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $attrs[strtolower($match[1])] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        }
        return $attrs;
    }

    // =========================================================================
    //  LAYOUT SHORTCODES (Phase 1b — before autoParagraph)
    // =========================================================================

    /**
     * Parse [card-grid cols="N" canvas="light|dark"]
     *   [card label="" title="" tagline=""]body[/card]
     * [/card-grid]
     */
    private function parseCardGrids(string $content): string {
        return preg_replace_callback(
            '/\[card-grid([^\]]*)\](.*?)\[\/card-grid\]/si',
            function ($matches) {
                $attrs  = $this->parseAttrs($matches[1]);
                $cols   = max(2, min(4, (int) ($attrs['cols'] ?? 3)));
                $canvas = ($attrs['canvas'] ?? '') === 'dark' ? 'dark' : 'light';
                $inner  = $matches[2];

                $cards_html = '';
                preg_match_all('/\[card([^\]]*)\](.*?)\[\/card\]/si', $inner, $cards, PREG_SET_ORDER);
                foreach ($cards as $card) {
                    $ca      = $this->parseAttrs($card[1]);
                    $label   = htmlspecialchars($ca['label']   ?? '');
                    $title   = htmlspecialchars($ca['title']   ?? '');
                    $tagline = htmlspecialchars($ca['tagline'] ?? '');
                    $body    = $this->autoParagraph(trim($card[2]));

                    $cards_html .= '<div class="snap-card">' . "\n";
                    if ($label   !== '') $cards_html .= '  <div class="snap-card-label">'   . $label   . '</div>' . "\n";
                    if ($title   !== '') $cards_html .= '  <h3 class="snap-card-title">'    . $title   . '</h3>'  . "\n";
                    if ($tagline !== '') $cards_html .= '  <div class="snap-card-tagline">' . $tagline . '</div>' . "\n";
                    $cards_html .= '  <div class="snap-card-body">' . $body . '</div>' . "\n";
                    $cards_html .= '</div>' . "\n";
                }

                return '<div class="snap-card-grid snap-card-grid--cols-' . $cols
                    . ' snap-card-grid--' . $canvas . '">' . "\n"
                    . $cards_html . '</div>';
            },
            $content
        );
    }

    /**
     * Parse [accent-grid cols="N"]
     *   [accent-card title=""]body[/accent-card]
     * [/accent-grid]
     */
    private function parseAccentGrids(string $content): string {
        return preg_replace_callback(
            '/\[accent-grid([^\]]*)\](.*?)\[\/accent-grid\]/si',
            function ($matches) {
                $attrs = $this->parseAttrs($matches[1]);
                $cols  = max(2, min(4, (int) ($attrs['cols'] ?? 3)));
                $inner = $matches[2];

                $cards_html = '';
                preg_match_all('/\[accent-card([^\]]*)\](.*?)\[\/accent-card\]/si', $inner, $cards, PREG_SET_ORDER);
                foreach ($cards as $card) {
                    $ca    = $this->parseAttrs($card[1]);
                    $title = htmlspecialchars($ca['title'] ?? '');
                    $body  = trim($card[2]);

                    $cards_html .= '<div class="snap-accent-card">' . "\n";
                    if ($title !== '') $cards_html .= '  <h3 class="snap-accent-card-title">' . $title . '</h3>' . "\n";
                    $cards_html .= '  <div class="snap-accent-card-body">' . $body . '</div>' . "\n";
                    $cards_html .= '</div>' . "\n";
                }

                return '<div class="snap-accent-grid snap-accent-grid--cols-' . $cols . '">' . "\n"
                    . $cards_html . '</div>';
            },
            $content
        );
    }

    /**
     * Parse [feature-box title=""]item one|item two|item three[/feature-box]
     * Dark box with heading and checkmark list. Items are pipe-separated.
     */
    private function parseFeatureBoxes(string $content): string {
        return preg_replace_callback(
            '/\[feature-box([^\]]*)\](.*?)\[\/feature-box\]/si',
            function ($matches) {
                $attrs = $this->parseAttrs($matches[1]);
                $title = htmlspecialchars($attrs['title'] ?? '');
                $items = array_filter(array_map('trim', explode('|', $matches[2])));

                $html  = '<div class="snap-feature-box">' . "\n";
                if ($title !== '') $html .= '  <h3 class="snap-feature-box-title">' . $title . '</h3>' . "\n";
                $html .= '  <ul class="snap-list snap-list--check snap-list--inverted">' . "\n";
                foreach ($items as $item) {
                    $html .= '    <li>' . htmlspecialchars($item) . '</li>' . "\n";
                }
                $html .= '  </ul>' . "\n";
                $html .= '</div>';
                return $html;
            },
            $content
        );
    }

    /**
     * Parse [bio img="" name="" role=""]text[/bio]
     * Portrait + name + role + bio copy. Portrait omitted if img is empty.
     */
    private function parseBios(string $content): string {
        return preg_replace_callback(
            '/\[bio([^\]]*)\](.*?)\[\/bio\]/si',
            function ($matches) {
                $attrs = $this->parseAttrs($matches[1]);
                $img   = htmlspecialchars($attrs['img']  ?? '');
                $name  = htmlspecialchars($attrs['name'] ?? '');
                $role  = htmlspecialchars($attrs['role'] ?? '');
                $text  = trim($matches[2]);

                $html  = '<div class="snap-bio">' . "\n";
                if ($img !== '') {
                    $html .= '  <div class="snap-bio-portrait">'
                           . '<img src="' . $img . '" alt="' . $name . '" loading="lazy">'
                           . '</div>' . "\n";
                }
                $html .= '  <div class="snap-bio-copy">' . "\n";
                if ($name !== '') $html .= '    <div class="snap-bio-name">' . $name . '</div>' . "\n";
                if ($role !== '') $html .= '    <div class="snap-bio-role">' . $role . '</div>' . "\n";
                $html .= '    <div class="snap-bio-text">' . $text . '</div>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '</div>';
                return $html;
            },
            $content
        );
    }

    // =========================================================================
    //  PROSE SHORTCODES (Phase 6c — after autoParagraph)
    // =========================================================================

    /**
     * Parse [lede]text[/lede] — large grey introductory paragraph.
     */
    private function parseLede(string $content): string {
        return preg_replace(
            '/\[lede\](.*?)\[\/lede\]/si',
            '<p class="snap-lede">$1</p>',
            $content
        );
    }

    /**
     * Parse [callout]text[/callout] — red left-border info box.
     * Inner content is auto-paragraphed.
     */
    private function parseCallout(string $content): string {
        return preg_replace_callback(
            '/\[callout\](.*?)\[\/callout\]/si',
            function ($m) {
                return '<div class="snap-callout">' . $this->autoParagraph(trim($m[1])) . '</div>';
            },
            $content
        );
    }

    /**
     * Parse [kicker]text[/kicker] — small uppercase label above a heading.
     */
    private function parseKicker(string $content): string {
        return preg_replace(
            '/\[kicker\](.*?)\[\/kicker\]/si',
            '<div class="snap-kicker">$1</div>',
            $content
        );
    }

    /**
     * Parse [dict word="" phon="" pos=""]definition[/dict]
     * Full-width dictionary-definition interstitial. phon and pos are optional.
     */
    private function parseDictPull(string $content): string {
        return preg_replace_callback(
            '/\[dict([^\]]*)\](.*?)\[\/dict\]/si',
            function ($m) {
                $attrs = $this->parseAttrs($m[1]);
                $word  = htmlspecialchars($attrs['word'] ?? '');
                $phon  = htmlspecialchars($attrs['phon'] ?? '');
                $pos   = htmlspecialchars($attrs['pos']  ?? '');
                $def   = trim($m[2]);

                $meta  = '';
                if ($word !== '') $meta .= '<span class="snap-dict-word">' . $word . '</span>';
                if ($phon !== '') $meta .= '<span class="snap-dict-phon">/' . $phon . '/</span>';
                if ($pos  !== '') $meta .= '<span class="snap-dict-pos">' . $pos . '</span>';
                if ($meta !== '') $meta .= '<br>';

                return '<div class="snap-dict-pull"><div class="snap-dict-inner">'
                    . $meta . $def
                    . '</div></div>';
            },
            $content
        );
    }

    /**
     * Parse [list bullet="check|arrow"]item one|item two[/list]
     * Styled unordered list. Items are pipe-separated. Defaults to check.
     */
    private function parseSnapList(string $content): string {
        return preg_replace_callback(
            '/\[list([^\]]*)\](.*?)\[\/list\]/si',
            function ($m) {
                $attrs  = $this->parseAttrs($m[1]);
                $bullet = ($attrs['bullet'] ?? '') === 'arrow' ? 'arrow' : 'check';
                $items  = array_filter(array_map('trim', explode('|', $m[2])));

                $html = '<ul class="snap-list snap-list--' . $bullet . '">' . "\n";
                foreach ($items as $item) {
                    $html .= '<li>' . htmlspecialchars($item) . '</li>' . "\n";
                }
                $html .= '</ul>';
                return $html;
            },
            $content
        );
    }

    /**
     * Parse [btn href="" style="primary|secondary"]label[/btn]
     * CTA button. Renders as <span> when href is empty.
     */
    private function parseSnapBtn(string $content): string {
        return preg_replace_callback(
            '/\[btn([^\]]*)\](.*?)\[\/btn\]/si',
            function ($m) {
                $attrs  = $this->parseAttrs($m[1]);
                $href   = htmlspecialchars($attrs['href'] ?? '');
                $style  = ($attrs['style'] ?? '') === 'secondary' ? 'secondary' : 'primary';
                $label  = trim($m[2]);
                $class  = 'snap-btn snap-btn--' . $style;

                return $href !== ''
                    ? '<a href="' . $href . '" class="' . $class . '">' . $label . '</a>'
                    : '<span class="' . $class . '">' . $label . '</span>';
            },
            $content
        );
    }
}

// ===== SNAPSMACK EOF =====
