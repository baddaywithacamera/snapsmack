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
.intro-body { max-width: 820px; padding: 56px 0 8px; }
.intro-body p { margin-bottom: 1.4em; max-width: 72ch; }
.slang {
    background: var(--light-grey);
    border-left: 4px solid var(--black);
    padding: 20px 24px; margin: 8px 0 0; font-size: 0.97rem;
}
.slang p { margin-bottom: 0; }
.slang strong { color: var(--black); }

.section-jump { padding: 32px 0; border-bottom: 3px solid var(--black); }
.section-jump a {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.8rem; font-weight: 900; text-transform: uppercase;
    letter-spacing: 0.06em; color: var(--dark-grey); margin-right: 28px;
}
.section-jump a:hover { color: var(--red); text-decoration: none; }

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

    <nav class="section-jump">
        <div class="wrap">
            <a href="#general">General</a>
            <a href="#security">Security</a>
            <a href="#future">The Future</a>
        </div>
    </nav>

    <!-- ════════════════════════════ GENERAL ════════════════════════════ -->
    <section class="faq-section" id="general">
        <div class="wrap">
            <h2 class="section-title">General</h2>
            <div class="section-rule"></div>

            <div class="qa">
                <h3>Is SnapSmack the biggest, best photoblogging software out there?</h3>
                <p>It's not the size of your army, it's the fury of its onslaught.</p>
            </div>

            <div class="qa">
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

            <div class="qa">
                <h3>Why this, why now?</h3>
                <p>Photoblogging software used to be made by people who loved blogging and loved photography. Greymatter, Movable Type, the original Pixelpost. One person or a small team, building something they actually wanted to use, sharing it because that was the right thing to do.</p>
                <p>That doesn't really exist anymore. The web ate it. Algorithms ate what the web left. The serious photography sites that survive are either platforms harvesting their users' work, or static-site generators that demand you become a developer to publish a photo.</p>
                <p>Ray and I talked about how nice it would be to have good blogging software again, because there was nothing usable left. And now, almost by magic, there is. SnapSmack is what photoblogging software looks like when somebody who loves photography and loves the old web builds it for themselves and shares it because that's still the right thing to do. I just wish that Ray was here to share it with me. That's the part that hurts.</p>
                <p>Slow software. Human curation. No algorithm. No telemetry. No upsell tier. Yours.</p>
            </div>

            <div class="qa">
                <h3>Why so rude and profane?</h3>
                <p>First, I'm a peach. Second, I'm ebullient. Are we good?</p>
            </div>

            <div class="qa">
                <h3>AI? AIIIIIIEEEEE!!!</h3>
                <p>Yes. Almost all of the code in SnapSmack is AI-produced. ETHICS.md in the repo names the AI systems involved and the role each one played. I am not a coder and have never claimed to be.</p>
                <p>My hands are on the keyboard for CSS. I am decent with it. The visual design of the skins is shaped by me at the stylesheet level. Everything else — the architecture, the spec, the security posture, the decisions about what ships — is the curation job.</p>
                <p>The code is AI. Said up front, in the FAQ, in the license, in the repo. I'd rather be honest.</p>
            </div>

            <div class="qa">
                <h3>What's the catch?</h3>
                <p>Why is SnapSmack free? Because nothing else is anymore. In my last year of running my blogs on WordPress, I had to pay for my sticky header plugin. I had to pay for themes. I had to pay for my SEO plugin. I paid for Softaculous' rubbish backup plugin for WP for a year, that never worked once in that year. I paid for an OpenGraph plugin. I paid, I paid, I paid.</p>
                <p>SnapSmack is what I need to stop paying everyone else to be able to share my photography in a way that works for me. I'm a prolific photographer and a power user who can flatten a shared hosting environment in ten seconds flat, so I needed something better and affordable. The arrival of AI and vibe coding let me build bespoke software that suits me. The truth is, if it works for me it will probably work for nearly everyone else because I'm a literal worst-case scenario as photographers who publish their work to the web go.</p>
                <p>This software is a gift from one photographer to others. I know you're all sick of paying the photography tax like I am. SnapSmack is free now, and forever. I have no plans to turn it into a paid product. Further to that, it's open source and under a copyleft license so I can't. Neither can anyone else. They can fork it, build off it, but not charge for it. It's free and staying that way.</p>
                <p>If you want to support me, hit my tip jar, buy gear from my affiliate links, watch a few of my videos which I have monetized — hey, lenses ain't free bro. You can support me, but you don't have to in order to use the product. That's the point.</p>
                <p>The only catch is there is no catch. Word.</p>
            </div>

            <div class="qa">
                <h3>A re-imagining of Pixelpost?</h3>
                <p>Yes. The admin panel says so.</p>
                <p>The honest origin: I dumped the old Pixelpost source into Google Workspace and asked Gemini if it was fixable. Gemini said it would be easier to rebuild from scratch while keeping the functionality. So that's what happened.</p>
                <p>The original Gemini-built rebuild was already better than Pixelpost had been. Then I realised something: I blog much harder now than I did when Pixelpost was current. More sites, more workflows, more files, more reasons to want serious tooling. Pixelpost's one-photo-a-day shape was beautiful and sufficient for what blogging used to be. It is not sufficient for what blogging is now, at least not for me.</p>
                <p>So the rebuild kept growing. Multisite. Companion apps. Security stack. Three install personalities for three different use shapes.</p>
                <p>SnapSmack isn't mission creep. It's mission accomplished.</p>
                <p>See "SnapSmack vs Pixelpost" below for the operational comparison.</p>
            </div>

            <div class="qa">
                <h3>Resources needed?</h3>
                <p>PHP 8.1 or newer. MySQL 5.7 or MariaDB equivalent or newer. Enough disk space for your image archive. Modest RAM — SnapSmack runs comfortably on the cheapest shared-host plans.</p>
                <p>A fresh SnapSmack install is approximately 6MB. With a full skin library loaded it stays under 10MB. The software footprint is negligible — plan your disk around your image archive, not the CMS. A prolific photographer running a busy site for a year can easily hit 15GB of images. That's on you and your hosting plan, not us.</p>
                <p>No Docker. No Node. No build step. No Composer. No package manager. No CI pipeline. Upload, configure, go.</p>
                <p>If your host runs WordPress, it runs SnapSmack. If your host runs WordPress badly, odds are it will still run SnapSmack well.</p>
            </div>

            <div class="qa">
                <h3>Platforms supported?</h3>
                <p><strong>Server.</strong> LAMP. Linux, Apache, MySQL/MariaDB, PHP 8.1 or newer. Nginx with PHP-FPM works in principle and several testers run it. Officially supported once it's been through enough cycles to call it tested. WIMP — Windows, IIS, MySQL, PHP — can go eat a bag of dicks. Not supported. Not going to be. Don't file bug reports. See "Why no Apple support?" for the other platform we don't build for, and why.</p>
                <p><strong>Desktop Companion Apps.</strong> Windows 10 and up. Any recent Linux distribution. That's it. FreeBSD: no. macOS: see "Why no Apple support?" Don't ask.</p>
            </div>

            <div class="qa">
                <h3>Why companion apps instead of plugins?</h3>
                <p>CMS plugins are the biggest scam in open source software. They promise functionality, deliver half of it, break on every update, charge you for the rest, and hold your workflow hostage to a developer who may or may not still care.</p>
                <p>I paid for Softaculous for a year, had to spend $200 in hosting package upgrades to get it to run properly and after that it still never ran properly. Their software is shit and no one should spend a dime on it and I'll swear to that in court. Come at me, you pricks.</p>
                <p>SnapSmack ships with dedicated companion apps instead. Each one does one job well, runs on your machine, touches your files directly, and doesn't need to ask the CMS's permission to do it. No plugin marketplace. No subscription tier. No "pro version" of a feature that should have been free.</p>
                <p>Moving the heavy lifting off the server matters too. Batch importing 15 GB of images, running backups, sorting and organizing your archive — none of that belongs on a shared hosting environment that's already doing its job serving your site. The companion apps handle it on your machine, where it belongs. Better still, large imports can be scheduled for off-peak hours and spread over days or weeks if necessary, so you never upset your hosting provider with a sudden spike. No commercial CMS we're aware of offers this — they all assume chonky hardware and phat bandwidth. We don't.</p>
                <p>There's also a practical reality worth acknowledging: not everyone has reliable home internet. If you're working from a library or a coffee shop, you should be able to organize your content, write posts, and queue up work offline, then sync when you have a connection. The companion apps are built with that in mind. Your photography practice shouldn't depend on your ISP.</p>
                <p>If you've been burned by plugins before: same. That's why these exist.</p>
            </div>

            <div class="qa">
                <h3>Why desktop apps for the companion tools?</h3>
                <p>Some things are better as native applications. File system access without an upload round-trip. System tray daemons that run in the background. Batch operations on thousands of files at native speed. Sync to cloud storage handled by code that lives on your machine, not in a browser tab that has to stay open.</p>
                <p>SnapSmack's web admin stays lean because the heavy lifting moves to the companion apps. Each one does one job well, on your machine, with your files.</p>
                <p>The industry forgot for a while that not everything needs to be a web app. The industry was wrong.</p>
            </div>

            <div class="qa">
                <h3>Why no Apple support?</h3>
                <p>Ray died in March 2024. Prostate cancer that metastasised. He was on disability. He was processing his life's photographic archive and trying to finish the work he wanted to leave behind.</p>
                <p>Partway through that work, Apple obsoleted his Mac. The machine he was using to do the most important job left in his life stopped getting the updates it needed to keep functioning as the tool he needed it to be. He could not afford to replace it. He was dying.</p>
                <p>I sent him a Windows system. He finished what he could.</p>
                <p>SnapSmack does not and will not build for Apple platforms. Not the OS, not the App Store, not iOS, not anything Apple makes money from. Not now. Not ever.</p>
                <p>This is not a technical decision. It will not be revisited.</p>
                <p>Nobody hurts my friends and gets away with it. No Apple support ever.</p>
            </div>

            <div class="qa">
                <h3>Who is Noah Grey?</h3>
                <p>Noah Grey wrote Greymatter in the year 2000. Greymatter was the first widely used personal blogging engine — predating Movable Type, predating WordPress, predating the entire industry that grew up around the idea that anyone could publish on the web.</p>
                <p>Noah also consulted on Picasa, which mattered to a generation of photographers in ways the current state of photo software cannot replicate.</p>
                <p>SnapSmack stands in Greymatter's lineage. Deliberately. The 50 Shades of Noah Grey skin is named for him. The admin uses a Greymatter-derived colour theme. The admin panel of every install carries an attribution. There is a Thomas the Bear Easter egg. There is a clause in the license named for Thomas.</p>
                <p>None of this is fan tribute. It is lineage claim. SnapSmack is what someone who learned to blog on Greymatter and never quite got over how good it was builds when given the means to build it.</p>
                <p>Noah is the senpai. SnapSmack is the kohai's offering.</p>
            </div>

            <div class="qa">
                <h3>What is Thomas the Bear?</h3>
                <p>Thomas is a bear.</p>
                <p>There is an Easter egg in SnapSmack somewhere. It is woven through the whole install — not gated to one corner of it. Go find it. The path from finding it to understanding why it's there is yours to walk. The work of finding out is the point. If you have to be told, you don't yet know enough about the lineage of this software for the answer to mean what it means.</p>
                <p>No peeking at gifts on Christmas eve.</p>
            </div>

            <div class="qa">
                <h3>SnapSmack vs Pixelpost — what's the difference?</h3>
                <p>Pixelpost is a gunship. Light, fast, one job: show one photo per day, well, with comments and a small archive. It was beautiful at it. It is still beautiful at it where installs survive.</p>
                <p>SnapSmack is a dreadnought. Multisite hub-and-spoke architecture. Three install personalities (single photos, Classic IG, or longform essays — pick one at install). Companion desktop apps for backup, sync, sorting, importing. Integrated security stack. Anti-spam layer. Multi-skin engine. Shortcode system.</p>
                <p>If you want one photo a day and nothing else, Pixelpost is the right answer. If you want an entire photoblogging operation with the tools to run it, SnapSmack is the right answer.</p>
                <p>Both are correct. They aim at different photographers.</p>
            </div>

            <div class="qa">
                <h3>Why can't I switch install modes?</h3>
                <p>SnapSmack ships in three install personalities — SMACKONEOUT (single-user photoblog), GRAMOFSMACK (a faithful 2016-Instagram-clone with three-across grids and carousel posts up to ten images deep), and SMACKTALK (essays and pages alongside the photoblog). You pick one when you install. You don't switch later.</p>
                <p>This is deliberate. Each mode has its own database conventions, its own admin behaviours, its own assumptions about what a post is, its own visible feature set. Letting installs toggle between them would mean every feature has to handle three modes plus every transition state between them. That is the road to bloat and to the kind of bugs that don't get found until somebody loses data.</p>
                <p>Pick the install mode that fits the site you're building. If the site changes shape later, install fresh in the new mode and migrate your content. The migration tools exist for exactly this case.</p>
            </div>

            <div class="qa">
                <h3>Classic IG, before Meta wrecked it</h3>
                <p>Greymatter, Pixelpost, Movable Type — those were blogging 1.0. When Instagram arrived it sort of became blogging 2.0, in a manner of speaking. No hosting costs or headaches. No setting up a server or learning CSS. You could just SHARE your images and the audience was there waiting. Yeah, it was boring looking, but the appeal was obvious, so a lot of blogs were abandoned for Instagram. Photogs found hacks like splitting an image across 3, 6, or 9 tiles to punch Insta up visually, and they were happy, even as enshittification crept into the platform.</p>
                <p>Then in 2025 Meta threw photographers under the bus. They yoinked the three-across grid, destroying so many years of careful work by photographers who curated their feeds, in favour of creepy preteen influencer videos. It sucked.</p>
                <p>We can't do anything about the social aspect, but we can help you get the look you loved back. GRAMOFSMACK is the classic-Instagram install — the curated three-across feed, square tiles, cover spreads, and carousel posts up to ten deep, the way it looked when Instagram was still about photographs. Leave it stock and period-correct, or bolt on the modern flourishes — the animated carousel skins, AURORA and PARADE — spinning rims on a Model T, if that's your thing. The Grid is the default skin; on phones it serves Photogram automatically.</p>
            </div>

            <div class="qa">
                <h3>I've got content locked in elsewhere — do I have to abandon it?</h3>
                <p>If you're talking about your Instagram stash or your many years of Flickr posts, no. We've built and already proven tools that take your data and image exports from both Flickr and Instagram and import them into SnapSmack — with all the likes, post counts, captions, and the rest those platforms package into their exports brought across. If you're currently using Flickr or Instagram and you want out, you can get out.</p>
                <p>Oh, and that beautifully curated three-across Instagram feed you had, the one the new scroll broke? It's still there, and we can give it back to you. Really. Go look <a href="https://unzucked.ca" target="_blank" rel="noopener">HERE</a> to see what we mean.</p>
            </div>

            <div class="qa">
                <h3>How do skins work?</h3>
                <p>SnapSmack ships with a skin gallery. You pick one, apply it, configure it. There are skins for traditional photoblog layouts, Instagram-style grids, gallery walls, newspaper-style typography, art-deco black-and-white, and several others. New skins are added over time.</p>
                <p>Architecturally: a skin is a CSS stylesheet, a manifest file, and a layout template. The JavaScript that makes archives, lightboxes, calendars, galleries, sliders, and the rest actually work lives in the CMS itself — not in the skin. Skins declare which engines they want by name. The CMS provides them.</p>
                <p>By default, skins cannot ship their own scripts. The Skin JS Scanner enforces this. If you're building your own skin, JavaScript can be enabled for local development — but a skin with custom JS cannot be shared with others or submitted to the gallery until we've reviewed the code and verified it's safe. This isn't bureaucracy. It's how we keep every SnapSmack install on the network protected.</p>
                <p>If they're helping in the kitchen, they use our knife.</p>
                <p>Want to build your own skin? Developers can work from the skin development guide. If you don't have dev skills but want something custom, OH SNAP is a skin design tool that doesn't require you to write a line of code. Both paths lead to the gallery if the work is good.</p>
                <p>See <a href="buzzers.php">BUZZERS!</a> for why the JS policy matters and what it protects you from.</p>
            </div>
        </div>
    </section>

    <!-- ════════════════════════════ SECURITY ════════════════════════════ -->
    <section class="faq-section" id="security">
        <div class="wrap">
            <h2 class="section-title">Security</h2>
            <div class="section-rule"></div>

            <div class="qa">
                <h3>Is SnapSmack secure?</h3>
                <p>As secure as Claude and I could make it — but perfectly secure? No. Assume there are holes. Practice good file and backup hygiene, rotate passwords and API keys regularly, keep them safe. Anyone who tells you their platform is bulletproof is lying.</p>
                <p>Hobbyist software or not, I have a duty of care to all users of the software to make it as secure as possible — protecting it from breach and tampering, and protecting your data from loss. It's important to me to uphold that duty of care. The receipts are public: see <a href="buzzers.php">BUZZERS!</a> for every audit we've run and closed.</p>
            </div>

            <div class="qa">
                <h3>What is SMACKBACK?</h3>
                <p>SMACKBACK is a file tamper and file system intrusion monitor. It's like an immune system for your CMS. It can trigger a response on your blog to alert you to it being screwed with. If enough of the network of blogs gets screwed with and notifies the main hub, the upstream version of SMACKBACK pushes out a Yellow Alert to all SnapSmack site operators to let them know a coordinated attack is possibly underway. That means backup, change passwords, rotate API keys — the works. We'd rather tell you in real time that you're getting hax0red than give you a lame apology two weeks after your work is destroyed.</p>
            </div>

            <div class="qa">
                <h3>Why does SnapSmack care so much about security?</h3>
                <p>I've been trolled and catfished personally online and it sucked. I'm not dumb and neither was the troll who made my life hell. The answer to that is a troll control system that isn't dumb either. I'm also a senior insurance broker with a solid understanding of cyber liability and how often companies get hacked. Spoiler: anyone can get hacked and probably will. You can slow it down and make it hard enough that the hackers go after softer targets.</p>
            </div>

            <div class="qa">
                <h3>Why does it feel like I have to reauthenticate every time I do something?</h3>
                <p>SnapSmack forces authentication on any action with a large blast radius — pushing from hubs to spokes, turning off security features, using a companion app that can aim a data hose at your shared host, and similar high-consequence operations. These are exactly the actions bad actors go after. We've put extra friction there on purpose. Sorry not sorry.</p>
                <p>Here's what the complaint usually gets wrong: it's not every ten minutes, and it's not everything. Posting, editing, browsing your own library — no gate, ever. The gate's on the short list of actions that can actually torch you. And when you do trip one, a single auth gets you a window to work in, not a nag on every click. The friction's smaller and a hell of a lot more targeted than it feels at 11pm when you just want to push one thing.</p>
                <p>There's an irony worth naming. We're not going to tell you SnapSmack is hackproof — that's BS, and anyone who says it about their platform is lying. We can get hacked. And the day that happens, the same people riding our tits two weeks before about having to type a password and whip out their phone for TOTP every ten minutes will be loudly asking why we didn't make it harder.</p>
                <p>We made it harder. You're welcome.</p>
            </div>

            <div class="qa">
                <h3>What happens if my site gets hacked?</h3>
                <p>See <a href="buzzers.php">BUZZERS!</a> and the security documentation for the full picture. The short version: SMACKBACK has a hair trigger and will notice changes and lock down your site. With paranoid settings enabled you can't do anything except use the provided tools in the interface to replace tampered files with clean, digitally signed versions. Besides, this isn't a big deal because you've been using our excellent backup tools daily, right? RIGHT?</p>
            </div>

            <div class="qa">
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

            <div class="qa">
                <h3>Is SnapSmack going to stay free?</h3>
                <p>You have my word. It's deliberately open source and copyleft specifically to make sure no one — including me — can ever put a price tag on it. It belongs to the photographic community. I'm just the Hindmost.</p>
            </div>

            <div class="qa">
                <h3>Will you add [feature]?</h3>
                <p>Odds are no, but I'm open to ideas. The hard rule is no bloat — SnapSmack is a photo publishing tool first and stays that way. If a feature doesn't serve that, it doesn't ship.</p>
            </div>

            <div class="qa">
                <h3>What's next?</h3>
                <p>What indeed. Honest answer: I don't know. Six months ago SnapSmack didn't exist. I'm not guessing what can happen next.</p>
            </div>

            <p class="updated">Written by Sean McCormick with Claude. The code is AI — said up front, here and in the repo.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
