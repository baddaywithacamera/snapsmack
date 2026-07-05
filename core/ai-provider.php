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

/**
 * Has the site owner accepted responsibility for third-party AI provider costs?
 * AI is hard-gated OFF until this is true (set at install or via the admin gate
 * in Settings → AI). Protects users from surprise per-use bills — see
 * _continuity / memory: AI is "capped, consented, detachable".
 */
function snap_ai_cost_accepted(): bool {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $row   = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_cost_accepted' LIMIT 1")->fetch();
        $cache = ($row && $row['setting_val'] === '1');
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

function snap_ai_gemini_model(): string {
    // Selectable in Settings → AI. Validated against the known model ids; any
    // other / unset value falls back to the recommended default.
    global $pdo;
    $allowed = ['gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-3.1-flash-lite'];
    try {
        $row = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_gemini_model' LIMIT 1")->fetch();
        $m   = $row ? trim($row['setting_val']) : '';
    } catch (Throwable $e) {
        $m = '';
    }
    return in_array($m, $allowed, true) ? $m : 'gemini-3.5-flash';
}

function snap_ai_openai_model(): string {
    // Selectable in Settings → AI. Validated; defaults to the cost-effective mini.
    global $pdo;
    $allowed = ['gpt-5.4-mini', 'gpt-5.5'];
    try {
        $row = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_openai_model' LIMIT 1")->fetch();
        $m   = $row ? trim($row['setting_val']) : '';
    } catch (Throwable $e) {
        $m = '';
    }
    return in_array($m, $allowed, true) ? $m : 'gpt-5.4-mini';
}

function snap_ai_configured(): bool {
    // Cost responsibility must be accepted first — AI is off until then.
    return snap_ai_cost_accepted() && snap_ai_provider() !== 'none' && snap_ai_api_key() !== '';
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

    if (!snap_ai_cost_accepted()) {
        return ['ok' => false, 'text' => '', 'error' => 'AI is disabled until you accept responsibility for AI provider costs in Settings → AI.'];
    }
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
    // Model is selectable in Settings → AI (default gemini-3.5-flash). The old
    // 'gemini-3-flash-preview' was a non-existent id and returned "Empty
    // response from Gemini" on every call.
    $model   = snap_ai_gemini_model();
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($key);
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['parts' => [['text' => $user]]]],
        'generationConfig'   => _snap_ai_gemini_gencfg($model, $max_tokens),
    ]);
    $response = _snap_ai_post($url, $payload, ['content-type: application/json']);
    if (!$response['ok']) return $response;
    $data = json_decode($response['body'], true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') return ['ok' => false, 'text' => '', 'error' => _snap_ai_gemini_empty_reason($data)];
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/**
 * Build Gemini generationConfig. On the 3.x "thinking" models a small
 * maxOutputTokens is consumed entirely by internal reasoning tokens, so the
 * candidate comes back with NO text part → "Empty response from Gemini" (the
 * regression the connection test hit at max_tokens=16). For short utility calls
 * (spell-check, AI-assist, connection test) we disable thinking so the whole
 * budget goes to visible output — cheaper and faster too. The 'pro' model can
 * reject a zero budget, so we leave its thinking alone and only give it room.
 */
function _snap_ai_gemini_gencfg(string $model, int $max_tokens): array {
    $cfg = ['maxOutputTokens' => $max_tokens];
    if (strpos($model, 'pro') === false) {
        $cfg['thinkingConfig'] = ['thinkingBudget' => 0];
    }
    return $cfg;
}

/** Turn an empty-candidate Gemini response into a diagnosable error string. */
function _snap_ai_gemini_empty_reason(?array $data): string {
    $fr = $data['candidates'][0]['finishReason'] ?? '';
    if ($fr === 'MAX_TOKENS') {
        return 'Gemini hit the output-token limit before returning text (thinking budget exhausted it). Raise max tokens or disable thinking.';
    }
    if ($fr === 'SAFETY' || $fr === 'PROHIBITED_CONTENT') {
        return 'Gemini blocked the response (' . $fr . ').';
    }
    return 'Empty response from Gemini' . ($fr !== '' ? " (finishReason: {$fr})." : '.');
}

function _snap_ai_openai(string $key, string $system, string $user, int $max_tokens): array {
    // Model selectable in Settings → AI (default gpt-5.4-mini). GPT-5-series chat
    // completions require 'max_completion_tokens'; 'max_tokens' is rejected on
    // these models and was returning an error/empty reply.
    $payload = json_encode([
        'model'                 => snap_ai_openai_model(),
        'max_completion_tokens' => $max_tokens,
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
    // Cost-acceptance gate applies even to a per-skin override — it still spends money.
    if (!snap_ai_cost_accepted()) {
        return ['ok' => false, 'text' => '', 'error' => 'AI is disabled until you accept responsibility for AI provider costs in Settings → AI.'];
    }
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
    $model   = snap_ai_gemini_model();
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($key);
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['parts' => $parts]],
        'generationConfig'   => _snap_ai_gemini_gencfg($model, $max_tokens),
    ]);
    $response = _snap_ai_post($url, $payload, ['content-type: application/json']);
    if (!$response['ok']) return $response;
    $data = json_decode($response['body'], true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return $text === '' ? ['ok' => false, 'text' => '', 'error' => _snap_ai_gemini_empty_reason($data)]
                        : ['ok' => true, 'text' => $text, 'error' => ''];
}

function _snap_ai_openai_vision(string $key, string $system, string $user, array $images, int $max_tokens): array {
    $content = [['type' => 'text', 'text' => $user]];
    foreach ($images as $im) {
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $im['mime'] . ';base64,' . $im['data']]];
    }
    $payload = json_encode([
        'model'                 => snap_ai_openai_model(),
        'max_completion_tokens' => $max_tokens,
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
