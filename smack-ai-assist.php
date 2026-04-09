<?php
/**
 * SNAPSMACK - AI Writing Assistant
 * Alpha v0.7.9c
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

require_once 'core/auth.php';
require_once 'core/ai-provider.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

if (!snap_ai_configured()) {
    echo json_encode(['ok' => false, 'error' => 'No AI provider configured.']);
    exit;
}

$mode     = trim($_POST['mode']     ?? 'chat');
$message  = trim($_POST['message']  ?? '');
$content  = trim($_POST['content']  ?? '');
$selected = trim($_POST['selected'] ?? '');

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
