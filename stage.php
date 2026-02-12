<?php
// SnapSmack Private Staging Area
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_GET['view']) || $_GET['view'] !== 'private') {
    die("Unauthorized Access.");
}

require_once 'core/db.php';
require_once 'core/parser.php';

$ss = new SnapSmack($pdo);

$stmt = $pdo->query("SELECT * FROM snap_images ORDER BY id DESC LIMIT 1");
$image = $stmt->fetch();

if (!$image) {
    $image = [
        'img_title' => 'Database Empty',
        'img_description' => 'No records found.',
        'img_file' => 'placeholder.jpg'
    ];
}

$template = '
<!DOCTYPE html>
<html>
<head>
    <title>Debug: <SITE_TITLE></title>
    <style>
        body { background: #1a1a1a; color: #0f0; font-family: monospace; padding: 40px; }
        .box { border: 1px solid #0f0; padding: 20px; max-width: 800px; margin: auto; }
        img { max-width: 100%; display: block; margin-top: 20px; border: 1px solid #333; }
    </style>
</head>
<body>
    <div class="box">
        <h3>[SYSTEM ONLINE]</h3>
        <p><strong>Site:</strong> <SITE_TITLE></p>
        <hr>
        <h1><IMAGE_NAME></h1>
        <p><IMAGE_DESCRIPTION></p>
        <p style="color:#888;">Path: <IMAGE_PATH></p>
        <img src="<IMAGE_PATH>" alt="Image">
    </div>
</body>
</html>';

echo $ss->render($template, $image);
?>