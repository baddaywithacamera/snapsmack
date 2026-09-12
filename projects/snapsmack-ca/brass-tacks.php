<?php
/**
 * SNAPSMACK.CA — Brass Tacks (FAQ hub)
 *
 * The SnapSmack FAQ, now split into sections (faq-*.php, shared layout in
 * includes/faq-page.php). This page is the index of every question. Old
 * deep links (brass-tacks.php#q-something) are forwarded to the right
 * section by the script at the bottom, so nothing anyone bookmarked breaks.
 * Source of record for the wording: _continuity/brass-tacks-v0_6.docx.
 * Deliberately brash, profane, honest. Keep the voice — substance edits only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

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
.faq-sections { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px; margin-top: 8px; background: var(--border); border: 1px solid var(--border); }
.faq-sections a { display: block; padding: 18px 20px; background: var(--white); color: var(--black); }
.faq-sections a strong { display: block; font: 900 .85rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.faq-sections a strong::after { content: " \2192"; color: var(--red); }
.faq-sections a span { display: block; margin-top: 5px; color: var(--mid-grey); font-size: .82rem; line-height: 1.45; }
.faq-sections a:hover { background: #fff5f3; text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
.faq-index { padding: 40px 0 72px; border-top: 3px solid var(--black); }
.faq-index h2 { font-family: Arial Black, Arial, sans-serif; font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--mid-grey); margin-bottom: 22px; }
.faq-index .idx-group { margin-bottom: 34px; }
.faq-index .idx-group:last-child { margin-bottom: 0; }
.faq-index .idx-group > h3 { font-family: Arial Black, Arial, sans-serif; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 12px; }
.faq-index .idx-group > h3 a { color: var(--red); }
.faq-index .idx-group > h3 a:hover { color: var(--black); text-decoration: none; }
.faq-index .idx-group > h3 span { display: block; margin-top: 3px; color: var(--mid-grey); font: .8rem/1.4 Georgia, serif; text-transform: none; letter-spacing: 0; }
.faq-index ol { list-style: none; columns: 2; column-gap: 48px; }
.faq-index ol li { margin-bottom: 10px; break-inside: avoid; }
.faq-index ol li a { font-family: Arial, sans-serif; font-size: 0.92rem; font-weight: 700; color: var(--dark-grey); line-height: 1.35; }
.faq-index ol li a:hover { color: var(--red); text-decoration: none; }
@media (max-width: 850px) { .faq-sections { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .faq-index ol { columns: 1; } .faq-sections { grid-template-columns: 1fr; } }
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
            <nav class="faq-sections" aria-label="FAQ sections">
                <a href="faq-why.php"><strong>Why SnapSmack</strong><span>Why it exists, where it came from, why it swears, and what the catch is.</span></a>
                <a href="faq-running.php"><strong>Running it</strong><span>How techie you need to be, what it needs, what it runs on, and why the phone is second fiddle.</span></a>
                <a href="faq-content.php"><strong>Your content</strong><span>Lock-in (none), what you can bring, and who this is for.</span></a>
                <a href="faq-tools.php"><strong>Desktop tools</strong><span>The free Windows and Linux suite: which tool does what, where your files go, and what it never does.</span></a>
                <a href="faq-fediverse.php"><strong>Fediverse &amp; discovery</strong><span>The network, the directory, the weekly challenge, and why you need a password to join.</span></a>
                <a href="faq-security.php"><strong>Security</strong><span>Is it secure, what SMACKBACK is, why you keep re-authenticating, and what happens if you get hacked.</span></a>
                <a href="faq-future.php"><strong>Trust &amp; the future</strong><span>Why trust it, will it stay free, and what is (and isn't) coming.</span></a>
            </nav>
        </div>
    </section>

    <section class="faq-index">
        <div class="wrap">
            <h2>All Questions</h2>

            <div class="idx-group">
                <h3><a href="faq-why.php">Why SnapSmack</a> <span>Why it exists, where it came from, why it swears, and what the catch is.</span></h3>
                <ol>
                    <li><a href="faq-why.php#q-biggest-best">Is SnapSmack the biggest, best photoblogging software out there?</a></li>
                    <li><a href="faq-why.php#q-the-point">What's the point of SnapSmack?</a></li>
                    <li><a href="faq-why.php#q-where-from">Where did this come from?</a></li>
                    <li><a href="faq-why.php#q-why-now">Why this, why now?</a></li>
                    <li><a href="faq-why.php#q-rude-profane">Why so rude and profane?</a></li>
                    <li><a href="faq-why.php#q-ai">AI? AIIIIIIEEEEE!!!</a></li>
                    <li><a href="faq-why.php#q-catch">What's the catch?</a></li>
                    <li><a href="faq-why.php#q-monetization">Where are the monetization options? Your demos don't show you running AdSense.</a></li>
                    <li><a href="faq-why.php#q-reimagining">A re-imagining of Pixelpost?</a></li>
                    <li><a href="faq-why.php#q-noah-grey">Who is Noah Grey?</a></li>
                    <li><a href="faq-why.php#q-thomas">What is Thomas the Bear?</a></li>
                    <li><a href="faq-why.php#q-vs-pixelpost">SnapSmack vs Pixelpost — what's the difference?</a></li>
                    <li><a href="faq-why.php#q-classic-ig">Why are you ripping off Instagram?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="faq-running.php">Running it</a> <span>How techie you need to be, what it needs, what it runs on, and why the phone is second fiddle.</span></h3>
                <ol>
                    <li><a href="faq-running.php#q-how-techie">How techie do I have to be to run this?</a></li>
                    <li><a href="faq-running.php#q-resources">Resources needed?</a></li>
                    <li><a href="faq-running.php#q-platforms">Platforms supported?</a></li>
                    <li><a href="faq-running.php#q-mobile">Why such limited mobile support?</a></li>
                    <li><a href="faq-running.php#q-mobile-app">Will there be a mobile app for posting?</a></li>
                    <li><a href="faq-running.php#q-companion-apps">Why companion apps instead of plugins?</a></li>
                    <li><a href="faq-running.php#q-macos">Does SnapSmack run on macOS?</a></li>
                    <li><a href="faq-running.php#q-install-modes">Why can't I switch install modes?</a></li>
                    <li><a href="faq-running.php#q-skins">How do skins work?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="faq-content.php">Your content</a> <span>Lock-in (none), what you can bring, and who this is for.</span></h3>
                <ol>
                    <li><a href="faq-content.php#q-content-locked">I've got content locked in elsewhere — do I have to abandon it?</a></li>
                    <li><a href="faq-content.php#q-snapsmack-lock-in">Does SnapSmack lock in my content?</a></li>
                    <li><a href="faq-content.php#q-business">Can I use SnapSmack to run my business website?</a></li>
                    <li><a href="faq-content.php#q-business-photoblog">I'm a business owner, but I want to have a real photoblog to show my work to my customers. Is that okay?</a></li>
                    <li><a href="faq-content.php#q-artist">I'm an artist, not a photographer. Can I use SnapSmack to share my paintings, drawings, or other non-photographic work?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="faq-tools.php">Desktop tools</a> <span>The free Windows and Linux suite: which tool does what, where your files go, and what it never does.</span></h3>
                <ol>
                    <li><a href="faq-tools.php#q-tools-which">There are nine desktop tools. Which one do I actually need?</a></li>
                    <li><a href="faq-tools.php#q-tools-required">Do I have to use them?</a></li>
                    <li><a href="faq-tools.php#q-tools-platforms">Windows only?</a></li>
                    <li><a href="faq-tools.php#q-tools-originals">Do the tools upload my originals anywhere? Copy them somewhere I didn't ask for?</a></li>
                    <li><a href="faq-tools.php#q-tools-ai">The AI enrichment &mdash; whose key, whose data?</a></li>
                    <li><a href="faq-tools.php#q-tools-safe">Are they safe to run against my live site?</a></li>
                    <li><a href="faq-tools.php#q-tools-download">Where do I download them?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="faq-fediverse.php">Fediverse &amp; discovery</a> <span>The network, the directory, the weekly challenge, and why you need a password to join.</span></h3>
                <ol>
                    <li><a href="faq-fediverse.php#q-fediverse-what">What is the Fediverse — and why do I want it in my photo blog?</a></li>
                    <li><a href="faq-fediverse.php#q-fediverse-ethics">Why did you build SnapSmack on the Fediverse?</a></li>
                    <li><a href="faq-fediverse.php#q-posse">What is POSSE?</a></li>
                    <li><a href="faq-fediverse.php#q-photoblogs-fyi">What is photoblogs.fyi?</a></li>
                    <li><a href="faq-fediverse.php#q-photofriday">What is PHOTOFRI.DAY?</a></li>
                    <li><a href="faq-fediverse.php#q-join-photofriday">How do I join PHOTOFRI.DAY?</a></li>
                    <li><a href="faq-fediverse.php#q-join-conduct">Why do I need a password and 2FA just to join — and do I have to behave myself?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="faq-security.php">Security</a> <span>Is it secure, what SMACKBACK is, why you keep re-authenticating, and what happens if you get hacked.</span></h3>
                <ol>
                    <li><a href="faq-security.php#q-secure">Is SnapSmack secure?</a></li>
                    <li><a href="faq-security.php#q-smackback">What is SMACKBACK?</a></li>
                    <li><a href="faq-security.php#q-why-security">Why does SnapSmack care so much about security?</a></li>
                    <li><a href="faq-security.php#q-reauth">Why does it feel like I have to reauthenticate every time I do something?</a></li>
                    <li><a href="faq-security.php#q-hacked">What happens if my site gets hacked?</a></li>
                    <li><a href="faq-security.php#q-contribute">Can I contribute?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="faq-future.php">Trust &amp; the future</a> <span>Why trust it, will it stay free, and what is (and isn't) coming.</span></h3>
                <ol>
                    <li><a href="faq-future.php#q-trust">Why should I trust you or your software?</a></li>
                    <li><a href="faq-future.php#q-stay-free">Is SnapSmack going to stay free?</a></li>
                    <li><a href="faq-future.php#q-add-feature">Will you add [feature]?</a></li>
                    <li><a href="faq-future.php#q-video">When is video support coming?</a></li>
                    <li><a href="faq-future.php#q-private-galleries">Will you be adding support for private image galleries?</a></li>
                    <li><a href="faq-future.php#q-watermarking">Will you add image watermarking as a feature?</a></li>
                    <li><a href="faq-future.php#q-whats-next">What's next?</a></li>
                </ol>
            </div>

        </div>
    </section>
</main>

<script>
// Old deep links were brass-tacks.php#q-something. Forward them to the section that holds the question now.
(function () {
    var map = {"q-biggest-best": "faq-why", "q-the-point": "faq-why", "q-where-from": "faq-why", "q-why-now": "faq-why", "q-rude-profane": "faq-why", "q-ai": "faq-why", "q-catch": "faq-why", "q-monetization": "faq-why", "q-reimagining": "faq-why", "q-noah-grey": "faq-why", "q-thomas": "faq-why", "q-vs-pixelpost": "faq-why", "q-classic-ig": "faq-why", "q-how-techie": "faq-running", "q-resources": "faq-running", "q-platforms": "faq-running", "q-mobile": "faq-running", "q-mobile-app": "faq-running", "q-companion-apps": "faq-running", "q-macos": "faq-running", "q-install-modes": "faq-running", "q-skins": "faq-running", "q-content-locked": "faq-content", "q-snapsmack-lock-in": "faq-content", "q-business": "faq-content", "q-business-photoblog": "faq-content", "q-artist": "faq-content", "q-tools-which": "faq-tools", "q-tools-required": "faq-tools", "q-tools-platforms": "faq-tools", "q-tools-originals": "faq-tools", "q-tools-ai": "faq-tools", "q-tools-safe": "faq-tools", "q-tools-download": "faq-tools", "q-fediverse-what": "faq-fediverse", "q-fediverse-ethics": "faq-fediverse", "q-posse": "faq-fediverse", "q-photoblogs-fyi": "faq-fediverse", "q-photofriday": "faq-fediverse", "q-join-photofriday": "faq-fediverse", "q-join-conduct": "faq-fediverse", "q-secure": "faq-security", "q-smackback": "faq-security", "q-why-security": "faq-security", "q-reauth": "faq-security", "q-hacked": "faq-security", "q-contribute": "faq-security", "q-trust": "faq-future", "q-stay-free": "faq-future", "q-add-feature": "faq-future", "q-video": "faq-future", "q-private-galleries": "faq-future", "q-watermarking": "faq-future", "q-whats-next": "faq-future", "general": "faq-why", "fediverse": "faq-fediverse", "security": "faq-security", "future": "faq-future"};
    var h = (location.hash || '').replace('#', '');
    if (h && map[h]) location.replace(map[h] + '.php#' + h);
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
