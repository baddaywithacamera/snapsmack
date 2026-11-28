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

/** The blog-level default for each surface, as settings keys. */
const SNAP_SIGNATURE_DEFAULT_KEYS = [
    'light' => 'signature_default_light_image_id',
    'dark'  => 'signature_default_dark_image_id',
];

/** The per-user default for each surface, as snap_users column names. */
const SNAP_USER_SIGNATURE_DEFAULT_COLUMNS = [
    'light' => 'default_signature_light_image_id',
    'dark'  => 'default_signature_dark_image_id',
];

/**
 * Defensive ALTER for snap_users per-user default signature columns.
 * Idempotent: swallows MySQL errno 1060 (duplicate column) / 1091 and
 * SQLite "duplicate column name" errors.
 */
function snap_user_signature_ensure_columns(PDO $pdo): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $defs = [
        'default_signature_light_image_id' => $driver === 'sqlite'
            ? 'INTEGER DEFAULT NULL'
            : 'INT UNSIGNED DEFAULT NULL AFTER `ui_mode`',
        'default_signature_dark_image_id' => $driver === 'sqlite'
            ? 'INTEGER DEFAULT NULL'
            : 'INT UNSIGNED DEFAULT NULL AFTER `default_signature_light_image_id`',
    ];
    foreach ($defs as $col => $def) {
        try {
            $stmt = $pdo->query("ALTER TABLE `snap_users` ADD COLUMN `{$col}` {$def}");
            if ($stmt !== false) { $stmt->closeCursor(); }
        } catch (PDOException $e) {
            $errno = (int)($e->errorInfo[1] ?? 0);
            $msg   = $e->getMessage();
            if (!in_array($errno, [1060, 1091], true) && stripos($msg, 'duplicate column') === false) {
                throw $e;
            }
        }
    }
}

/**
 * A user's default signature for one surface, or 0 when unset.
 */
function snap_user_signature_default_for(array $user_row, string $surface): int {
    $col = SNAP_USER_SIGNATURE_DEFAULT_COLUMNS[$surface] ?? '';
    if ($col === '') { return 0; }
    $raw = $user_row[$col] ?? '';
    if (!is_int($raw) && !(is_string($raw) && ctype_digit(trim($raw)))) { return 0; }
    $id = (int)$raw;
    return $id > 0 ? $id : 0;
}

/**
 * The blog's default signature for one surface, or 0 when the owner has not
 * chosen one.
 *
 * A BLANK DEFAULT STAYS BLANK. Every site starts with both pickers empty, so
 * inherit resolves to no signature exactly as it did before this existed and
 * nothing already published changes. The vendor does not seed this on anybody's
 * site: a signature is the author's own mark, and one chosen for them would
 * arrive on sites that never asked for it.
 */
function snap_signature_default_for(array $settings, string $surface): int {
    $key = SNAP_SIGNATURE_DEFAULT_KEYS[$surface] ?? '';
    if ($key === '') { return 0; }
    $raw = $settings[$key] ?? '';
    if (!is_int($raw) && !(is_string($raw) && ctype_digit(trim($raw)))) { return 0; }
    $id = (int)$raw;
    return $id > 0 ? $id : 0;
}

/**
 * Can "inherit" resolve to anything?
 *
 * Only once the owner has set at least one default. Before that an author
 * choosing "use this blog's signature" would get nothing and no explanation,
 * which is the dead-control fault, so callers can say so instead.
 *
 * The settings array is required rather than optional on purpose: a default
 * argument here would let a caller that has not loaded the settings report
 * "not resolvable" on a site where it is, and be wrong in the quiet direction.
 */
function snap_signature_inherit_is_resolvable(array $settings): bool {
    foreach (array_keys(SNAP_SIGNATURE_DEFAULT_KEYS) as $surface) {
        if (snap_signature_default_for($settings, $surface) > 0) { return true; }
    }
    return false;
}

/** The surface a skin gets when it declares nothing. */
const SNAP_SIGNATURE_SURFACE_DEFAULT = 'light';

/**
 * Which surface this skin renders its signature on.
 *
 * Read from the skin manifest's declared "signature_surface", per Gemini's v2
 * contract and the PM's ruling of 2026-11-27, with light as the default.
 *
 * WHY THE SKIN DECIDES AND NOT THE READER'S BROWSER. A signature is ink on a
 * page: which of the two it needs depends on what the page is made of, and the
 * skin is the only thing that knows that. A prefers-color-scheme switch would
 * also mean the server could not know which image it had sent, so the stored
 * page and the rendered page would stop agreeing.
 *
 * ANYTHING THAT IS NOT light OR dark IS light. A manifest is hand-edited JSON:
 * a misspelt value, a nested array or a boolean must not reach the resolver as
 * a surface name, and must not produce an error on a reader's page either. The
 * default is the quiet, correct answer -- which is exactly why the two valid
 * values are listed here rather than inferred from whatever was typed.
 */
function snap_signature_surface(array $manifest): string {
    $declared = $manifest['signature_surface'] ?? null;
    if (!is_string($declared)) { return SNAP_SIGNATURE_SURFACE_DEFAULT; }
    $declared = strtolower(trim($declared));
    return in_array($declared, array_keys(SNAP_SIGNATURE_DEFAULT_KEYS), true)
        ? $declared
        : SNAP_SIGNATURE_SURFACE_DEFAULT;
}

/**
 * Clamp a posted blog-default value. Blank is a real choice -- it means the
 * owner wants no blog signature -- so it is stored as '' rather than refused,
 * and anything that is not a positive integer becomes blank rather than a
 * number this code invented.
 */
function snap_signature_default_sanitise($posted): string {
    if (is_int($posted)) { return $posted > 0 ? (string)$posted : ''; }
    $raw = trim((string)$posted);
    if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) { return ''; }
    return (string)(int)$raw;
}

/**
 * The two blog-default pickers, for the admin Configuration page.
 *
 * Choices come from snap_images, which is where every signature any post can
 * use already lives. An id that is no longer in the table is shown as a
 * missing-image warning rather than silently dropped, so an owner who deletes
 * the photograph their signature pointed at is told, not left wondering why it
 * stopped appearing.
 *
 * No static style attributes: the markup leans on the page's own classes.
 */
/**
 * Shared light/dark signature image picker renderer.
 *
 * @param array<string, int>    $current_by_surface surface => selected image id (0 = none)
 * @param array<string, string> $field_names        surface => HTML input name
 * @param string                $label_prefix       Label prefix before " — LIGHT SURFACE"
 * @param string                $tip                Tooltip copy for the field tip
 * @param string                $missing_hint       Hint shown when a stored image id is missing
 */
function snap_signature_render_pickers(
    PDO $pdo,
    array $current_by_surface,
    array $field_names,
    string $label_prefix,
    string $tip,
    string $missing_hint
): void {
    try {
        $images = $pdo->query(
            "SELECT id, img_title, img_file FROM snap_images
             WHERE post_id IS NULL AND img_status = 'published'
             ORDER BY img_date DESC, id DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $images = [];
    }
    $labels = ['light' => 'LIGHT', 'dark' => 'DARK'];
    foreach ($field_names as $surface => $input_name) {
        $current = (int)($current_by_surface[$surface] ?? 0);
        $known   = false;
        echo '<label>' . $label_prefix . ' &mdash; ' . $labels[$surface]
           . ' SURFACE <span class="field-tip" data-tip="' . htmlspecialchars($tip, ENT_QUOTES)
           . '">&#9432;</span></label>' . PHP_EOL;
        echo '<select name="' . htmlspecialchars($input_name, ENT_QUOTES) . '">' . PHP_EOL;
        echo '<option value=""' . ($current === 0 ? ' selected' : '')
           . '>&mdash; NONE &mdash;</option>' . PHP_EOL;
        foreach ($images as $image) {
            $id = (int)$image['id'];
            if ($id === $current) { $known = true; }
            $name = trim((string)($image['img_title'] ?? ''));
            if ($name === '') { $name = basename((string)($image['img_file'] ?? '')); }
            echo '<option value="' . $id . '"' . ($id === $current ? ' selected' : '') . '>'
               . htmlspecialchars('#' . $id . ' ' . $name) . '</option>' . PHP_EOL;
        }
        if ($current > 0 && !$known) {
            echo '<option value="' . $current . '" selected>#' . $current
               . ' &mdash; IMAGE NOT FOUND</option>' . PHP_EOL;
        }
        echo '</select>' . PHP_EOL;
        if ($current > 0 && !$known) {
            echo '<span class="dim text-0-75 mt-4 d-block">&#9888; IMAGE #' . $current
               . ' IS NO LONGER IN THE GALLERY &mdash; ' . $missing_hint . '</span>' . PHP_EOL;
        }
    }
}

function snap_signature_default_control(PDO $pdo, array $settings): void {
    $current = [];
    $fields  = [];
    foreach (SNAP_SIGNATURE_DEFAULT_KEYS as $surface => $key) {
        $current[$surface] = snap_signature_default_for($settings, $surface);
        $fields[$surface]  = 'settings[' . $key . ']';
    }
    snap_signature_render_pickers(
        $pdo,
        $current,
        $fields,
        'THIS BLOG&rsquo;S SIGNATURE',
        'Used by any post set to inherit this blog’s signature. Leave blank for no blog signature; posts that choose their own are unaffected either way.',
        'POSTS INHERITING IT SHOW NO SIGNATURE'
    );
}

/**
 * Per-user default signature pickers (light + dark surface) for the user's
 * own profile/settings screen.
 */
function snap_user_signature_default_control(PDO $pdo, array $user_row): void {
    $current = [];
    $fields  = [];
    foreach (SNAP_USER_SIGNATURE_DEFAULT_COLUMNS as $surface => $col) {
        $current[$surface] = snap_user_signature_default_for($user_row, $surface);
        $fields[$surface]  = $col;
    }
    snap_signature_render_pickers(
        $pdo,
        $current,
        $fields,
        'YOUR DEFAULT SIGNATURE',
        'Used on your new SMACKTALK posts when no per-post signature is set. Leave blank for no default signature; an explicit per-post signature always wins.',
        'YOUR POSTS INHERITING IT SHOW NO SIGNATURE'
    );
}

/**
 * Which image a post actually shows on one surface, or 0 for none.
 *
 * The four states, resolved:
 *   an id in the surface column   that image
 *   0 in the surface column       no signature, the author said so
 *   NULL in the surface column    the blog default for this surface, or none
 *   the column is absent          the LEGACY field, with today's meaning
 *
 * THE LEGACY FALLBACK IS NOT A NULL-MEANS-INHERIT. On an install that has not
 * taken the two new columns yet, and on every post written before them, the
 * only thing stored is signature_image_id, where NULL has always meant "no
 * signature on this post". Reading that as inherit would give every post ever
 * written a signature the moment an owner set a default. So the legacy field
 * resolves to itself or to nothing, and never to the blog's.
 */
function snap_signature_resolve(array $post, array $settings, string $surface): int {
    $key = 'signature_' . $surface . '_image_id';
    // An unknown surface, or a row from an install that has not taken the two
    // columns yet, falls through to the legacy field below. That is the
    // conservative answer -- what the product showed before surfaces existed --
    // rather than a plausible-looking guess.
    if (in_array($key, SNAP_SIGNATURE_VARIANT_FIELDS, true) && array_key_exists($key, $post)) {
        $stored = $post[$key];
        if ($stored !== null) {
            $chosen = (int)$stored;
            return $chosen > 0 ? $chosen : 0;       // an id, or a deliberate opt-out
        }
        return snap_signature_default_for($settings, $surface);   // inherit
    }
    $legacy = $post[SNAP_SIGNATURE_LEGACY_FIELD] ?? null;
    return ($legacy === null) ? 0 : max(0, (int)$legacy);
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
