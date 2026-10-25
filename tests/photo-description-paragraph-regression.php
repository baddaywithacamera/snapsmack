<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/trusted-html.php';

function paragraph_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$plain = (string)snapsmack_photo_description_html("First paragraph.\n\nSecond paragraph with <a href=\"https://example.com\">a link</a>.\n\nThird paragraph.");
paragraph_check(substr_count($plain, '<p>') === 3, 'Plain photo descriptions did not retain three paragraphs.');
paragraph_check(str_contains($plain, '<p>Second paragraph with <a href="https://example.com"'), 'Inline links were not retained inside a restored paragraph.');

$mixed = (string)snapsmack_photo_description_html('Opening paragraph.<p>Accepting my faults is an ongoing process.</p><p>Even me.</p>');
paragraph_check(str_starts_with($mixed, '<p>Opening paragraph.</p><p>Accepting'), 'A mixed imported description did not restore its bare opening paragraph.');
paragraph_check(substr_count($mixed, '<p>') === 3, 'Existing paragraph markup was damaged while restoring the opening paragraph.');

$unsafe = (string)snapsmack_photo_description_html("Safe.\n\n<script>alert(1)</script>Still safe.");
paragraph_check(!str_contains($unsafe, '<script'), 'Description paragraph repair bypassed HTML sanitization.');

echo "photo description paragraph regression: ok\n";
