<?php
/**
 * SNAPSMACK - AI Connection Test
 *
 * AJAX endpoint called by the Settings page to verify the configured
 * AI provider and API key are working. Returns JSON.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/ai-provider.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    echo json_encode(['ok' => false, 'error' => 'Direct access not allowed.']);
    exit;
}

// Accept provider + api_key from POST so the test works before saving settings.
// If not supplied, fall back to whatever's saved in the DB.
$post_provider = trim($_POST['provider'] ?? '');
$post_api_key  = trim($_POST['api_key']  ?? '');

$valid_providers = ['claude', 'gemini', 'openai'];

if ($post_provider && in_array($post_provider, $valid_providers, true) && $post_api_key !== '') {
    // Test with the form values directly — no save required
    $result = match ($post_provider) {
        'claude' => _snap_ai_claude($post_api_key, 'You are a connection test. Respond with exactly: OK', 'Connection test — reply with OK only.', 16),
        'gemini' => _snap_ai_gemini($post_api_key, 'You are a connection test. Respond with exactly: OK', 'Connection test — reply with OK only.', 256),
        'openai' => _snap_ai_openai($post_api_key, 'You are a connection test. Respond with exactly: OK', 'Connection test — reply with OK only.', 16),
    };
    $provider_labels = ['claude' => 'Claude', 'gemini' => 'Gemini', 'openai' => 'ChatGPT'];
    $label = $provider_labels[$post_provider] ?? $post_provider;
} else {
    // Fall back to DB-saved values
    if (!snap_ai_configured()) {
        echo json_encode(['ok' => false, 'error' => 'No provider or API key configured. Save your settings first.']);
        exit;
    }
    $result = snap_ai_complete(
        'You are a connection test. Respond with exactly: OK',
        'Connection test — reply with OK only.',
        256
    );
    $provider_labels = ['claude' => 'Claude', 'gemini' => 'Gemini', 'openai' => 'ChatGPT'];
    $label = $provider_labels[snap_ai_provider()] ?? snap_ai_provider();
}

if ($result['ok']) {
    echo json_encode(['ok' => true, 'message' => "{$label} is responding correctly."]);
} else {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
}
// ===== SNAPSMACK EOF =====
