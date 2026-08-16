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
                <p><strong>Transparency and accountability aren't a marketing line here, they're the deal.</strong> Claude and Codex run ongoing security audits of the codebase; high and medium-risk findings are fixed immediately, low-risk ones on a schedule. The reports below are the closed ones — issues found, issues fixed, dated and signed. What you won't find is a report describing a live, serious hole that's still open: publishing the blueprint for an unpatched break-in would put every SnapSmack site at risk, which is the opposite of protecting you. Those stay private until they're fixed, and then they show up here. That's responsible disclosure, and it's the honest version of "we take security seriously."</p>
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
                <li><span class="idx-date">Aug 15</span><a href="#a048">Live Penetration Test &mdash; Installer, Bootstrap, JSON-LD &amp; Editor Boundary</a></li>
                <li><span class="idx-date">Aug 14</span><a href="#a047">Full Multi-Dimension Audit &mdash; Authentication, Recovery, Federation &amp; Uploads</a></li>
                <li><span class="idx-date">Aug 13</span><a href="#a045">Pixelix Live Test &amp; Release Lifecycle</a></li>
                <li><span class="idx-date">Aug 13</span><a href="#a044">THE HUB Shared Stores &amp; Launcher Boundary</a></li>
                <li><span class="idx-date">Aug 13</span><a href="#a043">Pixelix Posting &amp; OAuth for GRAMOFSMACK</a></li>
                <li><span class="idx-date">Aug 7</span><a href="#a042">Desktop Tools &mdash; What "Already Fixed" Was Hiding</a></li>
                <li><span class="idx-date">Aug 7</span><a href="#a041">Download Links &mdash; Escaping Is Not Validation</a></li>
                <li><span class="idx-date">Aug 7</span><a href="#a004c">Smack Up Your Backup&rsquo;s First Review &mdash; Closed at Last</a></li>
                <li><span class="idx-date">Aug 6</span><a href="#a040">FLKR FCKR &mdash; Credentials at Rest, in Transit, and Trusted Paths</a></li>
                <li><span class="idx-date">Aug 6</span><a href="#a039">GET YOUR SHIT SORTED &mdash; Desktop Trust Boundary &amp; API Key Lifetime</a></li>
                <li><span class="idx-date">Aug 5</span><a href="#a038">Gallery Skins &mdash; JavaScript Package Boundary</a></li>
                <li><span class="idx-date">Aug 4</span><a href="#a037">Smack Up Your Backup Desktop &mdash; Credential Vault &amp; Transport</a></li>
                <li><span class="idx-date">Aug 4</span><a href="#a036">SmackPress WordPress Migration &mdash; Import Sanitising</a></li>
                <li><span class="idx-date">Jul 27</span><a href="#a035">IP SMACKER &amp; Login Shield &mdash; Client-Address Spoofing</a></li>
                <li><span class="idx-date">Jul 24</span><a href="#a034">Skin Manifest RCE &amp; Credentials Export — Closure</a></li>
                <li><span class="idx-date">Jul 15</span><a href="#a033">Fediverse Client — Attack Surface</a></li>
                <li><span class="idx-date">Jul 4</span><a href="#a032">Fediverse Search — Token Isolation</a></li>
                <li><span class="idx-date">Jun 29</span><a href="#a031">Cloud Backup From the Web Host &mdash; Feature Removed</a></li>
                <li><span class="idx-date">Jun 28</span><a href="#a030">Skin Manifests Could Run Code &mdash; The Finding</a></li>
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
                <li><span class="idx-date">May 18</span><a href="#a012">Installer Step-5 CSRF Bypass</a></li>
                <li><span class="idx-date">May 18</span><a href="#a011">Post-Remediation Verification</a></li>
                <li><span class="idx-date">May 18</span><a href="#a010">Delta Review: 0.7.147–0.7.152</a></li>
                <li><span class="idx-date">May 10</span><a href="#a009">Multisite Remote-Admin Surface</a></li>
            </ol>
        </div>
    </section>

    <section class="posts">
        <div class="wrap">

            <article class="post" id="a048">
                <div class="post-meta"><span class="post-date">August 15, 2026</span><span class="post-tag">Serious Findings Closed</span></div>
                <h2>Live Penetration Test &mdash; Installer, Bootstrap, JSON-LD &amp; Editor Boundary</h2>
                <p>SnapSmack&rsquo;s first live penetration test attacked a disposable 0.7.527D installation as both an unauthenticated visitor and a compromised content-only editor. It confirmed two critical installation-state failures, one high-severity JSON-LD script-injection flaw, and one medium-severity editor-boundary gap. The test also exercised the major controls added after SECAUDIT 047 and confirmed that upload restrictions, signed downloads, sensitive-file blocking, traversal protection, session handling, login behaviour, reset throttling, and destructive-action security tokens held up.</p>
                <p>The critical installer and bootstrap exposures, the script-injection path, and the missing editor restrictions were corrected in 0.7.528D and verified on the upgraded live site. Two additional LOW findings were also closed there. The report transparently records that HSTS and Content-Security-Policy did not reach upgraded installations through the original protected-file delivery path; their shipping correction is tracked separately and remains subject to live response-header verification.</p>
                <a class="report-link" href="secaudits/2026-08-15-048-live-penetration-test-0.7.527.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a047">
                <div class="post-meta"><span class="post-date">August 14, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Full Multi-Dimension Audit &mdash; Authentication, Recovery, Federation &amp; Uploads</h2>
                <p>Nine focused reviewers examined the live 0.7.525 development code across authentication, authorization, SQL injection, path handling, uploads and code execution, federation and server-side requests, cross-site request forgery, secrets and updates, and cross-site scripting. The review found four critical, seven high, three medium, and nine low issues. The most serious included editor accounts reaching administrator powers, recovery kits writing executable files, federated actors claiming another server&rsquo;s identity, media uploads accepting executable content, and destructive admin actions reachable without a security token.</p>
                <p>The remediation established a central administrator boundary, confined recovery imports, bound federation signatures and comment changes to their real actors, protected mutation links with security tokens, restricted uploads to verified images, generated unique per-site download secrets, hardened the Hub login, and closed the identified script-injection and server-side request paths. An adversarial follow-up found four sibling and race-condition gaps; those were closed as well. The narrow updater fail-open edge case remains documented as a deferred low-risk item because changing the fleet&rsquo;s sole repair path requires a separate deliberate review.</p>
                <a class="report-link" href="secaudits/2026-08-14-047-full-multidimension-audit-0.7.525.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a045">
                <div class="post-meta"><span class="post-date">August 13, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Pixelix Live Test &amp; Release Lifecycle</h2>
                <p>The real-device contract now works: Pixelix registered, passed through the normal password and two-factor login, received owner consent, staged a photograph as a private draft, updated its ALT text, published it, refreshed the profile, and loaded the resulting thumbnail. The final compatibility patch also stopped optional settings, account, city-search and collections calls from falling through to HTML errors.</p>
                <p>The successful post did not make the first pass clean. It found five follow-up items: abandoned media and OAuth records had no expiry cleanup; a read token could request an unpublished post by numeric ID; bearer rows with a missing access-token deadline failed open; code redemption and refresh rotation were not atomic under concurrency; and the same public 520D tag had been moved repeatedly after deployment.</p>
                <p>All five are closed. A bounded seven-day draft-media lifecycle removes abandoned files and OAuth residue safely; client status reads are published-only; null-expiry bearers are rejected and legacy rows revoked; token redemption and rotation use locked, conditional single-winner updates; and Smack Central now burns every release identifier into a permanent ledger before publication while recording its source commit, checksum and signature. The 520D history cannot be made immutable retroactively, but that identifier is retired and the packaging path now refuses reuse.</p>
                <a class="report-link" href="secaudits/2026-08-13-045-pixelix-live-interoperability-and-release-lifecycle.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a044">
                <div class="post-meta"><span class="post-date">August 13, 2026</span><span class="post-tag">Closed</span></div>
                <h2>THE HUB &mdash; Shared Profiles, Prompts, and the Launcher Boundary</h2>
                <p>The shared profile and Gemini-prompt stores held up: filenames are confined, writes are atomic and recoverable, migrations are repeatable, and no new network or shell surface was introduced. An independent adversarial pass did, however, overturn the first review&rsquo;s low-risk rating on the launcher. THE HUB searched for wildcard-matched programs inside the same folder tree GYSS is allowed to write. A compromised GYSS window could plant a program there and make it appear installed. Hub 0.1.2 removes and defensively rejects those in-jail wildcard searches.</p>
                <p>The follow-up closes the architectural remainder too. GYSS&rsquo;s privileged file commands can now reach only <code>shared_library</code> and its own <code>config_files\gyss</code> data. The sibling SYBU and COLD SNAP executable directories are outside that allowlist, including when addressed by exact path. A Rust regression test pins the legitimate data paths and rejects executable, lookalike-directory and traversal paths.</p>
                <a class="report-link" href="secaudits/2026-08-13-044-hub-readside-shared-profiles-and-prompts.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a043">
                <div class="post-meta"><span class="post-date">August 13, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Pixelix Posting &amp; OAuth &mdash; GRAMOFSMACK Only</h2>
                <p>GRAMOFSMACK can now accept photo and carousel posts from Pixelix through a deliberately narrow Pixelfed-compatible API. The connection uses owner-approved OAuth, expiring access and refresh credentials, explicit read/write scopes, administrative disconnect, and the existing owner switch for offline posting. Other SnapSmack install modes fail closed.</p>
                <p>The first security pass found six issues before release: one authorized client could claim another client&rsquo;s staged media; public client registration lacked a throttle; public discovery attempted database schema changes; concurrent uploads could race the hourly posting cap; refresh credentials had no hard lifetime; and recorded scopes were not enforced. All six are remediated. Media is bound to its originating OAuth connection, registration and posting limits are serialized, schema work stays in the updater, refresh credentials expire after 90 days, and every bearer route checks its required scope. Targeted syntax and regression gates pass; a real Pixelix device and live-database concurrency run remain rollout checks.</p>
                <a class="report-link" href="secaudits/2026-08-13-043-pixelix-oauth-and-gram-client-posting.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a042">
                <div class="post-meta"><span class="post-date">August 7, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Desktop Tools &mdash; What &ldquo;Already Fixed&rdquo; Was Hiding</h2>
                <p>Three days earlier we fixed a problem where a desktop tool could send your password or your access key across the internet unencrypted, and we wrote that the fix covered five tools at once. The fix was real. The claim that it covered five tools was not. The repair lived in a shared piece of code, and a shared safety check only protects the tools that actually call it &mdash; three of them never did. Nothing in the code could tell the difference between &ldquo;protected&rdquo; and &ldquo;never connected up&rdquo;, so the record said everything was fine while three tools carried on exactly as before.</p>
                <p>Unzucker, the Instagram importer, sent its access key in the clear on every request if the blog address began with <code>http</code> instead of <code>https</code>. Smack Up Your Backup was worse: it sent your actual <strong>admin password</strong> that way, from two separate places &mdash; one of which was the exact file an audit had flagged a week before and a later audit had marked as fixed. Smack Your Batch Up sent its key unchecked from three different screens. All are now checked before anything is sent. The password paths <em>refuse</em> outright, because a key can be cancelled and reissued but your password cannot be without locking you out of your own site. The key paths warn clearly and ask you to confirm. Connecting to your own computer for testing is unaffected.</p>
                <p>Two smaller things in Unzucker were fixed at the same time. If your computer's password store was unavailable &mdash; locked, or missing on that system &mdash; saving your settings quietly threw the access key away instead of falling back to storing it, so the tool came up blank next time with no explanation. And its settings file, which holds that key when no password store exists, was readable by any other account on the machine; it is now owner-only, as the equivalent file in FLKR FCKR already was.</p>
                <p>The lasting change is in how this is tested. The new checks do not just confirm the safety code works &mdash; they confirm each tool actually calls it, before the credential is sent, in every place a connection is made. Tests that only checked the safety code itself would have passed happily throughout the entire period these tools were exposed. One tool, Oh Snap, has not been examined yet and is scheduled next. No exploitation is known; these are single-operator tools and an attacker would need to be positioned on the network between you and your own server.</p>
                <a class="report-link" href="secaudits/2026-08-07-042-desktop-transport-coverage-unzucker-suyb-sybu.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a041">
                <div class="post-meta"><span class="post-date">August 7, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Download Links &mdash; Escaping Is Not Validation</h2>
                <p>SnapSmack lets you attach a download link to a photograph, pointing at a full-resolution copy on Google Drive, OneDrive or anywhere else. That link can be set by the desktop posting tools over their scoped key. Nothing checked what <em>kind</em> of link it was. A link does not have to point at a file &mdash; it can be written so that clicking it runs code in the visitor's browser instead, and that code runs as though it came from your site.</p>
                <p>What makes this one worth reading is why it survived review. The page that draws the download button did pass the link through an escaping function, and escaping is a genuine defence &mdash; it stops a value breaking out of the surrounding HTML. But it works by neutralising particular characters, and this kind of malicious link contains none of them. The code looked defended, was defended against a different problem, and read as correct. Elsewhere, the floating social dock re-read the same link and printed it with no protection at all.</p>
                <p>All four places &mdash; the two that store the link and the two that display it &mdash; now require it to be an ordinary web address before it is used. The two that display it re-check rather than trusting what is already saved, because links stored before this release are still in the database. If a stored link fails the check, the button quietly falls back to SnapSmack's own internal download instead of disappearing.</p>
                <p>This was found while reviewing the IndieWeb markup added the same day, which turned out to be sound &mdash; and which had already closed a similar gap on the social dock's profile links on its way past. No exploitation is known: it needs a bad link to have been stored in the first place, and a visitor to click the download button.</p>
                <a class="report-link" href="secaudits/2026-08-07-041-download-url-scheme-and-indieweb-identity-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a004c">
                <div class="post-meta"><span class="post-date">August 7, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Smack Up Your Backup&rsquo;s First Review &mdash; Closed at Last</h2>
                <p>The backup tool&rsquo;s first security review was done back in April. Its findings were fixed over the following months, but the report itself was never updated and never posted here &mdash; so the earliest audit of the tool that holds your server passwords has been the one thing missing from this page. This closes it, with every original finding re-checked against the code as it stands today rather than taken on trust from a later report.</p>
                <p>The one that reads alarmingly in the original is a Google service-account key &mdash; the credential that lets the tool upload to your Drive &mdash; sitting loose in the tool&rsquo;s folder, where the ignore rules would not have stopped it being committed. The April report said, correctly, that if such a key has ever been committed it must be treated as public and rotated immediately. <strong>It never was.</strong> Checking the entire history of the repository, on every branch, turns up no commit of that file at any point. The risk was real &mdash; it was one careless command away from being published &mdash; but the key was never exposed, no rotation was ever needed, and the recommended ignore rule was adopted word for word.</p>
                <p>Of the other four: passwords stored with reversible encoding are now in the passphrase-locked vault that arrived with the August review of the same tool. An unescaped filter that could break a Drive query is escaped. A debug log that quietly recorded activity to a file beside the program on every machine is gone entirely.</p>
                <p>One is <strong>not</strong> fixed, deliberately, and this page would be worth less if it did not say so. FTPS certificate checking is still off by default. Most photographers running this are on inexpensive hosting that ships self-signed or expired certificates, and switching validation on would break the majority of real setups on the first connection &mdash; trading one bad outcome for another. The answer is not to demand a perfect certificate; it is to remember the certificate your server presented the first time and warn you if it ever changes, which is exactly what the tool already does for SFTP. That work is scheduled. Until then it is recorded here as an open decision with a date on it, not quietly dropped.</p>
                <a class="report-link" href="secaudits/2026-08-07-004-closure-suyb-first-review.pdf" target="_blank" rel="noopener">Read the closure record &rarr;</a>
            </article>

            <article class="post" id="a040">
                <div class="post-meta"><span class="post-date">August 6, 2026</span><span class="post-tag">Closed</span></div>
                <h2>FLKR FCKR &mdash; Credentials at Rest, in Transit, and Trusted Paths</h2>
                <p>FLKR FCKR moves a Flickr export into a photo blog. Worth saying plainly, because the name suggests otherwise: it holds no Flickr credentials at all &mdash; it reads the export folder Flickr gave you, off your own disk. What it does hold is the key to <em>your</em> blog. Four findings, all closed.</p>
                <p>The one that mattered was not in this tool alone. When an action needs more than the tool's ordinary permissions, it asks for your account password and a two-factor code and sends them to your blog. That shared piece of code never checked whether the address was encrypted. If a blog address had been entered as <code>http</code> rather than <code>https</code>, your <strong>password and a live two-factor code</strong> crossed the network in the clear, and a code stays usable for the rest of its short window. It now refuses outright &mdash; not a warning, a refusal &mdash; because unlike an access key, a password cannot simply be cancelled and reissued. Connecting to your own machine for testing is unaffected.</p>
                <p>Second, the tool's access key was stored on disk with encoding rather than encryption &mdash; reversible by anyone who had the folder. It now uses the same passphrase-locked vault built for Smack Up Your Backup: your passphrase is never written anywhere, so a copy of the folder is no longer enough. Lose the passphrase and nothing is destroyed; paste the key again or issue a new one. The tool must be rebuilt for this to reach you.</p>
                <p>Two server-side items closed alongside. The blog stored image file paths exactly as the tool sent them, and those same paths are handed to the delete function later when you remove a photograph &mdash; so an unchecked value was a delayed, arbitrary file deletion waiting for an ordinary action to trigger it. Paths must now name a file the upload endpoint itself wrote. And an imported comment's author link was stored without checking it was a real web address, which mattered because that link is later rendered as a clickable link. Both now validated on the way in.</p>
                <p>One thing deliberately <em>not</em> changed: imported photographs keep their EXIF data, including GPS coordinates, exactly as Flickr held it. An importer's job is to move your archive, not to quietly edit it. That is a settled decision, recorded in the report as such, and not a finding. No exploitation is known; the remaining findings require either local access to your own computer or a valid key and an open authorisation window &mdash; which in normal operation means you.</p>
                <a class="report-link" href="secaudits/2026-08-06-040-flkr-fckr-credential-transport-and-import-path-trust.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a039">
                <div class="post-meta"><span class="post-date">August 6, 2026</span><span class="post-tag">Closed</span></div>
                <h2>GET YOUR SHIT SORTED &mdash; Desktop Trust Boundary &amp; API Key Lifetime</h2>
                <p>GET YOUR SHIT SORTED is the desktop sorting tool. Its window displays information fetched from the blog it is connected to, and it also had three unrestricted commands for reading and writing files on the computer running it. Those commands accepted any location on disk, the window had no content-security policy, and a handful of values arriving from the server were displayed without being neutralised first. Individually each was minor. Together they formed a path: a blog that had been tampered with &mdash; or an unencrypted connection someone else was sitting on &mdash; could have caused the tool to write a file somewhere it should never write. No exploitation is known, and the tool is used by one operator on their own machine.</p>
                <p>The file commands are now confined to the tool's own data folder and reject any attempt to climb out of it. Every value arriving from the server is neutralised before display. Connecting over an unencrypted address now warns plainly that the key travels in the clear, and asks for a deliberate confirmation. Separately, the tool's API key was never checked for expiry &mdash; keys are issued with a lifetime of four weeks or less, but this handler honoured them forever. Reviewing that led to a sweep of every key-checking entry point in SnapSmack: three more had the same gap, and one accepted a key issued for a completely different tool. All four now verify both the key's type and its expiry.</p>
                <p>Two items remain open by decision rather than oversight. The tool still stores its key with encoding rather than encryption, which will be replaced by the same protected vault that closed the equivalent finding for Smack Up Your Backup. A content-security policy is prepared but needs testing against a running build before it ships. AI enrichment, which spends money with a third-party provider, now states the number of paid requests before a run begins and counts them as it goes; it remains unavailable unless AI has been deliberately enabled and cost accepted on the blog itself. Closed in 0.7.505D.</p>
                <a class="report-link" href="secaudits/2026-08-06-039-gyss-desktop-client-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a038">
                <div class="post-meta"><span class="post-date">August 5, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Gallery Skins &mdash; JavaScript Package Boundary</h2>
                <p>SnapSmack skins choose the site's look, while reusable browser behaviour belongs to a shared, reviewed engine library. The code followed that design most of the time, but the Gallery packager did not enforce it: several official skin folders still carried their own JavaScript, and a future package could have included a script, an inline event handler, or a remote script reference and still reached the signing step. The files we found were legitimate features, not malicious code. They exposed a missing rule at the publishing boundary.</p>
                <p>That rule is now absolute: a Gallery skin ships no JavaScript. The official skin-local copies have been removed or moved into SnapSmack's shared engine library, where one repair reaches every skin that uses it. A repository scanner checks for bundled files, inline scripts, event handlers, JavaScript links, remote scripts, and active embedded content. Smack Central runs that same check before it creates, signs, or publishes a package; any blocking finding stops the build. A signature now answers who produced the package, while the clean gate separately proves that the package obeys the no-JavaScript policy.</p>
                <p>The complete official skin set scans clean, the moved engines are registered in the core inventory, and the packager fails closed. No exploitation is known. Closed in 0.7.500D.</p>
                <a class="report-link" href="secaudits/2026-08-05-038-skin-javascript-package-boundary.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a037">
                <div class="post-meta"><span class="post-date">August 4, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Smack Up Your Backup Desktop &mdash; Credential Vault &amp; Transport</h2>
                <p>Smack Up Your Backup is the desktop app that copies your whole site &mdash; images, database, everything &mdash; down to your own machine and off to cloud storage. To do that job it has to hold real keys: your FTP password, your site admin password, scoped API keys, and your Google Drive and Box tokens. This audit looked at how it kept them, and how it talked to your server.</p>
                <p>The finding that mattered: those keys were sitting on disk with no real protection &mdash; plain text or trivially-reversible base64. Anyone who got hold of the backup folder (a lost laptop, a synced Dropbox, a shared PC) had working credentials to your site and your cloud backups. It now keeps every secret in a passphrase-locked vault (scrypt key derivation with Fernet encryption); lock it and the secrets are gone from memory, and switching it on leaves no plaintext copy behind. If the vault is locked or a write fails, it stops rather than quietly falling back to the old plaintext &mdash; it fails closed. Two smaller items closed alongside: the restore path can no longer be tricked by a doctored backup manifest into writing outside its target folder, and SFTP now remembers your server's identity on the first connection and warns you if it ever changes, which catches a machine-in-the-middle without needing anything from your host.</p>
                <p>One thing was left as-is on purpose: for plain FTPS, certificate checking stays off by default, because the budget shared hosts most photographers use ship broken or self-signed certificates and turning it on would simply break everyone's backups. That tradeoff is documented in the report, along with the planned fix &mdash; pinning your host's certificate fingerprint on first use, the same trick now used for SFTP, so you get tamper detection without needing your host to fix their certificate. Closed in SUYB 0.7.19.</p>
                <a class="report-link" href="secaudits/2026-08-04-037-suyb-desktop-client-credential-and-transport-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a036">
                <div class="post-meta"><span class="post-date">August 4, 2026</span><span class="post-tag">Closed</span></div>
                <h2>SmackPress WordPress Migration &mdash; Import Sanitising</h2>
                <p>SmackPress is the tool that moves an existing WordPress blog into SnapSmack. This audit walked its whole path &mdash; the authenticated import API, the image upload, and the desktop client &mdash; before the first real blog was migrated. The core held up well: every database query is parameterised, the import key is a short-lived 256-bit token stored only as a hash, and uploaded images are re-encoded so they can't smuggle in code.</p>
                <p>The finding that mattered: a WordPress post body is raw HTML, and plugins inject all manner of things into it &mdash; including scripts. SmackPress was storing that HTML as-is, so anything hostile in a source post could have run on the new site for every visitor. It now passes every imported post and page through a strict allowlist that strips scripts, styles, iframes, event handlers, and <code>javascript:</code> links before anything is saved &mdash; verified against a dozen attack payloads. Two smaller items closed alongside: GIF uploads are now re-encoded like every other image format, and the desktop tool keeps your WordPress and API keys in your operating system's keychain instead of a plain file. Closed in 0.7.496.</p>
                <a class="report-link" href="secaudits/2026-08-04-036-smackpress-migration-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a035">
                <div class="post-meta"><span class="post-date">Updated July 28, 2026</span><span class="post-tag">Closed</span></div>
                <h2>IP SMACKER &amp; Login Shield &mdash; Client-Address Spoofing</h2>
                <p>A fleet check found impossible addresses in every site's shared ban table, including loopback and private-network addresses that could only belong to our own infrastructure. Tracing those records exposed a critical trust mistake: IP SMACKER and the login brute-force shield accepted forwarded-address headers without first proving the request had actually arrived through a trusted proxy. A scanner could therefore choose who was banned, rotate its claimed address to avoid the five-strike login limit, or deliberately lock out a victim.</p>
                <p>SnapSmack 0.7.451 closed the remotely exploitable spoofing path; 0.7.453 completed the shipped-code remediation. All four known ban writers and the shared-address rate limiters now use the mandatory trusted resolver and independently refuse infrastructure addresses as ban targets. Trusted proxies accept validated IP or CIDR entries, Configuration shows the observed peer and selected client, and permanent regression tests cover direct, tunnel, forged-header, and malformed-address cases. BREAK THE GLASS also preserves both the selected operator address and the observed network peer.</p>
                <p>The report is <strong>CLOSED in 0.7.454</strong>. The trusted-address fix remains in place, and the remaining operational work is now complete: the backed-up update performs a one-time reset of potentially forged automatic rows, duplicate bans keep a fixed expiry, expired and unsafe rows are pruned, table growth is bounded, and administrator-login bans trigger an out-of-band owner warning.</p>
                <a class="report-link" href="secaudits/2026-07-27-035-ip-smacker-forwarded-header-ban-injection.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a034">
                <div class="post-meta"><span class="post-date">July 24, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Skin Manifest RCE &amp; Credentials Export — Closure</h2>
                <p>An early design decision stored skin metadata as executable PHP. A simple question &mdash; what happens if a submitted skin puts code in its manifest? &mdash; exposed the unnecessary trust boundary: loading the metadata could execute arbitrary code with the web server's privileges. There is no evidence it was exploited. Every skin now uses strictly parsed JSON metadata, official packages require signatures, unsafe ZIP paths are rejected, and the temporary transition bridge is gone now that the whole fleet has migrated.</p>
                <p>The same review removed a redundant USER CREDENTIALS download that exported the complete user table even though the protected Recovery Kit already contained it. A closure pass found the credential-only mode still available through the authenticated SUYB export endpoint, so that second route and the shared engine capability were removed too. Recovery remains intact; the extra attack surface does not.</p>
                <a class="report-link" href="secaudits/2026-07-24-034-manifest-rce-and-credentials-export-closure.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a033">
                <div class="post-meta"><span class="post-date">July 15, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Fediverse Client — Attack Surface</h2>
                <p>SnapSmack is a fully integrated, Pixelfed-compatible single-user server &mdash; your blog's own home on the Fediverse, speaking the ActivityPub protocol so it can follow, like, comment on, boost, and message people right across the network, and be followed back. Because it is fully interactive, it shows content written by people on other servers, so this audit walked that entire trust boundary. The engine room held up well: requests arriving from other servers are cryptographically verified before they are allowed to change anything, nobody can pose as someone they aren't, and the software is fenced off from reaching back into your own network.</p>
                <p>Two medium findings were in the browser display code, where a hostile profile or post could have slipped a booby-trapped link into a page you were viewing. Both are closed &mdash; links coming from other servers are now checked to be ordinary web links before they are shown, and the profile-bio display was rebuilt to permit only safe formatting. A low-risk hardening item on an internal search request was tightened for good measure, and two informational notes were reviewed and accepted. Closed in 0.7.405.</p>
                <a class="report-link" href="secaudits/2026-07-15-033-fediverse-client-attack-surface.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a032">
                <div class="post-meta"><span class="post-date">July 4, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Fediverse Search — Token Isolation</h2>
                <p>SnapSmack can borrow a login token you hold on a friendly Fediverse instance so your blog can run authenticated searches across the network. This review checked how that token is stored and used. The one finding: the key protecting the stored token was falling back to a shared default value instead of a per-site secret. Fixed — every install now generates its own dedicated, random search key, so no two sites share protection.</p>
                <p>A second item flagged around form security turned out to be a false alarm: every admin form on SnapSmack already carries automatic cross-site-request protection. Everything else passed — the outbound requests are guarded against server-side request forgery, results render without script injection, adding an account is gated behind password + two-factor, and the token is never exposed to the browser. Closed in 0.7.376.</p>
                <a class="report-link" href="secaudits/2026-07-04-032-fediverse-search-token-isolation-audit.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a031">
                <div class="post-meta"><span class="post-date">June 29, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Cloud Backup From the Web Host &mdash; Feature Removed</h2>
                <p>SnapSmack used to offer a Cloud Backup feature that pushed your backups from the web server straight to your Google Drive or OneDrive. To do that unattended &mdash; on a schedule, without you present &mdash; it had to keep a long-lived access token and the associated secret <strong>on the web host</strong>, in the site's settings table.</p>
                <p>That is a poor place for them. Most photographers running this are on inexpensive shared hosting, where you do not control who else is on the machine. A server compromise, a leaked database dump, a backup folder left readable &mdash; any of those hands someone a working token for your cloud storage, and the token keeps working until it is revoked.</p>
                <p>This was not patched. It was <strong>removed</strong>, in 0.7.324. The right answer was not to guard the credential better but to stop the web host holding it at all. Pushing backups to the cloud now happens from Smack Up Your Backup on your own computer, where the credential lives with you rather than on a rented machine. The Backup page says so plainly where the feature used to be, rather than quietly dropping it.</p>
                <p>Publishing this one late: the fix shipped in June and the write-up was never put on this page. The record should be complete whether or not anyone was watching.</p>
                <a class="report-link" href="secaudits/2026-06-29-031-web-cloud-push-removed.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
            </article>

            <article class="post" id="a030">
                <div class="post-meta"><span class="post-date">June 28, 2026</span><span class="post-tag">Closed</span></div>
                <h2>Skin Manifests Could Run Code &mdash; The Finding</h2>
                <p>Every skin shipped a settings file that SnapSmack did not read as data &mdash; it <em>executed</em> it, as program code, on public pages and inside the admin panel. That means any skin present on the site could run whatever it liked with the site's own permissions: read or alter the database, take secrets, write files, change settings, or quietly rewrite what the admin screens showed you.</p>
                <p>The informal worry at the time was &ldquo;a bad skin could change some options&rdquo;. That understated it considerably. It was full remote code execution, limited only by what the web server itself could do &mdash; and skins are exactly the kind of thing people are encouraged to download and try.</p>
                <p>Remediated in 0.7.440. Skin settings are now read as data and cannot execute. Because the fix changed what a valid skin package looks like, deployment was deliberately gated on rebuilding and re-signing every hosted skin, so no site was left able to install an old-format package.</p>
                <p>This report is the <strong>finding</strong>, and it is being published now that it has long been closed &mdash; while it was open, publishing a working description of how to run code on someone's photo blog would have been reckless. The closure record went up in July as <a href="#a034">Skin Manifest RCE &amp; Credentials Export &mdash; Closure</a>; this is the other half of the story.</p>
                <a class="report-link" href="secaudits/2026-06-28-030-skin-manifest-rce.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a>
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
            <p>Report it privately through the SnapSmack support forum rather than posting details in public, and give us a chance to close it before it's common knowledge. That's the same courtesy we extend to you: we don't publish the details of a serious, open issue until it's fixed. Once it's closed, it joins the list above. <a href="https://github.com/baddaywithacamera/snapsmack" target="_blank" rel="noopener">The codebase is public and open to inspection at any time</a> — if you find something we missed, that's a contribution, and it's welcome.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
