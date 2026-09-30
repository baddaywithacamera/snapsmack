<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/custom-code-policy.php';

function pwc_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return list<string> */
function pwc_tracked_php_files(string $root): array
{
    $command = 'git -C ' . escapeshellarg($root) . ' ls-files -co --exclude-standard -z -- "*.php"';
    $output = shell_exec($command);
    pwc_expect(is_string($output), 'Could not enumerate tracked PHP files.');
    return array_values(array_filter(explode("\0", $output), 'strlen'));
}

/** @return list<array{file:string,function:string,kind:string}> */
function pwc_inventory_file(string $root, string $file): array
{
    $source = file_get_contents($root . '/' . $file);
    pwc_expect(is_string($source), "Could not read {$file}.");
    $tokens = token_get_all($source);
    $function = '<top-level>';
    $pendingFunction = null;
    $functionDepth = null;
    $depth = 0;
    $findings = [];
    $significant = [];

    foreach ($tokens as $index => $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        if ($id === T_FUNCTION) {
            for ($look = $index + 1; $look < count($tokens); $look++) {
                if (is_array($tokens[$look]) && $tokens[$look][0] === T_STRING) {
                    $pendingFunction = $tokens[$look][1];
                    break;
                }
                if ($tokens[$look] === '(') break;
            }
        }
        if ($text === '{') {
            $depth++;
            if ($pendingFunction !== null) {
                $function = $pendingFunction;
                $functionDepth = $depth;
                $pendingFunction = null;
            }
        } elseif ($text === '}') {
            if ($functionDepth === $depth) {
                $function = '<top-level>';
                $functionDepth = null;
            }
            $depth--;
        }
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) continue;
        $significant[] = [$index, $id, $text, $function];
    }

    $count = count($significant);
    for ($i = 0; $i < $count; $i++) {
        [, $id, $text, $scope] = $significant[$i];
        $next = $significant[$i + 1][2] ?? '';
        $next2 = $significant[$i + 2][2] ?? '';
        $previous = $significant[$i - 1][2] ?? '';

        if (in_array($text, ['SnapTrustedHtml', 'SnapOwnerCode'], true)
            && $next === '::' && $next2 === '__snapsmackCmsOnly') {
            $findings[] = ['file' => $file, 'function' => $scope, 'kind' => $text . '::factory'];
        }
        if ($id === T_NEW && in_array($next, ['SnapTrustedHtml', 'SnapOwnerCode'], true)) {
            $findings[] = ['file' => $file, 'function' => $scope, 'kind' => $next . '::construct'];
        }
        if ($id === T_CLONE && in_array($next, ['SnapTrustedHtml', 'SnapOwnerCode'], true)) {
            $findings[] = ['file' => $file, 'function' => $scope, 'kind' => $next . '::clone'];
        }
        if ($id === T_EXTENDS && in_array($next, ['SnapTrustedHtml', 'SnapOwnerCode'], true)) {
            $findings[] = ['file' => $file, 'function' => $scope, 'kind' => $next . '::subclass'];
        }
        if ($id === T_STRING && strtolower($text) === 'unserialize') {
            $window = implode('', array_column(array_slice($significant, $i, 24), 2));
            if (str_contains($window, 'SnapTrustedHtml') || str_contains($window, 'SnapOwnerCode')) {
                $findings[] = ['file' => $file, 'function' => $scope, 'kind' => 'wrapper::unserialize'];
            }
        }
        if ($id === T_STRING && in_array($text, ['newInstanceWithoutConstructor', 'setAccessible'], true)) {
            $findings[] = ['file' => $file, 'function' => $scope, 'kind' => 'Reflection::' . $text];
        }
    }
    return $findings;
}

$root = dirname(__DIR__);
$excludedPrefixes = ['tests/', 'test/', 'fixtures/'];
$production = [];
$excluded = [];
foreach (pwc_tracked_php_files($root) as $file) {
    $normalized = str_replace('\\', '/', $file);
    $isExcluded = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($normalized, $prefix) || str_contains($normalized, '/tests/')
            || str_contains($normalized, '/fixtures/')) {
            $isExcluded = true;
            break;
        }
    }
    if ($isExcluded) array_push($excluded, ...pwc_inventory_file($root, $normalized));
    else array_push($production, ...pwc_inventory_file($root, $normalized));
}

// Security-significant allowlist: file + enclosing function + exact call count.
$approved = [
    'core/trusted-html.php|snapsmack_trusted_html|SnapTrustedHtml::factory' => 3,
    'core/custom-code-policy.php|snapsmack_owner_custom_code|SnapOwnerCode::factory' => 1,
    'core/custom-code-policy.php|snapsmack_skin_custom_style|SnapTrustedHtml::factory' => 2,
    'core/skin-presentation.php|snapsmack_instant_camera_presentation|SnapTrustedHtml::factory' => 1,
    'core/skin-presentation.php|snapsmack_parade_presentation|SnapTrustedHtml::factory' => 1,
    'core/skin-presentation.php|snapsmack_presentation_style|SnapTrustedHtml::factory' => 1,
    'core/skin-presentation.php|snapsmack_skin_presentation|SnapTrustedHtml::factory' => 2,
    'core/skin-render-helpers.php|snap_render_component|SnapTrustedHtml::factory' => 1,
];
ksort($approved);
$actual = [];
foreach ($production as $finding) {
    $key = $finding['file'] . '|' . $finding['function'] . '|' . $finding['kind'];
    $actual[$key] = ($actual[$key] ?? 0) + 1;
}
ksort($actual);
pwc_expect($actual === $approved,
    "Privileged-wrapper production inventory changed.\nExpected: " . var_export($approved, true)
    . "\nActual: " . var_export($actual, true));

foreach ([SnapTrustedHtml::class => 'html', SnapOwnerCode::class => 'value'] as $class => $property) {
    $reflection = new ReflectionClass($class);
    pwc_expect($reflection->isFinal(), "{$class} must remain final.");
    pwc_expect($reflection->getConstructor()?->isPrivate() === true, "{$class} constructor must remain private.");
    $payload = $reflection->getProperty($property);
    pwc_expect($payload->isPrivate() && $payload->hasType() && (string)$payload->getType() === 'string',
        "{$class} payload must remain private and typed string.");
    pwc_expect($reflection->getMethod('__clone')->isPrivate(), "{$class} cloning must remain prohibited.");

    try {
        new $class('forged');
        throw new RuntimeException("{$class} allowed direct construction.");
    } catch (Error $error) {
        pwc_expect(str_contains($error->getMessage(), 'private'), "{$class} failed direct construction unexpectedly.");
    }
    $forged = $reflection->newInstanceWithoutConstructor();
    try {
        (string)$forged;
        throw new RuntimeException("{$class} Reflection shell became usable trusted output.");
    } catch (Error $error) {
        pwc_expect(str_contains($error->getMessage(), 'must not be accessed before initialization'),
            "{$class} Reflection shell failed for an unexpected reason.");
    }
}

$excludedKinds = [];
foreach ($excluded as $finding) $excludedKinds[$finding['kind']] = ($excludedKinds[$finding['kind']] ?? 0) + 1;
ksort($excludedKinds);
echo 'Privileged-wrapper production inventory passed (' . count($production) . ' approved occurrences); '
    . 'excluded test/fixture occurrences reported: ' . json_encode($excludedKinds, JSON_UNESCAPED_SLASHES) . ".\n";
