<?php
/**
 * SNAPSMACK - 500 Internal Server Error
 *
 * Displayed when the server encounters an unexpected error.
 */

http_response_code(500);
$page_title = '500 - Server Error';
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
            background: linear-gradient(135deg, #2a0a0a 0%, #3a1a1a 100%);
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
            background: linear-gradient(135deg, #ff4444, #ff8844);
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
            background: #ff6655;
            color: #fff;
        }
        .btn-primary:hover {
            background: #ff8844;
            box-shadow: 0 0 20px rgba(255, 68, 68, 0.3);
        }
        .btn-secondary {
            background: rgba(255, 68, 68, 0.1);
            color: #ff6655;
            border: 1px solid #ff6655;
        }
        .btn-secondary:hover {
            background: rgba(255, 68, 68, 0.2);
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
        .error-info {
            margin-top: 24px;
            padding: 16px;
            background: rgba(255, 68, 68, 0.05);
            border-left: 3px solid #ff6655;
            border-radius: 3px;
            font-size: 13px;
            opacity: 0.7;
            text-align: left;
        }
    </style>
</head>
<body>
<div class="error-container">
    <div class="snapsmack-logo">SNAPSMACK</div>
    <div class="error-code">500</div>
    <h1 class="error-title">Server Error</h1>
    <p class="error-message">
        Something went wrong on our end.<br>
        The server encountered an unexpected condition that prevented it from fulfilling the request.
    </p>
    <div class="error-actions">
        <a href="/" class="btn btn-primary">Go Home</a>
        <a href="javascript:location.reload()" class="btn btn-secondary">Try Again</a>
    </div>
    <div class="error-info">
        <strong>SITE ADMIN:</strong> Did your page stop working after applying an update or when trying a new feature? We may have biffed it.<br><br>
        <strong>CHECK OUR MAIN SITE TO SEE IF WE HAVE DEPLOYED A FIX FOR THIS ISSUE YET:</strong><br>
        <a href="https://snapsmack.ca/bugger" style="color: #ff8844; text-decoration: underline;">https://snapsmack.ca/bugger</a>
    </div>
    <div class="error-footer">
        © 2026 Sean McCormick
    </div>
</div>
</body>
</html>
