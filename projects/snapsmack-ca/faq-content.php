<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Your content (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Your content - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, your content: Lock-in (none), what you can bring, and who this is for.';
$page_og_url      = 'https://snapsmack.ca/faq-content.php';
$faq_slug    = 'faq-content';
$faq_section = 'Your content';
$faq_lede    = 'Lock-in (none), what you can bring, and who this is for.';
$faq_qas = <<<'HTML'
            <div class="qa" id="q-content-locked">
                <h3>I've got content locked in elsewhere — do I have to abandon it?</h3>
                <p>If you're talking about your Instagram stash or your many years of Flickr posts, no. We've built and already proven tools that take your data and image exports from both Flickr and Instagram and import them into SnapSmack — with all the likes, post counts, captions, and the rest those platforms package into their exports brought across. If you're currently using Flickr or Instagram and you want out, you can get out.</p>
                <p>Oh, and that beautifully curated three-across Instagram feed you had, the one the new scroll broke? It's still there, and we can give it back to you. Really. Go look <a href="https://unzucked.ca" target="_blank" rel="noopener">HERE</a> to see what we mean.</p>
            </div>

            <div class="qa" id="q-snapsmack-lock-in">
                <h3>Does SnapSmack lock in my content?</h3>
                <p>No. Your photographs and writing remain on your server. SnapSmack backups preserve the complete site for recovery, and we are building a standard exit package into every backup: your media, your readable metadata, and import material for moving on. A WordPress export already exists in our export tool today; folding it into every backup — and adding Ghost — is what we're building now. For the Fediverse the export carries a portable copy of your follow graph: no one can lift an account's identity or signing keys off a server, and we say so plainly instead of pretending otherwise.</p>
                <p>That basic exit cannot depend on SnapSmack's continued existence, our servers, an AI service, or any person involved in the project. Where another platform cannot preserve something faithfully, the export will say so plainly.</p>
                <p>For destinations beyond those formats — or when WordPress or Ghost refuses to cooperate — <strong>TAKE YOUR SHIT WITH YOU</strong> is the companion tool for the exit itself. Optional AI-assisted conversion and troubleshooting — adapting your content to another CMS, diagnosing failed imports, cutting the manual cleanup — is planned for it, not built yet. We'll say so the day it's real.</p>
                <p>That AI assistance, when it lands, will make leaving easier; it will never be required to leave. Making departure as painless as we reasonably can is simply good manners.</p>
                <p>There's a saying: &ldquo;if you love someone, set them free. If they come back they're yours; if they don't, they never were.&rdquo; I (Sean) love everyone who has ever picked up a camera and felt alive because of it. You're my tribe. You are not required to love me back, and I will never try to hold your data hostage. You're as free as I can make you. Just remember this: if we break up, I'm not crying; you are.</p>
            </div>

            <div class="qa" id="q-business">
                <h3>Can I use SnapSmack to run my business website?</h3>
                <p>Probably, but you're not our target audience and you'll hear crickets in the support forum if you ask for help. This is free software from an unpaid volunteer who is not your support department. Neither are the other photographers using SnapSmack.</p>
            </div>

            <div class="qa" id="q-business-photoblog">
                <h3>I'm a business owner, but I want to have a real photoblog to show my work to my customers. Is that okay?</h3>
                <p>Hells, yes. You're sharing images that tell a story you're proud of. If you're a hair stylist with pics of hair styles. If you're a tattoo artist with photos of amazeballs work you're doing. If you're showing off custom rods you've built, dishes from your restaurant, pets you've groomed, yards you've cleaned, pottery you have made, etc. There are people who want to see it and they want to see it in style. SNAPSMACK helps you with that.</p>
                <p>Just please remember, we are not a commercial product and there is no commercial support. We'll try to have your back, but it happens when it happens. Keep that in mind before basing a business critical function on this software, please. Other than that, go for it.</p>
            </div>

            <div class="qa" id="q-artist">
                <h3>I'm an artist, not a photographer. Can I use SnapSmack to share my paintings, drawings, or other non-photographic work?</h3>
                <p>Yes, but you're not our target audience and we can't realistically support you — this is a small volunteer project. More importantly, you can't participate in our integrated web portals, photoblogs.fyi and PHOTOFRI.DAY. Those are photography communities and showing up with paintings is off-topic and unfair to the photographers who use them.</p>
                <p>Here's the thing though: we get it. You have the same problems we had before we built this. So we're building DAPHNE — in honour of Anishinaabe painter Daphne Odjig (1919–2016) — a fork of SnapSmack rebuilt for visual artists, with the photography assumptions stripped out and the terminology generalized. For artists, by artists. It's not ready yet, but it's coming. Watch the repo.</p>
                <p>In the meantime, everything is on GitHub under the Smack Public License. Fork it yourself if you're the right person to run it.</p>
            </div>

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
