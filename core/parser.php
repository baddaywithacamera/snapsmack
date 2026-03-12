<?php
/**
 * SNAPSMACK - Content Parser and Asset Router
 * Alpha v0.7.2
 *
 * Parses shortcodes in content and converts them to rich HTML.
 *
 * Supported shortcodes:
 *   [img:ID|size|align]              — inline image from media library or posts
 *   [columns=N] ... [col] ... [/columns] — multi-column grid layout
 *   [dropcap]X[/dropcap]            — decorative first-letter dropcap
 *   [spacer:N]                       — vertical gap (1–100 pixels)
 *
 * Auto-paragraph: double newlines (\n\n) become <p> tags automatically.
 * Single newlines within a paragraph become <br>.
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

        // --- PHASE 2: AUTO-PARAGRAPH ---
        // Convert double newlines to <p> tags for any remaining top-level text.
        $content = $this->autoParagraph($content);

        // --- PHASE 3: DROPCAP ---
        $content = $this->parseDropcap($content);

        // --- PHASE 4: IMAGE SHORTCODES ---
        $content = $this->parseImages($content);

        // --- PHASE 5: SPACER SHORTCODES ---
        $content = $this->parseSpacers($content);

        // --- PHASE 6: BLOCK NESTING CLEANUP ---
        // When [img:] shortcodes sit inside the same <p> as text (either from
        // old saves or same-line authoring), Phase 2 wraps everything in <p>
        // and Phase 4 converts the shortcode to a <div>, creating invalid
        // <p><div>...</div>text</p>. Split the div out and re-wrap leftovers.
        $content = $this->cleanBlockNesting($content);

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
        $block_tags = 'h[1-6]|div|blockquote|ul|ol|li|table|thead|tbody|tr|td|th|figure|figcaption|pre|hr|form|section|article|aside|nav|header|footer|p';

        // Split content into segments: block-level HTML vs. text
        // This regex matches self-contained block elements (opening through closing)
        // and also standalone <hr>, <br> etc.
        $segments = preg_split(
            '/(<(?:' . $block_tags . ')[\s>].*?<\/(?:' . $block_tags . ')>|<hr\s*\/?>)/si',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $output = '';
        foreach ($segments as $segment) {
            $trimmed = trim($segment);
            if ($trimmed === '') continue;

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
     * Parse [spacer:N] shortcodes into vertical gap divs.
     *
     * Accepts pixel values 1–100. Values outside range are clamped.
     * Renders as an empty div with an explicit height.
     */
    private function parseSpacers($content) {
        return preg_replace_callback(
            '/\[spacer:\s*(\d+)\]/i',
            function ($matches) {
                $px = max(1, min(100, (int) $matches[1]));
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
        return preg_replace_callback(
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
            '/\[img:\s*(\d+)(?:\s*\|\s*(small|wall|full))?(?:\s*\|\s*(left|center|right))?\s*\]/i',
            function ($matches) {
                $id    = $matches[1];
                $size  = $matches[2] ?? 'full';
                $align = $matches[3] ?? 'center';

                // --- ASSET LOOKUP (PRIORITY 1) ---
                // Try media assets first (smack-media.php uploads)
                $stmt = $this->pdo->prepare("SELECT asset_path as path, asset_name as name FROM snap_assets WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $asset = $stmt->fetch();

                // --- FALLBACK TO SNAP_IMAGES (PRIORITY 2) ---
                if (!$asset) {
                    $stmt = $this->pdo->prepare("SELECT img_file as path, img_title as name FROM snap_images WHERE id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    $asset = $stmt->fetch();
                }

                if (!$asset) return "";

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

                $full_src = $base . $final_path;
                $classes  = "snap-framed-img asset-$size align-$align";

                return sprintf(
                    '<div class="snap-inline-frame align-%s"><div class="ip-ascii-frame-inner"><img src="%s" class="%s" alt="%s" loading="lazy"></div></div>',
                    $align,
                    $full_src,
                    $classes,
                    htmlspecialchars($asset['name'])
                );
            },
            $content
        );
    }
}
