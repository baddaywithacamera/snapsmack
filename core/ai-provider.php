<?php
/**
 * SNAPSMACK - AI Provider
 * Alpha v0.7.9
 *
 * Thin abstraction over Claude, Gemini, and OpenAI chat APIs.
 * Routes a prompt to whichever provider is configured in snap_settings
 * and returns a plain text response.
 *
 * Usage:
 *   $result = snap_ai_complete($system_prompt, $user_prompt);
 *   if ($result['ok']) echo $result['text'];
 *   else echo $result['error'];
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
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($key);
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
    $payload = json_encode([
        'model'      => 'gpt-4o-mini',
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
