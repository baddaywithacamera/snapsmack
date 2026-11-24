<?php
/**
 * SNAPSMACK.CA - THE RECKONING
 * Current repository scope and conventional commercial replacement cost.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'THE RECKONING!: By the Numbers | Free photoblog software';
$page_description = 'The measured scale of SNAPSMACK, its security process, and what software of this scope would cost under conventional pre-AI development.';
$page_og_url      = 'https://snapsmack.ca/the-reckoning.php';
$nav_active       = 'reckoning';

$page_css = <<<'CSS'
.reckoning-intro { max-width: 820px; }
.page-header:has(+ .reckoning-premise) { padding-bottom: clamp(36px, 4vw, 56px); }
.reckoning-premise { padding: clamp(38px, 5vw, 64px) 0; color: var(--white); background: var(--black); border-top: 7px solid var(--red); }
.reckoning-premise-grid { display: grid; grid-template-columns: minmax(150px, .35fr) minmax(0, 1fr); gap: clamp(28px, 6vw, 90px); align-items: start; }
.reckoning-premise-label { margin: 7px 0 0; color: var(--red); font: 900 .78rem/1.2 Arial Black, Arial, sans-serif; letter-spacing: .14em; text-transform: uppercase; }
.reckoning-question { max-width: 800px; margin: 0; color: var(--white); font: 900 clamp(1.45rem, 2.55vw, 2.45rem)/1.12 Arial Black, Arial, sans-serif; letter-spacing: -.03em; text-transform: uppercase; }
.reckoning-question span { color: var(--red); }
.reckoning-answer { margin: 24px 0 0; color: #ccc; font-size: clamp(1rem, 1.7vw, 1.25rem); line-height: 1.55; }
.reckoning-answer strong { color: var(--white); }
.reckoning-section { border-top: 1px solid var(--border); }
.reckoning-section h2 { margin-bottom: 14px; }
.reckoning-copy { max-width: 76ch; }
.reckoning-copy p:last-child { margin-bottom: 0; }
.number-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; margin-top: 30px; background: var(--border); border: 1px solid var(--border); }
.number-card { min-width: 0; padding: 27px 25px; background: var(--white); }
.number-card strong { display: block; color: var(--black); font: 900 clamp(1.45rem, 3vw, 2.25rem)/1 Arial Black, Arial, sans-serif; letter-spacing: -.04em; }
.number-card span { display: block; margin-top: 9px; color: var(--mid-grey); font-size: .8rem; font-weight: 700; line-height: 1.4; text-transform: uppercase; }
.cost-callout { max-width: 820px; margin: 28px 0; padding: 30px; color: var(--white); background: var(--black); border-left: 7px solid var(--red); }
.cost-callout strong { display: block; margin-bottom: 9px; color: var(--white); font: 900 clamp(1.8rem, 4vw, 3rem)/1 Arial Black, Arial, sans-serif; letter-spacing: -.04em; }
.cost-callout p { margin: 0; color: #ddd; }
.reckoning-cost-table { width: 100%; border-collapse: collapse; margin: 24px 0; font-size: .9rem; }
.reckoning-cost-table th, .reckoning-cost-table td { padding: 12px 8px; text-align: left; border-bottom: 1px solid #ccc; }
.reckoning-cost-table th:last-child, .reckoning-cost-table td:last-child { text-align: right; white-space: nowrap; }
.method-note { max-width: 820px; margin: 28px 0 0; padding: 22px 25px; background: var(--light-grey); border-left: 4px solid var(--black); font-size: .9rem; }
.term-abbr { text-decoration-line: underline; text-decoration-style: dotted; text-underline-offset: .18em; cursor: help; }
@media (max-width: 760px) { .reckoning-premise-grid, .number-grid { grid-template-columns: 1fr; } .reckoning-premise-label { margin: 0; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">MORE BOLLOCKS! / THE RECKONING!</p>
            <h1>Count it properly.<br><span>Then count the cost.</span></h1>
            <p class="lede reckoning-intro">SNAPSMACK by the numbers: measured source, working scope, security practice, and an estimate of what commissioning equivalent software could cost under conventional pre-AI development.</p>
        </div>
    </section>

    <section class="reckoning-premise">
        <div class="wrap reckoning-premise-grid">
            <p class="reckoning-premise-label">The fair question</p>
            <div>
                <p class="reckoning-question">You&rsquo;re interested in running SNAPSMACK. But how do you know it&rsquo;s <span>serious software</span>, not disposable AI slop wearing a clever website?</p>
                <p class="reckoning-answer">How do you know there&rsquo;s something substantial underneath it? <strong>Fair questions. We brought numbers.</strong></p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>The codebase</h2>
            <div class="reckoning-copy">
                <p>These figures describe the Git-tracked source at commit <code>eb2ca35a</code>, counted on November 18, 2026. CLOC 2.10 separates code, comments, and blank lines and removes duplicate files. The count includes the CMS, desktop applications, tests, and developer tooling; it is not the size of a downloaded CMS installation. Dependency, build, distribution, and licence directories, minified assets, the third-party fjGallery assets, and untracked work are excluded from the line count.</p>
            </div>
            <div class="number-grid">
                <div class="number-card"><strong>3,283</strong><span>Tracked files</span></div>
                <div class="number-card"><strong>238,630</strong><span>PHP, JavaScript, and CSS source lines</span></div>
                <div class="number-card"><strong>371,998</strong><span>Expanded implementation source including Python, SQL, Rust, and scripts</span></div>
                <div class="number-card"><strong>619</strong><span>Python files for desktop applications and tooling</span></div>
                <div class="number-card"><strong>31</strong><span>Skin directories in development, not 31 finished releases</span></div>
                <div class="number-card"><strong>66</strong><span>Published numbered security audits, with 3 more held until their fixes ship</span></div>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>What would a company pay?</h2>
            <div class="cost-callout">
                <strong>CAD $7.2-18.1 million</strong>
                <p>Indicative commissioning budget for equivalent scope, using the assumptions below. Not an independent valuation or supplier quotation.</p>
            </div>
            <div class="reckoning-copy">
                <p>This is a replacement-cost estimate, not a sale price or company valuation. It covers product planning, core and desktop development, design, federation and infrastructure, security engineering, QA, compatibility testing, documentation, release engineering, project management, contingency, and vendor overhead.</p>
                <p>The November 18 planning model allows approximately <strong>42,420&ndash;74,040 delivery hours</strong>: about 21&ndash;37 person-years at 2,000 hours per year. The public October 6 estimate allowed 31,000&ndash;53,000 hours and CAD $5.3&ndash;13 million. This revision applies the same method to the expanded CMS, desktop suite, presentation families, federation, fleet operations, security, operational review, and delivery work.</p>
                <table class="reckoning-cost-table">
                    <caption>Conventional replacement effort: planning allowances, not recorded hours</caption>
                    <thead><tr><th scope="col">Workstream</th><th scope="col">Hours</th></tr></thead>
                    <tbody>
                        <tr><th scope="row">CMS, publishing, media, and administration</th><td>8,960&ndash;14,560</td></tr>
                        <tr><th scope="row">Desktop applications, importers, and integrations</th><td>7,840&ndash;13,440</td></tr>
                        <tr><th scope="row">Skin families and presentation controls</th><td>3,920&ndash;7,280</td></tr>
                        <tr><th scope="row">Federation, community, and multisite services</th><td>4,480&ndash;7,840</td></tr>
                        <tr><th scope="row">Signed distribution, updates, backup, and recovery</th><td>3,360&ndash;6,160</td></tr>
                        <tr><th scope="row">Security engineering and adversarial review</th><td>4,620&ndash;8,240</td></tr>
                        <tr><th scope="row">Integration, compatibility, accessibility, and acceptance testing</th><td>5,600&ndash;10,080</td></tr>
                        <tr><th scope="row">Product planning, documentation, and delivery management</th><td>3,360&ndash;5,600</td></tr>
                        <tr><th scope="row">Operational incident reviews</th><td>280&ndash;840</td></tr>
                        <tr><th scope="row">Total</th><td><strong>42,420&ndash;74,040</strong></td></tr>
                    </tbody>
                </table>
                <p>At assumed blended commercial delivery rates of CAD $170&ndash;$245 per hour, the endpoints are $7.21 million and $18.14 million, rounded to the headline range. These are judgement-based planning allowances, not measured hours, supplier bids, or surveyed market rates. Development includes implementation and routine developer checks; the separate testing allowance covers system-wide verification and acceptance. Vendor overhead and contingency are included in the blended rates, not added a second time.</p>
                <p>The scope spans photo publishing and media management, multiple presentation families, desktop editing and publishing tools, federation, multisite administration, signed updates, backup and recovery, security work, and the delivery work around them. The estimate assumes commissioning equivalent capabilities, rather than paying to reproduce every experiment or regression in this repository. More lines do not automatically mean more value. The range is sensitive to team composition, delivery standards, and what a buyer actually commissions. A priced workstream specification and supplier quotations would be needed to turn it into a procurement budget.</p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>The FOOD NETWORK problem</h2>
            <div class="reckoning-copy">
                <p>FOOD NETWORK once served people who wanted to cook. A larger audience preferred watching food-adjacent entertainment, so instruction gave way to contests and challenges. Photography software followed a similar path: the larger market wants feeds, video, engagement, and performance. The smaller audience still wants to practise photography, publish photographs, and keep an archive under its own control.</p>
                <p>Under conventional economics, SNAPSMACK sits in the abandoned middle: unusually broad for a small volunteer <abbr class="term-abbr" title="Free, Open Source Software">FOSS</abbr> project, too expensive for a company to commission sensibly, and aimed at a market too specialized to promise venture-scale returns. AI-assisted development changed that equation. It made substantial software for a limited community practical without requiring either mass-market revenue or a large unpaid contributor base.</p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>Security work</h2>
            <div class="reckoning-copy">
                <p>Security engineering is included in the replacement-cost estimate. SNAPSMACK uses layered controls covering authentication, authorization, abuse prevention, signed distribution, integrity monitoring, breach containment, recovery, and federation.</p>
                <p>The published audits document material findings, incomplete fixes, accepted trade-offs, and scheduled work, not merely successful checks. These are internal AI-assisted reviews rather than certification or independent penetration testing. Broader adversarial testing is planned before version 1.0.</p>
                <p>The record also includes <strong>35 operational audits</strong>: incident investigations, root-cause analysis, and repair records. That practice is common in companies that run production systems and unusual for a solo project.</p>
                <p><a href="features.php#why-built-this-way"><strong>Read why SNAPSMACK is built this way &rarr;</strong></a> &nbsp; <a href="buzzers.php"><strong>Read the closed security audits &rarr;</strong></a></p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>How it was produced</h2>
            <div class="reckoning-copy">
                <p><strong>Development began January 29, 2026.</strong></p>
                <p>SNAPSMACK uses a human-directed, AI-assisted production model. Sean McCormick supplies the photography workflow, requirements, priorities, aesthetic judgment, testing, and acceptance decisions. Claude, Gemini, and OpenAI Codex contribute implementation, analysis, review, and iteration.</p>
                <p>The source count is not a readiness score: unfinished skins, open defects, and repairs remain work to do. The useful conclusion is not that one person replaced a team in every sense. AI changed which parts of software production required scarce specialist labour. Human responsibility for goals, judgment, verification, and consequences remained.</p>
                <p><strong>Or, put less politely: Sean accidentally founded a small software company without the inconvenience of employees, funding, or adult supervision.</strong></p>
            </div>
            <p class="method-note"><strong>Counting note:</strong> CLOC classified 494,768 code/content lines, 91,237 comment lines, and 68,397 blank lines across the included recognized file types: 654,402 physical lines. Its broad code/content category includes documentation and configuration. The 371,998 implementation headline includes PHP, JavaScript, CSS, Python, SQL, Rust, shell, batch, and PowerShell; it excludes documentation, HTML, configuration, and data formats. The tracked-file and Python-file totals come from Git before CLOC exclusions and deduplication. Counts measure repository scale, not quality or effort. The security-audit figure counts published numbered audits, not the three held audits or supporting documents.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
