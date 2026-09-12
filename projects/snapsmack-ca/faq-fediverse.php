<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Fediverse & discovery (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Fediverse & discovery - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, fediverse & discovery: The network, the directory, the weekly challenge, and why you need a password to join.';
$page_og_url      = 'https://snapsmack.ca/faq-fediverse.php';
$faq_slug    = 'faq-fediverse';
$faq_section = 'Fediverse &amp; discovery';
$faq_lede    = 'The network, the directory, the weekly challenge, and why you need a password to join.';
$faq_qas = <<<'HTML'
            <div class="qa" id="q-fediverse-what">
                <h3>What is the Fediverse — and why do I want it in my photo blog?</h3>
                <p>The Fediverse — short for "federated universe" — is a pile of independent social servers that all speak the same language, a protocol called <strong>ActivityPub</strong>, so they can talk to each other. Think email: you're on one provider, your mate's on another, and your messages still land. Same idea, but for social posts — no single company owning it, no algorithm deciding who gets seen, and nobody who can sell the whole place out from under you.</p>
                <p>The two corners you'll hear about most: <strong>Mastodon</strong> is the Twitter-shaped one — short posts, timelines, replies — spread across thousands of independent servers instead of one company's. <strong>Pixelfed</strong> is the Instagram-shaped one — a photo-first grid with likes, comments and carousels — same federation, no Meta. SnapSmack speaks the exact protocol they do, so your photo blog shows up over there as a first-class, Pixelfed-compatible neighbour: people on Mastodon or Pixelfed can find you, follow you, like and comment on your work, and boost it to their own followers, with the traffic flowing back to your site.</p>
                <p>New to all this and not sure where to start? <a href="https://fediverse.info" target="_blank" rel="noopener">fediverse.info</a> is the friendly front door — it explains the whole thing in plain language and, more to the point, helps you <em>find real people to follow</em> so you don't land in an empty feed. (That "find real people" idea is exactly what we're building <a href="#q-photoblogs-fyi">photoblogs.fyi</a> around, scoped to photographers.)</p>
                <p>And here's the part most "social" quietly skips: with SnapSmack you get all of that without handing over a single thing. Your photos, your captions, your comments, your DMs, your data — they stay on <strong>your</strong> server, under your domain. SnapSmack federates by pointing the world at your work, not by uploading it to some stranger's box to host, mine and monetize. You're a peer on the network, not a tenant in someone's silo. Flip it on, flip it off — either way it's yours.</p>
                <p>Why you need it: it's simply how people find your work now. A blog nobody can stumble onto is a lonely blog. Federation is the reach of social — without becoming the product.</p>
            </div>

            <div class="qa" id="q-fediverse-ethics">
                <h3>Why did you build SnapSmack on the Fediverse?</h3>
                <p>Because we believe social software should serve the people using it, not capture them. The Fediverse gives photographers control over their identities, work, relationships, and choice of platform.</p>
                <p>SnapSmack is for photographers who want their own domain and their own place on the web while still participating in a larger community. Your blog remains the source of your work. Follows and interactions use established Fediverse standards, credit and traffic point back to the original posts, and people remain free to use whichever compatible software suits them.</p>
                <p>SnapSmack is not trying to replace Pixelfed, Mastodon, Vernissage, or the rest of the Fediverse. It is another door into the same network, built for a particular kind of independent photography blog. We want to strengthen photography culture across the Fediverse, not trap it inside one platform.</p>
            </div>

            <div class="qa" id="q-posse">
                <h3>What is POSSE?</h3>
                <p>POSSE stands for <strong>Publish on your Own Site, Syndicate Elsewhere</strong> — or, blunter and closer to how we mean it, <strong>Put On Server, Syndicate Elsewhere</strong>. Your photographs live on a server you control. Copies go out to the networks where the people are. The original never leaves home.</p>
                <p>And it runs both ways. The likes, replies, and boosts your syndicated copies pick up out there don't stay stranded on someone else's server — they feed back to the post they belong to, on your site. Syndicate out, gather the conversation back. Your work and its audience end up in the same place: yours. (POSSE is the <em>publishing</em> principle; <a href="#q-fediverse-what">ActivityPub</a> is one of the roads your copies travel.)</p>
            </div>

            <div class="qa" id="q-photoblogs-fyi">
                <h3>What is photoblogs.fyi?</h3>
                <p>The Fediverse still has real discoverability challenges, especially for a one-person server. Smart people across the community are working on them, but they are not fully resolved yet. We cannot fix everything, but we know where SnapSmack can help.</p>
                <p>Running your own SnapSmack instance is a cabin off-grid in the woods: private, yours, nobody can evict you — but on its own, isolated. On a big shared server like pixelfed.social you're a house in a neighbourhood — you glance out the window and see what everyone nearby is shooting, because the server hands you a local timeline for free. A solo instance has no neighbourhood. That's the hidden cost of real independence, and it's exactly why a single-user server doesn't thrive out here without something behind it.</p>
                <p>photoblogs.fyi is that something — a relay hub for SnapSmack blogs, the local server your cabin never had. <strong>Federate Your Instance</strong> and you get a neighbourhood: you see what your photographer neighbours are posting, and your posts fan out to theirs, the same way a shared instance's local timeline would if you'd rented a room in one. It also plays diplomat — helping your little community talk to the rest of the Fediverse, so your work carries past the tree line. And it's a directory on top of all that, with a domain that's pure SEO catnip, so your listing gets found by humans and search engines both.</p>
                <p>Your images never leave your own server — the relay carries the signal, not the pictures. It's opt-in and gated on purpose; the how and why are in "Why do I need a password and 2FA to join" below.</p>
            </div>

            <div class="qa" id="q-photofriday">
                <h3>What is PHOTOFRI.DAY?</h3>
                <p>Want to connect with other photographers across the Fediverse? Join the PHOTOFRI.DAY challenge. One theme a week, with up to five posts a week. Photographers from across the Fediverse enter. They'll see you. You'll see them. You'll find more friends together and hilarity will ensue. Best of all, people will stop calling you Jethro behind your back.</p>
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

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
