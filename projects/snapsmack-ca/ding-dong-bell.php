<?php
/**
 * SNAPSMACK.CA — Ding Dong Bell (Operability Audits & Honest Failure Ledger)
 *
 * Public operability-transparency page. The counterpart to BUZZERS! (security).
 * BUZZERS asks "can this be abused"; DING DONG BELL asks "does this actually do
 * its job — proven, not claimed". Unlike a security audit, this NEVER closes: it
 * is a living record of where we fell down, what we did, and what we have NOT yet
 * watched work. Honest labels are load-bearing — "fixed" must never read as
 * "watched working". Spec: _spec/SPEC-ding-dong-bell-operability-ledger-v0_1.md
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'DING DONG BELL! — SnapSmack Operability Audits';
$page_description = 'Every place SnapSmack fell down, what we did about it, and — honestly — what we have not yet watched work. Operability, in public.';
$page_og_url      = 'https://snapsmack.ca/ding-dong-bell.php';
$nav_active       = 'ding-dong';

$page_css = <<<'CSS'
/* ─── DING DONG BELL — H2/H3 OVERRIDES ────────────────────────────────────── */
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

/* ─── STATE LEGEND ────────────────────────────────────────────────────────── */
.state-legend {
    padding: 40px 0 8px;
}
.state-legend h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.75rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--mid-grey);
    margin-bottom: 18px;
}
.state-legend dl { max-width: 760px; }
.state-legend dt { margin-bottom: 4px; }
.state-legend dd {
    color: var(--mid-grey);
    font-size: 0.95rem;
    margin: 0 0 16px;
    padding-left: 2px;
}

/* ─── STATE CHIPS ─────────────────────────────────────────────────────────── */
.state {
    display: inline-block;
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 4px 10px;
    border-radius: 3px;
    white-space: nowrap;
    line-height: 1.3;
}
.state.open      { background: var(--red);      color: var(--white); }
.state.notwatch  { background: #F3E2B3;         color: #6b4c00; border: 1px solid #d9b84e; }
.state.watched   { background: #1a7a3c;         color: var(--white); }
.state.eyeball   { background: var(--black);    color: var(--white); }
.state.nottested { background: var(--light-grey);color: var(--dark-grey); border: 1px solid #ccc; }

/* ─── ENTRY LIST ──────────────────────────────────────────────────────────── */
.family-head {
    padding: 48px 0 8px;
    border-bottom: 3px solid var(--black);
    margin-bottom: 0;
}
.family-head h2 { color: var(--red); margin-bottom: 8px; }
.family-head p  { color: var(--mid-grey); max-width: 72ch; margin: 0; }

.entry {
    padding: 26px 0 0;
    max-width: 860px;
}
.entry-top {
    display: flex;
    gap: 14px;
    align-items: baseline;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.entry-top h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 1.02rem;
    text-transform: uppercase;
    letter-spacing: 0.01em;
    color: var(--black);
    margin: 0;
}
.entry .date { font-size: 0.8rem; color: var(--mid-grey); white-space: nowrap; }
.entry p { margin: 0 0 10px; max-width: 72ch; }
.entry .watched-line,
.entry .next-line {
    font-size: 0.9rem;
    color: var(--mid-grey);
    margin: 0 0 6px;
}
.entry .next-line strong { color: var(--black); }
.report-link-wrap { margin: 12px 0 0 !important; }
.report-link {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.8rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--red);
}
.report-link:hover { color: var(--black); text-decoration: none; }

/* ─── KILL STACKED-SECTION GAPS ───────────────────────────────────────────────
   Base CSS gives every <section> 72px top+bottom padding and a border between
   sections. This page uses several sections per family, so those paddings and
   borders compound into big empty bands. Collapse them: a family header sits
   directly above its own entries, with the header's black underline as the only
   divider between families. */
main section + section { border-top: none; }   /* kill the thin 1px inter-section lines */
section.state-legend { padding: 48px 0 44px; }
section.family-head  { padding: 52px 0 8px; }
section.posts        { padding: 0 0 44px; }
section.disclosure   { padding: 52px 0 72px; }
/* Alternating tinted bands, like the landing page. A family header and its
   entries share one background so each family reads as one block. */
.ddb-shade { background: var(--light-grey); }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>DING DONG BELL!</h1>
            <p class="lede">Where SnapSmack fell down, what we did about it &mdash; and, honestly, what we haven&rsquo;t yet watched work.</p>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="intro-body">
                <p><a href="buzzers.php">BUZZERS!</a> is where we show our work on security &mdash; whether the software can be <em>abused</em>. This is the other question, the one that&rsquo;s actually bitten us far more often: does a thing <em>do its job, end to end, watched on a real machine</em>. Almost nothing that hurt this project in the last several months was a break-in. It was plumbing that looked done and wasn&rsquo;t &mdash; a delivery that said &ldquo;sent&rdquo; and vanished, a fix written but never watched, a feature declared ready before it was built.</p>
                <p><strong>This page is different from BUZZERS! in one important way: it never closes.</strong> A security audit gets fixed, verified, and filed. This is a living record. Entries get more accurate over time; they&rsquo;re never quietly rewritten. And to be clear about the tone: a lot of what&rsquo;s below is <em>&ldquo;fixed, and we&rsquo;re confirming it&rdquo;</em> &mdash; not &ldquo;broken.&rdquo; We separate what we&rsquo;ve genuinely watched work from what we&rsquo;ve merely fixed, because those are two different claims and running them together is what kept biting us. Saying &ldquo;we&rsquo;re still confirming this&rdquo; out loud isn&rsquo;t an admission that it&rsquo;s broken; it&rsquo;s the honest version of done.</p>
                <p><strong>Why publish where we tripped?</strong> Because &ldquo;fixed&rdquo; in a changelog is the same word that&rsquo;s fooled us before, and the only cure is to say out loud what&rsquo;s been watched working versus what we&rsquo;ve merely been told is done. If you&rsquo;re running SnapSmack, or building something like it, this is the honest version of the story &mdash; not a highlight reel. The one thing you won&rsquo;t find here is a live, unfixed security hole; those stay in <a href="buzzers.php">BUZZERS!</a> until they&rsquo;re closed, then they&rsquo;re fair game to talk about.</p>
                <div class="slang">
                    <p><strong>&ldquo;Ding Dong Bell&rdquo;?</strong> Cockney rhyming slang for <em>hell</em>. This is the page that records every operational hell SnapSmack went through &mdash; the stuff we tripped over on the way to something that holds when you lean on it.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="state-legend ddb-shade">
        <div class="wrap">
            <h3>How to read the labels</h3>
            <dl>
                <dt><span class="state open">Blew up / Open</span></dt>
                <dd>It&rsquo;s broken, or the job it&rsquo;s meant to do can&rsquo;t be finished right now. A known problem, stated plainly.</dd>

                <dt><span class="state notwatch">Fixed &mdash; confirming</span></dt>
                <dd>We changed something to fix it, and confirming the real outcome on a real machine is the step still in progress. This isn&rsquo;t &ldquo;broken&rdquo; &mdash; it&rsquo;s &ldquo;fixed, and we&rsquo;re making sure.&rdquo; It stays here until someone actually watches it work, because &ldquo;fixed&rdquo; and &ldquo;watched working&rdquo; aren&rsquo;t the same claim.</dd>

                <dt><span class="state watched">Watched working</span></dt>
                <dd>Someone watched the real job succeed on a real machine and named what they saw. Trusted &mdash; for that version and that path only.</dd>

                <dt><span class="state eyeball">Eyeball next</span></dt>
                <dd>This incident pointed at a nearby path worth checking next. A lead we&rsquo;re following, labelled as a lead &mdash; not a known problem.</dd>

                <dt><span class="state nottested">Confirming</span></dt>
                <dd>Something we expect to work but haven&rsquo;t yet stood in front of and watched. Not broken &mdash; on the list to confirm, and said out loud rather than assumed done.</dd>
            </dl>
        </div>
    </section>

    <section class="family-head">
        <div class="wrap">
            <h2>Where our own plumbing fell down</h2>
            <p>Failures inside SnapSmack itself &mdash; the content model, background jobs, delivery, and the machinery that&rsquo;s supposed to keep a site running without anyone watching it.</p>
        </div>
    </section>

    <section class="posts">
        <div class="wrap">

            <div class="entry">
                <div class="entry-top">
                    <h3>The site had picture containers but no post containers</h3>
                    <span class="date">the deep one</span>
                    <span class="state watched">Watched working</span>
                </div>
                <p>For a long time a published photograph was stored as a bare image, not as a <em>post</em>. That sounds like an internal detail; it wasn&rsquo;t. With no post to hang things on, a photo&rsquo;s ownership, its date, its comments and likes, the collections it belonged to, and its identity out on the fediverse had no single home. Comments imported from Flickr were written against the picture instead of the post and came unstuck from it. Post counts collapsed to near-zero on photoblogs. A profile with thousands of live photos reported &ldquo;no posts yet.&rdquo; A repair tool kept having to convert loose photos into posts, and sites kept drifting back.</p>
                <p>The going-forward fix: posting a new photo now creates a real post in the background, built to match exactly what the repair tool and the poster already produced, so nothing else on the site can tell the difference &mdash; and web addresses never change, so federation stays stable. The deeper remediation is a proper post-model inventory with a transactional conversion.</p>
                <p class="next-line"><strong>One tail still being tidied:</strong> a batch of older comments are still being moved across from the photo to the post. They&rsquo;re real people&rsquo;s words, so it&rsquo;s being done carefully rather than rushed.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-002-picture-containers-no-post-containers.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Background jobs ran inside web requests and took whole sites down</h3>
                    <span class="date">the 524 outages</span>
                    <span class="state watched">Watched working</span>
                </div>
                <p>To pace out fediverse deliveries kindly, the code slept between them &mdash; but it was doing that <em>inside a live web request</em>. Each pause held a web-server worker hostage; enough of them piling up starved the pool, and the entire site timed out with a Cloudflare 524, even on a plain page load. A related version leaned on ordinary visitors to do background work, which made photoblog pages hang and 524 as well.</p>
                <p>The fix wasn&rsquo;t to patch it &mdash; it was to <strong>remove the feature that leaned on page loads.</strong> That work now runs through the desktop tools and proper scheduled jobs instead, never a visitor&rsquo;s page view. File backups now go through the desktop side; and you can always pull your files straight off the server yourself by FTP or SFTP. The sites stopped timing out.</p>
                <p class="watched-line"><strong>Watched working:</strong> the outages stopped once the feature was pulled &mdash; confirmed on live sites.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-003-background-jobs-in-web-requests-524.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Deliveries that landed on a web page counted as &ldquo;delivered&rdquo;</h3>
                    <span class="date">&ldquo;Wrong Door&rdquo;</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>Some follower records held an old, wrong delivery address that quietly rendered the site&rsquo;s homepage &mdash; a normal <code>200 OK</code>. The sender read that <code>200</code> as success, ticked the post off, deleted it from the queue, and the post simply vanished. This is the exact reason SnapSmack-to-SnapSmack followers never received the Photo Friday prompt cards while Mastodon and Pixelfed followers got them fine.</p>
                <p>Now a success that comes back as a full web page is treated as a <em>failure</em> &mdash; &ldquo;not an inbox&rdquo; &mdash; and on that failure the sender re-fetches the follower&rsquo;s live address, rewrites the wrong one, and knocks on the right door next pass. No manual unfollow-and-refollow needed. The lesson underneath it drives everything below: <strong>a <code>2xx</code> proves the pipe carried the bytes, never that the other end kept the post.</strong></p>
                <p class="next-line"><strong>Confirming:</strong> the site itself now confirms the delivery landed, but we haven&rsquo;t yet run it end-to-end through the test lab to watch a post travel the whole way. That&rsquo;s the remaining step.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-004-wrong-door-delivered-to-a-web-page.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Incoming fediverse activity arrived, passed checks, then vanished</h3>
                    <span class="date">&ldquo;Signed Receipt&rdquo;</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>Something coming in from another fediverse server &mdash; a post, a like, a reply &mdash; could arrive, pass its signature check, and still disappear: because it came from an account we weren&rsquo;t following, or was a duplicate, or the save failed and the error was swallowed. Every one of those read exactly like &ldquo;never delivered&rdquo; while someone hunted for a lost post. You can&rsquo;t fix what you can&rsquo;t see, and this class of bug was invisible.</p>
                <p>Every inbound item now leaves a receipt in the interactions log saying what happened to it &mdash; ingested, ignored and why, a duplicate suppressed, a reply routed &mdash; so a drop is now something you can read instead of a mystery. Several of the federation fixes on this page were only findable <em>after</em> this went in.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-005-incoming-fediverse-verified-then-vanished.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>The default network relay pointed at a machine that no longer existed</h3>
                    <span class="date">retired box</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>The built-in default relay address still named a standalone server that had been decommissioned. Any install that hadn&rsquo;t set its own relay address &mdash; including the fleet hub &mdash; aimed every join at a dead inbox, and every join silently failed with no error to show for it. The default now points at the live network actor; an explicit per-site address still overrides it.</p>
                <p class="next-line"><strong>Confirming:</strong> the default is corrected in the code; we haven&rsquo;t yet watched a fresh install join the relay cleanly on that new default alone.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-006-default-relay-pointed-at-a-dead-box.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>The scheduled jobs looked registered while pointing at yesterday&rsquo;s door</h3>
                    <span class="date">the cron drift</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>Federation quietly stopped across the whole fleet for about two days, and it looked like several separate features breaking at once &mdash; new follows got no catalogue, posts never pushed, the version check went silent. It was one cause: the scheduled jobs still existed, but the command each one ran pointed at a script path that no longer existed after a deploy moved the install directory. A check that only asks &ldquo;is the job registered?&rdquo; said yes &mdash; the job was registered to run nothing.</p>
                <p>The command-level checker (not just a heartbeat) exposed it, the jobs were re-registered across the hub and all 24 spokes, and a permanent self-heal shipped so a deploy re-points every job to the current path. The repair was watched working &mdash; a hub run completed and a backfill test landed end to end &mdash; but the durable fix isn&rsquo;t deployed fleet-wide yet.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-011-cron-registered-but-pointing-at-old-paths.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Adding a blog to the fleet never actually made it follow the others</h3>
                    <span class="date">discovery &ne; connection</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>The fleet is meant to be all-to-all: every blog follows every other, so a post on one reaches the rest. It wasn&rsquo;t. Blogs had been added to the fleet&rsquo;s roster &mdash; the list of who exists &mdash; but adding them never established the actual follow relationships. Being on the list is not being connected, and nothing ever did the connecting: about 180 of the 600 relationships a 25-blog network needs were simply never made.</p>
                <p>The missing follows were added back through each site&rsquo;s own controls (adding only, never deleting anyone&rsquo;s external follows), and a permanent reconciler now fills one missing peer per cron tick. All 600 relationships now exist and a backfill test landed end to end; the durable reconciler ships in the same build that isn&rsquo;t deployed fleet-wide yet.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-012-fleet-follow-mesh-never-reconciled.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

        </div>
    </section>

    <section class="family-head ddb-shade">
        <div class="wrap">
            <h2>Where talking to other software fell down</h2>
            <p>The fediverse is a room full of different implementations, and every assumption about how a peer reads our output was wrong until proven. One peer working proves nothing about the next.</p>
        </div>
    </section>

    <section class="posts ddb-shade">
        <div class="wrap">

            <div class="entry">
                <div class="entry-top">
                    <h3>Pixelfed accepted our posts, then silently dropped them</h3>
                    <span class="date">the teacher</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>The saga that taught us the whole doctrine. First, every Follow from Pixelfed was rejected for two weeks &mdash; Pixelfed builds its signature check from the path only and dropped the query string our inbox address carried, so the signatures never matched. Then, once that was fixed, deliveries were <em>accepted</em> and no post ever appeared: Pixelfed re-encodes an <code>&amp;</code> when it fetches an object back, so our object address arrived mangled and 404&rsquo;d &mdash; the post dropped <em>after</em> being accepted.</p>
                <p>Both of those specific bugs were found and fixed by matching what Pixelfed actually does rather than what the spec says it should: verify against both signature styles, and use plain object addresses with no query string. Posts did land in testing once those were in.</p>
                <p class="next-line"><strong>Still confirming &mdash; Pixelfed stays the rough one:</strong> our Mastodon side is confirmed solid, but Pixelfed still behaves inconsistently and we see drops we haven&rsquo;t fully explained yet. So we won&rsquo;t call Pixelfed solid the way we can call Mastodon solid. The known bugs are fixed; the peer itself is still being watched.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-001-pixelfed-accepted-then-dropped.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>A failed signed fetch dropped every incoming like, boost, and reply</h3>
                    <span class="date">&ldquo;Second Knock&rdquo;</span>
                    <span class="state watched">Watched working</span>
                </div>
                <p>To verify an inbound activity we fetch the sender&rsquo;s key, and we signed that outbound fetch. When a peer refused the signed fetch, the whole verification failed with &ldquo;could not fetch signer,&rdquo; and <em>every</em> like, boost, and reply from that server was dropped &mdash; which is why a wall of &ldquo;signature verify failed&rdquo; rejections was never actually a crypto problem. A failed signed fetch now retries unsigned, which is what most instances serve anyway. A companion tool re-pulls entries dropped during the outage so nobody had to re-post.</p>
                <p class="watched-line"><strong>Watched working:</strong> boosts now show up properly on our own site&rsquo;s Pixelfed page &mdash; confirmed live.</p>
                <p class="next-line"><strong>Eyeball next:</strong> a few instances genuinely insist on a signed fetch (authorized-fetch mode) and still refuse ours, so those specific servers aren&rsquo;t confirmed yet. Named and being worked on.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-007-second-knock-signed-fetch-dropped-inbound.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Modern Mastodon signs in a format our door couldn&rsquo;t read</h3>
                    <span class="date">RFC-9421</span>
                    <span class="state watched">Watched working</span>
                </div>
                <p>Mainline Mastodon 4.4 and later sign their inbox deliveries with a newer signature standard (RFC-9421) that our inbox verifier couldn&rsquo;t read, so those deliveries bounced. We first made the rejection log say <em>which</em> scheme arrived &mdash; turning every bounce into direct evidence &mdash; then built the verifier to accept the new format alongside the old.</p>
                <p class="watched-line"><strong>Watched working:</strong> talking to Mastodon looks flawless now &mdash; confirmed live. Mastodon also displays our GRAMOFSMACK carousels and our solo posts correctly.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-008-modern-mastodon-rfc9421-signing.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Likes from Pixelfed never landed</h3>
                    <span class="date">wrong key</span>
                    <span class="state notwatch">Fixed &mdash; confirming</span>
                </div>
                <p>A Like from Pixelfed points at the human-readable permalink of a photo, not the machine address our resolver knew how to match &mdash; so every like from a Pixelfed follower resolved to nothing and was dropped, each one showing as <em>unresolved</em> in the log. The resolver now also accepts the public permalink and maps it back to the right post or photo. (Notably, this was caught by the inbox log built for exactly this purpose &mdash; the drop was visible, so it got fixed.)</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-009-pixelfed-likes-never-landed.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>The desktop poster silently dropped ALT text and the colour tag</h3>
                    <span class="date">COLD SNAP</span>
                    <span class="state watched">Watched working</span>
                </div>
                <p>The site accepted alt text and the colour / black-and-white tag all along, but the COLD SNAP desktop poster never actually sent them &mdash; so the ALT you carefully typed went nowhere, quietly. All three posting modes now send both, and every photo travels with its own description and colour tag attached to the image, offline and on the wire.</p>
                <p class="report-link-wrap"><a class="report-link" href="opaudits/2026-09-07-010-cold-snap-dropped-alt-and-colour-tag.pdf" target="_blank" rel="noopener">Read the full report &rarr;</a></p>
            </div>

        </div>
    </section>

    <section class="family-head">
        <div class="wrap">
            <h2>What this cost us to learn &mdash; for anyone building with AI</h2>
            <p>SnapSmack is built by one photographer directing AI to write code he can&rsquo;t read himself. If that&rsquo;s you &mdash; building a real thing through an AI, or building something like this &mdash; every lesson below was paid for in one of the incidents above. They&rsquo;re the part worth stealing.</p>
        </div>
    </section>

    <section class="posts">
        <div class="wrap">

            <div class="entry">
                <div class="entry-top"><h3>&ldquo;Fixed&rdquo; and &ldquo;watched working&rdquo; are two different claims &mdash; never let them blur</h3></div>
                <p>This is the expensive one, and it&rsquo;s why this whole page has coloured tags. When an AI writes code you can&rsquo;t read, &ldquo;the agent says it&rsquo;s fixed&rdquo; is a claim, not a result. Track what you <em>changed</em> separately from what you&rsquo;ve <em>watched actually work on a real machine</em>, and never let the first quietly become the second. The word &ldquo;fixed&rdquo; in a changelog fooled us more than once &mdash; the Photo Friday cards read &ldquo;delivered&rdquo; and vanished for weeks.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>You verify by the outcome on a real machine, not by reading the code</h3></div>
                <p>If you can&rsquo;t read the code, that isn&rsquo;t the weakness it sounds like &mdash; it just moves where you check. Your real power is knowing exactly what the finished thing must <em>do</em>. Define that hole precisely before a line is written, then test the running system against it and pare off anything that doesn&rsquo;t fit. &ldquo;The agent says it&rsquo;s done&rdquo; doesn&rsquo;t fill the hole; filling the hole fills the hole &mdash; and you can see that with your own eyes without reading a single function.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>A success code proves the message was carried, never that the job got done</h3></div>
                <p>A server answering <code>200</code> means the bytes arrived &mdash; not that your post landed, saved, or displayed. Treat every &ldquo;success&rdquo; as &ldquo;received,&rdquo; and confirm the actual outcome separately. Pixelfed <em>accepted</em> our posts and then silently dropped them; the acceptance was real and the post was gone.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>Every assumption about how another system behaves is wrong until you prove it against that exact system</h3></div>
                <p>One peer working tells you nothing about the next. The fixes that made Pixelfed work proved nothing about Mastodon or GoToSocial &mdash; each had to be watched on its own. If your thing talks to anyone else&rsquo;s thing, confirm it against <em>their</em> real system, not the spec and not a sibling that happened to pass.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>You can&rsquo;t fix what you can&rsquo;t see &mdash; build the log before you chase the bug</h3></div>
                <p>Our worst bugs were invisible: things arrived, passed their checks, and disappeared with no trace, which reads exactly like &ldquo;never happened.&rdquo; The moment we made the system report what it did with each item &mdash; kept, ignored and why, dropped and where &mdash; the bugs became findable. Instrument first; then hunt.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>Slow or background work never belongs inside a page load</h3></div>
                <p>If a job can be slow, never run it while a person is waiting for a page &mdash; it can take the whole site down with it. Ours did: paced work running inside web requests starved the server and timed out entire sites. Move anything slow to a place that can fail on its own without a visitor noticing.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>&ldquo;Set up&rdquo; is not &ldquo;working,&rdquo; and &ldquo;on the list&rdquo; is not &ldquo;connected&rdquo;</h3></div>
                <p>Two of the worst outages here were things that <em>looked</em> configured and weren&rsquo;t. Scheduled jobs were registered &mdash; pointing at a script that no longer existed. Blogs were added to the network roster &mdash; without ever being made to follow anyone. A presence check passes on both, and the failure is silent and looks like something else breaking. Check the real thing: that the command resolves to the installed script, that the relationship actually exists &mdash; not just that a record of it does.</p>
            </div>

            <div class="entry">
                <div class="entry-top"><h3>Git history tells you what got fixed &mdash; never what&rsquo;s still untested</h3></div>
                <p>A test you never ran writes nothing to the record. So &ldquo;the changelog is clean&rdquo; is not &ldquo;the software is proven&rdquo; &mdash; the two just look alike. That&rsquo;s exactly why most tags on this page start yellow: the history proves the fix was written, and a person still has to watch it work before it earns green.</p>
            </div>

        </div>
    </section>

    <section class="family-head ddb-shade">
        <div class="wrap">
            <h2>What we haven&rsquo;t confirmed yet</h2>
            <p>These aren&rsquo;t broken and they aren&rsquo;t known bugs &mdash; they&rsquo;re things we expect to work but haven&rsquo;t yet stood in front of and watched. A quarter-million-line system confirmed by one photographer&rsquo;s hands-on testing has a real list of things still to confirm, and we&rsquo;d rather show you the list and work through it than quietly assume it&rsquo;s all fine.</p>
        </div>
    </section>

    <section class="posts ddb-shade">
        <div class="wrap">

            <div class="entry">
                <div class="entry-top">
                    <h3>Federation with peers we haven&rsquo;t specifically watched</h3>
                    <span class="state nottested">Confirming</span>
                </div>
                <p>Posting and display are watched working on Mastodon and Pixelfed. <strong>GoToSocial and every other implementation start at &ldquo;not confirmed&rdquo;</strong> for each operation &mdash; post, comment, boost, display &mdash; until that specific peer is watched. One peer passing never confirms another, because the failures we&rsquo;ve hit were always in the gap between what a peer <em>should</em> do and what it <em>actually</em> does.</p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Photo editor files opened in other editors</h3>
                    <span class="state watched">Watched working</span>
                    <span class="state nottested">Confirming</span>
                </div>
                <p><strong>SNAP SLAPPER into Photoshop is confirmed</strong> &mdash; a real export was opened in Photoshop and checked against the original with a difference file, and it held up. That&rsquo;s the one direction we&rsquo;ve genuinely proven.</p>
                <p class="watched-line"><strong>Watched working:</strong> SNAP SLAPPER export &rarr; Photoshop, verified against the original with a difference file.</p>
                <p class="next-line"><strong>Still confirming:</strong> the other direction (a Photoshop file coming <em>into</em> SNAP SLAPPER) is not yet checked, and neither are other editors like Affinity. Each editor and each direction gets its own confirmation &mdash; one passing doesn&rsquo;t vouch for the rest.</p>
            </div>

            <div class="entry">
                <div class="entry-top">
                    <h3>Everything a changelog calls &ldquo;fixed&rdquo; that nobody has since watched</h3>
                    <span class="state nottested">Confirming</span>
                </div>
                <p>Git tells us honestly what got fixed and why. It cannot tell us what&rsquo;s still broken or what was never tested &mdash; a test never run writes nothing to history. So most fixes on this page sit at <em>fixed, not watched yet</em> until a named person watches the real outcome on a real machine. We&rsquo;d rather tell you that than launder a changelog line into a promise.</p>
            </div>

        </div>
    </section>

    <section class="disclosure">
        <div class="wrap">
            <h2>Watched Something Break? Or Watched It Work?</h2>
            <p>This ledger gets more accurate when people who run SnapSmack tell us what they actually saw &mdash; a delivery that failed, a peer that choked, or, just as usefully, a job you watched succeed on your own box. A named observation is what turns &ldquo;fixed&rdquo; into &ldquo;watched working&rdquo; here.</p>
            <p>Tell us through the SnapSmack support forum. If it&rsquo;s a security issue &mdash; something that could be abused rather than something that just doesn&rsquo;t work &mdash; report it privately and it&rsquo;ll join <a href="buzzers.php">BUZZERS!</a> once it&rsquo;s closed. <a href="https://github.com/baddaywithacamera/snapsmack" target="_blank" rel="noopener">The codebase is public and open to inspection at any time.</a></p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
