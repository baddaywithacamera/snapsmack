<?php
/**
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
 * Canonical Apache execution guard for every web-served upload directory.
 *
 * The rule is deliberately scoped to executable extensions. A blanket deny
 * would also prevent the web server from serving legitimate images.
 */

function snapsmack_upload_execution_guard_content(): string {
    return "# SNAPSMACK-UPLOAD-EXECUTION-GUARD\n"
        . "# Uploaded files are data, never server-side programs.\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht .cgi .pl\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht .cgi .pl\n"
        . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|php8|phar|pht|cgi|pl)$\">\n"
        . "  SetHandler none\n"
        . "  <IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n"
        . "    Order Allow,Deny\n"
        . "    Deny from all\n"
        . "  </IfModule>\n"
        . "</FilesMatch>\n";
}

function snapsmack_write_upload_execution_guard(string $directory): bool {
    if (!is_dir($directory)) {
        return false;
    }

    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    return @file_put_contents(
        $path,
        snapsmack_upload_execution_guard_content(),
        LOCK_EX
    ) !== false;
}

function snapsmack_upload_execution_guard_is_current(string $directory): bool {
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($path)) {
        return false;
    }

    return hash_equals(
        hash('sha256', snapsmack_upload_execution_guard_content()),
        hash_file('sha256', $path)
    );
}

// ===== SNAPSMACK EOF =====
