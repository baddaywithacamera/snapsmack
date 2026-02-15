<?php
/**
 * SnapSmack - System Backup & Recovery
 * Version: 3.2 - Integrated Engine & Trinity UI
 */
require_once 'core/auth.php';

// --- THE ENGINE: EXTRACTION LOGIC ---
if (isset($_POST['action'])) {
    $type = $_POST['type'];
    $filename = "snapsmack_" . $type . "_" . date('Y-m-d_H-i') . ".sql";
    
    // Set headers to force download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = "-- SnapSmack Backup Service\n-- Type: " . strtoupper($type) . "\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";

    if ($type === 'full' || $type === 'schema' || $type === 'keys') {
        $tables = ($type === 'keys') ? ['snap_users'] : ['snap_images', 'snap_categories', 'snap_image_cat_map', 'snap_comments', 'snap_users', 'snap_settings'];
        
        foreach ($tables as $table) {
            // 1. Get Schema (DNA)
            $res = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_ASSOC);
            $output .= "DROP TABLE IF EXISTS `$table`;\n" . $res['Create Table'] . ";\n\n";
            
            // 2. Get Data (The Brain) - Skip if schema only
            if ($type !== 'schema') {
                $rows = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, array_values($row));
                    $output .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $output .= "\n";
            }
        }
    }
    echo $output;
    exit;
}

$page_title = "BACKUP & RECOVERY";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM BACKUP & RECOVERY</h2>

    <div class="dash-grid">
        <div class="box">
            <h3>FULL DATABASE (THE BRAIN)</h3>
            <p class="skin-desc-text">Extracts architecture and all content into a local SQL file. Standard tactical backup for site migrations.</p>
            <br>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type" value="full">
                <button type="submit" class="btn-smack btn-block">GET FULL SQL DUMP</button>
            </form>
        </div>

        <div class="box">
            <h3>EMERGENCY ACCESS (THE KEYS)</h3>
            <p class="skin-desc-text">Extracts only the user credentials and permission hashes. Essential for regaining entry to the system.</p>
            <br>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type" value="keys">
                <button type="submit" class="btn-security btn-block">GET RECOVERY SQL</button>
            </form>
        </div>

        <div class="box">
            <h3>ENGINE SCHEMA (THE DNA)</h3>
            <p class="skin-desc-text">Extracts structure only. No user data, no images. Used for system updates or cloning the engine.</p>
            <br>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type" value="schema">
                <button type="submit" class="btn-smack btn-block">GET SCHEMA ONLY</button>
            </form>
        </div>
    </div>
    
    <div class="dash-grid">
        <div class="box">
            <h3>MEDIA LIBRARY</h3>
            <p class="skin-desc-text">Archive all original photography. This process requires server-side compression.</p>
            <br>
            <button class="btn-secondary btn-block" onclick="alert('Engine Note: Link this to your zip-archive action.')">GET MEDIA ARCHIVE</button>
        </div>
        <div class="box">
            <h3>SITE SOURCE</h3>
            <p class="skin-desc-text">Archive core PHP and CSS logic. Excludes media directories.</p>
            <br>
            <button class="btn-secondary btn-block" onclick="alert('Engine Note: Link this to your source-archive action.')">GET CODE SOURCE</button>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>