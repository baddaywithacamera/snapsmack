<?php
/**
 * SNAPSMACK - 404 Not Found
 *
 * Displayed when a requested page or image does not exist.
 */

http_response_code(404);
$page_title = '404 - Not Found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | SnapSmack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            text-align: center;
            max-width: 600px;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #39FF14, #00ff88);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }
        .error-title {
            font-size: 32px;
            margin-bottom: 16px;
            font-weight: 300;
            letter-spacing: 1px;
        }
        .error-message {
            font-size: 16px;
            opacity: 0.75;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 28px;
            font-size: 14px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        .btn-primary {
            background: #39FF14;
            color: #000;
        }
        .btn-primary:hover {
            background: #00ff88;
            box-shadow: 0 0 20px rgba(57, 255, 20, 0.3);
        }
        .btn-secondary {
            background: rgba(57, 255, 20, 0.1);
            color: #39FF14;
            border: 1px solid #39FF14;
        }
        .btn-secondary:hover {
            background: rgba(57, 255, 20, 0.2);
        }
        .error-footer {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px;
            opacity: 0.5;
            letter-spacing: 0.5px;
        }
        .snapsmack-logo {
            font-size: 14px;
            letter-spacing: 2px;
            margin-bottom: 24px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
<div class="error-container">
    <div class="snapsmack-logo">SNAPSMACK</div>
    <div class="error-code">404</div>
    <h1 class="error-title">Page Not Found</h1>
    <p class="error-message">
        The transmission you're looking for has drifted into the void.<br>
        It may have been deleted, archived, or never existed at all.
    </p>
    <div class="error-actions">
        <a href="/" class="btn btn-primary">Back to Gallery</a>
        <a href="/archive" class="btn btn-secondary">Browse Archive</a>
    </div>
    <div class="error-footer">
        © 2026 Sean McCormick
    </div>
</div>
</body>
</html>
