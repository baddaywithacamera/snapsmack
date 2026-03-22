<?php
/**
 * SNAPSMACK - Hip to be Square static page template
 * Alpha v0.7.5
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

    /* Hero frame: floats on the wall, centred */
    .htbs-page-hero {
        display: flex;
        justify-content: center;
        padding: 0 40px 80px;
    }
    .htbs-page-hero .frame-mount {
        max-width: min(780px, 90vw);
    }
    .htbs-page-hero .frame-image img {
        display: block;
        width: 100%;
        height: auto;
        max-width: 100%;
    }

    /* Content card: white page sitting on the wall */
    .htbs-page-content {
        max-width: var(--static-content-width, 720px);
        margin: 0 auto;
        padding: 60px var(--static-content-gutter, 40px) 80px;
        box-sizing: border-box;
        background: #ffffff;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.28), 0 8px 40px rgba(0, 0, 0, 0.15);
    }

    .htbs-page-content .static-page-title {
        margin-bottom: 36px;
        color: #111111;
    }

    .htbs-page-content .description p {
        font-size: 1.05rem;
        line-height: 1.9;
        margin-bottom: 1.4em;
        color: #1a1a1a;
    }
    .htbs-page-content .description p:first-child {
        font-size: 1.1rem;
    }

    .htbs-page-content .description a {
        color: #333333;
    }

    @media (max-width: 640px) {
        .htbs-page-hero  { padding: 0 20px 48px; }
        .htbs-page-content { padding: 40px 20px 60px; }
    }
</style>
<body class="static-transmission htbs-page">
<div id="page-wrapper">
    <div id="scroll-stage" class="htbs-page-stage" style="<?php echo $frame_vars; ?>">

        <?php include __DIR__ . '/skin-header.php'; ?>

        <?php if (!empty($page_data['image_asset'])): ?>
            <div class="htbs-page-hero">
                <div class="frame-mount">
                    <div class="frame-border">
                        <div class="frame-mat">
                            <div class="frame-bevel">
                                <div class="frame-image">
                                    <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                                         alt="<?php echo $page_title; ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="htbs-page-content">
            <h1 class="static-page-title"><?php echo $page_title; ?></h1>
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
