<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/core/public-repository.php');
$required = [
    'photographBySlug', 'photographById', 'postBySlug', 'postById',
    'photographsForPost', 'photographLanding', 'longformLanding', 'activePages',
    'activePageBySlug', 'archivePhotographs', 'search', 'hashtagPhotographs',
    'adjacentPosts', 'approvedComments', 'publicAlbums', 'publicCollections',
    'collectionPhotographs', 'publishedPostCount',
];
foreach ($required as $method) {
    if (!str_contains($source, 'function ' . $method . '(')) {
        throw new RuntimeException("Missing public repository surface: {$method}");
    }
}
if (preg_match('/SELECT\s+(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?\*/i', $source)) {
    throw new RuntimeException('Public repository contains SELECT *.');
}
if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b[\s\S]*\b(?:INTO|TABLE|SET|FROM)\b/i', $source)) {
    throw new RuntimeException('Public repository contains a mutation statement.');
}

foreach (token_get_all($source) as $token) {
    if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || !preg_match('/\bSELECT\b/i', $token[1])) continue;
    $sql = $token[1];
    if (preg_match('/\b(?:FROM|JOIN)\s+snap_posts\b/i', $sql)
        && (!preg_match('/\bstatus\s*=\s*["\']published["\']/i', $sql) || !preg_match('/\bcreated_at\s*<=/i', $sql))) {
        throw new RuntimeException("Post query lacks publication boundary at line {$token[2]}.");
    }
    if (preg_match('/\b(?:FROM|JOIN)\s+snap_images\b/i', $sql)
        && (!preg_match('/\bimg_status\s*=\s*["\']published["\']/i', $sql) || !preg_match('/\bimg_date\s*<=/i', $sql))) {
        throw new RuntimeException("Image query lacks publication boundary at line {$token[2]}.");
    }
    if (preg_match('/\b(?:FROM|JOIN)\s+snap_pages\b/i', $sql) && !preg_match('/\bis_active\s*=\s*1\b/i', $sql)) {
        throw new RuntimeException("Page query lacks active boundary at line {$token[2]}.");
    }
    if (preg_match('/\b(?:FROM|JOIN)\s+snap_comments\b/i', $sql)
        && (!preg_match('/\bis_approved\s*=\s*1\b/i', $sql) || !preg_match('/\bis_spam\s*=\s*0\b/i', $sql))) {
        throw new RuntimeException("Comment query lacks moderation boundary at line {$token[2]}.");
    }
    if (preg_match('/\b(?:FROM|JOIN)\s+snap_collections\b/i', $sql) && !preg_match('/\bpublished\s*=\s*1\b/i', $sql)) {
        throw new RuntimeException("Collection query lacks publication boundary at line {$token[2]}.");
    }
}
echo "Public repository boundary regression passed.\n";
