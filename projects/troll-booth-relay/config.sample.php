<?php
// SNAPSMACK_EOF_HEADER
return [
    'base_url' => 'https://trollbooth.photoblogs.fyi',
    'db_host' => '192.168.1.110',
    'db_name' => 'troll_booth',
    'db_user' => 'troll_booth',
    'db_pass' => 'CHANGE_ME',
    'admin_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_VALUE',
    // Initial Claude Web connector credential. Replace with OAuth later.
    'actors' => [
        'claude' => 'CHANGE_ME_TO_A_DIFFERENT_LONG_RANDOM_VALUE',
    ],
    // Initial local clients. Tokens are submitted over HTTPS and stored hashed
    // in the database on first successful registration.
    'devices' => [
        'sean-workstation' => 'CHANGE_ME_TO_A_THIRD_LONG_RANDOM_VALUE',
    ],
    'job_ttl_seconds' => 120,
    'tool_wait_seconds' => 25,
];
// ===== SNAPSMACK EOF =====
