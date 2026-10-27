<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * SNAPSMACK - Forum post bodies, with images where the author put them.
 *
 * A forum post could carry up to eight images and had no way to say where any
 * of them belonged. The body was rendered as nl2br(htmlspecialchars($body))
 * and every picture was stacked underneath it, in upload order. Fine for "here
 * is what I see". Useless for three steps with a picture after each one, which
 * is most of what a support board is for.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not accept HTML, and it does not
 * accept a URL. Both would let a post reach off this server: a remote <img>
 * hands the IP of everyone who reads the thread to whoever hosts it, and can
 * be swapped for something else after a moderator has read the post. The forum
 * API already refuses that at the other end -- it re-encodes every upload,
 * discards EXIF, and serves the result from its own uploads directory.
 *
 * So an author places an image they have ALREADY attached, by its number:
 *
 *     Here is the setting.
 *     [img:1]
 *     And here is what it does.
 *     [img:2]
 *
 * Attachments are numbered from 1 in the order the API returns them, which is
 * upload order and stable. A number with no attachment behind it is left as
 * the author typed it rather than silently vanishing -- a post that says
 * [img:4] when three images are attached is a mistake worth seeing. Images the
 * body never names still appear below the text exactly as they always have, so
 * nobody who ignores this loses anything.
 */

if (!function_exists('snapsmack_forum_inline_image_numbers')) {
    /**
     * The 1-based attachment numbers this body places inline, in order of
     * first appearance. Used by a caller to work out which attachments are
     * left over and still belong under the post.
     *
     * @return int[]
     */
    function snapsmack_forum_inline_image_numbers(string $body): array
    {
        if (preg_match_all('/\[img:(\d{1,2})\]/', $body, $m) < 1) return [];
        $seen = [];
        foreach ($m[1] as $digits) {
            $n = (int)$digits;
            if ($n >= 1 && !in_array($n, $seen, true)) $seen[] = $n;
        }
        return $seen;
    }
}

if (!function_exists('snapsmack_forum_post_body')) {
    /**
     * Render one post body as safe HTML, with its placed images in place.
     *
     * The body is escaped FIRST and the placeholders are substituted into the
     * escaped text, so nothing an author types can become markup. The only
     * HTML this function emits is the <br> from nl2br and the figure it builds
     * itself from an attachment the API already validated.
     *
     * @param array $attachments The post's attachments as the API returns
     *                           them: a list of ['url' => ..., 'alt' => ...].
     */
    function snapsmack_forum_post_body(string $body, array $attachments): string
    {
        $attachments = array_values($attachments);
        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        return (string)preg_replace_callback(
            '/\[img:(\d{1,2})\]/',
            static function (array $m) use ($attachments): string {
                $n = (int)$m[1];
                $att = $attachments[$n - 1] ?? null;
                // No such attachment: leave the author's text alone. A silent
                // disappearance is how someone ends up swearing the image was
                // there when they posted it.
                if ($n < 1 || !is_array($att) || ($att['url'] ?? '') === '') return $m[0];

                $url = htmlspecialchars((string)$att['url'], ENT_QUOTES, 'UTF-8');
                $alt = htmlspecialchars((string)($att['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
                return '<a href="' . $url . '" target="_blank" rel="noopener"'
                     . ' class="forum-post__inline-image">'
                     . '<img src="' . $url . '" alt="' . $alt . '" loading="lazy">'
                     . '</a>';
            },
            $escaped
        );
    }
}

if (!function_exists('snapsmack_forum_trailing_attachments')) {
    /**
     * The attachments that belong under the post: everything the body did not
     * place itself. A post that places none keeps the old behaviour exactly.
     */
    function snapsmack_forum_trailing_attachments(string $body, array $attachments): array
    {
        $placed = snapsmack_forum_inline_image_numbers($body);
        if ($placed === []) return array_values($attachments);
        $out = [];
        foreach (array_values($attachments) as $i => $att) {
            if (!in_array($i + 1, $placed, true)) $out[] = $att;
        }
        return $out;
    }
}

// ===== SNAPSMACK EOF =====
