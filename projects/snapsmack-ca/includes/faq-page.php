<?php
/**
 * SNAPSMACK.CA - Shared FAQ page layout (BRASS TACKS! split into sections).
 *
 * The requiring page supplies metadata plus:
 *   $faq_slug     - this page's key (faq-why | faq-running | ...)
 *   $faq_section  - section title
 *   $faq_lede     - one line under it
 *   $faq_qas      - HTML: the .qa blocks (verbatim from the original FAQ)
 *
 * The question wording and #q-* anchors are the source of record from
 * _continuity/brass-tacks-v0_6.docx. Substance edits only; keep the voice.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$faq_pages = [
    'faq-why' => ['Why SnapSmack', 'Why it exists, where it came from, why it swears, and what the catch is.'],
    'faq-running' => ['Running it', 'How techie you need to be, what it needs, what it runs on, and why the phone is second fiddle.'],
    'faq-content' => ['Your content', 'Lock-in (none), what you can bring, and who this is for.'],
    'faq-tools' => ['Desktop tools', 'The free Windows and Linux suite: which tool does what, where your files go, and what it never does.'],
    'faq-fediverse' => ['Fediverse &amp; discovery', 'The network, the directory, the weekly challenge, and why you need a password to join.'],
    'faq-security' => ['Security', 'Is it secure, what SMACKBACK is, why you keep re-authenticating, and what happens if you get hacked.'],
    'faq-future' => ['Trust &amp; the future', 'Why trust it, will it stay free, and what is (and isn\'t) coming.']
];
$nav_active = $faq_slug;
$page_css = <<<'CSS'
.page-header { padding-bottom: 28px; }
.page-header .lede { max-width: 72ch; }
.faq-crumb { margin-bottom: 14px; }
.faq-crumb a { color: var(--red); font: 900 .72rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: .04em; }
.faq-subnav { padding: 24px 0 0; }
.faq-subnav ul { display: flex; flex-wrap: wrap; gap: 6px 8px; margin: 0; padding: 0; list-style: none; }
.faq-subnav a { display: inline-block; padding: 8px 12px; border: 1px solid var(--border); color: var(--dark-grey); font: 900 .7rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: .03em; }
.faq-subnav a:hover { border-color: var(--red); color: var(--black); text-decoration: none; }
.faq-subnav a.active { background: var(--black); border-color: var(--black); color: var(--white); }
.faq-section { padding: 40px 0 64px; border-top: 0; }
.qa { max-width: 820px; padding: 36px 0; border-bottom: 1px solid var(--border); scroll-margin-top: 80px; }
.qa:last-of-type { border-bottom: none; }
.qa h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 1.15rem; color: var(--red); text-transform: none;
    letter-spacing: 0; margin-bottom: 14px; line-height: 1.25;
}
.qa p { margin-bottom: 1.2em; max-width: 72ch; }
.qa p:last-child { margin-bottom: 0; }
.qa ul { margin: 0 0 1.2em 1.4em; max-width: 72ch; }
.callout a { color: var(--red); font-weight: bold; }
.callout a:hover { color: var(--black); }
.faq-more { padding: 40px 0 72px; border-top: 8px solid var(--black); background: #f4f1eb; }
.faq-more h2 { margin-bottom: 18px; }
.faq-more-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.faq-more-grid a { display: block; padding: 18px 20px; background: var(--white); color: var(--black); }
.faq-more-grid a strong { display: block; font: 900 .85rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.faq-more-grid a span { display: block; margin-top: 5px; color: var(--mid-grey); font-size: .84rem; line-height: 1.45; }
.faq-more-grid a:hover { background: #fff5f3; text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
.faq-more-grid a.active { background: var(--light-grey); box-shadow: inset 0 -4px 0 var(--black); }
@media (max-width: 700px) { .faq-more-grid { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/header.php';
?>
<main>
    <div class="page-header">
        <div class="wrap">
            <p class="faq-crumb"><a href="brass-tacks.php">&larr; BRASS TACKS! &middot; every question</a></p>
            <p class="site-discovery-kicker">BRASS TACKS! &middot; <?php echo $faq_section; ?></p>
            <h1><?php echo $faq_section; ?></h1>
            <p class="lede"><?php echo $faq_lede; ?></p>
            <nav class="faq-subnav" aria-label="FAQ sections">
                <ul>
<?php foreach ($faq_pages as $slug => [$title, $lede]): ?>
                    <li><a href="<?php echo $slug; ?>.php"<?php echo $slug === $faq_slug ? ' class="active"' : ''; ?>><?php echo $title; ?></a></li>
<?php endforeach; ?>
                </ul>
            </nav>
        </div>
    </div>

    <section class="faq-section">
        <div class="wrap">
<?php echo $faq_qas; ?>
        </div>
    </section>

    <section class="faq-more">
        <div class="wrap">
            <p class="site-discovery-kicker">More brass tacks</p>
            <h2>The other sections</h2>
            <div class="faq-more-grid">
<?php foreach ($faq_pages as $slug => [$title, $lede]): ?>
                <a href="<?php echo $slug; ?>.php"<?php echo $slug === $faq_slug ? ' class="active"' : ''; ?>><strong><?php echo $title; ?></strong><span><?php echo $lede; ?></span></a>
<?php endforeach; ?>
            </div>
            <p class="updated">Written by Sean McCormick with Claude. The code is AI — said up front, here and in the repo.</p>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
