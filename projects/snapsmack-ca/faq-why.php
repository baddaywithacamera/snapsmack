<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Why SnapSmack (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Why SnapSmack - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, why snapsmack: Why it exists, where it came from, why it swears, and what the catch is.';
$page_og_url      = 'https://snapsmack.ca/faq-why.php';
$faq_slug    = 'faq-why';
$faq_section = 'Why SnapSmack';
$faq_lede    = 'Why it exists, where it came from, why it swears, and what the catch is.';
$faq_qas = <<<'HTML'
            <div class="qa" id="q-biggest-best">
                <h3>Is SnapSmack the biggest, best photoblogging software out there?</h3>
                <p>Nothing lasts, nothing is finished, and nothing is perfect. We hope to provide the serenity you need to find the authenticity and beauty in your art you deserve.</p>
            </div>

            <div class="qa" id="q-the-point">
                <h3>What's the point of SnapSmack?</h3>
                <p>To take as much load off the photographer as possible, so sharing your work stays about the work. Running a blog should be a thrill for you, not kill you.</p>
            </div>

            <div class="qa" id="q-where-from">
                <h3>Where did this come from?</h3>
                <p>I've blogged in some form or another since getting my first digital camera in 2001. I searched online for other photographers where I lived and met another photographer who was also new to digital photography and who also had a blog. We went out on one shoot and almost never went out on a second shoot because he was a real asshole. Over the next 23 years he became MY asshole.</p>
                <p>In 2023 he was diagnosed with cancer in an ER visit for back pain. It was cancer that started in his prostate and then went on a world tour. Stage 4, but maybe three or four good years with treatment, they said. By January of 2024 it was already obvious the end was approaching and Ray's world was shrinking. It took all of his energy to crawl from his chair to a window to photograph the skating rink out back of his duplex. I had been on a break from blogging for some time. He remarked to me in passing that he missed when I had a blog, because it would mean a lot to him to be able to see my photos — he was increasingly unable to make his own. I had baddaywithacamera.ca running several days later.</p>
                <p>I lost Ray a few months later, but I've been taking photos for him and sharing them online as I promised ever since. I had to stop doing it at Bad Day, unfortunately. I'm a prolific photographer — I posted enough display sized images in that year at 80% quality to use 18 GB of disk space. My hosting provider made me upgrade my plan three times and what I thought was a minimal suite of plugins regularly beat the absolute shit out of their shared hosting environment. The site kept getting harder to use and I gave up.</p>
                <p>I needed better software, except there is none. WordPress crowded out every single photo blogging product. The remaining blogging CMSes went corporate, chasing publishers and monetization while hobbyist photographers got shoved toward social media. My wife, a teacher who consults on using AI in secondary schooling (plug: jennifermccormick.ca), casually remarked, "it's too bad you can't vibe code it." Pardon me? I asked her to explain what that was. You have to understand when AI first surfaced, my reaction was allergic. Like reach for a six-pack of epi-pen type of allergy. Soil myself by using an AI? NEVAH! Except…</p>
                <p>Ray would give me shit for having opinions about things I have no experience with (I'm really good at it). I figured I would give AI and this vibe coding thing a try and then I could tell my wife she was wrong, something that rarely happens (spoiler: she's smarter than me).</p>
                <p>Well.</p>
                <p>I started out by asking Gemini whether my favourite deceased photoblogging product, Pixelpost, could be brought back for the modern web. No, Gemini said. Too far gone. So SnapSmack was developed independently as a new product with the functionality I missed. It was working by the end of the week. By the end of the second week I had been adding features necessary to my workflow and my own sense of what is required for secure design and Gemini was overwhelmed. It recommended trying Claude AI.</p>
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
                <p>The code is AI. Said up front, in the FAQ, in the licence, in the repo. I'd rather be honest.</p>
            </div>

            <div class="qa" id="q-catch">
                <h3>What's the catch?</h3>
                <p>Why is SnapSmack free? Because nothing else is anymore. In my last year of running my blogs on WordPress, I had to pay for my sticky header plugin. I had to pay for themes. I had to pay for my SEO plugin. I paid for Softaculous' rubbish backup plugin for WP for a year, that never worked once in that year. I paid for an OpenGraph plugin. I paid, I paid, I paid.</p>
                <p>SnapSmack is what I need to stop paying everyone else to be able to share my photography in a way that works for me. I'm a prolific photographer and a power user who can flatten a shared hosting environment in ten seconds flat, so I needed something better and affordable. The arrival of AI and vibe coding let me build bespoke software that suits me. The truth is, if it works for me it will probably work for nearly everyone else because I'm a literal worst-case scenario as photographers who publish their work to the web go.</p>
                <p>This software is a gift from one photographer to others. I know you're all sick of paying the photography tax like I am. SnapSmack is free now, and forever. I have no plans to turn it into a paid product. Further to that, it's open source and under a copyleft licence so I can't. Neither can anyone else. They can fork it, build off it, but not charge for it. It's free and staying that way.</p>
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
                <p>The honest origin: I asked Gemini whether Pixelpost could be modernized. The answer was no. So I started over. SnapSmack was built independently from scratch. It doesn't use any Pixelpost code &mdash; nothing was copied, adapted, or carried over. Pixelpost inspired how SnapSmack should feel, but not how it was built. That also means SnapSmack has its own licence; it doesn't inherit Pixelpost's.</p>
                <p>The original Gemini-built implementation was already better suited to the modern web than Pixelpost had been. Then I realised something: I blog much harder now than I did when Pixelpost was current. More sites, more workflows, more files, more reasons to want serious tooling. Pixelpost's one-photo-a-day shape was beautiful and sufficient for what blogging used to be. It is not sufficient for what blogging is now, at least not for me.</p>
                <p>So the new product kept growing. Multisite. Companion apps. Security stack. Four install personalities for four different use shapes.</p>
                <p>SnapSmack isn't mission creep. It's mission accomplished.</p>
                <p>See "SnapSmack vs Pixelpost" below for the operational comparison.</p>
            </div>

            <div class="qa" id="q-noah-grey">
                <h3>Who is Noah Grey?</h3>
                <p>Noah Grey wrote Greymatter in the year 2000. Greymatter was the first widely used personal blogging engine — predating Movable Type, predating WordPress, predating the entire industry that grew up around the idea that anyone could publish on the web.</p>
                <p>Noah also consulted on Picasa, which mattered to a generation of photographers in ways the current state of photo software cannot replicate.</p>
                <p>SnapSmack stands in Greymatter's lineage. Deliberately. The 50 Shades of Noah Grey skin is named for him. The admin uses a Greymatter-derived colour theme. The admin panel of every install carries an attribution. There is a Thomas the Bear Easter egg. There is a clause in the licence named for Thomas.</p>
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
                <p>SnapSmack is a dreadnought. Multisite hub-and-spoke architecture. Four install personalities (single photos, Classic IG, longform essays, or Picasa-style albums — pick one at install). Companion desktop apps for backup, sync, sorting, importing. Integrated security stack. Anti-spam layer. Multi-skin engine. Shortcode system.</p>
                <p>If you want one photo a day and nothing else, that stripped-down minimalism was Pixelpost's whole soul — and it's exactly what SnapSmack's SMACKONEOUT mode gives you, minus the decade of rot. We didn't build SnapSmack to compete with Pixelpost. We built it to carry on after it, because nobody else did.</p>
                <p>Pixelpost showed what a photoblog should feel like, then quietly died. SnapSmack is the heir, not the rival — as far as we can tell, the only dedicated, still-actively-built photoblog CMS left standing. Know of another living one? Point us at it. We'd like to know.</p>
            </div>

            <div class="qa" id="q-classic-ig">
                <h3>Why are you ripping off Instagram?</h3>
                <p>We're not ripping them off, we're taking back what's ours. Greymatter, Pixelpost, Movable Type — those were blogging 1.0. When Instagram arrived it sort of became blogging 2.0, in a manner of speaking. No hosting costs or headaches. No setting up a server or learning CSS. You could just SHARE your images and the audience was there waiting. Yeah, it was boring looking, but the appeal was obvious, so a lot of blogs were abandoned for Instagram. Photogs found hacks like splitting an image across 3, 6, or 9 tiles to punch Insta up visually, and they were happy, even as enshittification crept into the platform.</p>
                <p>Then in 2025 Meta threw photographers under the bus. They yoinked the three-across grid, destroying so many years of careful work by photographers who curated their feeds, in favour of creepy preteen influencer videos. It sucked.</p>
                <p>And these days we CAN do something about the social aspect, too. Now you host your own images and retain control while participating in the Fediverse, a more ethical alternative to Insta. As for the look you loved, that's the easy part. GRAMOFSMACK is the classic-Instagram install — the curated three-across feed, square tiles, cover spreads, and carousel posts up to ten deep, the way it looked when Instagram was still about photographs. Leave it stock and period-correct with The Grid, put it under AURORA's night sky, or fly one of PARADE's twelve LGBT+ identity flags behind it. On phones it serves PHOTOGRAM automatically.</p>
            </div>

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
