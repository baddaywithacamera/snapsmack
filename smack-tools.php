<?php
/**
 * SNAPSMACK - Companion Tools
 * Alpha v0.7.4d
 *
 * Lists available companion desktop tools and handles build package uploads.
 * Packages are stored in /packages/ and served as direct downloads.
 */

require_once 'core/auth.php';

$msg      = '';
$msg_type = 'success';

// --- UPLOAD HANDLER ---
// Accepts a new build zip for a named tool and saves it to /packages/.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_tool'])) {
    $tool_key = preg_replace('/[^a-z0-9\-]/', '', $_POST['tool_key'] ?? '');

    if (empty($tool_key)) {
        $msg      = 'Invalid tool key.';
        $msg_type = 'error';
    } elseif (!isset($_FILES['tool_zip']) || $_FILES['tool_zip']['error'] !== UPLOAD_ERR_OK) {
        $msg      = 'Upload failed or no file selected.';
        $msg_type = 'error';
    } else {
        $tmp   = $_FILES['tool_zip']['tmp_name'];
        $name  = $_FILES['tool_zip']['name'];
        $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext !== 'zip') {
            $msg      = 'Only .zip files are accepted.';
            $msg_type = 'error';
        } else {
            $dest = __DIR__ . '/packages/' . $tool_key . '.zip';
            if (move_uploaded_file($tmp, $dest)) {
                $msg = 'Package uploaded successfully.';
            } else {
                $msg      = 'Could not save file. Check /packages/ directory permissions.';
                $msg_type = 'error';
            }
        }
    }
}

// --- TOOL REGISTRY ---
// Add new companion tools here. package_file is relative to /packages/.
$tools = [
    [
        'key'          => 'ft-batch-poster',
        'name'         => 'Batch Image Poster',
        'version'      => '0.7.4d',
        'platform'     => 'Windows (64-bit)',
        'package_file' => 'ft-batch-poster.zip',
        'description'  => 'Desktop tool for bulk-posting images to SnapSmack. Loads manifest files, embeds full EXIF/IPTC/XMP copyright metadata via ExifTool, resizes to web dimensions, uploads originals to Google Drive, and posts the batch to SnapSmack. Borrows the active admin colour scheme on connect. Drag to reorder, per-row category and album, accumulate multiple manifests before posting.',
        'requires'     => 'Windows 10/11 · ExifTool 12+ (bundled) · Google Drive credentials JSON (optional)',
        'source'       => 'tools/ft-batch-poster/',
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

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type === 'error' ? 'error' : 'success'; ?>">
            > <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <div class="box">
        <p class="dim" style="margin:0 0 4px;">
            Companion tools are standalone desktop applications that work alongside SnapSmack.
            Upload a build package below to make it available for download here.
        </p>
    </div>

    <?php foreach ($tools as $tool): ?>
        <?php
        $pkg_path   = __DIR__ . '/packages/' . $tool['package_file'];
        $pkg_exists = file_exists($pkg_path);
        $pkg_size   = $pkg_exists ? round(filesize($pkg_path) / 1024 / 1024, 1) . ' MB' : null;
        $pkg_date   = $pkg_exists ? date('Y-m-d', filemtime($pkg_path)) : null;
        ?>
        <div class="box" style="margin-top:16px;">
            <div class="box-header">
                <span class="box-title"><?php echo htmlspecialchars($tool['name']); ?></span>
                <code class="slug-display" style="margin-left:10px;">v<?php echo htmlspecialchars($tool['version']); ?></code>
                <code class="slug-display" style="margin-left:6px;"><?php echo htmlspecialchars($tool['platform']); ?></code>
            </div>

            <div style="padding:16px 0 8px;">
                <p style="margin:0 0 10px; color:var(--fg-main, #e8e8e0);">
                    <?php echo htmlspecialchars($tool['description']); ?>
                </p>
                <p class="dim" style="margin:0 0 16px; font-size:0.85em;">
                    <strong>Requires:</strong> <?php echo htmlspecialchars($tool['requires']); ?><br>
                    <strong>Source:</strong> <code><?php echo htmlspecialchars($tool['source']); ?></code>
                </p>

                <?php if ($pkg_exists): ?>
                    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                        <a href="packages/<?php echo htmlspecialchars($tool['package_file']); ?>"
                           class="btn-smack"
                           download>
                            ↓ DOWNLOAD PACKAGE
                        </a>
                        <span class="dim" style="font-size:0.85em;">
                            <?php echo $pkg_size; ?> &middot; uploaded <?php echo $pkg_date; ?>
                        </span>
                    </div>
                <?php else: ?>
                    <p class="dim" style="margin:0 0 12px;">
                        No package uploaded yet. Build the tool and upload the zip below.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Upload form for this tool -->
            <form method="POST" enctype="multipart/form-data"
                  style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border, #2a2a2a);">
                <input type="hidden" name="upload_tool" value="1">
                <input type="hidden" name="tool_key" value="<?php echo htmlspecialchars($tool['key']); ?>">

                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <label class="dim" style="white-space:nowrap; font-size:0.85em;">
                        <?php echo $pkg_exists ? 'REPLACE PACKAGE' : 'UPLOAD PACKAGE'; ?> (.zip)
                    </label>
                    <input type="file" name="tool_zip" accept=".zip"
                           style="background:var(--bg-input, #1e1e2a); color:var(--fg-main, #e8e8e0);
                                  border:1px solid var(--border, #2a2a2a); padding:4px 8px; font-size:0.85em;">
                    <button type="submit" class="btn-smack btn-settings">UPLOAD</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
