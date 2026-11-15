<?php
/**
 * SNAPSMACK.CA — Brass Tacks (single-page FAQ)
 *
 * The question copy stays in the seven faq-*.php source files so the old URLs
 * can issue precise section redirects without duplicating the answers here.
 * Source of record for the wording: _continuity/brass-tacks-v0_6.docx.
 * Deliberately brash, profane, honest. Keep the voice — substance edits only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$faq_pages = [
    'faq-why' => ['Why SnapSmack', 'Why it exists, where it came from, why it swears, and what the catch is.'],
    'faq-running' => ['Running it', 'How techie you need to be, what it needs, what it runs on, and why the phone is second fiddle.'],
    'faq-content' => ['Your content', 'Lock-in (none), what you can bring, and who this is for.'],
    'faq-tools' => ['Desktop tools', 'The free Windows and Linux suite: which tool does what, where your files go, and what it never does.'],
    'faq-fediverse' => ['Fediverse &amp; discovery', 'The network, the directory, the weekly challenge, and why you need a password to join.'],
    'faq-security' => ['Security', 'Is it secure, what SMACKBACK is, why you keep re-authenticating, and what happens if you get hacked.'],
    'faq-future' => ['Trust &amp; the future', 'Why trust it, will it stay free, and what is (and isn\'t) coming.'],
];

define('SNAPSMACK_FAQ_ASSEMBLY', true);
$faq_sections = [];
foreach ($faq_pages as $slug => [$title, $lede]) {
    require __DIR__ . '/' . $slug . '.php';
    $faq_sections[$slug] = [
        'title' => $faq_section,
        'lede' => $faq_lede,
        'qas' => $faq_qas,
    ];
}

// The answer copy is unchanged; only old section-page links now point directly
// at their destination on this page.
$old_section_links = array_map(static fn (string $slug): string => $slug . '.php#', array_keys($faq_pages));
foreach ($faq_sections as &$section) {
    $section['qas'] = str_replace($old_section_links, '#', $section['qas']);
    foreach (array_keys($faq_pages) as $slug) {
        $section['qas'] = str_replace($slug . '.php', '#' . $slug, $section['qas']);
    }
}
unset($section);

$page_title       = 'BRASS TACKS! — The SnapSmack FAQ';
$page_description = 'The SnapSmack FAQ. The facts, no fluff — where it came from, what it costs (nothing), the desktop tools, the fediverse, security, and why it is built the way it is.';
$page_og_url      = 'https://snapsmack.ca/brass-tacks.php';
$nav_active       = 'brass-tacks';

$page_css = <<<'CSS'
.page-header { padding-bottom: 32px; }
.intro-body { max-width: 820px; padding: 24px 0 8px; }
.intro-body p { margin-bottom: 1.4em; max-width: 72ch; }
.slang { background: var(--light-grey); border-left: 4px solid var(--black); padding: 20px 24px; margin: 8px 0 0; font-size: 0.97rem; }
.slang p { margin-bottom: 0; }
.slang strong { color: var(--black); }
.faq-jumps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0; border-top: 1px solid var(--border); border-left: 1px solid var(--border); margin-top: 8px; }
.faq-jumps a { display: block; padding: 18px 20px; background: var(--white); color: var(--black); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.faq-jumps a strong { display: block; font: 900 .85rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.faq-jumps a strong::after { content: " \2193"; color: var(--red); }
.faq-jumps a span { display: block; margin-top: 5px; color: var(--mid-grey); font-size: .82rem; line-height: 1.45; }
.faq-jumps a:hover { background: #fff5f3; text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
.faq-section-group { padding: 54px 0 72px; border-top: 8px solid var(--black); scroll-margin-top: 78px; }
.faq-section-group:nth-of-type(even) { background: #f8f6f2; }
.faq-section-heading { max-width: 820px; margin-bottom: 6px; }
.faq-section-heading h2 { margin-bottom: 8px; }
.faq-section-heading .lede { max-width: 72ch; margin-bottom: 0; }
.qa { max-width: 820px; padding: 34px 0; border-bottom: 1px solid var(--border); scroll-margin-top: 78px; }
.qa:last-child { border-bottom: none; padding-bottom: 0; }
.qa h3 { font-family: Arial Black, Arial, sans-serif; font-size: 1.15rem; color: var(--red); text-transform: none; letter-spacing: 0; margin-bottom: 14px; line-height: 1.25; }
.qa p { margin-bottom: 1.2em; max-width: 72ch; }
.qa p:last-child { margin-bottom: 0; }
.qa ul { margin: 0 0 1.2em 1.4em; max-width: 72ch; }
.faq-signoff { padding: 34px 0 70px; border-top: 1px solid var(--border); }
@media (max-width: 850px) { .faq-jumps { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .faq-jumps { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>BRASS TACKS!</h1>
            <p class="lede">The SnapSmack FAQ. The facts, no fluff.</p>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="intro-body">
                <p>This is the honest version of every question worth asking about SnapSmack — where it came from, what it costs, who built it, and why it's put together the way it is.</p>
                <div class="slang">
                    <p><strong>"Brass tacks"?</strong> As in <em>get down to brass tacks</em> — get down to the facts, the real stuff, the part that actually matters. In Cockney rhyming slang, brass tacks = facts. (Purists will tell you the phrase is older than the rhyme. They're not wrong. The facts are still what this page is.)</p>
                </div>
            </div>
            <nav class="faq-jumps" aria-label="Jump to an FAQ section">
<?php foreach ($faq_pages as $slug => [$title, $lede]): ?>
                <a href="#<?php echo $slug; ?>"><strong><?php echo $title; ?></strong><span><?php echo $lede; ?></span></a>
<?php endforeach; ?>
            </nav>
        </div>
    </section>

<?php foreach ($faq_sections as $slug => $section): ?>
    <section class="faq-section-group" id="<?php echo $slug; ?>">
        <div class="wrap">
            <div class="faq-section-heading">
                <p class="site-discovery-kicker">FAQ section</p>
                <h2><?php echo $section['title']; ?></h2>
                <p class="lede"><?php echo $section['lede']; ?></p>
            </div>
<?php echo $section['qas']; ?>
        </div>
    </section>
<?php endforeach; ?>

    <section class="faq-signoff">
        <div class="wrap">
            <p class="updated">Written by Sean McCormick with Claude. The code is AI — said up front, here and in the repo.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
