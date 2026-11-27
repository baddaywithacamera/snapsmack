<?php
declare(strict_types=1);

/**
 * SNAPSMACK - the two-variant signature contract, in one place.
 *
 * WHY THIS FILE EXISTS. The contract lived inside core/smackpress-api.php,
 * which reads a route and authenticates at the top level, so a test cannot
 * require it: including the file runs the API. Every rule below was therefore
 * unexecutable by the suite, which is how the old reader kept a bug nobody
 * tripped over. Same correction as core/duplicate-detection.php.
 *
 * THE CONTRACT. Three fields travel with a longform post:
 *
 *   signature_image_id         legacy, back-compat, follows the LIGHT surface
 *   signature_light_image_id   the light surface
 *   signature_dark_image_id    the dark surface
 *
 * Each has FOUR states and they are not interchangeable:
 *
 *   omitted   preserve whatever is stored. A client re-pulling a post to fix
 *             its text must not clear a signature somebody chose.
 *   null      inherit the blog default.
 *   0         explicit opt-out. This post carries no signature.
 *   an id     that image.
 *
 * THE BUG THIS REPLACES. The old reader was
 *
 *     !empty($body['signature_image_id']) ? (int)$body[...] : null
 *
 * so a client sending 0 got null. While null meant "no signature" that was
 * harmless. The moment null means "inherit" it is the OPPOSITE of what the
 * author asked for: declining a signature would have produced the blog's.
 * Two of the four states were collapsed into one and nothing could see it.
 *
 * WHAT IS DELIBERATELY NOT HERE. Resolving "inherit" to an actual image. There
 * is no per-blog signature anywhere in the product -- no setting, no column --
 * so inherit currently resolves to nothing. Writing a resolver against a
 * default that does not exist would be a control that does nothing, which is
 * the fault class this whole contract came out of. snap_signature_inherit_is_
 * resolvable() says so out loud instead, so a caller can tell a client the
 * truth rather than silently returning no signature.
 *
 * ALSO NOT HERE. The legacy column keeps TODAY's meaning: NULL in
 * signature_image_id is "no signature on this post", which is what every post
 * on every live site currently holds. If NULL there started meaning "inherit",
 * every post ever written would gain a signature the moment an owner set a
 * default. Only the two NEW columns carry the new meaning, so nothing already
 * published moves and no data has to be rewritten.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** The legacy field, which follows the light surface. */
const SNAP_SIGNATURE_LEGACY_FIELD = 'signature_image_id';

/** The two variant columns, in the order they are written. */
const SNAP_SIGNATURE_VARIANT_FIELDS = [
    'signature_light_image_id',
    'signature_dark_image_id',
];

/**
 * Can "inherit" resolve to anything yet?
 *
 * False, and it will stay false until a per-blog signature exists. Kept as a
 * function rather than a comment so a caller can say so to a client instead of
 * quietly returning no signature.
 */
function snap_signature_inherit_is_resolvable(): bool {
    return false;
}

/**
 * One signature field, read under the four-state contract.
 *
 * @param array    $body   The decoded request body.
 * @param string   $key    The field name.
 * @param callable $refuse Called with a message when the value is none of the
 *                         four states. It must not return.
 * @return array{sent: bool, value: int|null}
 *
 * WHY '' IS AN OPT-OUT AND NOT AN INHERIT. An empty string is what a cleared
 * form control sends, and under the old reader it became null, which meant "no
 * signature on this post". Mapping it to 0 keeps exactly that effect now that
 * 0 is how no-signature is spelled. Mapping it to null would change what
 * clearing a field does, and nobody asked for that.
 *
 * A negative or non-numeric value is REFUSED rather than coerced. It is none of
 * the four states, and reading it as one of them is how a control ends up doing
 * something its caller never asked for.
 */
function snap_signature_field(array $body, string $key, callable $refuse): array {
    if (!array_key_exists($key, $body)) {
        return ['sent' => false, 'value' => null];        // preserve
    }
    $raw = $body[$key];
    if ($raw === null) {
        return ['sent' => true, 'value' => null];         // inherit
    }
    if ($raw === '' || $raw === 0 || $raw === '0' || $raw === false) {
        return ['sent' => true, 'value' => 0];            // explicit opt-out
    }
    // ctype_digit already excludes a signed string, but a NEGATIVE INT passes
    // is_int, so it needs its own guard. The first version of this file lost
    // that guard in the move out of the API and -3 went straight through as an
    // id; the regression caught it. Fail closed on anything left over.
    if ((!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) || (int)$raw < 0) {
        $refuse("Field {$key} must be null, 0, or an image id.");
        return ['sent' => true, 'value' => 0];            // $refuse should not return
    }
    return ['sent' => true, 'value' => (int)$raw];
}

/**
 * All three signature fields, read from one body.
 *
 * @return array<string, array{sent: bool, value: int|null}>
 */
function snap_signature_read(array $body, callable $refuse): array {
    $fields = [SNAP_SIGNATURE_LEGACY_FIELD =>
        snap_signature_field($body, SNAP_SIGNATURE_LEGACY_FIELD, $refuse)];
    foreach (SNAP_SIGNATURE_VARIANT_FIELDS as $name) {
        $fields[$name] = snap_signature_field($body, $name, $refuse);
    }
    return $fields;
}

/**
 * Every id the caller actually sent, so they can be checked against the image
 * table before anything is stored. Omitted, inherit and opt-out contribute
 * nothing: there is no id to look for.
 *
 * @return array<string, int> field name => id
 */
function snap_signature_ids_to_verify(array $fields): array {
    $ids = [];
    foreach ($fields as $name => $field) {
        if (!empty($field['sent']) && !empty($field['value'])) {
            $ids[$name] = (int)$field['value'];
        }
    }
    return $ids;
}

/**
 * The SQL for the two variant columns, written ONLY where the caller sent them.
 * Same shape the colophon and the portfolio designation use, so a re-pull of a
 * post's text cannot clear a signature.
 *
 * @param bool $insert true for an INSERT (columns + placeholders), false for an
 *                     UPDATE (assignments).
 * @return array{sql: string, marks: string, params: array<int, int|null>}
 */
function snap_signature_write_sql(array $fields, bool $insert): array {
    $sql    = '';
    $marks  = '';
    $params = [];
    foreach (SNAP_SIGNATURE_VARIANT_FIELDS as $name) {
        if (empty($fields[$name]['sent'])) { continue; }
        if ($insert) {
            $sql   .= ',' . $name;
            $marks .= ',?';
        } else {
            $sql .= ', ' . $name . '=?';
        }
        $params[] = $fields[$name]['value'];
    }
    return ['sql' => $sql, 'marks' => $marks, 'params' => $params];
}

/**
 * The two variants as they go back out to a client, UNCAST.
 *
 * (int)null is 0, and 0 is the explicit opt-out while null is inherit, so
 * casting hands the client the opposite of what is stored with no way to tell.
 * A client that reads a post and sends it straight back must get the same four
 * states out as it would put in.
 *
 * @return array<string, int|null>
 */
function snap_signature_response(array $row): array {
    $out = [];
    foreach (SNAP_SIGNATURE_VARIANT_FIELDS as $name) {
        $stored = $row[$name] ?? null;
        $out[$name] = ($stored === null) ? null : (int)$stored;
    }
    return $out;
}

// ===== SNAPSMACK EOF =====
