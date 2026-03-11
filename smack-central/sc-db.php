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
