<?php
/**
 * SNAPSMACK.CA — Buzzers (Security Audits & Disclosure)
 *
 * Public security-transparency page. Lists CLOSED / resolved security audits
 * only. Reports describing an open, serious, unfixed issue are deliberately NOT
 * published here (responsible disclosure) — they live private until remediated.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'BUZZERS! — SnapSmack Security Audits';
$page_description = 'Every security audit we have run and closed. Transparency and accountability, in public.';
$page_og_url      = 'https://snapsmack.ca/buzzers.php';
$nav_active       = 'buzzers';

$page_css = <<<'CSS'
/* ─── BUZZERS — H2/H3 OVERRIDES ───────────────────────────────────────────── */
h2 {
    font-size: clamp(1.4rem, 2.5vw, 1.9rem);
    color: var(--black);
    margin-bottom: 6px;
    letter-spacing: -0.01em;
}
h3 { font-size: 1rem; }
.lede { margin-bottom: 0; }

/* ─── INTRO ───────────────────────────────────────────────────────────────── */
.intro-body { max-width: 820px; padding: 56px 0 8px; }
.intro-body p { margin-bottom: 1.4em; max-width: 72ch; }
.slang {
    background: var(--light-grey);
    border-left: 4px solid var(--black);
    padding: 20px 24px;
    margin: 8px 0 0;
    font-size: 0.97rem;
}
.slang p { margin-bottom: 0; }
.slang strong { color: var(--black); }

/* ─── AUDIT INDEX (links up top) ──────────────────────────────────────────── */
.post-index {
    padding: 48px 0 40px;
    border-bottom: 3px solid var(--black);
}
.post-index h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.75rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--mid-grey);
    margin-bottom: 20px;
}
.post-index ol { list-style: none; columns: 2; column-gap: 48px; }
.post-index ol li {
    margin-bottom: 10px;
    break-inside: avoid;
    display: flex;
    gap: 12px;
    align-items: baseline;
}
.post-index ol li .idx-date {
    font-size: 0.8rem;
    color: var(--mid-grey);
    white-space: nowrap;
    font-family: Arial, sans-serif;
    flex-shrink: 0;
}
.post-index ol li a {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.88rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.01em;
    color: var(--dark-grey);
    line-height: 1.3;
}
.post-index ol li a:hover { color: var(--red); text-decoration: none; }

/* ─── AUDITS ──────────────────────────────────────────────────────────────── */
.posts { padding-bottom: 40px; }
article.post {
    padding: 56px 0;
    border-bottom: 1px solid var(--border);
    max-width: 820px;
}
article.post:last-child { border-bottom: none; }
.post-meta { display: flex; align-items: center; gap: 16px; margin-bottom: 14px; }
.post-date {
    font-family: Arial, sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--mid-grey);
}
.post-tag {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.68rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--white);
    background: #1a7f37;
    padding: 2px 8px;
    display: inline-block;
}
.post h2 { margin-bottom: 14px; font-size: clamp(1.4rem, 2.6vw, 1.9rem); line-height: 1.1; }
.post p { margin-bottom: 1.2em; max-width: 72ch; }
.report-link {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.8rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--red);
}
.report-link:hover { color: var(--black); text-decoration: none; }
.report-refs { margin-top: 1.2em; font-size: 0.9rem; color: var(--mid-grey); }
.report-refs a { font-weight: 700; color: var(--dark-grey); }
.report-refs a:hover { color: var(--red); text-decoration: none; }

/* ─── DISCLOSURE ──────────────────────────────────────────────────────────── */
.disclosure { padding: 64px 0 96px; max-width: 820px; }
.disclosure h2 { color: var(--red); margin-bottom: 20px; }
.disclosure p { margin-bottom: 1.4em; max-width: 72ch; }

@media (max-width: 700px) {
    .post-index ol { columns: 1; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>BUZZERS!</h1>
            <p class="lede">Every security audit we've run and closed. Out in the open, on purpose.</p>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="intro-body">
                <p>We believe a photographer should be able to see how the software guarding their life's work actually holds up — not take our word for it. So here it is: the security audits we've run on SnapSmack and its companion tools, the findings, and the releases that closed them.</p>
                <p><strong>Transparency and accountability aren't a marketing line here, they're the deal.</strong> Claude runs ongoing security audits of the codebase; high and medium-risk findings are fixed immediately, low-risk ones on a schedule. The reports below are the closed ones — issues found, issues fixed, dated and signed. What you won't find is a report describing a live, serious hole that's still open: publishing the blueprint for an unpatched break-in would put every SnapSmack site at risk, which is the opposite of protecting you. Those stay private until they're fixed, and then they show up here. That's responsible disclosure, and it's the honest version of "we take security seriously."</p>
                <div class="slang">
                    <p><strong>"Buzzers"?</strong> Victorian thieves' cant. To <em>buzz</em> was to pick pockets, and a <em>buzzer</em> was the pickpocket — the one working the crowd for whatever wasn't nailed down. This is the page where we show our work against the buzzers.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="post-index">
        <div class="wrap">
            <h3>Closed Audits</h3>
            <ol>
                <li><span class="idx-date">Jul 15</span><a href="#a033">SMACKVERSE Federation Client — Attack Surface</a></li>
                <li><span class="idx-date">Jul 4</span><a href="#a032">SMACKVERSE Piggyback Search — Token Isolation</a></li>
                <li><span class="idx-date">Jun 27</span><a href="#csrf">Cross-Site Request Forgery — Closed Site-Wide</a></li>
                <li><span class="idx-date">Jun 25</span><a href="#asob">Son of a Batch — Batch Poster Review</a></li>
                <li><span class="idx-date">Jun 20</span><a href="#a028">Dev-File Leak &amp; SMACKBACK Blind Spot</a></li>
                <li><span class="idx-date">Jun 20</span><a href="#a029">Auto File-Deletion Attack Surface</a></li>
                <li><span class="idx-date">Jun 19</span><a href="#a027">SMACKBACK Unknown-File &amp; Cron-Verify Gap</a></li>
                <li><span class="idx-date">Jun 18</span><a href="#a026">Installer Constants &amp; Skin Attack Surface</a></li>
                <li><span class="idx-date">Jun 17</span><a href="#a025">Skin Inline-Script Manifest Bypass</a></li>
                <li><span class="idx-date">Jun 15</span><a href="#amesh">Mesh Roster Key Broadcast</a></li>
                <li><span class="idx-date">Jun 12</span><a href="#a024a">Imported-Caption XSS — Remediation</a></li>
                <li><span class="idx-date">Jun 12</span><a href="#a024">SYBU Recovery &amp; Unzucker Captions</a></li>
                <li><span class="idx-date">Jun 7</span><a href="#a023">Unzucker Attack Surface</a></li>
                <li><span class="idx-date">Jun 7</span><a href="#a022">Canonical-Schema Fetch Hardening</a></li>
                <li><span class="idx-date">Jun 5</span><a href="#a209">SMACKBACK False-Positive Fix Review (0.7.209)</a></li>
                <li><span class="idx-date">Jun 4</span><a href="#a021a">Hub/Spoke Attack Surface — Resolution</a></li>
                <li><span class="idx-date">May 31</span><a href="#a020">PUSH IT Hub-Controls Attack Surface</a></li>
                <li><span class="idx-date">May 26</span><a href="#a019">Deep Review: 0.7.184</a></li>
                <li><span class="idx-date">May 23</span><a href="#a018">Hub-Initiated Maintenance Mode</a></li>
                <li><span class="idx-date">May 22</span><a href="#a017">SMACKBACK File-Integrity Monitoring</a></li>
                <li><span class="idx-date">May 22</span><a href="#a016">Maintenance-Mode Session &amp; Parse Error</a></li>
                <li><span class="idx-date">May 19</span><a href="#a015">2FA Cookie Flags &amp; Recovery Policy</a></li>
                <li><span class="idx-date">May 19</span><a href="#a014">Orphaned login.php at a Predictable URL</a></li>
                <li><span class="idx-date">May 19</span><a href="#a013">Installer Admin-Creation Bypass</a></li>
                <li><span class="idx-date">May 18</span><a href="#a011">Post-Remediation Verification</a></li>
                <li><span class="idx-date">May 18</span><a href="#a010">Delta Review: 0.7.147–0.7.152</a></li>
                <li><span class="idx-date">May 10</span><a href="#a009">Multisite Remote-Admin Surface</a></li>
            </ol>
        </div>
    </section>

    <section class="posts">
        <div class="wrap">

            <article class="post" id="a033">
                <div class="post-meta"><span class="post-date">July 15, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SMACKVERSE Federation Client — Attack Surface</h2>
                <p>SMACKVERSE is SnapSmack's fully integrated, Pixelfed-compatible single-user server &mdash; your blog's own instance on the Fediverse, speaking the ActivityPub protocol so it can follow, like, comment on, boost, and message people right across the network, and be followed back. Because it is fully interactive, it shows content written by people on other servers, so this audit walked that entire trust boundary. The engine room held up well: requests arriving from other servers are cryptographically verified before they are allowed to change anything, nobody can pose as someone they aren't, and the software is fenced off from reaching back into your own network.</p>
                <p>Two medium findings were in the browser display code, where a hostile profile or post could have slipped a booby-trapped link into a page you were viewing. Both are closed &mdash; links coming from other servers are now checked to be ordinary web links before they are shown, and the profile-bio display was rebuilt to permit only safe formatting. A low-risk hardening item on an internal search request was tightened for good measure, and two informational notes were reviewed and accepted. Closed in 0.7.405.</p>
                <a class="report-link" href="secaudits/2026-07-15-033-smackverse-federation-client-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a032">
                <div class="post-meta"><span class="post-date">July 4, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SMACKVERSE Piggyback Search — Token Isolation</h2>
                <p>SMACKVERSE can borrow a login token you hold on a friendly Fediverse instance so your blog can run authenticated searches out across the network. This review checked how that token is stored and used. The one finding: the key protecting the stored token was falling back to a shared default value instead of a per-site secret. Fixed — every install now generates its own dedicated, random search key, so no two sites share protection.</p>
                <p>A second item flagged around form security turned out to be a false alarm: every admin form on SnapSmack already carries automatic cross-site-request protection. Everything else passed — the outbound requests are guarded against server-side request forgery, results render without script injection, adding an account is gated behind password + two-factor, and the token is never exposed to the browser. Closed in 0.7.376.</p>
                <a class="report-link" href="secaudits/2026-07-04-032-smackverse-piggyback-search-audit.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="csrf">
                <div class="post-meta"><span class="post-date">June 27, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Cross-Site Request Forgery — Closed Site-Wide</h2>
                <p>Cross-Site Request Forgery (CSRF) is a trick where a booby-trapped web page quietly gets your browser to fire a real action on a site you're already logged into — without you clicking anything that means to. The defence is a per-session token that proves a request genuinely came from your own admin screens and not from somewhere else.</p>
                <p>Across our early-2026 review series, site-wide CSRF protection was the one item we flagged and deliberately deferred while higher-severity findings were closed first. It's now done: every admin form and every background request in SnapSmack carries and checks that token automatically, with no per-page switch anyone can forget to flip. That closes the single open thread carried through reports 001 to 008.</p>
                <a class="report-link" href="secaudits/2026-06-27-csrf-closure-sweep.pdf" target="_blank" rel="noopener">Read the closure record &rarr;</a>
                <p class="report-refs">Underlying reviews:
                    <a href="secaudits/2024-04-25-001-initial-full-codebase-audit.pdf" target="_blank" rel="noopener">001</a>,
                    <a href="secaudits/2026-04-25-002-contact-form-injection-ratelimiter-race.pdf" target="_blank" rel="noopener">002</a>,
                    <a href="secaudits/2026-04-26-003-installer-credential-overwrite.pdf" target="_blank" rel="noopener">003</a>,
                    <a href="secaudits/2026-04-29-005-login-hardening-ip-shield.pdf" target="_blank" rel="noopener">005</a>,
                    <a href="secaudits/2026-04-29-006-post-release-integrity-verification.pdf" target="_blank" rel="noopener">006</a>,
                    <a href="secaudits/2026-05-03-007-featured-image-picker-dom-xss.pdf" target="_blank" rel="noopener">007</a>,
                    <a href="secaudits/2026-05-05-008-masthead-logo-upload-mime-bypass.pdf" target="_blank" rel="noopener">008</a>.
                </p>
            </article>

            <article class="post" id="asob">
                <div class="post-meta"><span class="post-date">June 25, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Son of a Batch — Batch Poster Review</h2>
                <p>A security review of the batch-posting pipeline that pushes large image sets to your site. Findings fixed or mitigated; desktop-side encryption of stored keys at rest is noted as a tracked follow-up rather than a live exposure.</p>
                <a class="report-link" href="secaudits/2026-06-25-son-of-a-batch.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a028">
                <div class="post-meta"><span class="post-date">June 20, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Dev-File Leak &amp; SMACKBACK Blind Spot</h2>
                <p>Closed a path where development-only files could be swept into a release package, plus a SMACKBACK blind spot around the release-staging directory. The integrity monitor now flags leaked central code if it ever lands on a normal install. Closed in 0.7.317.</p>
                <a class="report-link" href="secaudits/2026-06-20-028-package-dev-file-leak-and-smackback-blindspot.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a029">
                <div class="post-meta"><span class="post-date">June 20, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Auto File-Deletion Attack Surface</h2>
                <p>A pre-emptive review of the attack surface around automatic file deletion. Confirmed the dangerous capability was never actually shipped; the review closed with no exploitable exposure on any live site.</p>
                <a class="report-link" href="secaudits/2026-06-20-029-auto-file-deletion-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a027">
                <div class="post-meta"><span class="post-date">June 19, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SMACKBACK Unknown-File &amp; Cron-Verify Gap</h2>
                <p>Two SMACKBACK gaps — how it handles unexpected files, and a timing gap in the scheduled verification pass. Both addressed; the only residual was a low-sensitivity information item handled operationally.</p>
                <a class="report-link" href="secaudits/2026-06-19-027-smackback-unknown-file-and-cron-verify-gap.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a026">
                <div class="post-meta"><span class="post-date">June 18, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Installer Constants &amp; Skin Attack Surface</h2>
                <p>An installer self-breach around how configuration constants were written, alongside a review of the skin attack surface. Remediated, with the skin-side hardening folded into the manifest-only JavaScript policy.</p>
                <a class="report-link" href="secaudits/2026-06-18-026-installer-constants-breach-and-skin-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a025">
                <div class="post-meta"><span class="post-date">June 17, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Skin Inline-Script Manifest Bypass</h2>
                <p>Skins could ship an inline script tag that slipped past the manifest-only JavaScript policy — the rule that keeps every install's scripts reviewed and accounted for. The last remaining carrier, Photogram's landing feed, was moved to a manifest-loaded engine file. Closed in 0.7.317.</p>
                <a class="report-link" href="secaudits/2026-06-17-025-skin-js-direct-script-tag-manifest-bypass.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="amesh">
                <div class="post-meta"><span class="post-date">June 15, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Mesh Roster Key Broadcast</h2>
                <p>A review of the multisite mesh-roster key broadcast. The UI key exposure was fixed — keys now show once, then are hidden — and encryption of those keys at rest is a documented, accepted residual rather than a live hole.</p>
                <a class="report-link" href="secaudits/2026-06-15-mesh-roster-key-broadcast.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a024a">
                <div class="post-meta"><span class="post-date">June 12, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Imported-Caption XSS — Remediation</h2>
                <p>The remediation record for a cross-site-scripting risk in captions brought in during import — confirming the fix landed and imported caption text is properly sanitized before it is ever displayed.</p>
                <a class="report-link" href="secaudits/2026-06-12-024A-caption-xss-remediation-addendum.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a024">
                <div class="post-meta"><span class="post-date">June 12, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SYBU Recovery &amp; Unzucker Captions</h2>
                <p>A review of the backup-recovery flows and Unzucker's caption handling. Findings resolved; the caption cross-site-scripting item is closed out in its own remediation addendum (024A above).</p>
                <a class="report-link" href="secaudits/2026-06-12-024-sybu-recovery-and-unzucker-caption-changes.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a023">
                <div class="post-meta"><span class="post-date">June 7, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Unzucker Attack Surface</h2>
                <p>A full review of the Unzucker desktop importer's attack surface. The transport-layer findings are now moot: Unzucker moved to HTTPS with Bearer-token auth, removing the old FTP and cross-site-request surfaces entirely.</p>
                <a class="report-link" href="secaudits/2026-06-07-023-unzucker-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a022">
                <div class="post-meta"><span class="post-date">June 7, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Canonical-Schema Fetch Hardening</h2>
                <p>Closed a gap where a remote database schema could be applied without verifying its signature first. The fetch path now checks the signature before anything touches your database. Fixed in 0.7.214, with no residual open items.</p>
                <a class="report-link" href="secaudits/2026-06-07-022-canonical-schema-fetch-hardening.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a209">
                <div class="post-meta"><span class="post-date">June 5, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SMACKBACK False-Positive Fix Review (0.7.209)</h2>
                <p>A review tied to the 0.7.209 fix for SMACKBACK false-positive breach alerts. The release deliverables shipped clean; two follow-ups were flagged at the time, and the one that mattered for public installs — denying direct web access to the integrity manifest — is closed in 0.7.317.</p>
                <a class="report-link" href="secaudits/2026-06-05-0.7.209-review.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a021a">
                <div class="post-meta"><span class="post-date">June 4, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Hub/Spoke Attack Surface — Resolution</h2>
                <p>The closing record for a deep review of the multisite hub-and-spoke attack surface — the machinery that lets one install manage a fleet of others. Every finding resolved, the bulk of them in 0.7.203.</p>
                <a class="report-link" href="secaudits/2026-06-04-021A-hub-spoke-attack-surface-addendum.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
                <p class="report-refs">Underlying review: <a href="secaudits/2026-06-04-021-hub-spoke-attack-surface.pdf" target="_blank" rel="noopener">021</a>.</p>
            </article>

            <article class="post" id="a020">
                <div class="post-meta"><span class="post-date">May 31, 2026</span><span class="post-tag">Closed</span></div>
                <h2>PUSH IT Hub-Controls Attack Surface</h2>
                <p>A review of the PUSH IT hub controls — the fleet-wide action buttons that let a hub act on every spoke at once. Findings closed, with one accepted low-risk item documented.</p>
                <a class="report-link" href="secaudits/2026-05-31-020-push-it-hub-controls-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a019">
                <div class="post-meta"><span class="post-date">May 26, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Deep Review: 0.7.184</h2>
                <p>A deep review of the large 0.7.184 feature drop — mandatory 2FA, self-sealing breach lockdown, the skin JS scanner, and more. All five findings closed: fixed, or confirmed safe by design.</p>
                <a class="report-link" href="secaudits/2026-05-26-019-deep-review-0.7.184.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a018">
                <div class="post-meta"><span class="post-date">May 23, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Hub-Initiated Maintenance Mode</h2>
                <p>A review of the new multisite feature that lets a hub put its spokes into maintenance mode. Closed with no security findings requiring remediation — it shipped clean in 0.7.171.</p>
                <a class="report-link" href="secaudits/2026-05-23-018-hub-maintenance-mode.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a017">
                <div class="post-meta"><span class="post-date">May 22, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SMACKBACK File-Integrity Monitoring</h2>
                <p>A design review of SMACKBACK, the file-tamper monitor that watches your install for unexpected changes. Items addressed in 0.7.170; no exploitable issues left open.</p>
                <a class="report-link" href="secaudits/2026-05-22-017-smackback-file-integrity-monitoring.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a016">
                <div class="post-meta"><span class="post-date">May 22, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Maintenance-Mode Session &amp; Parse Error</h2>
                <p>Two minor defects in maintenance mode — a session-handling issue and a parse error. Both fixed in 0.7.169.</p>
                <a class="report-link" href="secaudits/2026-05-22-016-maintenance-mode-session-and-parse-error.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a015">
                <div class="post-meta"><span class="post-date">May 19, 2026</span><span class="post-tag">Closed</span></div>
                <h2>2FA Cookie Flags &amp; Recovery Policy</h2>
                <p>Tightened the security flags on the two-factor verification cookie and firmed up the recovery-code policy, so the second factor stays a real second factor. Fixed in 0.7.159.</p>
                <a class="report-link" href="secaudits/2026-05-19-015-2fa-verify-cookie-and-recovery-policy.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a014">
                <div class="post-meta"><span class="post-date">May 19, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Orphaned login.php at a Predictable URL</h2>
                <p>A duplicate login page was sitting at a guessable path, quietly bypassing the configurable login-slug protection that's supposed to hide your front door from bots. Removed and fixed in 0.7.155.</p>
                <a class="report-link" href="secaudits/2026-05-19-014-login-php-orphaned-predictable-url.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a013">
                <div class="post-meta"><span class="post-date">May 19, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Installer Admin-Creation Bypass</h2>
                <p>A leftover installer could be used to create a brand-new admin account on an already-installed site, sidestepping two-factor auth entirely. Closed in 0.7.157.</p>
                <a class="report-link" href="secaudits/2026-05-19-013-installer-admin-creation-bypass.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a012">
                <div class="post-meta"><span class="post-date">May 18, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Installer Step-5 CSRF Bypass</h2>
                <p>A cross-site-request-forgery bypass in step 5 of the installer. Closed as part of the site-wide CSRF work recorded in the closure at the top of this page.</p>
                <a class="report-link" href="secaudits/2026-05-18-012-installer-step5-csrf-bypass.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a011">
                <div class="post-meta"><span class="post-date">May 18, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Post-Remediation Verification</h2>
                <p>A second pass confirming the previous review's fixes were actually applied correctly — because "we fixed it" should be something you can check. All seven items verified resolved or confirmed clean.</p>
                <a class="report-link" href="secaudits/2026-05-18-011-post-remediation-verification.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a010">
                <div class="post-meta"><span class="post-date">May 18, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Delta Review: 0.7.147–0.7.152</h2>
                <p>A routine review of everything that changed across half a dozen releases. Two low-severity housekeeping items found and resolved; no exploitable vulnerabilities.</p>
                <a class="report-link" href="secaudits/2026-05-18-010-delta-review-0.7.147-0.7.152.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a009">
                <div class="post-meta"><span class="post-date">May 10, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Multisite Remote-Admin Surface</h2>
                <p>A full review of the new hub-and-spoke remote-admin features before they went out. All five findings were remediated in 0.7.102 ahead of release — nothing reached a live site unfixed.</p>
                <a class="report-link" href="secaudits/2026-05-10-009-multisite-remote-admin-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

        </div>
    </section>

    <section class="disclosure">
        <div class="wrap">
            <h2>Found Something? Tell Us.</h2>
            <p>SnapSmack is not bulletproof. No software is, and anyone who tells you theirs is, is lying. If you've found a security issue, we want to know about it — and we'd rather you tell us quietly than tell the internet.</p>
            <p>Report it privately through the SnapSmack support forum rather than posting details in public, and give us a chance to close it before it's common knowledge. That's the same courtesy we extend to you: we don't publish the details of a serious, open issue until it's fixed. Once it's closed, it joins the list above. The codebase is public and open to inspection at any time — if you find something we missed, that's a contribution, and it's welcome.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
