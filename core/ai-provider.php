<?php
/**
 * SNAPSMACK - AI Provider
 *
 * Thin abstraction over Claude, Gemini, and OpenAI chat APIs.
 * Routes a prompt to whichever provider is configured in snap_settings
 * and returns a plain text response.
 *
 * Usage:
 * $result = snap_ai_complete($system_prompt, $user_prompt);
 * if ($result['ok']) echo $result['text'];
 * else echo $result['error'];
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// ── Configuration helpers ────────────────────────────────────────────────────

function snap_ai_provider(): string {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $row   = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_provider' LIMIT 1")->fetch();
        $cache = $row ? $row['setting_val'] : 'none';
    }
    return $cache;
}

function snap_ai_api_key(): string {
    global $pdo;
    $provider = snap_ai_provider();
    $key_map  = [
        'claude'  => 'ai_key_claude',
        'gemini'  => 'ai_key_gemini',
        'openai'  => 'ai_key_openai',
    ];
    $setting = $key_map[$provider] ?? null;
    if (!$setting) return '';
    $row = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = " . $pdo->quote($setting) . " LIMIT 1")->fetch();
    return $row ? trim($row['setting_val']) : '';
}

function snap_ai_configured(): bool {
    return snap_ai_provider() !== 'none' && snap_ai_api_key() !== '';
}

// ── Main completion function ─────────────────────────────────────────────────

/**
 * Send a prompt to the configured AI provider and return the response.
 *
 * @param string $system  System prompt (role/instructions)
 * @param string $user    User message
 * @param int    $max_tokens  Max tokens in the response (default 1024)
 * @return array{ok: bool, text: string, error: string}
 */
function snap_ai_complete(string $system, string $user, int $max_tokens = 1024): array {
    $provider = snap_ai_provider();
    $api_key  = snap_ai_api_key();

    if ($provider === 'none' || $api_key === '') {
        return ['ok' => false, 'text' => '', 'error' => 'No AI provider configured. Visit Settings → AI to set one up.'];
    }

    return match ($provider) {
        'claude' => _snap_ai_claude($api_key, $system, $user, $max_tokens),
        'gemini' => _snap_ai_gemini($api_key, $system, $user, $max_tokens),
        'openai' => _snap_ai_openai($api_key, $system, $user, $max_tokens),
        default  => ['ok' => false, 'text' => '', 'error' => "Unknown provider: {$provider}"],
    };
}

// ── Provider implementations ─────────────────────────────────────────────────

function _snap_ai_claude(string $key, string $system, string $user, int $max_tokens): array {
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ]);
    $response = _snap_ai_post(
        'https://api.anthropic.com/v1/messages',
        $payload,
        [
            'x-api-key: '       . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]
    );
    if (!$response['ok']) return $response;
    $data = json_decode($response['body'], true);
    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty response from Claude.'];
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

function _snap_ai_gemini(string $key, string $system, string $user, int $max_tokens): array {
    // Corrected to gemini-3-flash-preview for the 2026 v1beta API
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=' . urlencode($key);
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['parts' => [['text' => $user]]]],
        'generationConfig'   => ['maxOutputTokens' => $max_tokens],
    ]);
    $response = _snap_ai_post($url, $payload, ['content-type: application/json']);
    if (!$response['ok']) return $response;
    $data = json_decode($response['body'], true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty response from Gemini.'];
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

function _snap_ai_openai(string $key, string $system, string $user, int $max_tokens): array {
    // Updated to gpt-5.4-mini (released March 2026)
    $payload = json_encode([
        'model'      => 'gpt-5.4-mini',
        'max_tokens' => $max_tokens,
        'messages'   => [
            ['role' => 'system',  'content' => $system],
            ['role' => 'user',    'content' => $user],
        ],
    ]);
    $response = _snap_ai_post(
        'https://api.openai.com/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . $key,
            'content-type: application/json',
        ]
    );
    if (!$response['ok']) return $response;
    $data = json_decode($response['body'], true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty response from OpenAI.'];
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

// ── Vision completion (prompt + images) ─────────────────────────────────────

/**
 * Like snap_ai_complete() but with images attached (vision). Routes to the
 * configured provider. Reusable for any image-understanding task.
 *
 * @param string $system  System prompt
 * @param string $user    User message
 * @param array  $images  list of ['mime' => 'image/jpeg', 'data' => <base64>]
 * @param int    $max_tokens
 * @return array{ok: bool, text: string, error: string}
 */
function snap_ai_vision(string $system, string $user, array $images, int $max_tokens = 512, string $providerOverride = '', string $keyOverride = ''): array {
    // Optional override (e.g. a per-skin key); otherwise the site's AI config.
    $provider = $providerOverride !== '' ? $providerOverride : snap_ai_provider();
    $api_key  = $providerOverride !== '' ? $keyOverride       : snap_ai_api_key();
    if ($provider === 'none' || $provider === '' || $api_key === '') {
        return ['ok' => false, 'text' => '', 'error' => 'No AI provider configured. Visit Settings → AI to set one up (or add a skin override).'];
    }
    if (empty($images)) {
        return ['ok' => false, 'text' => '', 'error' => 'No images supplied for vision request.'];
    }
    return match ($provider) {
        'claude' => _snap_ai_claude_vision($api_key, $system, $user, $images, $max_tokens),
        'gemini' => _snap_ai_gemini_vision($api_key, $system, $user, $images, $max_tokens),
        'openai' => _snap_ai_openai_vision($api_key, $system, $user, $images, $max_tokens),
        default  => ['ok' => false, 'text' => '', 'error' => "Unknown provider: {$provider}"],
    };
}

function _snap_ai_claude_vision(string $key, string $system, string $user, array $images, int $max_tokens): array {
    $content = [['type' => 'text', 'text' => $user]];
    foreach ($images as $im) {
        $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $im['mime'], 'data' => $im['data']]];
    }
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ]);
    $response = _snap_ai_post('https://api.anthropic.com/v1/messages', $payload, [
        'x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json',
    ]);
    if (!$response['ok']) return $response;
    $text = json_decode($response['body'], true)['content'][0]['text'] ?? '';
    return $text === '' ? ['ok' => false, 'text' => '', 'error' => 'Empty response from Claude.']
                        : ['ok' => true, 'text' => $text, 'error' => ''];
}

function _snap_ai_gemini_vision(string $key, string $system, string $user, array $images, int $max_tokens): array {
    $parts = [['text' => $user]];
    foreach ($images as $im) {
        $parts[] = ['inline_data' => ['mime_type' => $im['mime'], 'data' => $im['data']]];
    }
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=' . urlencode($key);
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['parts' => $parts]],
        'generationConfig'   => ['maxOutputTokens' => $max_tokens],
    ]);
    $response = _snap_ai_post($url, $payload, ['content-type: application/json']);
    if (!$response['ok']) return $response;
    $text = json_decode($response['body'], true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return $text === '' ? ['ok' => false, 'text' => '', 'error' => 'Empty response from Gemini.']
                        : ['ok' => true, 'text' => $text, 'error' => ''];
}

function _snap_ai_openai_vision(string $key, string $system, string $user, array $images, int $max_tokens): array {
    $content = [['type' => 'text', 'text' => $user]];
    foreach ($images as $im) {
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $im['mime'] . ';base64,' . $im['data']]];
    }
    $payload = json_encode([
        'model'      => 'gpt-5.4-mini',
        'max_tokens' => $max_tokens,
        'messages'   => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $content],
        ],
    ]);
    $response = _snap_ai_post('https://api.openai.com/v1/chat/completions', $payload, [
        'Authorization: Bearer ' . $key, 'content-type: application/json',
    ]);
    if (!$response['ok']) return $response;
    $text = json_decode($response['body'], true)['choices'][0]['message']['content'] ?? '';
    return $text === '' ? ['ok' => false, 'text' => '', 'error' => 'Empty response from OpenAI.']
                        : ['ok' => true, 'text' => $text, 'error' => ''];
}

// ── HTTP helper ──────────────────────────────────────────────────────────────

function _snap_ai_post(string $url, string $payload, array $headers): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'text' => '', 'body' => '', 'error' => 'cURL is not available on this server.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err) {
        return ['ok' => false, 'text' => '', 'body' => '', 'error' => "cURL error: {$err}"];
    }
    if ($code >= 400) {
        $msg = json_decode($body, true)['error']['message']
            ?? json_decode($body, true)['error']['errors'][0]['message']
            ?? "HTTP {$code}";
        return ['ok' => false, 'text' => '', 'body' => $body, 'error' => $msg];
    }
    return ['ok' => true, 'text' => '', 'body' => $body, 'error' => ''];
}
// ===== SNAPSMACK EOF =====
