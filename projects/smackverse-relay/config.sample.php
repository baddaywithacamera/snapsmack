<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * SMACKVERSE Relay — configuration. Copy to config.php (which is gitignored) and
 * fill in. The relay is a standalone service on its own Proxmox CT.
 */
return [
    'domain'      => 'smackverse.snapsmack.ca', // the relay's public host (TLS)
    'db_host'     => '127.0.0.1',
    'db_name'     => 'smackverse_relay',
    'db_user'     => 'relay',
    'db_pass'     => 'CHANGE_ME',
    'admin_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING', // operator admin page
    // At-rest encryption key for the relay's PRIVATE signing key (stored in
    // relay_settings). Set this to a long random string BEFORE first /actor hit
    // so the freshly minted keypair is encrypted from birth. Generate with e.g.
    //   php -r "echo bin2hex(random_bytes(32));"
    // Losing this value means the stored private key can't be decrypted (the
    // relay then mints a fresh keypair) — back it up alongside db_pass. Leave
    // empty ('') to disable at-rest encryption (NOT recommended).
    'secret_kek'  => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
];
// ===== SNAPSMACK EOF =====
