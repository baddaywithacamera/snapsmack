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

/**
 * Image-aware whole-post prompt used by the CMS editor. This deliberately
 * mirrors SYBU's metadata contract so repair enrichment produces the same
 * shape of title, caption, tags, categories, albums, and colours.
 */
function snap_ai_post_enrichment_default_prompt(): string
{
    return <<<'PROMPT'
You are generating metadata for a photo blog post on a site called SnapSmack.

Analyse the image carefully and respond ONLY in this exact format — no extra text:

TITLE: <a short, plain, descriptive title — a few words; not poetic>
CAPTION: <a short, natural one-line caption describing the image; no hashtags>
ALT: <one plain sentence of screen-reader ALT text — lead with the main subject, concrete, no "image of"/"photo of">
TAGS: <5 to 8 space-separated hashtags>
CATEGORY: <every applicable exact option from the supplied category list, comma-separated, or blank>
ALBUM: <every applicable exact option from the supplied album list, comma-separated, or blank>
COLORS: <the three most prominent colours as uppercase #RRGGBB codes, space-separated>

Rules:
- Describe what is literally visible. Do not invent facts, places, people, categories, or albums.
- ALT is accessibility text: one plain sentence, lead with the subject, describe only what is visible, never begin with "image of"/"photo of", and do not just repeat the caption.
- Tags must be lowercase, begin with #, and contain no spaces.
- Distorted specular highlights with swirly blurred bokeh indicate a modified Helios 44 lens; add #helios and #helios44 when those characteristics are present.
- Use only exact category and album options supplied with the request.
- Return no explanation, preamble, markdown, or extra lines.
PROMPT;
}

function snap_ai_post_enrichment_prompt(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_post_enrichment_prompt' LIMIT 1");
        $stmt->execute();
        $saved = trim((string)($stmt->fetchColumn() ?: ''));
        if ($saved !== '') return $saved;
    } catch (Throwable $e) {
        // Built-in prompt remains available if the setting is absent.
    }
    return snap_ai_post_enrichment_default_prompt();
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

/**
 * Vision enrichment prompt — a byte-for-byte PHP port of SYBU's
 * gemini.py::_build_prompt(). "Same prompt exactly" is a hard requirement: this
 * text (including the Helios-44 rule, the COLORS line, and the category/album
 * interpolation) MUST stay identical to the desktop tool so the in-CMS "enrich
 * from image" produces the same metadata SnapSmack owners already trust from
 * SYBU. If you change one, change the other.
 *
 * @param string[] $categories          category names (cat_name)
 * @param string[] $albums              album names (album_name)
 * @param array    $cat_descriptions    map of lower(name) => description
 * @param array    $album_descriptions  map of lower(name) => description
 * @param string[] $existing_tags       hashtags already in use (soft matching)
 */
function snap_ai_vision_prompt(
    array $categories,
    array $albums,
    array $cat_descriptions = [],
    array $album_descriptions = [],
    array $existing_tags = []
): string {
    $list = static function (array $items, array $desc): string {
        if (!$items) return ' (none)';
        $lines = [];
        foreach ($items as $c) {
            $d = trim((string)($desc[mb_strtolower($c)] ?? ''));
            $lines[] = '  - ' . $c . ($d !== '' ? ' (' . $d . ')' : '');
        }
        return "\n" . implode("\n", $lines);
    };
    $cats_str   = $list($categories, $cat_descriptions);
    $albums_str = $list($albums, $album_descriptions);

    if ($existing_tags) {
        $tag_sample    = implode(' ', array_slice($existing_tags, 0, 80));
        $tags_guidance = "Prefer tags from this existing list where they fit: {$tag_sample}\n"
                       . "Invent new tags only when nothing in the list applies.";
    } else {
        $tags_guidance = 'Use descriptive hashtags for subject, texture, colour, and mood.';
    }

    return <<<PROMPT
You are generating metadata for a photo blog post on a site called SnapSmack.

Analyse the image carefully and respond ONLY in this exact format — no extra text:

TITLE: <a short, plain, descriptive title — a few words, e.g. "Drumheller water tower at dusk". NOT a haiku, not poetic.>
CAPTION: <a short, natural one-line caption describing the image — this becomes the post's caption/description. No hashtags.>
ALT: <one plain sentence of accessibility ALT text describing what is visibly in the frame for a screen-reader user — lead with the main subject, be concrete and specific, no "image of"/"photo of".>
TAGS: <5 to 8 space-separated hashtags, e.g. #stone #rust #texture #macro #urban>
CATEGORY: <pick every applicable match from this list, comma-separated, or leave blank:{$cats_str}>
ALBUM: <pick every applicable match from this list, comma-separated, or leave blank:{$albums_str}>
COLORS: <the three most visually prominent colors in the image as uppercase hex codes separated by spaces, e.g. #A3724B #2E1F0D #8C6B3A>

Rules:
- TITLE must be a short, plain description of what is literally in the image — not a haiku, not poetic
- CAPTION is the post's caption/description: one natural line, no hashtags
- ALT is accessibility text for screen readers: one plain sentence, lead with the main subject, describe only what is visibly in the frame, never begin with "image of" or "photo of", and do not just repeat the caption
- {$tags_guidance}
- Tags must be lowercase with no spaces within a tag
- Images with multiple distorted specular highlights and swirly blurred bokeh were made with a modified Helios 44 lens; add both #helios and #helios44 to TAGS when those visual characteristics are present
- CATEGORY may contain one or more exact options from the provided list, separated by commas, or be left completely blank
- ALBUM may contain one or more exact options from the provided list, separated by commas, or be left completely blank
- Do not repeat an option and do not invent categories or albums
- COLORS must be exactly 3 hex codes in #RRGGBB format, uppercase, space-separated
- Do not add any explanation, preamble, or extra lines
PROMPT;
}

/**
 * Parse a SYBU-format vision response — port of gemini.py::_parse_response().
 * Returns keys: title, caption, alt, tags, category, album, colors.
 */
function snap_ai_vision_parse(string $text): array
{
    $result = ['title' => '', 'caption' => '', 'alt' => '', 'tags' => '', 'category' => '', 'album' => '', 'colors' => ''];
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
        if (preg_match('/^(TITLE|CAPTION|ALT|TAGS|CATEGORY|ALBUM|COLORS):\s*(.*)/i', trim($line), $m)) {
            $result[strtolower($m[1])] = trim($m[2]);
        }
    }
    if ($result['colors'] !== '') {
        preg_match_all('/#[0-9A-Fa-f]{6}/', $result['colors'], $mm);
        $result['colors'] = implode(' ', array_map('strtoupper', array_slice($mm[0], 0, 3)));
    }
    return $result;
}

// ===== SNAPSMACK EOF =====
