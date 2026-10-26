<?php
/**
 * SNAPSMACK.CA - THE RECKONING
 * Current repository scope and conventional commercial replacement cost.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'THE RECKONING! - SNAPSMACK by the Numbers';
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
.method-note { max-width: 820px; margin: 28px 0 0; padding: 22px 25px; background: var(--light-grey); border-left: 4px solid var(--black); font-size: .9rem; }
@media (max-width: 760px) { .reckoning-premise-grid, .number-grid { grid-template-columns: 1fr; } .reckoning-premise-label { margin: 0; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">MORE BOLLOCKS! / THE RECKONING!</p>
            <h1>Count it properly.<br><span>Then count the cost.</span></h1>
            <p class="lede reckoning-intro">SNAPSMACK by the numbers: measured source, working scope, security practice, and what a company would likely spend to commission the same thing under conventional pre-AI software economics.</p>
        </div>
    </section>

    <section class="reckoning-premise">
        <div class="wrap reckoning-premise-grid">
            <p class="reckoning-premise-label">The fair question</p>
            <div>
                <p class="reckoning-question">You&rsquo;re interested in running SNAPSMACK. But how do you know it&rsquo;s <span>serious software</span>&mdash;not disposable AI slop wearing a clever website?</p>
                <p class="reckoning-answer">How do you know there&rsquo;s something substantial underneath it? <strong>Fair questions. We brought numbers.</strong></p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>The codebase</h2>
            <div class="reckoning-copy">
                <p>These figures come from Git-tracked files in the working tree on October 25, 2026, including local edits to tracked files. CLOC 2.10 classified source, comments, and blank lines separately, with duplicate-file suppression disabled. Untracked work and untracked build output are excluded.</p>
            </div>
            <div class="number-grid">
                <div class="number-card"><strong>2,519</strong><span>Tracked files</span></div>
                <div class="number-card"><strong>215,227</strong><span>PHP, JavaScript, and CSS source lines</span></div>
                <div class="number-card"><strong>329,300</strong><span>Expanded implementation source including Python, SQL, Rust, and scripts</span></div>
                <div class="number-card"><strong>481</strong><span>Tracked Python files, including desktop applications, tooling, and tests</span></div>
                <div class="number-card"><strong>30</strong><span>Skins with tracked manifests</span></div>
                <div class="number-card"><strong>60</strong><span>Published numbered security audits</span></div>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>What would a company pay?</h2>
            <div class="cost-callout">
                <strong>CAD $6.7-16.4 million</strong>
                <p>Estimated conventional commercial replacement cost, reassessed October 25, 2026 from the scope added since the original August 12 estimate.</p>
            </div>
            <div class="reckoning-copy">
                <p>This is a replacement-cost estimate, not a sale price or company valuation. It covers product planning, core and desktop development, design, federation and infrastructure, security engineering, QA, compatibility testing, documentation, release engineering, project management, contingency, and vendor overhead.</p>
                <p>The revised estimate is approximately <strong>40,500-70,000 hours</strong>, or <strong>20-35 person-years</strong> at 2,000 hours per year. The original August 12 workstream model was 26,500-46,000 hours. Added scope contributes an estimated 14,000-24,000 hours; this replaces the later interim headline of 31,000-53,000 hours rather than adding that uplift twice.</p>
                <table>
                    <caption>Conventional replacement effort by workstream, in hours</caption>
                    <thead><tr><th scope="col">Workstream</th><th scope="col">August 12</th><th scope="col">October 25</th></tr></thead>
                    <tbody>
                        <tr><th scope="row">Core CMS, publishing and skins</th><td>12,000-20,000</td><td>14,000-23,500</td></tr>
                        <tr><th scope="row">Desktop, editing and workflow tools</th><td>5,000-9,000</td><td>12,000-21,000</td></tr>
                        <tr><th scope="row">Federation, multisite and distribution</th><td>4,000-7,000</td><td>5,000-9,000</td></tr>
                        <tr><th scope="row">Security engineering</th><td>1,500-3,000</td><td>2,500-4,500</td></tr>
                        <tr><th scope="row">QA, compatibility, releases and documentation</th><td>4,000-7,000</td><td>7,000-12,000</td></tr>
                        <tr><th scope="row">Total</th><td>26,500-46,000</td><td>40,500-70,000</td></tr>
                    </tbody>
                </table>
                <p><strong>The largest addition is SNAP SLAPPER:</strong> non-destructive layered editing, masks and colour tools, high-bit image processing, RAW development integration, portable projects, layered export, catalogue management, local people tagging, image-quality selection and optional generative-fill integration. The desktop allowance also covers the expanded GYSS organizer, shared profiles and credential handling, and additional publishing, export and recovery integration.</p>
                <p>Other additions include post-backed content and mosaic workflows, long-form publishing, photo-challenge boards and prompt archives, the directory and fleet workflows, broader client compatibility, and the enforced presentation-only skin boundary. Security and QA allowances cover the resulting trust boundaries, hostile-file handling, package validation, visual comparisons, interaction checks and installed-build compatibility.</p>
                <p><strong>Cost calculation:</strong> 40,500-70,000 hours at an assumed blended CAD $120-170 per hour gives $4.86-11.90 million. Add $0.30-0.70 million for separately commissioned independent security assessment and its remediation reserve, then 30% for vendor programme management, procurement coordination, contingency and margin: <strong>$6.708-16.380 million, rounded to $6.7-16.4 million</strong>. These are explicit planning assumptions carried forward from the original rate range, not measured supplier quotations. QA, documentation and release engineering are already in the hours and are not added again in the 30%.</p>
                <p>The estimate assumes reuse of available libraries and separately installed tools; it prices integration with them, not rebuilding Qt, RawTherapee, image models or other third-party software. It excludes abandoned features, future roadmap work, the experimental agent broker, taxes and ongoing hosting or service fees. Repeated regression repairs are not counted as additional product features. Current source scope is not a claim that every feature is deployed or free of outstanding defects. This is a planning range, not an audited valuation or a vendor bid.</p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>The FOOD NETWORK problem</h2>
            <div class="reckoning-copy">
                <p>FOOD NETWORK once served people who wanted to cook. A larger audience preferred watching food-adjacent entertainment, so instruction gave way to contests and challenges. Photography software followed a similar path: the larger market wants feeds, video, engagement, and performance. The smaller audience still wants to practise photography, publish photographs, and keep an archive under its own control.</p>
                <p>Under conventional economics, SNAPSMACK sits in the abandoned middle: unusually broad for a small volunteer FOSS project, too expensive for a company to commission sensibly, and aimed at a market too specialized to promise venture-scale returns. AI-assisted development changed that equation. It made substantial software for a limited community practical without requiring either mass-market revenue or a large unpaid contributor base.</p>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>Security work</h2>
            <div class="reckoning-copy">
                <p>Security engineering is included in the replacement-cost estimate. SNAPSMACK uses layered controls covering authentication, authorization, abuse prevention, signed distribution, integrity monitoring, breach containment, recovery, and federation.</p>
                <p>The published audits document material findings, incomplete fixes, accepted trade-offs, and scheduled work, not merely successful checks. These are internal AI-assisted reviews rather than certification or independent penetration testing. Broader adversarial testing is planned before version 1.0.</p>
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
                <p>The useful conclusion is not that one person replaced a team in every sense. AI changed which parts of software production required scarce specialist labour. Human responsibility for goals, judgment, verification, and consequences remained.</p>
                <p><strong>Or, put less politely: Sean accidentally founded a small software company without the inconvenience of employees, funding, or adult supervision.</strong></p>
            </div>
            <p class="method-note"><strong>Counting note:</strong> CLOC classified 409,990 source lines, 63,914 comment lines, and 60,969 blank lines across all recognized tracked file types: 534,873 physical lines in total. The 329,300 headline is the implementation-language subset and excludes documentation, configuration, and data formats. Counts include tracked tests and tooling; they measure repository scale, not quality, effort, or the contents of a deployed release.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
