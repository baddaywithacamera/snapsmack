<?php
/**
 * SNAPSMACK - Galleria static page template
 * Alpha v0.7.8
 *
 * Renders static pages (page.php) inside the Galleria shell: wall background,
 * sticky header, hero image wrapped in the full frame system, proper content
 * column with gutters. Replaces the generic page.php template entirely.
 */

// Skin-level frame settings (from compiled custom_css_public / manifest defaults)
$frame_color = $settings['htbs_frame_color'] ?? '#2c2017';
$frame_width = $settings['htbs_frame_width'] ?? '8';
$mat_color   = $settings['htbs_mat_color']   ?? '#f5f0eb';
$mat_width   = $settings['htbs_mat_width']   ?? '24';

// Derive inline root overrides for this page's frame
$frame_vars = implode('', [
    "--frame-color:{$frame_color};",
    "--frame-width:{$frame_width}px;",
    "--mat-color:{$mat_color};",
    "--mat-width:{$mat_width}px;",
]);

include __DIR__ . '/skin-meta.php';
?>
<style>
    /* Page-specific overrides: scrollable, wall background retained */
    body.htbs-page { overflow-y: auto; }
    .htbs-page-stage {
        padding-top: calc(var(--header-height, 60px) + 40px);
        min-height: 100vh;
        box-sizing: border-box;
    }

    /* Content card: white page sitting on the wall */
    .htbs-page-content {
        max-width: var(--static-content-width, 720px);
        margin: 0 auto;
        padding: 60px var(--static-content-gutter, 40px) 80px;
        box-sizing: border-box;
        background: #ffffff;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18), 0 8px 40px rgba(0, 0, 0, 0.10);
    }

    /* Title: no separator line — the hero image below it does that job */
    .htbs-page-content .static-page-title {
        margin-bottom: 36px;
        padding-bottom: 0;
        border-bottom: none;
        color: #111111;
    }

    /* Hero frame: sits within the card padding */
    .htbs-page-hero {
        margin: 0 0 48px;
    }
    .htbs-page-hero .frame-mount {
        width: 100%;
    }
    .htbs-page-hero .frame-image img {
        display: block;
        width: 100%;
        height: auto;
        max-width: 100%;
    }

    /* Body text: larger, near-black, generous line height */
    .htbs-page-content .description p {
        font-size: 1.05rem;
        line-height: 1.9;
        margin-bottom: 1.4em;
        color: #1a1a1a;
    }
    .htbs-page-content .description p:first-child {
        font-size: 1.1rem;
    }

    /* Headings inside the card: generous top space to signal a new section,
       tight bottom space so the heading stays close to its own content. */
    .htbs-page-content .description h2,
    .htbs-page-content .description h3,
    .htbs-page-content .description h4 {
        margin-top: 1.8em;
        margin-bottom: 0.4em;
        color: #111111;
    }
    .htbs-page-content .description h2:first-child,
    .htbs-page-content .description h3:first-child,
    .htbs-page-content .description h4:first-child { margin-top: 0; }

    /* Lists: match paragraph line-height and bottom spacing */
    .htbs-page-content .description ul,
    .htbs-page-content .description ol {
        margin-top: 0;
        margin-bottom: 1.4em;
        padding-left: 1.4em;
    }
    .htbs-page-content .description li {
        font-size: 1.05rem;
        line-height: 1.9;
        color: #1a1a1a;
        margin-bottom: 0.3em;
    }
    .htbs-page-content .description li:last-child { margin-bottom: 0; }

    /* Links inside the card — no underline (matches hero treatment) */
    .htbs-page-content .description a {
        color: #333333;
        text-decoration: none;
    }

    /* Hero image link: lightbox wraps img.post-image in <a> — suppress underline/border */
    .htbs-page-hero a,
    .htbs-page-hero a:has(img) {
        text-decoration: none !important;
        border-bottom: none !important;
    }

    @media (max-width: 640px) {
        .htbs-page-content { padding: 40px 20px 60px; }
    }
</style>
<body class="static-transmission htbs-page">
<div id="page-wrapper">
    <div id="scroll-stage" class="htbs-page-stage" style="<?php echo $frame_vars; ?>">

        <?php include __DIR__ . '/skin-header.php'; ?>

        <div class="htbs-page-content">
            <h1 class="static-page-title"><?php echo $page_title; ?></h1>

            <?php if (!empty($page_data['image_asset'])): ?>
                <div class="htbs-page-hero">
                    <div class="frame-mount">
                        <div class="frame-border">
                            <div class="frame-mat">
                                <div class="frame-bevel">
                                    <div class="frame-image">
                                        <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                                             class="post-image"
                                             alt="<?php echo $page_title; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="description">
                <?php
                if (!empty($page_data['content'])) {
                    echo $snapsmack->parseContent($page_data['content']);
                } else {
                    echo "<p class='dim'>No content signal found for this sector.</p>";
                }
                ?>
            </div>
        </div>

        <?php include __DIR__ . '/skin-footer.php'; ?>

    </div>
</div>
<?php include __DIR__ . '/../../core/footer-scripts.php'; ?>
</body>
</html>
