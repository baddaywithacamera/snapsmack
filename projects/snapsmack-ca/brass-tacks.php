<?php
/**
 * SNAPSMACK.CA — Brass Tacks (FAQ)
 *
 * The SnapSmack FAQ. Deliberately brash, profane, honest. Source of record:
 * _continuity/brass-tacks-v0_6.docx. Keep the voice — substance edits only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'BRASS TACKS! — The SnapSmack FAQ';
$page_description = 'The SnapSmack FAQ. The facts, no fluff — where it came from, what it costs (nothing), and why it is built the way it is.';
$page_og_url      = 'https://snapsmack.ca/brass-tacks.php';
$nav_active       = 'brass-tacks';

$page_css = <<<'CSS'
/* ─── BRASS TACKS — FAQ LAYOUT ────────────────────────────────────────────── */
.page-header { padding-bottom: 32px; }
.intro-body { max-width: 820px; padding: 24px 0 8px; }
.intro-body p { margin-bottom: 1.4em; max-width: 72ch; }
.slang {
    background: var(--light-grey);
    border-left: 4px solid var(--black);
    padding: 20px 24px; margin: 8px 0 0; font-size: 0.97rem;
}
.slang p { margin-bottom: 0; }
.slang strong { color: var(--black); }

.faq-index { padding: 40px 0; border-bottom: 3px solid var(--black); }
.faq-index h2 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--mid-grey); margin-bottom: 22px;
}
.faq-index .idx-group { margin-bottom: 28px; }
.faq-index .idx-group:last-child { margin-bottom: 0; }
.faq-index .idx-group > h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.04em;
    margin-bottom: 12px;
}
.faq-index .idx-group > h3 a { color: var(--red); }
.faq-index .idx-group > h3 a:hover { color: var(--black); text-decoration: none; }
.faq-index ol { list-style: none; columns: 2; column-gap: 48px; }
.faq-index ol li { margin-bottom: 10px; break-inside: avoid; }
.faq-index ol li a {
    font-family: Arial, sans-serif; font-size: 0.92rem; font-weight: 700;
    color: var(--dark-grey); line-height: 1.35;
}
.faq-index ol li a:hover { color: var(--red); text-decoration: none; }
@media (max-width: 700px) { .faq-index ol { columns: 1; } }

.faq-section { padding: 64px 0; border-bottom: 1px solid var(--border); }
.faq-section > .wrap > h2.section-title {
    font-family: Arial Black, Arial, sans-serif;
    font-size: clamp(1.7rem, 3vw, 2.3rem);
    color: var(--black); text-transform: uppercase;
    letter-spacing: -0.01em; margin-bottom: 8px;
}
.faq-section .section-rule { width: 64px; height: 4px; background: var(--red); margin-bottom: 8px; }

.qa { max-width: 820px; padding: 36px 0; border-bottom: 1px solid var(--border); }
.qa:last-child { border-bottom: none; }
.qa h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 1.15rem; color: var(--red); text-transform: none;
    letter-spacing: 0; margin-bottom: 14px; line-height: 1.25;
}
.qa p { margin-bottom: 1.2em; max-width: 72ch; }
.qa p:last-child { margin-bottom: 0; }

.callout a { color: var(--red); font-weight: bold; }
.callout a:hover { color: var(--black); }
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
        </div>
    </section>

    <section class="faq-index">
        <div class="wrap">
            <h2>All Questions</h2>

            <div class="idx-group">
                <h3><a href="#general">General</a></h3>
                <ol>
                    <li><a href="#q-biggest-best">Is SnapSmack the biggest, best photoblogging software out there?</a></li>
                    <li><a href="#q-where-from">Where did this come from?</a></li>
                    <li><a href="#q-why-now">Why this, why now?</a></li>
                    <li><a href="#q-rude-profane">Why so rude and profane?</a></li>
                    <li><a href="#q-ai">AI? AIIIIIIEEEEE!!!</a></li>
                    <li><a href="#q-catch">What's the catch?</a></li>
                    <li><a href="#q-monetization">Where are the monetization options?</a></li>
                    <li><a href="#q-reimagining">A re-imagining of Pixelpost?</a></li>
                    <li><a href="#q-how-techie">How techie do I have to be to run this?</a></li>
                    <li><a href="#q-resources">Resources needed?</a></li>
                    <li><a href="#q-platforms">Platforms supported?</a></li>
                    <li><a href="#q-mobile">Why such limited mobile support?</a></li>
                    <li><a href="#q-mobile-app">Will there be a mobile app for posting?</a></li>
                    <li><a href="#q-companion-apps">Why companion apps instead of plugins?</a></li>
                    <li><a href="#q-no-apple">Why no Apple support?</a></li>
                    <li><a href="#q-noah-grey">Who is Noah Grey?</a></li>
                    <li><a href="#q-thomas">What is Thomas the Bear?</a></li>
                    <li><a href="#q-vs-pixelpost">SnapSmack vs Pixelpost — what's the difference?</a></li>
                    <li><a href="#q-install-modes">Why can't I switch install modes?</a></li>
                    <li><a href="#q-classic-ig">Why are you ripping off Instagram?</a></li>
                    <li><a href="#q-content-locked">I've got content locked in elsewhere — do I have to abandon it?</a></li>
                    <li><a href="#q-skins">How do skins work?</a></li>
                    <li><a href="#q-business">Can I use SnapSmack to run my business website?</a></li>
                    <li><a href="#q-business-photoblog">I'm a business owner — can I use SnapSmack as a photoblog for my work?</a></li>
                    <li><a href="#q-artist">I'm an artist, not a photographer — can I use SnapSmack?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="#fediverse">Fediverse &amp; Discovery</a></h3>
                <ol>
                    <li><a href="#q-fediverse-what">What is the Fediverse — and why do I want it?</a></li>
                    <li><a href="#q-photoblogs-fyi">What is photoblogs.fyi?</a></li>
                    <li><a href="#q-photofriday">What is PHOTOFRI.DAY?</a></li>
                    <li><a href="#q-join-photofriday">How do I join PHOTOFRI.DAY?</a></li>
                    <li><a href="#q-join-conduct">Why the password + 2FA to join, and the rules?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="#security">Security</a></h3>
                <ol>
                    <li><a href="#q-secure">Is SnapSmack secure?</a></li>
                    <li><a href="#q-smackback">What is SMACKBACK?</a></li>
                    <li><a href="#q-why-security">Why does SnapSmack care so much about security?</a></li>
                    <li><a href="#q-reauth">Why does it feel like I have to reauthenticate every time I do something?</a></li>
                    <li><a href="#q-hacked">What happens if my site gets hacked?</a></li>
                    <li><a href="#q-contribute">Can I contribute?</a></li>
                </ol>
            </div>

            <div class="idx-group">
                <h3><a href="#future">The Future</a></h3>
                <ol>
                    <li><a href="#q-stay-free">Is SnapSmack going to stay free?</a></li>
                    <li><a href="#q-add-feature">Will you add [feature]?</a></li>
                    <li><a href="#q-video">When is video support coming?</a></li>
                    <li><a href="#q-private-galleries">Will you be adding support for private image galleries?</a></li>
                    <li><a href="#q-watermarking">Will you add image watermarking as a feature?</a></li>
                    <li><a href="#q-whats-next">What's next?</a></li>
                </ol>
            </div>
        </div>
    </section>

    <!-- ════════════════════════════ GENERAL ════════════════════════════ -->
    <section class="faq-section" id="general">
        <div class="wrap">
            <h2 class="section-title">General</h2>
            <div class="section-rule"></div>

            <div class="qa" id="q-biggest-best">
                <h3>Is SnapSmack the biggest, best photoblogging software out there?</h3>
                <p>It's not the size of your army, it's the fury of its onslaught.</p>
            </div>

            <div class="qa" id="q-where-from">
                <h3>Where did this come from?</h3>
                <p>I've blogged in some form or another since getting my first digital camera in 2001. I searched online for other photographers where I lived and met another photographer who was also new to digital photography and who also had a blog. We went out on one shoot and almost never went out on a second shoot because he was a real asshole. Over the next 23 years he became MY asshole.</p>
                <p>In 2023 he was diagnosed with cancer in an ER visit for back pain. It was cancer that started in his prostate and then went on a world tour. Stage 4, but maybe three or four good years with treatment, they said. By January of 2024 it was already obvious the end was approaching and Ray's world was shrinking. It took all of his energy to crawl from his chair to a window to photograph the skating rink out back of his duplex. I had been on a break from blogging for some time. He remarked to me in passing that he missed when I had a blog, because it would mean a lot to him to be able to see my photos — he was increasingly unable to make his own. I had baddaywithacamera.ca running several days later.</p>
                <p>I lost Ray a few months later, but I've been taking photos for him and sharing them online as I promised ever since. I had to stop doing it at Bad Day, unfortunately. I'm a prolific photographer — I posted enough display sized images in that year at 80% quality to use 18 GB of disk space. My hosting provider made me upgrade my plan three times and what I thought was a minimal suite of plugins regularly beat the absolute shit out of their shared hosting environment. The site kept getting harder to use and I gave up.</p>
                <p>I needed better software, except there is none. WordPress crowded out every single photo blogging product. The remaining blogging CMSes went corporate, chasing publishers and monetization while hobbyist photographers got shoved toward social media. My wife, a teacher who consults on using AI in secondary schooling (plug: jennifermccormick.ca), casually remarked, "it's too bad you can't vibe code it." Pardon me? I asked her to explain what that was. You have to understand when AI first surfaced, my reaction was allergic. Like reach for a six-pack of epi-pen type of allergy. Soil myself by using an AI? NEVAH! Except…</p>
                <p>Ray would give me shit for having opinions about things I have no experience with (I'm really good at it). I figured I would give AI and this vibe coding thing a try and then I could tell my wife she was wrong, something that rarely happens (spoiler: she's smarter than me).</p>
                <p>Well.</p>
                <p>I started out by feeding Gemini the old source code from my favourite deceased photoblogging product, Pixelpost, and asking if it could be updated? No, Gemini said. Too far gone. But it offered to build a new product with identical functionality. It did and it was working by the end of the week. By the end of the second week I had been adding features necessary to my workflow and my own sense of what is required for secure design and Gemini was overwhelmed. It recommended trying Claude AI.</p>
                <p>Well, again.</p>
                <p>Claude is amazing. I'm still not happy with how AI was trained, but AI has also given me a suite of bespoke tools doing everything I need and then some for pennies on the dollar. More importantly, the thing that I thought would ruin photography for everyone gave mine a big old shot of 'roids in the booty. I was wrong and I'm okay with it. The software you're considering installing right now would not exist without Claude, who was so helpful you'll notice the co-author credit. I'm not someone who is generous with praise (I'm an asshole like Ray was), so believe me when I say that credit is earned.</p>
                <p>I hope you like the product and find it useful.</p>
            </div>

            <div class="qa" id="q-why-now">
                <h3>Why this, why now?</h3>
                <p>Photoblogging software used to be made by people who loved blogging and loved photography. Greymatter, Movable Type, the original Pixelpost. One person or a small team, building something they actually wanted to use, sharing it because that was the right thing to do.</p>
                <p>That doesn't really exist anymore. The web ate it. Algorithms ate what the web left. The serious photography sites that survive are either platforms harvesting their users' work, or static-site generators that demand you become a developer to publish a photo.</p>
                <p>Ray and I talked about how nice it would be to have good blogging software again, because there was nothing usable left. And now, almost by magic, there is. SnapSmack is what photoblogging software looks like when somebody who loves photography and loves the old web builds it for themselves and shares it because that's still the right thing to do. I just wish that Ray was here to share it with me. That's the part that hurts.</p>
            </div>

            <div class="qa" id="q-rude-profane">
                <h3>Why so rude and profane?</h3>
                <p>First, I'm a peach. Second, I'm ebullient. Are we good?</p>
            </div>

            <div class="qa" id="q-ai">
                <h3>AI? AIIIIIIEEEEE!!!</h3>
                <p>Yes. Almost all of the code in SnapSmack is AI-produced. ETHICS.md in the repo names the AI systems involved and the role each one played. I am not a coder and have never claimed to be.</p>
                <p>My hands are on the keyboard for CSS. I am decent with it. The visual design of the skins is shaped by me at the stylesheet level. Everything else — the architecture, the spec, the security posture, the decisions about what ships — is the curation job.</p>
                <p>The code is AI. Said up front, in the FAQ, in the license, in the repo. I'd rather be honest.</p>
            </div>

            <div class="qa" id="q-catch">
                <h3>What's the catch?</h3>
                <p>Why is SnapSmack free? Because nothing else is anymore. In my last year of running my blogs on WordPress, I had to pay for my sticky header plugin. I had to pay for themes. I had to pay for my SEO plugin. I paid for Softaculous' rubbish backup plugin for WP for a year, that never worked once in that year. I paid for an OpenGraph plugin. I paid, I paid, I paid.</p>
                <p>SnapSmack is what I need to stop paying everyone else to be able to share my photography in a way that works for me. I'm a prolific photographer and a power user who can flatten a shared hosting environment in ten seconds flat, so I needed something better and affordable. The arrival of AI and vibe coding let me build bespoke software that suits me. The truth is, if it works for me it will probably work for nearly everyone else because I'm a literal worst-case scenario as photographers who publish their work to the web go.</p>
                <p>This software is a gift from one photographer to others. I know you're all sick of paying the photography tax like I am. SnapSmack is free now, and forever. I have no plans to turn it into a paid product. Further to that, it's open source and under a copyleft license so I can't. Neither can anyone else. They can fork it, build off it, but not charge for it. It's free and staying that way.</p>
                <p>If you want to support me, hit my tip jar, buy gear from my affiliate links, watch a few of my videos which I have monetized — hey, lenses ain't free bro. You can support me, but you don't have to in order to use the product. That's the point.</p>
                <p>The only catch is there is no catch. Word to your mother.</p>
            </div>

            <div class="qa" id="q-monetization">
                <h3>Where are the monetization options? Your demos don't show you running AdSense.</h3>
                <p>There aren't any and there never will be. SNAPSMACK was created by one hobbyist photographer for other hobbyist photographers. If you run your blog as a business and monetize it, you're not a hobbyist and this software is not for you. Go swim in the Wordpress cesspool with the rest of your ilk.</p>
            </div>

            <div class="qa" id="q-reimagining">
                <h3>A re-imagining of Pixelpost?</h3>
                <p>Yes. The admin panel says so.</p>
                <p>The honest origin: I dumped the old Pixelpost source into Google Workspace and asked Gemini if it was fixable. Gemini said it would be easier to rebuild from scratch while keeping the functionality. So that's what happened.</p>
                <p>The original Gemini-built rebuild was already better than Pixelpost had been. Then I realised something: I blog much harder now than I did when Pixelpost was current. More sites, more workflows, more files, more reasons to want serious tooling. Pixelpost's one-photo-a-day shape was beautiful and sufficient for what blogging used to be. It is not sufficient for what blogging is now, at least not for me.</p>
                <p>So the rebuild kept growing. Multisite. Companion apps. Security stack. Three install personalities for three different use shapes.</p>
                <p>SnapSmack isn't mission creep. It's mission accomplished.</p>
                <p>See "SnapSmack vs Pixelpost" below for the operational comparison.</p>
            </div>

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
                <p><strong>Server.</strong> LAMP. Linux, Apache, MySQL/MariaDB, PHP 8.1 or newer. Nginx with PHP-FPM works in principle and several testers run it. Officially supported once it's been through enough cycles to call it tested. WIMP — Windows, IIS, MySQL, PHP — can go eat a bag of dicks. Not supported. Not going to be. Don't file bug reports. See "Why no Apple support?" for the other platform we don't build for, and why.</p>
                <p><strong>Desktop Companion Apps.</strong> Windows 10 and up. Any recent Linux distribution. That's it. FreeBSD: no. macOS: see "Why no Apple support?" Don't ask.</p>
            </div>

            <div class="qa" id="q-mobile">
                <h3>Why such limited mobile support?</h3>
                <p>SnapSmack is circa 2001&ndash;2005 throwback software. There were no smartphones, people looked at images on large, chonky displays, and life was perfect. To those who say it is 2026 now, you're right, so we do offer LIMITED mobile support instead of telling mobile users to FOAD, but there are limits. Also, we've seen the ugly 70s fashions in your closet so you don't get to lecture us about missing an older era.</p>
                <p>We support tablets with larger screens just fine. For phones you get exactly one mobile skin &mdash; PHOTOGRAM &mdash; and that's the whole of it; everything else assumes a proper display. SnapSmack is built to be <em>seen</em>, on a screen big enough to do the photography justice. It's too big to fit in your girly pocket.</p>
            </div>

            <div class="qa" id="q-mobile-app">
                <h3>Will there be a mobile app for posting?</h3>
                <p>No &mdash; at least not one from us. SnapSmack is throwback software to a time before smartphones; people posted from a computer back in the day (think 2001&ndash;2005). Today you post from a computer, or a large-screen tablet in desktop mode. A phone posting app was never in the vision &mdash; this project has a specific scope and we're keeping it. If someone else wants to build one, good on them; we won't block it.</p>
            </div>

            <div class="qa" id="q-companion-apps">
                <h3>Why companion apps instead of plugins?</h3>
                <p>CMS plugins are the biggest scam in open source software. They promise functionality, deliver half of it, break on every update, charge you for the rest, and hold your workflow hostage to a developer who may or may not still care.</p>
                <p>I paid for Softaculous for a year, had to spend $200 in hosting package upgrades to get it to run properly and after that it still never ran properly. Their software is shit and no one should spend a dime on it and I'll swear to that in court. Come at me, you pricks.</p>
                <p>SnapSmack ships with dedicated companion apps instead. Each one does one job well, runs on your machine, touches your files directly, and just gets the job done. No plugin marketplace. No subscription tier. No "pro version" of a feature that should have been free. And many of them now pack optional AI assist — suggested captions and hashtags — to take the sting out of posting large batches of images.</p>
                <p>Moving the heavy lifting off the server matters too. Batch importing 15 GB of images, running backups, sorting and organizing your archive — none of that belongs on a shared hosting environment that's already doing its job serving your site. The companion apps handle it on your machine, where it belongs. Better still, large imports can be scheduled for off-peak hours and spread over days or weeks if necessary, so you never upset your hosting provider with a sudden spike. No commercial CMS we're aware of offers this — they all assume chonky hardware and phat bandwidth. We don't.</p>
                <p>There's also a practical reality worth acknowledging: not everyone has reliable home internet. If you're working from a library or a coffee shop, you should be able to organize your content, write posts, and queue up work offline, then sync when you have a connection. The companion apps are built with that in mind. Your photography practice shouldn't depend on your ISP.</p>
                <p>And they're native desktop apps, not browser tabs, on purpose. Direct filesystem access with no upload round-trip. Background tray daemons that keep working while you don't. Batch operations on thousands of files at native speed. Cloud sync run by code that lives on your machine, not a tab you have to leave open. The industry forgot for a while that not everything needs to be a web app. The industry was wrong.</p>
                <p>If you've been burned by plugins before: same. That's why these exist.</p>
            </div>

            <div class="qa" id="q-no-apple">
                <h3>Why no Apple support?</h3>
                <p>Ray died in March 2024. Prostate cancer that metastasised. He was on disability. He was processing his life's photographic archive and trying to finish the work he wanted to leave behind.</p>
                <p>Partway through that work, Apple obsoleted his Mac. The machine he was using to do the most important job left in his life stopped getting the updates it needed to keep functioning as the tool he needed it to be. He could not afford to replace it. He was dying.</p>
                <p>I sent him a Windows system. He finished what he could.</p>
                <p>SnapSmack does not and will not build for Apple platforms. Not the OS, not the App Store, not iOS, not anything Apple makes money from. Not now. Not ever.</p>
                <p>This is not a technical decision. It will not be revisited.</p>
                <p>Nobody hurts my friends and gets away with it. No Apple support ever.</p>
            </div>

            <div class="qa" id="q-noah-grey">
                <h3>Who is Noah Grey?</h3>
                <p>Noah Grey wrote Greymatter in the year 2000. Greymatter was the first widely used personal blogging engine — predating Movable Type, predating WordPress, predating the entire industry that grew up around the idea that anyone could publish on the web.</p>
                <p>Noah also consulted on Picasa, which mattered to a generation of photographers in ways the current state of photo software cannot replicate.</p>
                <p>SnapSmack stands in Greymatter's lineage. Deliberately. The 50 Shades of Noah Grey skin is named for him. The admin uses a Greymatter-derived colour theme. The admin panel of every install carries an attribution. There is a Thomas the Bear Easter egg. There is a clause in the license named for Thomas.</p>
                <p>None of this is fan tribute. It is lineage claim. SnapSmack is what someone who learned to blog on Greymatter and never quite got over how good it was builds when given the means to build it.</p>
                <p>Noah is the senpai. SnapSmack is the kohai's offering.</p>
            </div>

            <div class="qa" id="q-thomas">
                <h3>What is Thomas the Bear?</h3>
                <p>Thomas is a bear.</p>
                <p>There is an Easter egg in SnapSmack somewhere. It is woven through the whole install — not gated to one corner of it. Go find it. The path from finding it to understanding why it's there is yours to walk. The work of finding out is the point. If you have to be told, you don't yet know enough about the lineage of this software for the answer to mean what it means.</p>
                <p>No peeking at gifts on Christmas eve.</p>
            </div>

            <div class="qa" id="q-vs-pixelpost">
                <h3>SnapSmack vs Pixelpost — what's the difference?</h3>
                <p>Pixelpost was a gunship. Light, fast, one job: show one photo a day, well, with comments and a small archive. It was beautiful at it. Past tense, though — the last real release was 2009, the project was officially abandoned and archived in 2019, and what survives runs on ancient PHP with unpatched cross-site-scripting and SQL-injection holes. A lovely ghost, but not something you should hang on the public internet in 2026.</p>
                <p>SnapSmack is a dreadnought. Multisite hub-and-spoke architecture. Three install personalities (single photos, Classic IG, or longform essays — pick one at install). Companion desktop apps for backup, sync, sorting, importing. Integrated security stack. Anti-spam layer. Multi-skin engine. Shortcode system.</p>
                <p>If you want one photo a day and nothing else, that stripped-down minimalism was Pixelpost's whole soul — and it's exactly what SnapSmack's SMACKONEOUT mode gives you, minus the decade of rot. We didn't build SnapSmack to compete with Pixelpost. We built it to carry on after it, because nobody else did.</p>
                <p>Pixelpost showed what a photoblog should feel like, then quietly died. SnapSmack is the heir, not the rival — as far as we can tell, the only dedicated, still-actively-built photoblog CMS left standing. Know of another living one? Point us at it. We'd like to know.</p>
            </div>

            <div class="qa" id="q-install-modes">
                <h3>Why can't I switch install modes?</h3>
                <p>SnapSmack ships in three install personalities — SMACKONEOUT (single-user photoblog), GRAMOFSMACK (a faithful 2016-Instagram-clone with three-across grids and carousel posts up to ten images deep), and SMACKTALK (essays and pages alongside the photoblog). You pick one when you install. You don't switch later.</p>
                <p>This is deliberate. Each mode has its own database conventions, its own admin behaviours, its own assumptions about what a post is, its own visible feature set. Letting installs toggle between them would mean every feature has to handle three modes plus every transition state between them. That is the road to bloat and to the kind of bugs that don't get found until somebody loses data.</p>
                <p>Pick the install mode that fits the site you're building. If the site changes shape later, you install fresh in the new mode and bring your content across. A dedicated mode-migration tool is on the build list — until it ships, that move is a manual one, and we're not going to pretend otherwise.</p>
            </div>

            <div class="qa" id="q-classic-ig">
                <h3>Why are you ripping off Instagram?</h3>
                <p>We're not ripping them off, we're taking back what's ours. Greymatter, Pixelpost, Movable Type — those were blogging 1.0. When Instagram arrived it sort of became blogging 2.0, in a manner of speaking. No hosting costs or headaches. No setting up a server or learning CSS. You could just SHARE your images and the audience was there waiting. Yeah, it was boring looking, but the appeal was obvious, so a lot of blogs were abandoned for Instagram. Photogs found hacks like splitting an image across 3, 6, or 9 tiles to punch Insta up visually, and they were happy, even as enshittification crept into the platform.</p>
                <p>Then in 2025 Meta threw photographers under the bus. They yoinked the three-across grid, destroying so many years of careful work by photographers who curated their feeds, in favour of creepy preteen influencer videos. It sucked.</p>
                <p>And these days we CAN do something about the social aspect, too &mdash; SMACKVERSE. Now you host your own images and retain control while feeding them into the Fediverse, a more ethical alternative to Insta. As for the look you loved, that's the easy part. GRAMOFSMACK is the classic-Instagram install — the curated three-across feed, square tiles, cover spreads, and carousel posts up to ten deep, the way it looked when Instagram was still about photographs. Leave it stock and period-correct, or bolt on the modern flourishes — the animated carousel skins, AURORA and PARADE — spinning rims on a Model T, if that's your thing. The Grid is the default skin; on phones it serves Photogram automatically.</p>
            </div>

            <div class="qa" id="q-content-locked">
                <h3>I've got content locked in elsewhere — do I have to abandon it?</h3>
                <p>If you're talking about your Instagram stash or your many years of Flickr posts, no. We've built and already proven tools that take your data and image exports from both Flickr and Instagram and import them into SnapSmack — with all the likes, post counts, captions, and the rest those platforms package into their exports brought across. If you're currently using Flickr or Instagram and you want out, you can get out.</p>
                <p>Oh, and that beautifully curated three-across Instagram feed you had, the one the new scroll broke? It's still there, and we can give it back to you. Really. Go look <a href="https://unzucked.ca" target="_blank" rel="noopener">HERE</a> to see what we mean.</p>
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
        </div>
    </section>

    <!-- ============================ FEDIVERSE & DISCOVERY ============================ -->
    <section class="faq-section" id="fediverse">
        <div class="wrap">
            <h2 class="section-title">Fediverse &amp; Discovery</h2>
            <div class="section-rule"></div>

            <div class="qa" id="q-fediverse-what">
                <h3>What is the Fediverse — and why do I want it in my photo blog?</h3>
                <p>The Fediverse — short for "federated universe" — is a pile of independent social servers that all speak the same language, a protocol called <strong>ActivityPub</strong>, so they can talk to each other. Think email: you're on one provider, your mate's on another, and your messages still land. Same idea, but for social posts — no single company owning it, no algorithm deciding who gets seen, and nobody who can sell the whole place out from under you.</p>
                <p>The two corners you'll hear about most: <strong>Mastodon</strong> is the Twitter-shaped one — short posts, timelines, replies — spread across thousands of independent servers instead of one company's. <strong>Pixelfed</strong> is the Instagram-shaped one — a photo-first grid with likes, comments and carousels — same federation, no Meta. SnapSmack speaks the exact protocol they do, so your photo blog shows up over there as a first-class, Pixelfed-compatible neighbour: people on Mastodon or Pixelfed can find you, follow you, like and comment on your work, and boost it to their own followers, with the traffic flowing back to your site.</p>
                <p>New to all this and not sure where to start? <a href="https://fediverse.info" target="_blank" rel="noopener">fediverse.info</a> is the friendly front door — it explains the whole thing in plain language and, more to the point, helps you <em>find real people to follow</em> so you don't land in an empty feed. (That "find real people" idea is exactly what we're building <a href="#q-photoblogs-fyi">photoblogs.fyi</a> around, scoped to photographers.)</p>
                <p>And here's the part most "social" quietly skips: with SnapSmack you get all of that without handing over a single thing. Your photos, your captions, your comments, your DMs, your data — they stay on <strong>your</strong> server, under your domain. SnapSmack federates by pointing the world at your work, not by uploading it to some stranger's box to host, mine and monetize. You're a peer on the network, not a tenant in someone's silo. Flip it on, flip it off — either way it's yours.</p>
                <p>Why you need it: it's simply how people find your work now. A blog nobody can stumble onto is a lonely blog. Federation is the reach of social — without becoming the product.</p>
            </div>

            <div class="qa" id="q-photoblogs-fyi">
                <h3>What is photoblogs.fyi?</h3>
                <p>The Achilles heel of the Fediverse is discoverability — and for a one-person server, so is plain isolation. Everyone knows the discovery problem, nobody's fixing it, and some people have the gall to sell that as a feature. If we're being honest, the Fediverse could almost be called the Inbrediverse. We can't fix everything, but we know where to start.</p>
                <p>Running your own SnapSmack instance is a cabin off-grid in the woods: private, yours, nobody can evict you — but on its own, isolated. On a big shared server like pixelfed.social you're a house in a neighbourhood — you glance out the window and see what everyone nearby is shooting, because the server hands you a local timeline for free. A solo instance has no neighbourhood. That's the hidden cost of real independence, and it's exactly why a single-user server doesn't thrive out here without something behind it.</p>
                <p>photoblogs.fyi is that something — a relay hub for SnapSmack blogs, the local server your cabin never had. <strong>Federate Your Instance</strong> and you get a neighbourhood: you see what your photographer neighbours are posting, and your posts fan out to theirs, the same way a shared instance's local timeline would if you'd rented a room in one. It also plays diplomat — helping your little community talk to the rest of the Fediverse, so your work carries past the tree line. And it's a directory on top of all that, with a domain that's pure SEO catnip, so your listing gets found by humans and search engines both.</p>
                <p>Your images never leave your own server — the relay carries the signal, not the pictures. It's opt-in and gated on purpose; the how and why are in "Why do I need a password and 2FA to join" below.</p>
            </div>

            <div class="qa" id="q-photofriday">
                <h3>What is PHOTOFRI.DAY?</h3>
                <p>Want to connect with other photographers across the Fediverse? Join the PHOTOFRI.DAY challenge. One theme a week, no entry limit — PHOTOFRI.DAY surfaces your five most recent. Photographers from across the Fediverse enter. They'll see you. You'll see them. You'll find more friends together and hilarity will ensue. Best of all, people will stop calling you Jethro behind your back.</p>
                <p>Discoverability across server instances. And it's fun. You're welcome.</p>
            </div>

            <div class="qa" id="q-join-photofriday">
                <h3>How do I join PHOTOFRI.DAY?</h3>
                <p>Follow @participate@photofri.day. It will follow you back.</p>
                <p>Every Friday morning a new prompt appears as an image post in your global feed — or find it any time by looking at our profile. The post carries the hashtag for that week. Put that hashtag in your posts and our server boosts and reposts your image previews across the Fediverse, sending all traffic back to your profile on your own server instance.</p>
                <p>Want to leave? Unfollow. You're out.</p>
                <p>That's it.</p>
            </div>

            <div class="qa" id="q-join-conduct">
                <h3>Why do I need a password and 2FA just to join — and do I have to behave myself?</h3>
                <p>Both photoblogs.fyi and PHOTOFRI.DAY are opt-in, and turning either on takes a deliberate step-up: your password and a TOTP code. That's friction on purpose. You're not just flipping a setting — you're joining a community, and we want you to stop for a second and mean it before you opt in.</p>
                <p>Here's why we make you pause. The Fediverse keeps itself clean by defederation — instances simply stop talking to servers that harbour bad actors. So if you show up to spam, harass, or generally act the goat, the damage isn't only yours to wear: other instances can decide our whole relay is trouble and cut it off entirely. That doesn't kick out just you — it kicks out every photographer sharing the relay, people who did nothing but stand next to you. Your bad behaviour can get your neighbours defederated off servers they were perfectly welcome on. The password and 2FA aren't bureaucracy; they're a speed bump to make sure you're joining on purpose, eyes open, knowing you're now part of something bigger than your own blog.</p>
                <p>So behave. These are photographers' spaces — show up with your work, be decent, and don't be a spammer or a jackass. Abuse it and you're gone, and we'll cut you loose fast, because keeping everyone else's connection to the wider Fediverse intact matters a hell of a lot more than protecting yours. Common decency is the house rule. Simple as that.</p>
            </div>
        </div>
    </section>

    <!-- ════════════════════════════ SECURITY ════════════════════════════ -->
    <section class="faq-section" id="security">
        <div class="wrap">
            <h2 class="section-title">Security</h2>
            <div class="section-rule"></div>

            <div class="qa" id="q-secure">
                <h3>Is SnapSmack secure?</h3>
                <p>As secure as Claude and I could make it — but perfectly secure? No. Assume there are holes. Practice good file and backup hygiene, rotate passwords and API keys regularly, keep them safe. Anyone who tells you their platform is bulletproof is lying.</p>
                <p>Hobbyist software or not, I have a duty of care to all users of the software to make it as secure as possible — protecting it from breach and tampering, and protecting your data from loss. It's important to me to uphold that duty of care. The receipts are public: see <a href="buzzers.php">BUZZERS!</a> for every audit we've run and closed.</p>
            </div>

            <div class="qa" id="q-smackback">
                <h3>What is SMACKBACK?</h3>
                <p>SMACKBACK is a file tamper and file system intrusion monitor. It's like an immune system for your CMS. It can trigger a response on your blog to alert you to it being screwed with. If enough of the network of blogs gets screwed with and notifies the main hub, the upstream version of SMACKBACK pushes out a Yellow Alert to all SnapSmack site operators to let them know a coordinated attack is possibly underway. That means backup, change passwords, rotate API keys — the works. We'd rather tell you in real time that you're getting hax0red than give you a lame apology two weeks after your work is destroyed.</p>
            </div>

            <div class="qa" id="q-why-security">
                <h3>Why does SnapSmack care so much about security?</h3>
                <p>I've been trolled and catfished personally online and it sucked. I'm not dumb and neither was the troll who made my life hell. The answer to that is a troll control system that isn't dumb either. I'm also a senior insurance broker with a solid understanding of cyber liability and how often companies get hacked. Spoiler: anyone can get hacked and probably will. You can slow it down and make it hard enough that the hackers go after softer targets.</p>
            </div>

            <div class="qa" id="q-reauth">
                <h3>Why does it feel like I have to reauthenticate every time I do something?</h3>
                <p>SnapSmack forces authentication on any action with a large blast radius — pushing from hubs to spokes, turning off security features, using a companion app that can aim a data hose at your shared host, and similar high-consequence operations. These are exactly the actions bad actors go after. We've put extra friction there on purpose. Sorry not sorry.</p>
                <p>Here's what the complaint usually gets wrong: it's not every ten minutes, and it's not everything. Posting, editing, browsing your own library — no gate, ever. The gate's on the short list of actions that can actually torch you. And when you do trip one, a single auth gets you a window to work in, not a nag on every click. The friction's smaller and a hell of a lot more targeted than it feels at 11pm when you just want to push one thing.</p>
                <p>There's an irony worth naming. We're not going to tell you SnapSmack is hackproof — that's BS, and anyone who says it about their platform is lying. We can get hacked. And the day that happens, the same people riding our tits two weeks before about having to type a password and whip out their phone for TOTP every ten minutes will be loudly asking why we didn't make it harder.</p>
                <p>We made it harder. You're welcome.</p>
            </div>

            <div class="qa" id="q-hacked">
                <h3>What happens if my site gets hacked?</h3>
                <p>See <a href="buzzers.php">BUZZERS!</a> and the security documentation for the full picture. The short version: SMACKBACK has a hair trigger and will notice changes and lock down your site. With paranoid settings enabled you can't do anything except use the provided tools in the interface to replace tampered files with clean, digitally signed versions. Besides, this isn't a big deal because you've been using our excellent backup tools daily, right? RIGHT?</p>
            </div>

            <div class="qa" id="q-contribute">
                <h3>Can I contribute?</h3>
                <p>To the codebase, no. We don't need our own version of the XZ Utils backdoor — one of the most sophisticated supply chain attacks in open source history was a social engineering job that took years of patient groundwork. We're not leaving that door open.</p>
                <p>What you can contribute: bug reports, skin submissions through the gallery process, and feedback. All of it is welcome.</p>
                <p>The codebase is publicly available in our repository for inspection at any time. Claude performs ongoing security audits of the codebase — high and medium risk items are fixed immediately, low risk items are addressed on a schedule. If you find something we missed, tell us.</p>
            </div>
        </div>
    </section>

    <!-- ════════════════════════════ THE FUTURE ════════════════════════════ -->
    <section class="faq-section" id="future">
        <div class="wrap">
            <h2 class="section-title">The Future</h2>
            <div class="section-rule"></div>

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

            <p class="updated">Written by Sean McCormick with Claude. The code is AI — said up front, here and in the repo.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
