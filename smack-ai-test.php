<?php
/**
 * SNAPSMACK - AI Connection Test
 * Alpha v0.7.9
 *
 * AJAX endpoint called by the Settings page to verify the configured
 * AI provider and API key are working. Returns JSON.
 */

require_once 'core/auth.php';
require_once 'core/ai-provider.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    echo json_encode(['ok' => false, 'error' => 'Direct access not allowed.']);
    exit;
}

if (!snap_ai_configured()) {
    echo json_encode(['ok' => false, 'error' => 'No provider or API key configured.']);
    exit;
}

$result = snap_ai_complete(
    'You are a connection test. Respond with exactly: OK',
    'Connection test — reply with OK only.',
    16
);

if ($result['ok']) {
    $provider_labels = ['claude' => 'Claude', 'gemini' => 'Gemini', 'openai' => 'ChatGPT'];
    $label = $provider_labels[snap_ai_provider()] ?? snap_ai_provider();
    echo json_encode(['ok' => true, 'message' => "{$label} is responding correctly."]);
} else {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
}
