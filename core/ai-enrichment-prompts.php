<?php
/**
 * SNAPSMACK — saved prompt library for AI metadata enrichment.
 *
 * Stored as JSON in snap_settings.ai_enrichment_prompts. Built-ins remain in
 * code so a damaged or deleted setting always falls back to usable prompts.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

function snap_ai_enrichment_defaults(): array
{
    return [
        'prompts' => [
            [
                'id'      => 'builtin-title-faithful',
                'type'    => 'title',
                'name'    => 'Faithful title',
                'prompt'  => 'Improve the title without changing its meaning. Keep the author’s voice. Make it specific, natural, and concise. Do not use clickbait.',
                'builtin' => true,
            ],
            [
                'id'      => 'builtin-title-options',
                'type'    => 'title',
                'name'    => 'Stronger title',
                'prompt'  => 'Write the strongest single title supported by the supplied text. Preserve the author’s attitude and vocabulary. Return one title only.',
                'builtin' => true,
            ],
            [
                'id'      => 'builtin-caption-faithful',
                'type'    => 'caption',
                'name'    => 'Faithful polish',
                'prompt'  => 'Polish the caption or body while preserving the author’s voice, facts, profanity, humour, paragraphing, and intent. Tighten only where it helps. Do not sanitize the author.',
                'builtin' => true,
            ],
            [
                'id'      => 'builtin-caption-tight',
                'type'    => 'caption',
                'name'    => 'Tighten',
                'prompt'  => 'Make the writing tighter and clearer without flattening its personality. Remove repetition and weak phrasing. Preserve all facts and return only the revised text.',
                'builtin' => true,
            ],
            [
                'id'      => 'builtin-hashtags-discovery',
                'type'    => 'hashtags',
                'name'    => 'Useful discovery tags',
                'prompt'  => 'Generate a restrained set of relevant discovery hashtags from the title and caption. Prefer precise subject, place, medium, and community tags. Avoid spammy, invented, or unrelated tags.',
                'builtin' => true,
            ],
            [
                'id'      => 'builtin-hashtags-minimal',
                'type'    => 'hashtags',
                'name'    => 'Minimal tags',
                'prompt'  => 'Generate only the few most useful and defensible hashtags. Quality over quantity. Do not add a tag unless the supplied text supports it.',
                'builtin' => true,
            ],
        ],
        'preferred' => [
            'title'    => 'builtin-title-faithful',
            'caption'  => 'builtin-caption-faithful',
            'hashtags' => 'builtin-hashtags-discovery',
        ],
        'custom' => [],
    ];
}

function snap_ai_enrichment_library(PDO $pdo): array
{
    $defaults = snap_ai_enrichment_defaults();
    try {
        $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_enrichment_prompts' LIMIT 1");
        $stmt->execute();
        $stored = json_decode((string)($stmt->fetchColumn() ?: ''), true);
    } catch (Throwable $e) {
        $stored = null;
    }

    if (!is_array($stored)) {
        return [
            'prompts'   => $defaults['prompts'],
            'preferred' => $defaults['preferred'],
        ];
    }

    $types = ['title', 'caption', 'hashtags'];
    $custom = [];
    foreach (($stored['custom'] ?? []) as $prompt) {
        if (!is_array($prompt)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($prompt['id'] ?? ''));
        $type = (string)($prompt['type'] ?? '');
        $name = trim((string)($prompt['name'] ?? ''));
        $text = trim((string)($prompt['prompt'] ?? ''));
        if ($id === '' || !in_array($type, $types, true) || $name === '' || $text === '') continue;
        $custom[] = [
            'id'      => $id,
            'type'    => $type,
            'name'    => mb_substr($name, 0, 80),
            'prompt'  => mb_substr($text, 0, 4000),
            'builtin' => false,
        ];
    }

    $preferred = $defaults['preferred'];
    foreach ($types as $type) {
        $candidate = (string)($stored['preferred'][$type] ?? '');
        if ($candidate !== '') $preferred[$type] = $candidate;
    }

    $all = array_merge($defaults['prompts'], $custom);
    $idsByType = [];
    foreach ($all as $prompt) $idsByType[$prompt['type']][] = $prompt['id'];
    foreach ($types as $type) {
        if (!in_array($preferred[$type], $idsByType[$type] ?? [], true)) {
            $preferred[$type] = $defaults['preferred'][$type];
        }
    }

    return ['prompts' => $all, 'preferred' => $preferred];
}

function snap_ai_enrichment_save_library(PDO $pdo, array $library): void
{
    $custom = array_values(array_filter($library['prompts'], static function (array $prompt): bool {
        return empty($prompt['builtin']);
    }));
    $payload = json_encode([
        'custom'    => $custom,
        'preferred' => $library['preferred'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ai_enrichment_prompts', ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $stmt->execute([$payload]);
}

function snap_ai_enrichment_prompt(array $library, string $id, string $type): ?array
{
    foreach ($library['prompts'] as $prompt) {
        if ($prompt['id'] === $id && $prompt['type'] === $type) return $prompt;
    }
    return null;
}

// ===== SNAPSMACK EOF =====
