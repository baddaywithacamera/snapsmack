<?php
/**
 * SnapSmack - Media Library
 * Version: 2.2 - Density Restoration & Compact Grid
 * MASTER DIRECTIVE: Full file return. Logic preserved.
 */
require_once 'core/auth.php';

$target_dir = "media_assets/";
if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

// 1. AJAX Signal Handling
if (isset($_FILES['file'])) {
    $file_ext = pathinfo($_FILES["file"]["name"], PATHINFO_EXTENSION);
    $file_name = time() . '_' . uniqid() . '.' . $file_ext;
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["file"]["tmp_name"], $target_file)) {
        $stmt = $pdo->prepare("INSERT INTO snap_assets (asset_name, asset_path) VALUES (?, ?)");
        $stmt->execute([$_FILES["file"]["name"], $target_file]);
        echo json_encode(['status' => 'success']);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// 2. Delete Logic
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT asset_path FROM snap_assets WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $path = $stmt->fetchColumn();
    if ($path && file_exists($path)) unlink($path);
    $stmt = $pdo->prepare("DELETE FROM snap_assets WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: smack-media.php");
    exit;
}

$assets = $pdo->query("SELECT * FROM snap_assets ORDER BY created_at DESC")->fetchAll();
$page_title = "MEDIA LIBRARY";

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>MEDIA LIBRARY</h2>

    <div class="box">
        <h3>INJECT GLOBAL ASSET</h3>
        
        <div class="progress-container" id="p-container">
            <div class="progress-bar" id="p-bar"></div>
        </div>

        <div class="file-upload-wrapper" id="drop-zone" onclick="document.getElementById('file-input').click()">
            <div class="file-custom-btn">CHOOSE FILE</div>
            <span id="file-name-display" class="file-name-display">No signal selected... or drag & drop here.</span>
            <input type="file" id="file-input" accept="image/*" style="display:none">
        </div>
    </div>

    <div class="box">
        <h3>GLOBAL ASSET GALLERY</h3>
        <div class="asset-grid">
            <?php foreach ($assets as $a): ?>
                <div class="asset-card" id="asset-<?php echo $a['id']; ?>">
                    <div class="asset-thumb-wrapper">
                        <img src="<?php echo $a['asset_path']; ?>" alt="Asset">
                    </div>
                    
                    <div class="asset-info">
                        <div class="asset-controls">
                            <div class="control-group">
                                <label class="compact-label">Size</label>
                                <select class="compact-select size-select" onchange="updateShortcode(<?php echo $a['id']; ?>)">
                                    <option value="full">Full</option>
                                    <option value="medium">Medium</option>
                                    <option value="small">Small</option>
                                </select>
                            </div>
                            <div class="control-group">
                                <label class="compact-label">Align</label>
                                <select class="compact-select align-select" onchange="updateShortcode(<?php echo $a['id']; ?>)">
                                    <option value="center">Center</option>
                                    <option value="left">Left</option>
                                    <option value="right">Right</option>
                                </select>
                            </div>
                        </div>

                        <label class="compact-label mt-15">SHORTCODE</label>
                        <div class="compact-preview shortcode-display" onclick="copyToClipboard(this)">
                            [img:<?php echo $a['id']; ?>|full|center]
                        </div>
                        
                        <div class="asset-actions">
                            <a href="?delete=<?php echo $a['id']; ?>" class="action-delete-link" onclick="return confirm('Purge Asset?')">PURGE</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const fileInput = document.getElementById('file-input');
const pContainer = document.getElementById('p-container');
const pBar = document.getElementById('p-bar');
const nameDisplay = document.getElementById('file-name-display');

function updateShortcode(id) {
    const card = document.getElementById('asset-' + id);
    const size = card.querySelector('.size-select').value;
    const align = card.querySelector('.align-select').value;
    const display = card.querySelector('.shortcode-display');
    display.innerText = `[img:${id}|${size}|${align}]`;
}

fileInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        nameDisplay.innerText = this.files[0].name;
        uploadFile(this.files[0]);
    }
});

function uploadFile(file) {
    pContainer.style.display = 'block';
    const formData = new FormData();
    formData.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'smack-media.php', true);

    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            const percent = (e.loaded / e.total) * 100;
            pBar.style.width = percent + '%';
        }
    };

    xhr.onload = () => {
        if (xhr.status === 200) {
            location.reload();
        } else {
            alert('Transmission Interrupted.');
            pContainer.style.display = 'none';
        }
    };

    xhr.send(formData);
}

function copyToClipboard(element) {
    const text = element.innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        const original = text;
        element.innerText = "COPIED";
        setTimeout(() => { element.innerText = original; }, 1000);
    });
}
</script>

<?php include 'core/admin-footer.php'; ?>