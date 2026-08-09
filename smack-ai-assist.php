<?php
/**
 * SNAPSMACK - AI Writing Assistant
 *
 * AJAX endpoint for the post editor AI Assist panel and Spell/Grammar button.
 * Accepts a mode ('chat' or 'spellcheck'), the user's message, and the current
 * editor content as context. Returns JSON.
 *
 * POST params:
 *   mode     — 'chat' | 'spellcheck'
 *   message  — user's question or instruction (chat mode)
 *   content  — current editor text (used as context in chat; full text for spellcheck)
 *   selected — selected text only (spellcheck falls back to content if empty)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/ai-provider.php';
require_once 'core/ai-enrichment-prompts.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

if (!snap_ai_active()) {
    echo json_encode([
        'ok'      => false,
        'status'  => snap_ai_status(),
        'error'   => snap_ai_status() === 'expired'
            ? snap_ai_expired_message()
            : 'No AI provider configured. Set a provider, add its API key, and accept the cost in Settings → AI.',
    ]);
    exit;
}

$mode     = trim($_POST['mode']     ?? 'chat');
$message  = trim($_POST['message']  ?? '');
$content  = trim($_POST['content']  ?? '');
$selected = trim($_POST['selected'] ?? '');

// ── Saved enrichment prompts ────────────────────────────────────────────────
if ($mode === 'prompt_list') {
    echo json_encode(['ok' => true, 'library' => snap_ai_enrichment_library($pdo)]);
    exit;
}

if ($mode === 'prompt_save') {
    $type      = trim($_POST['target'] ?? '');
    $id        = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['prompt_id'] ?? ''));
    $name      = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
    $promptText = mb_substr(trim($_POST['prompt'] ?? ''), 0, 4000);
    $preferred = ($_POST['preferred'] ?? '0') === '1';
    if (!in_array($type, ['title', 'caption', 'hashtags'], true) || $name === '' || $promptText === '') {
        echo json_encode(['ok' => false, 'error' => 'A field, name, and prompt are required.']);
        exit;
    }

    $library = snap_ai_enrichment_library($pdo);
    $existing = $id !== '' ? snap_ai_enrichment_prompt($library, $id, $type) : null;
    if ($existing && !empty($existing['builtin'])) $id = '';
    if ($id === '') $id = 'custom-' . bin2hex(random_bytes(8));

    $saved = false;
    foreach ($library['prompts'] as &$item) {
        if ($item['id'] !== $id) continue;
        $item = ['id' => $id, 'type' => $type, 'name' => $name, 'prompt' => $promptText, 'builtin' => false];
        $saved = true;
        break;
    }
    unset($item);
    if (!$saved) {
        $library['prompts'][] = ['id' => $id, 'type' => $type, 'name' => $name, 'prompt' => $promptText, 'builtin' => false];
    }
    if ($preferred) {
        $library['preferred'][$type] = $id;
    } elseif (($library['preferred'][$type] ?? '') === $id) {
        $defaults = snap_ai_enrichment_defaults();
        $library['preferred'][$type] = $defaults['preferred'][$type];
    }
    snap_ai_enrichment_save_library($pdo, $library);
    echo json_encode(['ok' => true, 'prompt_id' => $id, 'library' => snap_ai_enrichment_library($pdo)]);
    exit;
}

if ($mode === 'prompt_prefer') {
    $type = trim($_POST['target'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['prompt_id'] ?? ''));
    $preferred = ($_POST['preferred'] ?? '0') === '1';
    $library = snap_ai_enrichment_library($pdo);
    $prompt = snap_ai_enrichment_prompt($library, $id, $type);
    if (!$prompt || !in_array($type, ['title', 'caption', 'hashtags'], true)) {
        echo json_encode(['ok' => false, 'error' => 'That prompt is not available for this field.']);
        exit;
    }
    if ($preferred) {
        $library['preferred'][$type] = $id;
    } elseif (($library['preferred'][$type] ?? '') === $id) {
        $defaults = snap_ai_enrichment_defaults();
        $library['preferred'][$type] = $defaults['preferred'][$type];
    }
    snap_ai_enrichment_save_library($pdo, $library);
    echo json_encode(['ok' => true, 'library' => snap_ai_enrichment_library($pdo)]);
    exit;
}

if ($mode === 'prompt_delete') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['prompt_id'] ?? ''));
    $library = snap_ai_enrichment_library($pdo);
    $found = null;
    foreach ($library['prompts'] as $prompt) {
        if ($prompt['id'] === $id) $found = $prompt;
    }
    if (!$found || !empty($found['builtin'])) {
        echo json_encode(['ok' => false, 'error' => 'That prompt cannot be deleted.']);
        exit;
    }
    $library['prompts'] = array_values(array_filter($library['prompts'], static function (array $prompt) use ($id): bool {
        return $prompt['id'] !== $id;
    }));
    $defaults = snap_ai_enrichment_defaults();
    foreach ($library['preferred'] as $type => $preferredId) {
        if ($preferredId === $id) $library['preferred'][$type] = $defaults['preferred'][$type];
    }
    snap_ai_enrichment_save_library($pdo, $library);
    echo json_encode(['ok' => true, 'library' => snap_ai_enrichment_library($pdo)]);
    exit;
}

// ── Field-specific enrichment ───────────────────────────────────────────────
if ($mode === 'enrich') {
    $type = trim($_POST['target'] ?? '');
    if (!in_array($type, ['title', 'caption', 'hashtags'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Unknown enrichment field.']);
        exit;
    }
    $current = mb_substr(trim($_POST['current'] ?? ''), 0, 12000);
    $context = json_decode((string)($_POST['context'] ?? ''), true);
    if (!is_array($context)) $context = [];

    $library = snap_ai_enrichment_library($pdo);
    $promptId = trim($_POST['prompt_id'] ?? '') ?: ($library['preferred'][$type] ?? '');
    $prompt = snap_ai_enrichment_prompt($library, $promptId, $type);
    if (!$prompt) {
        echo json_encode(['ok' => false, 'error' => 'The selected prompt no longer exists.']);
        exit;
    }

    $format = $type === 'hashtags'
        ? 'Return ONLY space-separated hashtags. Every tag must start with #. Do not include commentary.'
        : 'Return ONLY the finished text with no preamble, quotation marks, alternatives, or commentary.';
    $system = "You are SnapSmack's field-specific writing assistant. {$format}\n\n"
            . "The site owner selected these instructions:\n{$prompt['prompt']}";
    $user = "TARGET FIELD: {$type}\n"
          . "CURRENT VALUE:\n{$current}\n\n"
          . "POST TITLE:\n" . mb_substr((string)($context['title'] ?? ''), 0, 1000) . "\n\n"
          . "POST CAPTION OR BODY:\n" . mb_substr((string)($context['caption'] ?? ''), 0, 8000) . "\n\n"
          . "CURRENT HASHTAGS:\n" . mb_substr((string)($context['hashtags'] ?? ''), 0, 2000);
    $result = snap_ai_complete($system, $user, $type === 'caption' ? 2048 : 512);
    if ($result['ok'] && $type === 'hashtags') {
        preg_match_all('/#[\p{L}\p{N}_-]+/u', $result['text'], $matches);
        $result['text'] = implode(' ', array_values(array_unique($matches[0] ?? [])));
    }
    echo json_encode($result);
    exit;
}

// ── Vision enrichment (analyse the actual image) ─────────────────────────────
// The in-CMS counterpart to the SYBU desktop batch tool: sends the selected
// image to the configured vision model with SYBU's EXACT prompt and fills every
// field at once (title, caption, tags, and matching categories/albums). The
// client downscales the image to ~600px before upload — vision is billed by
// image size and a thumb carries all the detail these fields need.
if ($mode === 'vision') {
    $raw  = (string)($_POST['image'] ?? '');
    // Accept a bare base64 payload or a data: URI.
    if (preg_match('/^data:([^;]+);base64,(.*)$/s', $raw, $m)) {
        $mime = $m[1];
        $b64  = $m[2];
    } else {
        $mime = trim($_POST['mime'] ?? 'image/jpeg');
        $b64  = $raw;
    }
    $b64 = preg_replace('/\s+/', '', $b64);
    if ($b64 === '' || strlen($b64) > 12_000_000) {
        echo json_encode(['ok' => false, 'error' => $b64 === ''
            ? 'Choose an image first — vision enrichment reads the photo itself.'
            : 'That image is too large to enrich. Try a smaller file.']);
        exit;
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) $mime = 'image/jpeg';

    // Gather the site's taxonomy + tag vocabulary, exactly as SYBU is fed it.
    $categories = $albums = [];
    $cat_desc = $album_desc = [];
    $cat_id_by = $album_id_by = [];
    foreach ($pdo->query("SELECT id, cat_name, cat_description FROM snap_categories ORDER BY cat_name") as $r) {
        $categories[] = $r['cat_name'];
        $cat_desc[mb_strtolower($r['cat_name'])] = (string)($r['cat_description'] ?? '');
        $cat_id_by[mb_strtolower(trim($r['cat_name']))] = (int)$r['id'];
    }
    foreach ($pdo->query("SELECT id, album_name, album_description FROM snap_albums ORDER BY album_name") as $r) {
        $albums[] = $r['album_name'];
        $album_desc[mb_strtolower($r['album_name'])] = (string)($r['album_description'] ?? '');
        $album_id_by[mb_strtolower(trim($r['album_name']))] = (int)$r['id'];
    }
    $existing_tags = [];
    foreach ($pdo->query("SELECT tag FROM snap_tags ORDER BY use_count DESC, tag ASC LIMIT 200") as $r) {
        $existing_tags[] = $r['tag'];
    }

    $prompt = snap_ai_vision_prompt($categories, $albums, $cat_desc, $album_desc, $existing_tags);
    $result = snap_ai_vision('', $prompt, [['mime' => $mime, 'data' => $b64]], 800);
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?: 'The vision request failed.']);
        exit;
    }

    $parsed = snap_ai_vision_parse($result['text']);

    // Map the returned CATEGORY / ALBUM names back to existing IDs (exact,
    // case-insensitive) so the editor can tick the right boxes. Names the model
    // invented despite the "do not invent" rule simply find no match.
    $names_to_ids = static function (string $csv, array $map): array {
        $ids = [];
        foreach (explode(',', $csv) as $name) {
            $key = mb_strtolower(trim($name));
            if ($key !== '' && isset($map[$key])) $ids[] = $map[$key];
        }
        return array_values(array_unique($ids));
    };

    echo json_encode([
        'ok'           => true,
        'title'        => $parsed['title'],
        'caption'      => $parsed['caption'],
        'alt'          => $parsed['alt'],
        'tags'         => $parsed['tags'],
        'colors'       => $parsed['colors'],
        'category_ids' => $names_to_ids($parsed['category'], $cat_id_by),
        'album_ids'    => $names_to_ids($parsed['album'], $album_id_by),
    ]);
    exit;
}

// ── Spell / Grammar check ────────────────────────────────────────────────────
if ($mode === 'spellcheck') {
    $target = $selected !== '' ? $selected : $content;
    if ($target === '') {
        echo json_encode(['ok' => false, 'error' => 'Nothing to check — write something first.']);
        exit;
    }

    $system = <<<PROMPT
You are a precise copy editor. The user will give you a block of text.
Correct all spelling mistakes and grammatical errors. Keep the author's
voice, style, and intent completely intact. Do not rewrite, rephrase,
or add anything — only fix actual errors. Return ONLY the corrected text
with no commentary, preamble, or explanation.
PROMPT;

    $result = snap_ai_complete($system, $target, 2048);
    echo json_encode($result);
    exit;
}

// ── Chat / writing assist ────────────────────────────────────────────────────
if ($message === '') {
    echo json_encode(['ok' => false, 'error' => 'No message provided.']);
    exit;
}

$context_block = $content !== ''
    ? "\n\nThe author's current text is:\n---\n" . mb_substr($content, 0, 3000) . "\n---"
    : '';

$system = <<<PROMPT
You are a skilled writing assistant embedded in a photo blog editor called SnapSmack.
Help the author improve their post — rephrase sentences, suggest better wording,
define words in context, tighten prose, fix awkward phrasing, or answer questions
about writing style. Be concise and practical. When providing rewritten text, provide
it as clean text the author can drop straight into their post.{$context_block}
PROMPT;

$result = snap_ai_complete($system, $message, 1024);
echo json_encode($result);
// ===== SNAPSMACK EOF =====
