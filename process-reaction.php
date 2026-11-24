<?php
/**
 * SNAPSMACK - Retired Reaction Endpoint
 *
 * Emoji reactions were replaced by the single heart system. Keep this route
 * as an explicit tombstone so old clients receive a clear response, while no
 * request can read, insert, update, or delete preserved snap_reactions rows.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['error' => 'reactions_retired']);
// ===== SNAPSMACK EOF =====
