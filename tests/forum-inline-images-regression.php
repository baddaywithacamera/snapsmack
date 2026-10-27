<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A forum post puts its pictures where the author put them, and stays safe.
 *
 * Sean: "images should be inline." A post could carry eight images and had no
 * way to say where any of them belonged -- the body was rendered as
 * nl2br(htmlspecialchars($body)) and every picture was stacked underneath, in
 * upload order. Fine for "here is what I see", useless for three steps with a
 * picture after each one, which is most of what a support board is for.
 *
 * The placement is by attachment NUMBER, never a URL and never HTML, because
 * either of those lets a post reach off this server: a remote <img> hands the
 * IP of everyone reading the thread to whoever hosts it, and can be swapped
 * for something else after a moderator has read the post.
 *
 * That is the part worth a gate. A renderer that starts accepting markup is
 * stored XSS on a page every blog owner on the fleet reads.
 */

$root = dirname(__DIR__);
require_once $root . '/core/forum-post-body.php';

$fail = 0;
function fi_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

$atts = [
    ['url' => '/api/forum/uploads/a.jpg', 'alt' => 'first.jpg'],
    ['url' => '/api/forum/uploads/b.jpg', 'alt' => 'second.jpg'],
];

// ── It does the thing it is for ──────────────────────────────────────────────
$body = "Open the panel.\n[img:1]\nThen press save.\n[img:2]";
$html = snapsmack_forum_post_body($body, $atts);
fi_test(str_contains($html, 'src="/api/forum/uploads/a.jpg"')
     && str_contains($html, 'src="/api/forum/uploads/b.jpg"'),
    'a placed image is rendered where the author put it');
fi_test(strpos($html, 'a.jpg') < strpos($html, 'Then press save'),
    'image 1 comes before the text that follows it');
fi_test(!str_contains($html, '[img:1]'),
    'the placeholder itself does not survive into the page');

// Anything not placed still appears under the post, exactly as before.
fi_test(snapsmack_forum_trailing_attachments($body, $atts) === [],
    'images the body placed are not repeated underneath');
fi_test(count(snapsmack_forum_trailing_attachments("Just text.", $atts)) === 2,
    'a post that places nothing keeps both images underneath, as it always did');
fi_test(snapsmack_forum_trailing_attachments("[img:2] only", $atts)
        === [['url' => '/api/forum/uploads/a.jpg', 'alt' => 'first.jpg']],
    'placing the second image leaves the first one underneath');

// ── It cannot be used to inject anything ─────────────────────────────────────
$nasty = '<script>alert(1)</script> <img src=x onerror=alert(1)> <b>bold</b>';
$out = snapsmack_forum_post_body($nasty, $atts);
// The words survive as TEXT -- "onerror" reading back is correct and safe.
// What must not survive is a tag. The only '<' this renderer may emit is its
// own: the <br> from nl2br and the <a>/<img> it builds from an attachment.
$tags = [];
preg_match_all('/<([a-zA-Z\/][^>]*)>/', $out, $tagMatches);
foreach ($tagMatches[0] as $tag) $tags[] = $tag;
fi_test($tags === [],
    'HTML typed into a post produces no tags at all: ' . ($tags ? implode(' ', $tags) : 'none'));
fi_test(str_contains($out, '&lt;script&gt;') && str_contains($out, '&lt;b&gt;'),
    'and it is still readable as the text the author typed');
fi_test(substr_count($out, '<img') === 0,
    'an <img> tag typed by hand produces no image element at all');

// A URL in the placeholder is not a URL. Only a number is accepted, so there
// is no form of this syntax that can point off the server.
foreach (['[img:https://evil.test/x.jpg]', '[img:../../etc/passwd]', '[img:a.jpg]'] as $attempt) {
    $r = snapsmack_forum_post_body("look {$attempt} here", $atts);
    fi_test(!str_contains($r, '<img'),
        "a placeholder carrying \"{$attempt}\" renders no image");
}

// A number with nothing behind it stays visible rather than vanishing: a post
// that says [img:4] with three images attached is a mistake worth seeing.
$missing = snapsmack_forum_post_body('see [img:9]', $atts);
fi_test(str_contains($missing, '[img:9]') && !str_contains($missing, '<img'),
    'a number with no attachment behind it is left as the author typed it');

// Alt text comes from the stored original filename and is escaped with it.
$quoted = snapsmack_forum_post_body('[img:1]', [['url' => '/u/a.jpg', 'alt' => 'he said "hi".jpg']]);
fi_test(!str_contains($quoted, 'said "hi".jpg"'),
    'a quote in a filename cannot break out of the alt attribute');

// ── Both forum screens use the shared renderer ───────────────────────────────
foreach (['smack-forum.php' => 'the site forum', 'smack-central/sc-forum.php' => 'SMACK CENTRAL'] as $file => $what) {
    $src = (string)file_get_contents($root . '/' . $file);
    fi_test(str_contains($src, 'snapsmack_forum_post_body(')
         && str_contains($src, 'snapsmack_forum_trailing_attachments('),
        "{$what} renders post bodies through the shared renderer");
    fi_test(!preg_match("/nl2br\(htmlspecialchars\(\\$(thread|r)\['body'\]\)\)/", $src),
        "{$what} no longer renders a body straight to the page");
}

// SMACK CENTRAL showed no attachments AT ALL before this: it read the forum
// database directly and had no attachment query, so a screenshot posted from a
// site was dropped in silence on the one screen the hub owner reads.
$sc = (string)file_get_contents($root . '/smack-central/sc-forum.php');
fi_test(str_contains($sc, 'function sc_forum_attachments(')
     && str_contains($sc, 'ss_forum_attachments'),
    'SMACK CENTRAL loads attachments instead of dropping them silently');

if ($fail > 0) {
    fwrite(STDERR, "Forum inline images are wrong or unsafe. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Forum inline images: PASS.\n";

// ===== SNAPSMACK EOF =====
