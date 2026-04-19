<?php
/**
 * SMACK CENTRAL - Database connection
 *
 * Returns a shared PDO instance. Call sc_db() from any page after
 * requiring sc-config.php.
 */

function sc_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . SC_DB_HOST . ';dbname=' . SC_DB_NAME . ';charset=utf8mb4',
            SC_DB_USER,
            SC_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/**
 * Returns a PDO connection to the FORUM database (isolated from Smack Central).
 * Used by sc-forum.php for all forum operations so a compromised API key
 * cannot touch Smack Central admin tables.
 */
function sc_forum_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . SC_FORUM_DB_HOST . ';dbname=' . SC_FORUM_DB_NAME . ';charset=utf8mb4',
            SC_FORUM_DB_USER,
            SC_FORUM_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
