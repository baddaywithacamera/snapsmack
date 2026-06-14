<?php
/**
 * SNAPSMACK.CA — HAIRY MUFF — Ethics, AI, and the Smack Public License
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'HAIRY MUFF — SnapSmack Ethics, AI, and the Smack Public License';
$page_description = 'How SnapSmack thinks about AI, code, training data, and why everything we build stays free forever. Fair\'s fair, innit?';
$page_og_url      = 'https://snapsmack.ca/hairy-muff.php';
$nav_active       = 'hairy-muff';
$page_css = <<<'PAGECSS'
───────────────────────────────────────────────────────── */
.page-header {
    padding: 80px 0 64px;
    border-bottom: 1px solid var(--border);
}

.page-header h1 { margin-bottom: 12px; }

/* ─── CONTENT ───────────────────────────────────────────────────────────── */
.page-body {
    max-width: 820px;
    padding: 64px 0 96px;
}

.page-body ul {
    margin: 0 0 1.4em 1.5em;
}

.page-body ul li {
    margin-bottom: 0.6em;
}

.callout {
    background: var(--light-grey);
    border-left: 4px solid var(--red);
    padding: 20px 24px;
    margin: 32px 0;
    font-size: 0.97rem;
}

.callout p { margin-bottom: 0.8em; }
.callout p:last-child { margin-bottom: 0; }

.updated {
    font-family: Arial, sans-serif;
    font-size: 0.8rem;
    color: var(--mid-grey);
    letter-spacing: 0.04em;
    margin-top: 56px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
}
PAGECSS;
include __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>HAIRY <span>MUFF!</span></h1>
            <p class="lede">Fair enough, innit? Here's where SnapSmack stands on AI, training data, the code commons, and why this software stays free forever — whether you like it or not.</p>
        </div>
    </div>

    <div class="wrap">
        <div class="page-body">

            <h2>The situation, plainly</h2>
            <p>SnapSmack is co-authored by a human and an AI. The human is Sean McCormick. The AI is Claude, made by Anthropic. You can see both names in the license file. We are not shy about this.</p>
            <p>This project is a Human/AI collaboration — architecture decisions, security audits, code review, the works. That's honest. What is also honest is that it raises a question worth addressing directly: AI models are trained on data scraped from the internet. Some of that data is code. Some of that code belongs to people who never consented to have it used as training material. The provenance of what went into making Claude is, frankly, murky. That is not Claude's fault. But it's a real thing.</p>
            <p>Our answer to that is simple: if the raw material might have come from the commons, then what we make with it goes back to the commons. Permanently. No exceptions.</p>

            <h2>Fruit of the poisoned tree</h2>
            <p>There is a legal concept called "fruit of the poisoned tree." Evidence obtained through illegal means taints everything derived from it. We are not making a legal argument here — we are making an ethical one. If the training data that shaped the AI tools we used included copyrighted code that was scraped without permission, then the code that AI helped us write carries that uncertainty with it.</p>
            <p>We cannot resolve that uncertainty. Nobody can, right now. What we can do is treat the output as if it were already in the commons — because the most honest thing to do with something of uncertain provenance is to make sure it can never be enclosed again.</p>
            <p>That is why SnapSmack is not just free. It is copyleft. Derivatives must stay free too. The code stays open, full stop. No one gets to take this and build a closed product on top of it.</p>

            <div class="callout">
                <p><strong>Hairy muff</strong> is Cockney rhyming slang for "fair enough." If you're reading this and thinking "yeah, that seems reasonable, actually" — that's the spirit of it. We're not trying to be preachy. We're just trying to be square.</p>
                <p>If you clicked on this link looking for hairy muffs, sorry to disappoint. PornHub is over there ==&gt;</p>
            </div>

            <h2>The Smack Public License (SPL)</h2>
            <p>SnapSmack is released under the Smack Public License — a copyleft license that means:</p>
            <ul>
                <li>SnapSmack is free to use, modify, and redistribute.</li>
                <li>If you build something on top of SnapSmack and distribute it, your version must also be free and open-source under the same terms. You cannot take this codebase, change the logo, and sell it as a subscription SaaS. That path is closed.</li>
                <li>The full license text is in the repository. It includes an Ethical Provenance Summary that lays out the AI co-authorship and explains why we chose copyleft. It's actually readable, which is unusual for a license document.</li>
            </ul>
            <p>We did not go with a standard open-source license like GPL or MIT because we wanted to say the quiet part loud — the AI provenance thing, the "vibe coded" disclosure, the co-author credit for Claude. Standard licenses don't have fields for that. So we wrote our own.</p>
            <p>The SPL is not anti-commercial in the sense of begrudging you a paid hosting bill. Run it on whatever server you like. What you cannot do is take the software itself and make it a closed, proprietary product. The code stays open. Everything else is up to you.</p>
            <p>Hosting providers are welcome to include a SnapSmack installer in their cPanel or hosting control panel once SnapSmack reaches public release (planned for 2027). We appreciate the support. The same terms apply — the software stays free and open for whoever installs it.</p>

            <h2>The full meal deal</h2>
            <p>Plenty of open-source projects open the core and keep the good bits back. The reference engine is free; the polished themes are a paid add-on. The CMS is GPL, but the deploy tooling and the import scripts and the admin niceties stay in a private repo. You can have the motor — the bodywork, the trim, and the keys cost extra. That is a normal and respectable way to run an open-source project. It is not how we run this one.</p>
            <p>Everything SnapSmack is, is in the open. Not just the core CMS — <em>every</em> skin we have built, including the ones that don't ship in the default install and the ones we keep on a tight leash internally for our own reasons. And every desktop app built so far: the Instagram archive importer, the backup tool, the batch poster, the skin designer, the lot. If we wrote it for SnapSmack, it is in the repository under the same copyleft license as the core. There is no inner circle, no pro tier, no "source-available" sleight of hand where the license technically lets you look but practically lets you do nothing.</p>
            <p>The reason is the same reason as everything else on this page. If the material that helped build this came from the commons, then all of it goes back — not a curated slice, not just the parts we don't mind parting with. All of it. Anyone who forks SnapSmack gets the whole working system on day one: the engine, every skin, and every tool we use to run our own sites. The full meal deal, right out of the gate. You can read this whole site top to bottom and you will not find a corner where we quietly held something back, because there isn't one. That's rather the point.</p>

            <h2>The Thomas Clause</h2>
            <p>Buried in the SPL is a specific addendum called the Thomas Clause. You should know about this.</p>
            <p>Somewhere inside SnapSmack is a harmless Easter egg named 'Thomas the Bear'. The bear's name is Thomas. He's a real bear — Noah's bear, always and forever. The Easter egg is a tribute to Noah Grey, who created Greymatter and was one of the original photobloggers back when the internet was young and weird and full of people trying to make something honest.</p>
            <p>Noah Grey was (and still is to many) a big deal. He built tools that let photographers own their archives before anyone had thought to call that radical. SnapSmack exists in that lineage. Thomas the bear keeps that connection alive.</p>
            <p>The Thomas Clause is simple: Thomas must persist in all forks and derivatives. He must be credited to Noah Grey. Removing Thomas from a fork of SnapSmack is a violation of the license. The penalty specified in Section 3.1 of the Thomas Clause is flaming ass herpes. We are not making this up.</p>
            <p>It is, as the clause puts it, "a small act of love." We think that's worth preserving.</p>

            <div class="callout">
                <p><strong>On AI and credit:</strong> The SPL names Claude (Anthropic) as a co-author alongside Sean McCormick. This is not a boast and not a disclaimer — it is just accurate. Code review, security audits, architectural decisions, and a significant portion of the implementation were done in collaboration with an AI. Pretending otherwise would be dishonest. If that makes you uncomfortable, we'd gently suggest that what makes people uncomfortable about AI in creative work is usually the dishonesty around it, not the use itself. We are being honest about it. That should count for something.</p>
                <p>Gemini (Google) also contributed to early sessions. It's in the credits. It also overwrote code and added features nobody asked for in January 2026 — not a vendor complaint, just the honest record.</p>
            </div>

            <h2>The short version</h2>
            <p>We built this with AI help. The training data that shaped those AI tools may include code from people who never consented to it. So we're giving the result back to the commons, a license that makes sure it stays there. We think that is the right call. You are welcome to disagree, but you cannot change it.</p>
            <p>Also, Thomas the bear must stay in the code forever. Non-negotiable.</p>
            <p>Hairy muff.</p>

            <p class="updated">Last updated: June 2026</p>

        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
