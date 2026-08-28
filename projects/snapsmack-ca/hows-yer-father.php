<?php
/**
 * SNAPSMACK.CA - HOW'S YER FATHER?
 * Architecture, lineage, and the lessons SnapSmack chose to inherit.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = "HOW'S YER FATHER? - SnapSmack Architecture and Lineage";
$page_description = 'Where SnapSmack came from, who taught it the good bits, and why its architecture refuses the usual answers.';
$page_og_url      = 'https://snapsmack.ca/hows-yer-father.php';
$page_social_title = "HOW'S YER FATHER? - The SnapSmack Family Tree";
$page_social_description = 'Greymatter, Pixelpost, skin manifests, internal support, layered security, and the lessons behind SnapSmack.';
$nav_active       = 'hows-yer-father';

$page_css = <<<'CSS'
.lineage-intro { max-width: 820px; }
.family-section:nth-of-type(even) { background: var(--light-grey); }
.family-copy { max-width: 850px; }
.family-copy > p { max-width: 74ch; margin-bottom: 1.35em; }
.family-copy > p:last-child { margin-bottom: 0; }
.family-kicker { color: var(--red); font: 900 .74rem/1.2 Arial Black, Arial, sans-serif; letter-spacing: .08em; text-transform: uppercase; }
.family-callout { margin: 30px 0 0; padding: 24px 28px; color: var(--white); background: var(--black); border-left: 6px solid var(--red); }
.family-callout strong { color: var(--white); }
.inheritance-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; margin-top: 32px; background: var(--border); border: 1px solid var(--border); }
.inheritance-card { padding: 28px; background: var(--white); }
.inheritance-card h3 { margin-bottom: 10px; color: var(--red); font-size: 1rem; }
.inheritance-card p { margin: 0; font-size: .9rem; line-height: 1.6; }
.claim-box { padding: 32px; border: 3px solid var(--black); background: var(--white); }
.claim-box h2 { color: var(--red); }
.page-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
.page-actions a { display: inline-block; padding: 12px 18px; border: 2px solid var(--black); color: var(--black); font: 900 .76rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.page-actions a:hover { color: var(--white); background: var(--black); text-decoration: none; }
@media (max-width: 700px) { .inheritance-grid { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">THE FAMILY TREE, WITH THE BARK LEFT ON</p>
            <h1>How&rsquo;s Yer Father?</h1>
            <p class="lede lineage-intro">Where SnapSmack came from, who taught it the good bits, and why it refuses to behave like ordinary software.</p>
        </div>
    </section>

    <section class="family-section">
        <div class="wrap family-copy">
            <p class="family-kicker">A Fine Pedigree</p>
            <h2>The family jewels were never venture capital.</h2>
            <p>SnapSmack did not appear from nowhere. It follows a trail broken by personal publishing systems, photoblogging projects, and the people who sustained them&mdash;often with too little help or recognition. Preserving that history means naming the work, learning from what its maintainers endured, and carrying the best of it forward.</p>
            <p><strong><a href="https://bsky.app/profile/thatnoahgrey.bsky.social" target="_blank" rel="noopener">Noah Grey</a> comes first.</strong> He inspired me as a photographer, a creator, and a person. He is senpai. I am kohai. SnapSmack is the kohai&rsquo;s offering. His photographs and his work on <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank" rel="noopener">Greymatter</a> showed me that publishing software could be personal, independent, generous, strange, and unmistakably human.</p>
            <p>Pixelpost carried that independent photoblogging spirit into a system built specifically around photographs. Its interface shaped much of what SnapSmack became, and its history supplied lessons worth taking seriously rather than quietly strip-mining for nostalgia.</p>
            <div class="family-callout"><strong>Well-bred, badly behaved.</strong> SnapSmack respects its lineage without embalming it. The point is to preserve what was right, learn from what hurt, and build the thing photographers need now.</div>
        </div>
    </section>

    <section class="family-section">
        <div class="wrap family-copy">
            <p class="family-kicker">Bad Habits We Didn&rsquo;t Inherit</p>
            <h2>No plugin pile. No public support honeypot.</h2>
            <p><strong>No arbitrary plugins.</strong> A SnapSmack skin manifest is not executable code. It is a validated, declarative shopping list describing the skin and naming the approved layouts, controls, fonts, and effects it needs. The CMS supplies those capabilities from its shared, reviewed library. A skin can ask for the masonry engine or a film effect; it cannot arrive carrying an unknown script and run it.</p>
            <p>This keeps presentation separate from machinery. One library engine can serve radically different skins, and a security or compatibility repair reaches every skin that declared it. Remove a skin and it removes its presentation without leaving abandoned plugin code behind. The boundary owes a debt to b2evolution&rsquo;s resistance to plugin hell; SnapSmack takes it further.</p>
            <p><strong>Support without a public forum surface.</strong> The support forum lives inside authenticated SnapSmack administration. Operators can ask for help from the software itself without leaving the usual public registration, login, and posting endpoints outside for bots and drive-by spammers.</p>
        </div>
    </section>

    <section class="family-section">
        <div class="wrap family-copy">
            <p class="family-kicker">Behind the Bike Sheds</p>
            <h2>The lesson nobody puts in the launch announcement.</h2>
            <p>This decision owes something to <a href="https://github.com/pixelpost/pixelpost/wiki" target="_blank" rel="noopener">Jay Williams&rsquo; candid account of the attempted Pixelpost rewrite</a>. Jay deserves enormous credit: he wrote most of the code that moved Pixelpost from version 1.5 to 1.6, contributed substantially to the rewrite, and spent countless hours helping its users. He deserves far more recognition for that work than he received. SnapSmack stands in the shadow of that effort.</p>
            <p>As Pixelpost&rsquo;s last active developer and moderator, Jay described automatic spam across the forum and blog as nearly impossible for one person to clean up. He was equally clear that the rewrite stopped for broader reasons: too few developers, too little available time, and the difficulty of maintaining the site while finishing the software without the collaboration it needed.</p>
            <p>That was not a failure of effort. SnapSmack&rsquo;s internal forum applies one practical lesson from his experience: public support infrastructure must not be allowed to consume the limited time available to maintain the software itself.</p>
        </div>
    </section>

    <section class="family-section">
        <div class="wrap family-copy">
            <p class="family-kicker">The Bits Under the Bonnet</p>
            <h2>One engine, declared capabilities, fewer nasty surprises.</h2>
            <div class="inheritance-grid">
                <article class="inheritance-card"><h3>Skins declare</h3><p>Presentation says what approved capabilities it needs. It does not bring executable machinery along for the ride.</p></article>
                <article class="inheritance-card"><h3>The CMS delivers</h3><p>Shared, reviewed engines provide layouts, effects, controls, and behavior consistently across every compatible skin.</p></article>
                <article class="inheritance-card"><h3>Repairs propagate</h3><p>Fix the shared engine once and every skin using it receives the correction instead of maintaining its own forgotten copy.</p></article>
                <article class="inheritance-card"><h3>Removal means removal</h3><p>Taking away a skin removes its presentation. It does not leave a midden of abandoned plugin code and database debris.</p></article>
            </div>
        </div>
    </section>

    <section class="family-section">
        <div class="wrap family-copy">
            <p class="family-kicker">Belt and Braces</p>
            <h2>Security works better when the layers know each other.</h2>
            <p>Authentication, authorization, abuse prevention, signed distribution, integrity monitoring, breach containment, recovery, fleet intelligence, and public audit closure reinforce one another instead of operating as isolated checkboxes.</p>
            <p>That does not make any individual ingredient a SnapSmack invention. What is unusual is the coordination: putting all the fixings on the burger and bringing the architectural and security depth people expect from paid software to freeware built for a small community.</p>
            <div class="page-actions">
                <a href="index.php#security">See the eight-layer stack &rarr;</a>
                <a href="buzzers.php">Read the closed audits &rarr;</a>
            </div>
        </div>
    </section>

    <section class="family-section">
        <div class="wrap">
            <div class="claim-box">
                <p class="family-kicker">No Claiming Someone Else&rsquo;s Bastard</p>
                <h2>The claim is the combination, not immaculate conception.</h2>
                <p>SnapSmack does not claim to have invented personal publishing, photoblogging, manifests, federation, moderation, backups, two-factor authentication, integrity monitoring, or any of the other ingredients it uses.</p>
                <p>It claims to have assembled them deliberately around independent photographers: one owned publishing home, several ways to present the work, a connected social presence, a free desktop ecosystem, and enough defensive depth to keep the whole thing alive.</p>
                <div class="page-actions"><a href="features.php">Back to what it does &rarr;</a></div>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
