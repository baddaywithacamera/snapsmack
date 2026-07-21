<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * PHOTOFRI.DAY — configuration. Copy to config.php (gitignored) and fill in.
 * Standalone service on its own Proxmox CT. Its DB holds the keypair + the
 * participant (follower) list — back it up alongside db_pass + secret_kek.
 */
return [
    'domain'      => 'photofri.day',   // public host (TLS)
    'db_host'     => '127.0.0.1',
    'db_name'     => 'photofri_day',
    'db_user'     => 'photofri',
    'db_pass'     => 'CHANGE_ME',
    'admin_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
    // At-rest encryption key for @participate's PRIVATE signing key (stored in
    // pfd_settings). Set BEFORE the first /actor hit so the minted keypair is
    // encrypted from birth. Generate: php -r "echo bin2hex(random_bytes(32));"
    // Losing it strands every follower (the relay mints fresh) — back it up.
    'secret_kek'  => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
];
// ===== SNAPSMACK EOF =====
