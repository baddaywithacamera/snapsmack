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
];
// ===== SNAPSMACK EOF =====
