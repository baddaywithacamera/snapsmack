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
.method-note { max-width: 820px; padding: 22px 25px; background: var(--light-grey); border-left: 4px solid var(--black); font-size: .9rem; }
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
                <p>These figures come from the Git-tracked repository on August 12, 2026. CLOC 2.10 classified source, comments, and blank lines separately. Dependencies, generated files, and untracked work are excluded.</p>
            </div>
            <div class="number-grid">
                <div class="number-card"><strong>1,581</strong><span>Tracked files</span></div>
                <div class="number-card"><strong>178,840</strong><span>PHP, JavaScript, and CSS source lines</span></div>
                <div class="number-card"><strong>215,993</strong><span>Expanded source including Python, SQL, Rust, and scripts</span></div>
                <div class="number-card"><strong>124</strong><span>Python files for desktop applications and tooling</span></div>
                <div class="number-card"><strong>29</strong><span>Skin directories</span></div>
                <div class="number-card"><strong>49</strong><span>Published security-audit records</span></div>
            </div>
        </div>
    </section>

    <section class="reckoning-section">
        <div class="wrap">
            <h2>What would a company pay?</h2>
            <div class="cost-callout">
                <strong>CAD $4.5-11 million</strong>
                <p>Estimated conventional commercial development cost for a company commissioning SNAPSMACK at its current scope.</p>
            </div>
            <div class="reckoning-copy">
                <p>This is a replacement-cost estimate, not a sale price or company valuation. It covers product planning, core and desktop development, design, federation and infrastructure, security engineering, QA, compatibility testing, documentation, release engineering, project management, contingency, and vendor overhead.</p>
                <p>The underlying estimate is approximately 26,500-46,000 hours, or 13-23 person-years. It is based on replacement workstreams and commercial delivery costs, not a dollars-per-line formula.</p>
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
                <p>SNAPSMACK uses a human-directed, AI-assisted production model. Sean McCormick supplies the photography workflow, requirements, priorities, aesthetic judgment, testing, and acceptance decisions. Claude, Gemini, and OpenAI Codex contribute implementation, analysis, review, and iteration.</p>
                <p>The useful conclusion is not that one person replaced a team in every sense. AI changed which parts of software production required scarce specialist labour. Human responsibility for goals, judgment, verification, and consequences remained.</p>
            </div>
            <p class="method-note"><strong>Counting note:</strong> The headline source figures exclude 46,739 comment lines and 40,516 blank lines. The broader CLOC-classified total, including documentation and configuration, is 262,815 lines. Counts measure repository scale, not quality or effort.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
