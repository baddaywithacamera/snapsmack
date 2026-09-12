<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Trust & the future (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Trust & the future - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, trust & the future: Why trust it, will it stay free, and what is (and isn\'t) coming.';
$page_og_url      = 'https://snapsmack.ca/faq-future.php';
$faq_slug    = 'faq-future';
$faq_section = 'Trust &amp; the future';
$faq_lede    = 'Why trust it, will it stay free, and what is (and isn\'t) coming.';
$faq_qas = <<<'HTML'
            <div class="qa" id="q-trust">
                <h3>Why should I trust you or your software?</h3>
                <p>You should not. Assume the software will fail. Assume I will fail, because at some point we both will. I've been on the Net since 1992. Nothing that I use now is stuff I used then; it's all gone. I give my word I will do my best while I can, but the day will arrive when I can't.</p>
                <p>To that end there is a succession plan (already chosen and coming up to speed, to be named when the time is right) and also the fact that the code for SnapSmack &mdash; and that would be EVERY LINE OF CODE &mdash; is on GitHub. Anyone who really wants to carry it forward can, with or without me. You don't even need to be a good coder. I mean, I'm not and you're here, right? AI can help the next person like they helped me.</p>
                <p>In the meantime, there are fantastic backup and export tools that ship inside every install &mdash; not scattered across some download site to go stale &mdash; and they work, but that is only my word for it. Test your own backups anyhow, don't trust my word. Distrust is the sanest choice. Someone has to say it.</p>
            </div>

            <div class="qa" id="q-stay-free">
                <h3>Is SnapSmack going to stay free?</h3>
                <p>You have my word. It's deliberately open source and copyleft specifically to make sure no one — including me — can ever put a price tag on it. It belongs to the photographic community. I'm just the Hindmost.</p>
            </div>

            <div class="qa" id="q-add-feature">
                <h3>Will you add [feature]?</h3>
                <p>Odds are no, but I'm open to ideas. The hard rule is no bloat — SnapSmack is a photo publishing tool first and stays that way. If a feature doesn't serve that, it doesn't ship.</p>
            </div>

            <div class="qa" id="q-video">
                <h3>When is video support coming?</h3>
                <p>Never. SnapSmack is photo blogging software. Videos are not photos. We're not trying to be everything to everyone &mdash; we have a specific focus, which is photography (see what I did there???), and we're staying firmly in that lane. Besides, every time a photo product bolts on video it goes straight into the crapper. <em>*cough*</em> Instagram <em>*cough*</em></p>
            </div>

            <div class="qa" id="q-private-galleries">
                <h3>Will you be adding support for private image galleries?</h3>
                <p>No. SnapSmack is a photo blogging platform. Blogging is, by its very nature, public sharing of content. Adding private galleries is mission creep away from the software's intended purpose. The other issue is that private content online quite often gets exposed by accident or by malicious intent, creating legal liability issues for the creator of the software that hosted them. I do not wish to have this kind of headache and refuse to go there for this other reason. There's lots of good, free software already for maintaining private galleries. We suggest using that instead.</p>
            </div>

            <div class="qa" id="q-watermarking">
                <h3>Will you add image watermarking as a feature?</h3>
                <p>No. Watermarking is mostly superfluous these days and is easily negated by powerful and ubiquitous AI tools. If you really want watermarks on your images you should add them in your post production.</p>
            </div>

            <div class="qa" id="q-whats-next">
                <h3>What's next?</h3>
                <p>What indeed. Honest answer: I don't know. Six months ago SnapSmack didn't exist. I'm not guessing what can happen next.</p>
            </div>

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
