<?php
/**
 * SNAPSMACK - Shortcode Reference (SMACKCODES)
 *
 * Read-only reference page for all available shortcodes.
 * Shows live-rendered previews (via parseContent) and copy buttons.
 * SMACKONEOUT + SMACKTALK only — redirects on GRAMOFSMACK installs.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
require_once 'core/parser.php';

// --- CAROUSEL GUARD ---
// Shortcodes not available on GRAMOFSMACK installs.
if (($settings['site_mode'] ?? 'photoblog') === 'carousel') {
    $page_title = "Shortcodes";
    include 'core/admin-header.php';
    include 'core/sidebar.php';
    ?>
    <div class="main">
        <h2>SMACKCODES</h2>
        <div class="msg">> Shortcodes are not available on GRAMOFSMACK installs.</div>
    </div>
    <?php
    include 'core/admin-footer.php';
    exit;
}

// --- PARSER INSTANCE ---
$parser = new SnapSmack($pdo);

// --- SHORTCODE DEFINITIONS ---
// Each entry: [syntax, description, sample content to pass through parseContent()]
$shortcodes = [

    // --- PROSE ---
    [
        'syntax'  => '[lede]text[/lede]',
        'desc'    => 'Large grey introductory paragraph. Use as the opening line of a page or section.',
        'sample'  => '[lede]This is where you set the scene. A calm, wide-open sentence that pulls the reader forward.[/lede]',
    ],
    [
        'syntax'  => '[callout]text[/callout]',
        'desc'    => 'Red left-border info or warning box. Inner content is auto-paragraphed.',
        'sample'  => '[callout]This is important. Pay attention to this before you proceed.[/callout]',
    ],
    [
        'syntax'  => '[kicker]text[/kicker]',
        'desc'    => 'Small uppercase label that appears above a heading.',
        'sample'  => '[kicker]Established 1987[/kicker]',
    ],
    [
        'syntax'  => '[dict word="" phon="" pos=""]definition[/dict]',
        'desc'    => 'Full-width dictionary-definition interstitial. <code>phon</code> and <code>pos</code> are optional.',
        'sample'  => '[dict word="smack" phon="smak" pos="v."]To strike sharply; to put down with satisfying finality.[/dict]',
    ],
    [
        'syntax'  => '[list bullet="check|arrow"]item one|item two|item three[/list]',
        'desc'    => 'Styled unordered list. Items are pipe-separated. <code>bullet</code> defaults to <code>check</code>.',
        'sample'  => '[list bullet="check"]Clean installs|No bloat|Your data stays yours[/list]',
    ],
    [
        'syntax'  => '[btn href="" style="primary|secondary"]label[/btn]',
        'desc'    => 'Call-to-action button. <code>style</code> defaults to <code>primary</code>. Renders as a <code>&lt;span&gt;</code> if <code>href</code> is empty.',
        'sample'  => '[btn href="https://snapsmack.ca" style="primary"]Download SnapSmack[/btn]',
    ],

    // --- LAYOUT ---
    [
        'syntax'  => '[card-grid cols="N" canvas="light|dark"] [card label="" title="" tagline=""]body[/card] [/card-grid]',
        'desc'    => 'N-column card grid (2–4 cols). <code>canvas</code> defaults to <code>light</code>. Each card supports <code>label</code>, <code>title</code>, <code>tagline</code>, and body text. Nested <code>[card]</code> blocks.',
        'sample'  => '[card-grid cols="3" canvas="light"][card title="SMACK DAB" tagline="Local install"]Your blog, your server, your rules.[/card][card title="SMACK CENTRAL" tagline="Distribution hub"]Push updates to every spoke at once.[/card][card title="SMACK TALK" tagline="Longform mode"]Write essays, not just captions.[/card][/card-grid]',
    ],
    [
        'syntax'  => '[accent-grid cols="N"] [accent-card title=""]body[/accent-card] [/accent-grid]',
        'desc'    => 'N-column grid of cards with red left accent bar (2–4 cols). Good for "what this is not" style content.',
        'sample'  => '[accent-grid cols="2"][accent-card title="Not a WordPress replacement"]Different problem, different tool. SnapSmack does one thing well.[/accent-card][accent-card title="Not a hosted service"]You run it. You own it. We just write the software.[/accent-card][/accent-grid]',
    ],
    [
        'syntax'  => '[feature-box title=""]item one|item two|item three[/feature-box]',
        'desc'    => 'Dark box with a heading and a checkmarked list. Items are pipe-separated.',
        'sample'  => '[feature-box title="What\'s included"]Clean install in under 5 minutes|No FTP client required|Runs on shared hosting|Automatic update pipeline[/feature-box]',
    ],
    [
        'syntax'  => '[bio img="" name="" role=""]text[/bio]',
        'desc'    => 'Portrait photo + name + role + bio copy. <code>img</code> is optional — omit to show copy only.',
        'sample'  => '[bio name="SEAN McCORMICK" role="Lead Developer"]Photographer, developer, dog owner. Building SnapSmack in the basement since 2024.[/bio]',
    ],
];

$page_title = "Smackcodes";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/shortcodes.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">

<style>
.shortcode-entry {
    margin-bottom: 48px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 40px;
}
.shortcode-entry:last-child {
    border-bottom: none;
}
.shortcode-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.shortcode-syntax {
    display: inline-block;
    font-family: 'Courier New', Courier, monospace;
    font-size: 0.85rem;
    background: var(--code-bg, #1a1a1a);
    color: #e0e0e0;
    padding: 6px 12px;
    border-radius: 2px;
    word-break: break-all;
}
.shortcode-copy {
    flex-shrink: 0;
    font-size: 0.72rem;
    font-family: 'Arial Black', Arial, sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 6px 14px;
    cursor: pointer;
    background: transparent;
    border: 1px solid var(--border);
    color: var(--mid-text);
    transition: border-color 0.15s, color 0.15s;
}
.shortcode-copy:hover {
    border-color: var(--red);
    color: var(--red);
}
.shortcode-copy.copied {
    border-color: #4caf50;
    color: #4caf50;
}
.shortcode-desc {
    font-size: 0.88rem;
    color: var(--mid-text);
    margin-bottom: 16px;
    line-height: 1.5;
}
.shortcode-preview {
    background: var(--preview-bg, #fafafa);
    border: 1px solid var(--border);
    padding: 28px 32px;
    overflow: hidden;
}
</style>

<div class="main">
    <h2>SMACKCODES</h2>
    <p class="admin-lede">Available in posts and pages on SMACKONEOUT and SMACKTALK installs. Paste the syntax into any content field — the parser handles the rest.</p>

    <?php foreach ($shortcodes as $sc): ?>
    <div class="shortcode-entry">
        <div class="shortcode-header">
            <code class="shortcode-syntax"><?php echo htmlspecialchars($sc['syntax']); ?></code>
            <button class="shortcode-copy" data-copy="<?php echo htmlspecialchars($sc['syntax']); ?>">Copy</button>
        </div>
        <div class="shortcode-desc"><?php echo $sc['desc']; ?></div>
        <div class="shortcode-preview">
            <?php echo $parser->parseContent($sc['sample']); ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
document.querySelectorAll('.shortcode-copy').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var text = btn.dataset.copy;
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = orig;
                btn.classList.remove('copied');
            }, 1800);
        }).catch(function() {
            // Clipboard API unavailable — show the text to copy manually
            prompt('Copy this shortcode:', text);
        });
    });
});
</script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
