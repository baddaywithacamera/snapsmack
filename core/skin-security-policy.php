<?php
/**
 * Shared security policy for installable skin packages.
 *
 * This file is deliberately independent of the CMS bootstrap so build tools,
 * registry code, installers, and tests can all apply the same rules.
 */

function snapsmack_skin_policy_finding(string $file, int $line, string $type, string $excerpt): array {
    return [
        'file' => str_replace('\\', '/', $file),
        'line' => $line,
        'type' => $type,
        'severity' => 'block',
        'excerpt' => trim($excerpt),
    ];
}

function snapsmack_skin_policy_scan_php(string $path, string $rel): array {
    $source = @file_get_contents($path);
    if ($source === false) return [];
    $findings = [];
    $tokens = token_get_all($source);
    $source_lines = preg_split('/\R/', $source);
    $line = 1;
    $dangerous_calls = [
        'header' => 'response-control', 'setcookie' => 'cookie-access',
        'session_start' => 'session-access', 'session_id' => 'session-access',
        'http_response_code' => 'response-control',
        'file_get_contents' => 'filesystem-access', 'file_put_contents' => 'filesystem-access',
        'fopen' => 'filesystem-access', 'unlink' => 'filesystem-access',
        'rename' => 'filesystem-access', 'copy' => 'filesystem-access',
        'mkdir' => 'filesystem-access', 'rmdir' => 'filesystem-access',
        'glob' => 'filesystem-access', 'scandir' => 'filesystem-access',
        'curl_init' => 'network-access', 'fsockopen' => 'network-access',
        'stream_socket_client' => 'network-access', 'mail' => 'network-access',
        'exec' => 'process-execution', 'shell_exec' => 'process-execution',
        'system' => 'process-execution', 'passthru' => 'process-execution',
        'proc_open' => 'process-execution', 'popen' => 'process-execution',
    ];
    $superglobals = [
        '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE', '$_SESSION',
        '$_FILES', '$_ENV', '$GLOBALS',
    ];

    foreach ($tokens as $token) {
        if (!is_array($token)) continue;
        [$id, $text, $token_line] = $token;
        $line = $token_line ?: $line;
        if ($id === T_VARIABLE) {
            if ($text === '$pdo') {
                $findings[] = snapsmack_skin_policy_finding($rel, $line, 'database-handle', $text);
            } elseif (in_array($text, $superglobals, true)) {
                $findings[] = snapsmack_skin_policy_finding($rel, $line, 'request-global', $text);
            }
        } elseif ($id === T_STRING) {
            $name = strtolower($text);
            if (isset($dangerous_calls[$name])) {
                $findings[] = snapsmack_skin_policy_finding($rel, $line, $dangerous_calls[$name], $text);
            } elseif (in_array($name, ['pdo', 'mysqli'], true)) {
                $findings[] = snapsmack_skin_policy_finding($rel, $line, 'database-api', $text);
            }
        } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            $statement = (string)($source_lines[$line - 1] ?? '');
            $local_template = preg_match(
                '#\b(?:include|require)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*["\']/[a-zA-Z0-9._-]+["\']#',
                $statement
            );
            $approved_core = preg_match(
                '#/core/(?:meta|footer|footer-scripts|community-component)\.php["\']#',
                $statement
            );
            if (!$local_template && !$approved_core) {
                $findings[] = snapsmack_skin_policy_finding($rel, $line, 'php-include', trim($text));
            }
        } elseif (in_array($id, [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE], true)) {
            $findings[] = snapsmack_skin_policy_finding($rel, $line, 'php-declaration', trim($text));
        } elseif ($id === T_EVAL) {
            $findings[] = snapsmack_skin_policy_finding($rel, $line, 'dynamic-execution', trim($text));
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b[\s\S]*\b(?:FROM|INTO|TABLE|SET|VALUES)\b/i', $text)) {
            $findings[] = snapsmack_skin_policy_finding($rel, $line, 'sql-statement', substr($text, 0, 160));
        }
    }
    return $findings;
}

function snapsmack_skin_policy_scan_markup(string $path, string $rel): array {
    $source = @file_get_contents($path);
    if ($source === false) return [];
    $checks = [
        'inline-event-handler' => '/\son[a-z]{2,}\s*=\s*["\']/i',
        'javascript-uri' => '/javascript\s*:/i',
        'active-embed' => '/<\s*(?:iframe|object|embed)\b/i',
        'inline-script' => '/<\s*script\b(?![^>]*\bsrc\s*=)[^>]*>/i',
        'remote-script' => '/<\s*script\b[^>]*\bsrc\s*=\s*["\'](?:https?:)?\/\//i',
        'direct-script-tag' => '/<\s*script\b[^>]*\bsrc\s*=/i',
    ];
    $findings = [];
    foreach (preg_split('/\R/', $source) as $offset => $line) {
        foreach ($checks as $type => $pattern) {
            if (preg_match($pattern, $line)) {
                $findings[] = snapsmack_skin_policy_finding($rel, $offset + 1, $type, $line);
            }
        }
    }
    return $findings;
}

function snapsmack_skin_policy_scan_css(string $path, string $rel): array {
    $source = @file_get_contents($path);
    if ($source === false) return [];
    $findings = [];
    foreach (preg_split('/\R/', $source) as $offset => $line) {
        if (preg_match('/@import\s+(?:url\s*\()?\s*["\']?(?:https?:)?\/\//i', $line)
            || preg_match('/url\s*\(\s*["\']?(?:https?:)?\/\//i', $line)) {
            $findings[] = snapsmack_skin_policy_finding($rel, $offset + 1, 'remote-css-resource', $line);
        }
    }
    return $findings;
}

function snapsmack_skin_security_findings(string $skin_dir): array {
    $skin_dir = rtrim(str_replace('\\', '/', $skin_dir), '/');
    if (!is_dir($skin_dir)) {
        return [snapsmack_skin_policy_finding('', 0, 'not-a-directory', $skin_dir)];
    }
    $findings = [];
    $manifest_path = $skin_dir . '/manifest.json';
    $manifest = json_decode((string)@file_get_contents($manifest_path), true);
    if (is_array($manifest) && (int)($manifest['schema_version'] ?? 0) >= 2) {
        foreach (['cms_controller', 'view_model', 'templates'] as $required) {
            if (!isset($manifest[$required]) || $manifest[$required] === '' || $manifest[$required] === []) {
                $findings[] = snapsmack_skin_policy_finding('manifest.json', 0, 'manifest-contract', "Missing {$required}");
            }
        }
        foreach (($manifest['templates'] ?? []) as $template) {
            if (!is_string($template) || !preg_match('/^[a-zA-Z0-9._-]+\.php$/', $template)) {
                $findings[] = snapsmack_skin_policy_finding('manifest.json', 0, 'manifest-template-path', (string)$template);
            }
        }
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($skin_dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        $rel = ltrim(substr($path, strlen($skin_dir)), '/');
        // Reference material explicitly marked gitignored is not packaged.
        if (stripos($rel, 'gitignore') !== false) continue;
        $ext = strtolower($file->getExtension());
        if ($ext === 'js') {
            $findings[] = snapsmack_skin_policy_finding($rel, 0, 'bundled-javascript', 'Skin packages may not ship JavaScript.');
            continue;
        }
        if ($ext === 'php' || $ext === 'phtml' || $ext === 'inc') {
            array_push($findings, ...snapsmack_skin_policy_scan_php($path, $rel));
            array_push($findings, ...snapsmack_skin_policy_scan_markup($path, $rel));
        } elseif ($ext === 'html' || $ext === 'htm') {
            array_push($findings, ...snapsmack_skin_policy_scan_markup($path, $rel));
        } elseif ($ext === 'css') {
            array_push($findings, ...snapsmack_skin_policy_scan_css($path, $rel));
        }
    }
    usort($findings, static fn(array $a, array $b): int =>
        [$a['file'], $a['line'], $a['type']] <=> [$b['file'], $b['line'], $b['type']]
    );
    return $findings;
}

function snapsmack_skin_security_strict(string $skin_dir): bool {
    $manifest_path = rtrim($skin_dir, '/\\') . '/manifest.json';
    $manifest = json_decode((string)@file_get_contents($manifest_path), true);
    return is_array($manifest) && (int)($manifest['schema_version'] ?? 0) >= 2;
}

function snapsmack_skin_security_gate(string $skin_dir, array $legacy_baseline = []): array {
    $findings = snapsmack_skin_security_findings($skin_dir);
    $slug = basename(rtrim(str_replace('\\', '/', $skin_dir), '/'));
    if (snapsmack_skin_security_strict($skin_dir) || !isset($legacy_baseline[$slug])) {
        return $findings;
    }
    $allowed = $legacy_baseline[$slug];
    $seen = [];
    $excess = [];
    foreach ($findings as $finding) {
        $file = $finding['file'];
        $type = $finding['type'];
        $seen[$file][$type] = ($seen[$file][$type] ?? 0) + 1;
        if ($seen[$file][$type] > (int)($allowed[$file][$type] ?? 0)) $excess[] = $finding;
    }
    return $excess;
}

// ===== SNAPSMACK EOF =====
