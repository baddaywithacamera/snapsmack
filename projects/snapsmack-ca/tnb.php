<?php
/**
 * SNAPSMACK.CA — TWIG N BERRIES — SnapSmack Privacy
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'TWIG N BERRIES — SnapSmack Privacy';
$page_description = 'What SnapSmack knows about you and your site, and what it does with that.';
$page_og_url      = 'https://snapsmack.ca/tnb.php';
$nav_active       = 'tnb';
$page_css = <<<'PAGECSS'
───────────────────────────────────────────────────────── */
.page-header {
    padding: 80px 0 64px;
    border-bottom: 1px solid var(--border);
}

.page-header h1 { margin-bottom: 12px; }

/* ─── CONTENT ───────────────────────────────────────────────────────────── */
.page-body {
    max-width: 820px;
    padding: 64px 0 96px;
}

.page-body ul {
    margin: 0 0 1.4em 1.5em;
}

.page-body ul li {
    margin-bottom: 0.6em;
}

.callout {
    background: var(--light-grey);
    border-left: 4px solid var(--red);
    padding: 20px 24px;
    margin: 32px 0;
    font-size: 0.97rem;
}

.callout p { margin-bottom: 0; }

.updated {
    font-family: Arial, sans-serif;
    font-size: 0.8rem;
    color: var(--mid-grey);
    letter-spacing: 0.04em;
    margin-top: 56px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
}
PAGECSS;
include __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>TWIG N BERRIES</h1>
            <p class="lede">Let's talk about your privates. Er, privacy. Yeah. Here's what SnapSmack and its creator knows about you and your site, and what it does with that. No legalese. No tricks. Just a straight account of how this works.</p>
        </div>
    </div>

    <div class="wrap">
        <div class="page-body">

            <h2>The short version</h2>
            <p>SnapSmack is software you install on your own server. Your blog, your posts, your visitors — that data lives on your machine and nowhere else. The SnapSmack project does not collect it, see it, or have any access to it.</p>
            <p>Opt-in features — the spam protection network and the community forum — are the only ones that identify your site to us by name. SnapSmack does send two automatic pings: a version check when your installation looks for updates (so we can count installs and track which versions need support), and a counter ping when someone discovers the Thomas the Bear Easter egg. Both use pseudonymous hashed IDs. We get a count — not a name, not a URL, nothing that identifies you. Nothing is installed on your system by us. Nothing identifies you to us unless you choose to connect.</p>

            <h2>Your blog and your visitors</h2>
            <p>SnapSmack is self-hosted software. When someone visits your blog, comments on a post, or sends you a message through your site, that data goes to your server — not ours. We have no access to your posts, your images, your visitors, or your comment data.</p>
            <p>What your blog collects from visitors, and what you do with that data, is your responsibility. You should have your own privacy policy if your site collects personal information from visitors. SnapSmack's administration panel includes a privacy policy field for exactly this reason.</p>

            <h2>When you opt into SMACK UP (spam protection)</h2>
            <p>SMACK UP is SnapSmack's voluntary spam protection network. Sites that join share information about bad actors — trolls and spammers — so the whole network can benefit. Here is exactly what gets shared and what does not.</p>

            <p><strong>What is transmitted to the central server:</strong></p>
            <ul>
                <li>Your site's URL. This is how the network identifies you. It is stored on the central server and visible to the network administrator.</li>
                <li>SHA-256 hashes of IP addresses, email addresses, and browser fingerprints belonging to commenters you report. The hash is a one-way transformation — the original IP address or email cannot be recovered from it. The actual addresses never leave your server.</li>
                <li>Your site's post count and age, used to calculate how much weight your reports carry. Newer or smaller sites carry less weight than established ones.</li>
                <li>When you approve a flagged commenter, that approval is recorded as an allow-vote, which helps correct false positives across the network.</li>
            </ul>

            <p><strong>What is not shared:</strong></p>
            <ul>
                <li>Your visitors' actual IP addresses or email addresses — only hashes.</li>
                <li>Your post content, images, or any other blog data.</li>
                <li>Your admin credentials or API key (the key authenticates your site but is not stored in a readable form on the central server).</li>
            </ul>

            <div class="callout">
                <p>Other sites in the SMACK UP network cannot see your site's URL or your reporting history. That information is only visible to the network administrator (currently Sean McCormick, who runs Smack Central). What other sites receive from the network is threat scores for fingerprints — not a list of who reported them.</p>
            </div>

            <p>You can opt out of SMACK UP at any time in your admin settings. Opting out stops all further data transmission. Your site's registration record on the central server is retained but your weight is zeroed — you stop contributing to and receiving from the network.</p>

            <p>SMACK UP also includes GOBSMACKED, a feature that analyses writing style to detect ban evasion. <a href="#gobsmacked">Read more below.</a></p>

            <h2 id="gobsmacked">GOBSMACKED — writing fingerprinting (Tier 3 protection)</h2>
            <p>GOBSMACKED is an additional layer of spam protection built on top of SMACK UP. It exists for a specific reason: the developer of SnapSmack was personally cyberstalked by a troll for several years — one who evaded bans by rotating IP addresses, using disposable email accounts, and clearing browser fingerprints. GOBSMACKED is built to detect that kind of evasion, so that no one using SnapSmack has to go through the same thing.</p>

            <p>The plain version: if you're a bad actor, we take your fingerprints and save them — we just don't write your name on them. We don't need to. You'll leave more, and we'll match them up.</p>

            <p>Here is how it works and what it means for your blog's visitors.</p>

            <p><strong>What it does:</strong> When a commenter is banned on a SnapSmack blog that has SMACK UP enabled, the blog software analyses that commenter's previous comments and extracts a writing style fingerprint — a set of 25 numbers describing things like how often they use certain common words, their punctuation habits, average sentence length, and similar stylistic patterns. This is the same approach used in academic authorship analysis. The 25 numbers are then transmitted to Smack Central alongside the ban report.</p>

            <p><strong>What is not transmitted:</strong> The actual text of the comments never leaves your server. Only the 25 numeric values are sent. Smack Central has no record of what anyone wrote — only an abstract description of how they write.</p>

            <p><strong>What Smack Central does with it:</strong> The writing style fingerprints from all participating sites are periodically compared against each other. If a banned commenter shows up on a different site with a different IP address and a different email address, but writes in a recognisably similar style, Smack Central can flag that as a likely match and surface it for the network administrator to review. No automatic action is taken on a style match alone — it is a signal for human review, not an automatic ban.</p>

            <p><strong>Retention:</strong> Writing style fingerprints are stored for one year and then permanently deleted. They are used only to detect evasion of existing bans. They are not used for any other purpose and are not shared with any third party.</p>

            <div class="callout">
                <p>If your blog has SMACK UP enabled, you should disclose to your own visitors that when a commenter is banned, a writing style fingerprint derived from their comments may be transmitted to the SMACK CENTRAL network. The actual comment text is never transmitted — only the derived numeric fingerprint. You can copy and adapt the language above for your own privacy policy.</p>
            </div>

            <h2>When you opt into the community forum</h2>
            <p>The SnapSmack community forum is a place for site owners to talk to each other. When you connect your site to the forum:</p>
            <ul>
                <li>Your site's URL and the display name you choose are visible to all forum participants. This is intentional — the forum is a community of site owners, and participants can see who they are talking to.</li>
                <li>Posts and replies you make in the forum are visible to all participants.</li>
                <li>Your SnapSmack version is shown on your posts so other participants can see whether a support issue is version-related.</li>
            </ul>
            <p>You can disconnect your site from the forum at any time. When you do, or if you request it, your posts are <strong>anonymized</strong> rather than deleted — your display name, site URL, and identifying information are stripped, but the discussion thread stays intact so the support history remains useful to others. If a post contains a screenshot with sensitive site information visible, moderators can swap it for a pixelated version on request, or remove it entirely if it adds nothing to the discussion.</p>

            <h2>Version checks and install counting</h2>
            <p>When your SnapSmack installation checks for updates, it sends a brief ping to snapsmack.ca. This is how we count active installs and understand what versions are in the wild — useful for knowing how much support a given version might need. Here is what that ping contains:</p>
            <ul>
                <li>A pseudonymous ID derived from your site's URL using a one-way hash. The original URL cannot be recovered from it.</li>
                <li>Your installed version and update track.</li>
                <li>If your install is a hub, the number of active spokes it manages (so the fleet is counted once, not once per site).</li>
            </ul>
            <p>No site name, no traffic data, no content. If your install is a spoke in a multisite network, it skips the ping entirely — your hub's count already includes you.</p>

            <h2>Thomas the Bear (the Easter egg)</h2>
            <p>SnapSmack contains a hidden Easter egg called Thomas the Bear, a tribute to Noah Grey. When a visitor to your site discovers it, a single silent ping is sent to snapsmack.ca. Here is exactly what that ping contains:</p>
            <ul>
                <li>A pseudonymous random ID generated when SnapSmack is installed, stored in your site's database. It is not derived from your site's URL or any identifying information and cannot be linked back to you or your site.</li>
                <li>Which part of the Easter egg was triggered (the bears, or the Noah Grey dedication modal).</li>
                <li>Whether this is the first time the Easter egg has been found in the visitor's current browser session.</li>
            </ul>
            <p>Nothing else. No IP addresses, no site URL, no visitor data. We get a count. If you want to suppress the ping anyway, block outbound requests to snapsmack.ca at your firewall or web server — the Easter egg keeps working either way.</p>

            <h2>This website (snapsmack.ca)</h2>
            <p>This site does not use analytics, tracking pixels, or advertising scripts of any kind. Our web server access logs record the standard information any web server records — IP address, browser, referring page, page requested. We do not use that data for any purpose beyond diagnosing server problems.</p>
            <p>There are no cookies on this site other than any your browser sets on its own.</p>

            <h2>Who runs this</h2>
            <p>SnapSmack is built and maintained by Sean McCormick. If you've read this far you've probably noticed that he's a right nutter, but he hopes that he's an honest one. He's also mostly harmless unless you're a jelly-filled doughnut. If that's the case, you should run (roll?) for your life. The Smack Central network server and the community forum are operated by Sean McCormick (mirror universe version). If you have questions about your data, want to request removal of your site's record, or just want to know something this policy does not cover, get in touch.</p>
            <p>Contact: <span id="contact-privacy"></span></p>
            <script>
            (function(){
                var u='sean',d='baddaywithacamera.ca';
                var el=document.getElementById('contact-privacy');
                if(el){var a=document.createElement('a');a.href='mailto:'+u+'@'+d;a.textContent=u+'@'+d;el.appendChild(a);}
            })();
            </script>

            <p class="updated">Last updated: June 2026</p>

        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====