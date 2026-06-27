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
                <li><span class="idx-date">Jun 7</span><a href="#a022">Canonical-Schema Fetch Hardening</a></li>
                <li><span class="idx-date">Jun 4</span><a href="#a021a">Hub/Spoke Attack Surface — Resolution</a></li>
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

            <article class="post" id="a022">
                <div class="post-meta"><span class="post-date">June 7, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Canonical-Schema Fetch Hardening</h2>
                <p>Closed a gap where a remote database schema could be applied without verifying its signature first. The fetch path now checks the signature before anything touches your database. Fixed in 0.7.214, with no residual open items.</p>
                <a class="report-link" href="secaudits/2026-06-07-022-canonical-schema-fetch-hardening.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a021a">
                <div class="post-meta"><span class="post-date">June 4, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Hub/Spoke Attack Surface — Resolution</h2>
                <p>The closing record for a deep review of the multisite hub-and-spoke attack surface — the machinery that lets one install manage a fleet of others. Every finding resolved, the bulk of them in 0.7.203.</p>
                <a class="report-link" href="secaudits/2026-06-04-021A-hub-spoke-attack-surface-addendum.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a019">
                <div class="post-meta"><span class="post-date">May 26, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Deep Review: 0.7.184</h2>
                <p>A deep review of the large 0.7.184 feature drop — mandatory 2FA, self-sealing breach lockdown, the skin JS scanner, and more. All five findings closed: fixed, or confirmed safe by design.</p>
                <a class="report-link" href="secaudits/2026-05-26-019-snapsmack-security-audit.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
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

            <article class="post" id="a011">
                <div class="post-meta"><span class="post-date">May 18, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Post-Remediation Verification</h2>
                <p>A second pass confirming the previous review's fixes were actually applied correctly — because "we fixed it" should be something you can check. All seven items verified resolved or confirmed clean.</p>
                <a class="report-link" href="secaudits/2026-05-18-011-snapsmack-security-audit.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a010">
                <div class="post-meta"><span class="post-date">May 18, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Delta Review: 0.7.147–0.7.152</h2>
                <p>A routine review of everything that changed across half a dozen releases. Two low-severity housekeeping items found and resolved; no exploitable vulnerabilities.</p>
                <a class="report-link" href="secaudits/2026-05-18-010-snapsmack-security-audit.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a009">
                <div class="post-meta"><span class="post-date">May 10, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Multisite Remote-Admin Surface</h2>
                <p>A full review of the new hub-and-spoke remote-admin features before they went out. All five findings were remediated in 0.7.102 ahead of release — nothing reached a live site unfixed.</p>
                <a class="report-link" href="secaudits/2026-05-10-009-snapsmack-security-audit.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
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
