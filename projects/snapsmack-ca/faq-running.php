<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Running it (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Running it - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, running it: How techie you need to be, what it needs, what it runs on, and why the phone is second fiddle.';
$page_og_url      = 'https://snapsmack.ca/faq-running.php';
$faq_slug    = 'faq-running';
$faq_section = 'Running it';
$faq_lede    = 'How techie you need to be, what it needs, what it runs on, and why the phone is second fiddle.';
$faq_qas = <<<'HTML'
            <div class="qa" id="q-how-techie">
                <h3>How techie do I have to be to run this?</h3>
                <p>Honestly? Not very.</p>
                <p>If you've ever installed WordPress yourself — not watched someone do it, actually done it — you're overqualified. The installer is browser-based and walks you through everything. If you can fill out a form, you can get SnapSmack running.</p>
                <p>Making it look the way you want doesn't require touching a line of code either. Every skin ships with sliders for spacing, font pickers, and colour pickers. You click until it looks right. That's the whole job.</p>
                <p>If you want to get your hands dirty with CSS, the door is open. But nobody's going to make you.</p>
                <p>We're also currently producing video tutorials that walk you through the whole process start to finish, for those who'd rather watch someone do it first.</p>
            </div>

            <div class="qa" id="q-resources">
                <h3>Resources needed?</h3>
                <p>PHP 8.1 or newer. MySQL 5.7 or MariaDB equivalent or newer. Enough disk space for your image archive. Modest RAM — SnapSmack runs comfortably on the cheapest shared-host plans.</p>
                <p>A fresh SnapSmack install is approximately 6MB. With a full skin library loaded it stays under 10MB. The software footprint is negligible — plan your disk around your image archive, not the CMS. A prolific photographer running a busy site for a year can easily hit 15GB of images. That's on you and your hosting plan, not us.</p>
                <p>No Docker. No Node. No build step. No Composer. No package manager. No CI pipeline. Upload, configure, go.</p>
                <p>If your host runs WordPress, it runs SnapSmack. If your host runs WordPress badly, odds are it will still run SnapSmack well.</p>
            </div>

            <div class="qa" id="q-platforms">
                <h3>Platforms supported?</h3>
                <p><strong>Server.</strong> LAMP. Linux, Apache, MySQL/MariaDB, PHP 8.1 or newer. Nginx with PHP-FPM works in principle and several testers run it. Officially supported once it's been through enough cycles to call it tested. WIMP — Windows, IIS, MySQL, PHP — can go eat a bag of dicks. Not supported. Not going to be. Don't file bug reports. See "Does SnapSmack run on macOS?" for the other platform we don't build for, and why.</p>
                <p><strong>Desktop Companion Apps.</strong> Windows 10 and up. Any recent Linux distribution. The Linux builds have also been found to work on macOS, entirely by accident, but macOS remains unsupported. See "Does SnapSmack run on macOS?" before getting excited.</p>
            </div>

            <div class="qa" id="q-mobile">
                <h3>Why such limited mobile support?</h3>
                <p>SnapSmack is circa 2001&ndash;2005 throwback software. There were no smartphones, people looked at images on large, chonky displays, and life was perfect. To those who say it is 2026 now, you're right, so we do offer LIMITED mobile support instead of telling mobile users to FOAD, but there are limits. Also, we've seen the ugly 70s fashions in your closet so you don't get to lecture us about missing an older era.</p>
                <p>We support tablets with larger screens just fine. For phones you get exactly one mobile skin &mdash; PHOTOGRAM &mdash; and that's the whole of it; everything else assumes a proper display. SnapSmack is built to be <em>seen</em>, on a screen big enough to do the photography justice. It's too big to fit in your girly pocket.</p>
            </div>

            <div class="qa" id="q-mobile-app">
                <h3>Will there be a mobile app for posting?</h3>
                <p>Eventually, yes. A Progressive Web App (PWA) is in the works for open beta.</p>
            </div>

            <div class="qa" id="q-companion-apps">
                <h3>Why companion apps instead of plugins?</h3>
                <p>The desktop tools started with one backup app. It worked so well that I built another for batch posting, then realized I preferred working that way altogether. They're faster, easier, and let my own computer do the heavy lifting.</p>
                <p>That matters on shared hosting. Ask a modest web server to process backups, chew through large batches of photographs, and run every other demanding job, and the whole site starts to chug. Even worse, you may crash the server and find yourself pushed onto a more expensive hosting plan. Moving that work offline reduces the friction of blogging for me and leaves the CMS free to do what it does best: serve photographs, handle comments, and keep the site secure.</p>
                <p>So I'm moving as much of the workflow to desktop tools as I can. Not because desktop apps are fashionable&mdash;they absolutely are not&mdash;but because they make blogging faster, easier, and less dependent on how much horsepower your hosting company has decided to give you.</p>
            </div>

            <div class="qa" id="q-macos">
                <h3>Does SnapSmack run on macOS?</h3>
                <p>No. SnapSmack's desktop tools are built for Windows and Linux only. Windows and Linux are free to develop for. Apple's platform isn't: it charges developers to build, to renew each year, and to ship each release &mdash; for software we give away for free. We can't justify paying rent at every gate to hand you a free tool. And no, we won't take donations to change that. This is free and staying free.</p>
                <p>The CMS itself runs fine in any browser, so Mac users can absolutely use SnapSmack sites. It's only the desktop suite that's Windows/Linux. The main missing feature is backups &mdash; those are desktop-only because online backups shred shared hosts. There's just no way around that.</p>
                <p>If you're an Apple developer and you want the desktop tools on Mac, the repo is <a href="https://github.com/baddaywithacamera/snapsmack">right here</a>. Help yourself. Just remember: if you port them, you own the support for them, not us. We can't test or fix what we can't run.</p>
            </div>

            <div class="qa" id="q-install-modes">
                <h3>Why can't I switch install modes?</h3>
                <p>SnapSmack ships in three install personalities — SMACKONEOUT (single-user photoblog), GRAMOFSMACK (a faithful 2016-Instagram-clone with three-across grids and carousel posts up to ten images deep), and SMACKTALK (essays and pages alongside the photoblog). You pick one when you install. You don't switch later.</p>
                <p>This is deliberate. Each mode has its own database conventions, its own admin behaviours, its own assumptions about what a post is, its own visible feature set. Letting installs toggle between them would mean every feature has to handle three modes plus every transition state between them. That is the road to bloat and to the kind of bugs that don't get found until somebody loses data.</p>
                <p>Pick the install mode that fits the site you're building. If the site changes shape later, install fresh in the new mode and move what maps cleanly by hand. There will not be an automatic mode switcher: a ten-image GRAMOFSMACK carousel has no honest translation into a one-image SMACKONEOUT post, and turning it into SMACKTALK would require the software to invent editorial structure or discard your work. We're not going to pretend otherwise.</p>
            </div>

            <div class="qa" id="q-skins">
                <h3>How do skins work?</h3>
                <p>SnapSmack ships with a skin gallery. You pick one, apply it, configure it. There are skins for traditional photoblog layouts, Instagram-style grids, gallery walls, newspaper-style typography, art-deco black-and-white, and several others. New skins are added over time.</p>
                <p>Architecturally: a skin is a CSS stylesheet, a manifest file, and a layout template. The JavaScript that makes archives, lightboxes, calendars, galleries, sliders, and the rest actually work lives in the CMS itself — not in the skin. Skins declare which engines they want by name. The CMS provides them.</p>
                <p>By default, skins cannot ship their own scripts. The Skin JS Scanner enforces this. If you're building your own skin, JavaScript can be enabled for local development — but a skin with custom JS cannot be shared with others or submitted to the gallery until we've reviewed the code and verified it's safe. This isn't bureaucracy. It's how we keep every SnapSmack install on the network protected.</p>
                <p>If they're helping in the kitchen, they use our knife.</p>
                <p>Want to build your own skin? Developers can work from the skin development guide. If you don't have dev skills but want something custom, OH SNAP is a skin design tool that doesn't require you to write a line of code. Both paths lead to the gallery if the work is good.</p>
                <p>See <a href="buzzers.php">BUZZERS!</a> for why the JS policy matters and what it protects you from.</p>
            </div>

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
