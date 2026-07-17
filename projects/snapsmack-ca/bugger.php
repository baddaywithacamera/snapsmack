<?php
/**
 * SNAPSMACK.CA — BUGGER! - Emergency Help & Updates
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'BUGGER! - Emergency Help & Updates';
$page_description = 'Emergency updates and critical fixes for SnapSmack. Check here if your site is down.';
$page_og_url      = 'https://snapsmack.ca/bugger.php';
$nav_active       = 'bugger';
$page_css = <<<'CSS'
/* ─── MAIN CONTENT ──────────────────────────────────────────────────────── */
main { margin-top: 0; }
.page-header { padding: 80px 0 72px; border-bottom: 1px solid var(--border); }
.page-header h1 { margin-bottom: 8px; }
.content { padding: 60px 0; max-width: 820px; }
.content p { margin-bottom: 1.5em; }
.alert {
    background: #fff3cd;
    border-left: 4px solid var(--red);
    padding: 20px; margin-bottom: 40px; font-weight: 500;
}
.alert strong { color: var(--black); }
.key-block {
    background: #111;
    border: 1px solid var(--border);
    border-left: 4px solid var(--red);
    padding: 24px 28px;
    margin: 0 0 48px;
}
.key-block h2 { margin: 0 0 12px; color: var(--red); }
/* Dark card (#111) — force light body text so it's readable in daylight. */
.key-block p, .key-block ol, .key-block li { color: #dadada; }
.key-block strong { color: #fff; }
.key-block p { margin: 0 0 14px; font-size: 0.9rem; }
.key-block ol { margin: 0 0 14px; padding-left: 1.4em; font-size: 0.9rem; line-height: 1.9; }
.key-copy-wrap { display: flex; align-items: stretch; gap: 0; margin: 16px 0; }
.key-value {
    font-family: 'Courier New', monospace;
    font-size: 0.82rem;
    background: #000;
    border: 1px solid #333;
    padding: 10px 14px;
    color: #c8ff00;
    flex: 1;
    word-break: break-all;
    user-select: all;
}
.btn-copy {
    background: #1a1a1a;
    border: 1px solid #333;
    border-left: none;
    color: var(--fg);
    padding: 10px 18px;
    font-size: 0.75rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    cursor: pointer;
    white-space: nowrap;
    font-family: inherit;
}
.btn-copy:hover { background: #222; color: var(--red); }
.btn-copy.copied { color: #c8ff00; }
CSS;
include __DIR__ . '/includes/header.php';
?>

<!-- MAIN CONTENT -->
<main>
    <div class="page-header">
        <div class="wrap">
            <h1>BUGGER!</h1>
            <p class="lede">Known issues, half-finished bits, and things that technically work but not the way they're supposed to. Alpha software. You've been warned.</p>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="content">
                <div class="alert">
                    <strong>Alpha status.</strong> SnapSmack is in active development. The core platform runs live sites without drama. The items below are known rough edges — tracked, being worked on, not forgotten.
                </div>

                <div class="key-block">
                    <h2>Signature Verification Failed?</h2>
                    <p>If System Updates is showing <strong>"ED25519 SIGNATURE VERIFICATION FAILED. THIS PACKAGE CANNOT BE TRUSTED."</strong> — the public key stored in your install is out of date. This happens when the release keypair is rotated. Fix it in about ten seconds:</p>
                    <ol>
                        <li>Copy the current public key below.</li>
                        <li>Go to <strong>Admin &rarr; System Updates</strong> and scroll to the <strong>REPAIR SIGNING KEY</strong> section.</li>
                        <li>Paste the key and hit repair.</li>
                        <li>Check for updates again.</li>
                    </ol>
                    <p><strong>Current release public key:</strong></p>
                    <div class="key-copy-wrap">
                        <span class="key-value" id="pubkey-val">b0cbadef25a6aca5292e5c31b29dededb3f710f1d57908ba3c83a5e641f53bc2</span>
                        <button class="btn-copy" onclick="(function(b){var v=document.getElementById('pubkey-val');navigator.clipboard.writeText(v.textContent).then(function(){b.textContent='COPIED';b.classList.add('copied');setTimeout(function(){b.textContent='COPY';b.classList.remove('copied');},2000);});})(this)">COPY</button>
                    </div>
                </div>

                <h2 style="margin-top: 48px;">Oh Snap! Skin Designer — AI Integration</h2>
                <p>Oh Snap! is the desktop skin design tool — you build skins visually and push them directly to your blog without touching code. The design side works: the controls panel, the live preview, the push-to-blog pipeline. What isn't cooperating yet is the AI side. Oh Snap! supports multiple AI providers (local via Ollama, cloud via a handful of others) for generating skin suggestions and colour palettes from a prompt or a photo. The handoff between the UI and the AI provider is inconsistent — responses come back in the wrong shape, the generated CSS occasionally lands with bad values, and streaming output from local models cuts off mid-response. It functions enough to be useful in favourable conditions, but it isn't reliable enough to document as a feature yet.</p>

                <h2 style="margin-top: 48px;">Community Forum</h2>
                <p>The forum exists and works at a basic level — you can register, post threads, reply, and moderate. What it doesn't have yet is anything that makes it worth using over email: no solved/accepted answers, no post editing, no reactions, no search, no view counts, no thread excerpts in the listing. It's functional the way a bus shelter is functional. Keeps the rain off. Improvements are coming in the next cycle.</p>

                <h2 style="margin-top: 48px;">Smack Your Batch Up — Repair Operations</h2>
                <p>The SYBU desktop tool handles bulk post management, Drive sync, and audit operations. The Repair tab — which handles renaming Drive files, re-enriching duplicate post titles via AI, and backfilling missing Drive links — is new and hasn't had a full run against a large dataset yet. The operations are rate-limited and resumable, but they haven't been stress-tested at scale. If you're running a repair job on a large archive, watch the first few hundred entries before walking away from it.</p>

                <h2 style="margin-top: 48px;">Development Skins (Not Yet in Gallery)</h2>
                <p>Several skins are in the repo but not available in the skin gallery yet. A Grey Reckoning, In Stereo Where Available, 52 Card Pickup, and Show-n-Tell are all in various states of done. They're tracked in git, they won't disappear, but they're not ready for installs. When they're ready, they'll show up in the gallery without any action required on your end.</p>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<!-- ===== SNAPSMACK EOF ===== -->
<?php // ===== SNAPSMACK EOF ===== ?>
