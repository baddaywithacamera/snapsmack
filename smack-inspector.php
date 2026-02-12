<?php
// smack-inspector.php
require_once 'core/db.php';
echo "<h1>HARDWARE INSPECTION</h1>";

$tables = ['snap_pages', 'snap_images', 'snap_assets', 'snap_settings'];

foreach ($tables as $table) {
    echo "<h3>Table: $table</h3><ul>";
    try {
        $q = $pdo->query("DESCRIBE $table");
        while($row = $q->fetch(PDO::FETCH_ASSOC)) {
             echo "<li>" . $row['Field'] . " (" . $row['Type'] . ")</li>";
        }
    } catch (Exception $e) {
        echo "<li style='color:red'>TABLE MISSING or ERROR</li>";
    }
    echo "</ul><hr>";
}
?>