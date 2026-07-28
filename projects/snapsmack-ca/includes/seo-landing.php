<?php
/**
 * SNAPSMACK.CA - Shared focused landing-page layout.
 *
 * The requiring page supplies metadata plus:
 *   $landing_eyebrow, $landing_h1, $landing_lede, $landing_sections
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$nav_active = $nav_active ?? '';
$page_css = <<<'CSS'
.seo-hero {
    padding: clamp(64px, 10vw, 120px) 0;
    background: var(--black);
    color: var(--white);
    border-bottom: 5px solid var(--red);
}
.seo-hero .eyebrow {
    color: var(--red);
    font: 800 .78rem/1.2 Arial, sans-serif;
    letter-spacing: .13em;
    text-transform: uppercase;
    margin-bottom: 18px;
}
.seo-hero h1 {
    max-width: 900px;
    font: 900 clamp(2.5rem, 7vw, 5.8rem)/.98 Arial Black, Arial, sans-serif;
    letter-spacing: -.045em; color: var(--white);
    margin-bottom: 24px;
}
.seo-hero .lede { max-width: 760px; color: var(--white); font-size: clamp(1.08rem, 2vw, 1.4rem); line-height: 1.65; }
.seo-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
.seo-actions a {
    display: inline-block; padding: 12px 18px; border: 2px solid var(--red);
    color: var(--white); font: 800 .8rem/1 Arial, sans-serif;
    letter-spacing: .06em; text-transform: uppercase; text-decoration: none;
}
.seo-actions a:first-child { background: var(--red); }
.seo-actions a:hover, .seo-actions a:focus-visible { background: var(--white); color: var(--black); border-color: var(--white); }
.seo-section { padding: clamp(48px, 7vw, 84px) 0; border-bottom: 1px solid var(--border); }
.seo-section:nth-child(even) { background: var(--offwhite); }
.seo-section h2 { max-width: 850px; font: 900 clamp(1.7rem, 3vw, 2.7rem)/1.08 Arial Black, Arial, sans-serif; margin-bottom: 20px; }
.seo-section p, .seo-section li { max-width: 760px; font-size: 1.04rem; line-height: 1.75; }
.seo-section p { margin-bottom: 1em; }
.seo-section ul { margin: 18px 0 0 22px; }
.seo-section a { color: var(--red); font-weight: 700; }
@media (max-width: 480px) {
    .seo-hero .wrap { padding-left: 18px; padding-right: 18px; }
    .seo-hero h1 { font-size: 2rem; overflow-wrap: anywhere; }
}
CSS;

require_once __DIR__ . '/header.php';
?>
<main>
    <header class="seo-hero">
        <div class="wrap">
            <p class="eyebrow"><?php echo htmlspecialchars($landing_eyebrow); ?></p>
            <h1><?php echo htmlspecialchars($landing_h1); ?></h1>
            <p class="lede"><?php echo htmlspecialchars($landing_lede); ?></p>
            <div class="seo-actions">
                <a href="index.php#beta">Apply for the Closed Beta</a>
                <a href="brass-tacks.php">Read the Honest FAQ</a>
            </div>
        </div>
    </header>

<?php foreach ($landing_sections as $section): ?>
    <section class="seo-section">
        <div class="wrap">
            <h2><?php echo htmlspecialchars($section['heading']); ?></h2>
<?php foreach ($section['body'] as $paragraph): ?>
            <p><?php echo $paragraph; ?></p>
<?php endforeach; ?>
<?php if (!empty($section['list'])): ?>
            <ul>
<?php foreach ($section['list'] as $item): ?>
                <li><?php echo $item; ?></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
