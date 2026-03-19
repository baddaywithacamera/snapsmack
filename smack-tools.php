<?php
/**
 * SNAPSMACK - Companion Tools
 * Alpha v0.7.4d
 *
 * Lists available companion desktop tools with download links.
 */

require_once 'core/auth.php';

// --- TOOL REGISTRY ---
// Add new companion tools here.
$tools = [
    [
        'name'         => 'Smack Your Batch Up',
        'version'      => '0.7.4d',
        'platform'     => 'Windows (64-bit)',
        'download_url' => 'https://snapsmack.ca/tools/smackyourbatchup.zip',
        'description'  => 'Desktop tool for bulk-posting images to SnapSmack. Loads manifest files, embeds EXIF copyright metadata via piexif (pure Python, no external dependencies), resizes to web dimensions, uploads originals to Google Drive, and posts the batch to SnapSmack. Borrows the active admin colour scheme on connect. Drag to reorder, per-row category and album, accumulate multiple manifests before posting.',
        'requires'     => 'Windows 10/11 · Google Drive credentials JSON (optional)',
    ],
];

$page_title = 'Companion Tools';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>COMPANION TOOLS</h2>
    </div>

    <div class="box">
        <p class="dim tool-intro-text">
            Companion tools are standalone desktop applications that work alongside SnapSmack.
        </p>
    </div>

    <?php foreach ($tools as $tool): ?>
        <div class="box mt-15">
            <div class="box-header">
                <span class="box-title"><?php echo htmlspecialchars($tool['name']); ?></span>
                <code class="slug-display tool-version-badge">v<?php echo htmlspecialchars($tool['version']); ?></code>
                <code class="slug-display tool-platform-badge"><?php echo htmlspecialchars($tool['platform']); ?></code>
            </div>

            <div class="tool-details">
                <p class="tool-desc">
                    <?php echo htmlspecialchars($tool['description']); ?>
                </p>
                <p class="dim tool-requires">
                    <strong>Requires:</strong> <?php echo htmlspecialchars($tool['requires']); ?>
                </p>

                <div class="tool-download-row">
                    <a href="<?php echo htmlspecialchars($tool['download_url']); ?>"
                       class="btn-smack">
                        ↓ DOWNLOAD
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
