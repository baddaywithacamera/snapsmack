<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Desktop tools (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * DRAFT 2026-09-12: this is the NEW desktop-tools section. Sean to read for voice.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Desktop tools - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, desktop tools: The free Windows and Linux suite: which tool does what, where your files go, and what it never does.';
$page_og_url      = 'https://snapsmack.ca/faq-tools.php';
$faq_slug    = 'faq-tools';
$faq_section = 'Desktop tools';
$faq_lede    = 'The free Windows and Linux suite: which tool does what, where your files go, and what it never does.';
$faq_qas = <<<'HTML'
            <div class="qa" id="q-tools-which">
                <h3>There are nine desktop tools. Which one do I actually need?</h3>
                <p>Depends what you're doing. <strong>Editing a photograph:</strong> SNAP SLAPPER. <strong>Writing a post with no internet:</strong> COLD SNAP. <strong>Publishing a whole shoot:</strong> SMACK YOUR BATCH UP. <strong>Fixing a messy archive:</strong> GET YOUR SHIT SORTED. <strong>Leaving Instagram / Flickr / WordPress:</strong> THE UNZUCKER / FLKR FCKR / SMACKPRESS. <strong>Backing up:</strong> SMACK UP YOUR BACKUP. <strong>Running more than one site:</strong> SNAP HQ. Start with SNAP HQ &mdash; it launches the rest and holds your site details so you only type them once.</p>
            </div>

            <div class="qa" id="q-tools-required">
                <h3>Do I have to use them?</h3>
                <p>No. The website does everything a photoblog needs on its own. The tools exist because some jobs &mdash; editing eighty RAW files, importing fifteen years of Flickr, backing up twenty sites &mdash; are miserable through a browser and cruel to a shared host. They move that work onto your own computer. If you post one photograph a week from your phone, you may never open one.</p>
            </div>

            <div class="qa" id="q-tools-platforms">
                <h3>Windows only?</h3>
                <p>Windows and Linux. macOS is not supported and isn't planned &mdash; the reasons are in the <a href="faq-running.php#q-macos">Running it</a> section, and they're not going to change. SNAP HQ is currently Windows only.</p>
            </div>

            <div class="qa" id="q-tools-originals">
                <h3>Do the tools upload my originals anywhere? Copy them somewhere I didn't ask for?</h3>
                <p>No, and this is a rule, not a setting. Every tool treats its local store as a <em>cache of your site</em> &mdash; never a second copy of your originals. Nothing shadow-copies your RAW files into some tool folder. You archive your own work; the tools work beside it. SNAP SLAPPER reads photographs where they live and never modifies the original file. Exports are new files. If a tool ever quietly duplicated your archive, that would be a bug, and a serious one.</p>
            </div>

            <div class="qa" id="q-tools-ai">
                <h3>The AI enrichment &mdash; whose key, whose data?</h3>
                <p>Your key, your bill, your data relationship. You pick the provider (Claude, ChatGPT, Gemini, Kimi, Deepseek), you paste your own API key into SNAP HQ once, and the tools use it. The photograph and your site's prompt go to the provider you chose; nothing goes through us. Every tool that writes for a site reads the same per-site prompt, so a caption from GYSS sounds like a caption from SMACK YOUR BATCH UP sounds like you. And it's optional: leave the key blank and the tools simply don't offer it.</p>
            </div>

            <div class="qa" id="q-tools-safe">
                <h3>Are they safe to run against my live site?</h3>
                <p>They're built to be, and the failures we found are documented. Site credentials live in an encrypted vault, not a text file. Anything destructive &mdash; delete, recover-over-the-top, push-to-site &mdash; is a step-up action: password and 2FA, every time. The suite guards hardest against the one failure that would really hurt: pushing to the <em>wrong</em> site. That bug existed once, in GYSS. It was found, fixed, and tested, and it's written up on <a href="ding-dong-bell.php">DING DONG BELL</a> because you're entitled to know.</p>
            </div>

            <div class="qa" id="q-tools-download">
                <h3>Where do I download them?</h3>
                <p>Through SNAP HQ, which also keeps them updated. SNAP HQ itself comes with the closed beta &mdash; <a href="index.php#beta">apply</a> and it's in the welcome pack. Releases are signed; if a download doesn't verify, don't run it, and <a href="bugger.php">tell us</a>.</p>
            </div>

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
