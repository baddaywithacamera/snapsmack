<?php
/**
 * SNAPSMACK.CA - BRASS TACKS! / Security (FAQ section)
 * Question wording + #q-* anchors verbatim from the original single-page FAQ.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BRASS TACKS! - Security - SnapSmack FAQ';
$page_description = 'SnapSmack FAQ, security: Is it secure, what SMACKBACK is, why you keep re-authenticating, and what happens if you get hacked.';
$page_og_url      = 'https://snapsmack.ca/faq-security.php';
$faq_slug    = 'faq-security';
$faq_section = 'Security';
$faq_lede    = 'Is it secure, what SMACKBACK is, why you keep re-authenticating, and what happens if you get hacked.';
$faq_qas = <<<'HTML'
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

HTML;
require_once __DIR__ . '/includes/faq-page.php';
// ===== SNAPSMACK EOF =====
