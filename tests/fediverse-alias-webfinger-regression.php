<?php
/**
 * 719D "STAGE NAME": @handle@photoblogs.fyi aliases — both ends must agree.
 *
 * Mastodon shows the handle domain from the WebFinger SUBJECT the actor's own
 * host returns, then re-asks the subject's domain and requires the same actor
 * back. So: the spoke answers under both names with the alias as subject when
 * ON (own domain as subject when OFF); the hub answers acct:<alias>@<hub> for
 * ACTIVE members only — never for a blog that left or was blocked, never for
 * a name it does not hold. The alias is never written to alsoKnownAs.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
define('BASE_URL', 'https://spoke.test/');
require dirname(__DIR__) . '/core/fediverse.php';

/** Just enough PDO for the hub branch: one roster table in an array. */
class AliasFakePDO extends PDO {
    public array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        // information_schema probe: say the column exists so no ALTER runs.
        return new AliasFakeStmt([[1]], $this);
    }
    public function exec(string $q): int|false { throw new RuntimeException('no ALTER expected: ' . $q); }
    public function prepare(string $q, array $o = []): PDOStatement|false { return new AliasFakeStmt(null, $this, $q); }
}
class AliasFakeStmt extends PDOStatement {
    private array $out = [];
    public function __construct(private ?array $fixed, private AliasFakePDO $db, private string $q = '') {}
    public function execute(?array $p = null): bool {
        if ($this->fixed !== null) { $this->out = $this->fixed; return true; }
        $h = (string)($p[0] ?? '');
        $this->out = array_values(array_filter($this->db->rows,
            fn($r) => $r['alias_handle'] === $h && $r['state'] === 'active'));
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $o = PDO::FETCH_ORI_NEXT, int $off = 0): mixed { return $this->out[0] ?? false; }
    public function fetchColumn(int $c = 0): mixed { return isset($this->out[0]) ? array_values($this->out[0])[$c] : false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$a): array { return $this->out; }
}

$fails = [];
$check = function (string $name, bool $ok) use (&$fails) { if (!$ok) $fails[] = $name; };

// ── spoke ──
$spoke = ['site_url' => 'https://spoke.test', 'fediverse_handle' => 'leo',
          'photoblogs_relay_url' => 'https://photoblogs.fyi/ap/actor', 'fediverse_alias_enabled' => '1'];
$own   = sv_webfinger('acct:leo@spoke.test', $spoke);
$alias = sv_webfinger('acct:leo@photoblogs.fyi', $spoke);
$check('spoke ON: own-domain question answered', is_array($own));
$check('spoke ON: subject is the alias', ($own['subject'] ?? '') === 'acct:leo@photoblogs.fyi');
$check('spoke ON: alias question answered with the same actor',
    is_array($alias) && ($alias['links'][0]['href'] ?? '') === 'https://spoke.test/ap/actor'
    && ($alias['subject'] ?? '') === 'acct:leo@photoblogs.fyi');
$check("spoke ON: a stranger's name on the hub domain is not ours", sv_webfinger('acct:leonardo@photoblogs.fyi', $spoke) === null);
$check('spoke ON: case-insensitive', is_array(sv_webfinger('acct:Leo@PhotoBlogs.fyi', $spoke)));

$spoke_off = $spoke; $spoke_off['fediverse_alias_enabled'] = '0';
$check('spoke OFF: subject is own domain', (sv_webfinger('acct:leo@spoke.test', $spoke_off)['subject'] ?? '') === 'acct:leo@spoke.test');
$check('spoke OFF: alias question unanswered', sv_webfinger('acct:leo@photoblogs.fyi', $spoke_off) === null);
$check('sv_acct follows the switch', sv_acct($spoke) === 'acct:leo@photoblogs.fyi' && sv_acct($spoke_off) === 'acct:leo@spoke.test');

// A blog whose relay IS itself (the hub) has no alias domain.
$self = ['site_url' => 'https://photoblogs.fyi', 'fediverse_handle' => 'hub',
         'photoblogs_relay_url' => 'https://photoblogs.fyi/ap/actor', 'fediverse_alias_enabled' => '1'];
$check('alias domain empty when relay is self', sv_alias_domain($self) === '' && !sv_alias_enabled($self));

// ── hub ──
$hub = ['site_url' => 'https://photoblogs.fyi', 'fediverse_handle' => 'photoblogs',
        'site_mode' => 'fedistructure', 'distribution' => 'fedistructure', 'node_role' => 'hub',
        'distribution_profile' => 'smackcast', 'smackcast_relay_enabled' => '1'];
$pdo = new AliasFakePDO([
    ['actor_url' => 'https://spoke.test/ap/actor',  'domain' => 'spoke.test',  'state' => 'active', 'alias_handle' => 'leo'],
    ['actor_url' => 'https://gone.test/ap/actor',   'domain' => 'gone.test',   'state' => 'left',   'alias_handle' => 'gone'],
    ['actor_url' => 'https://bad.test/ap/actor',    'domain' => 'bad.test',    'state' => 'blocked','alias_handle' => 'bad'],
    ['actor_url' => 'https://maybe.test/ap/actor',  'domain' => 'maybe.test',  'state' => 'pending','alias_handle' => 'maybe'],
]);
$check('hub predicate true for the test hub', sc_relay_is_hub($hub));
$r = sv_webfinger('acct:leo@photoblogs.fyi', $hub, $pdo);
$check('hub: active member alias resolves to the member actor',
    is_array($r) && ($r['links'][0]['href'] ?? '') === 'https://spoke.test/ap/actor'
    && ($r['subject'] ?? '') === 'acct:leo@photoblogs.fyi'
    && ($r['links'][1]['href'] ?? '') === 'https://spoke.test/');
$check('hub: left member does not answer',    sv_webfinger('acct:gone@photoblogs.fyi', $hub, $pdo) === null);
$check('hub: blocked member does not answer', sv_webfinger('acct:bad@photoblogs.fyi', $hub, $pdo) === null);
$check('hub: pending member does not answer', sv_webfinger('acct:maybe@photoblogs.fyi', $hub, $pdo) === null);
$check('hub: unknown name does not answer',   sv_webfinger('acct:nobody@photoblogs.fyi', $hub, $pdo) === null);
$check('hub: other domain does not answer',   sv_webfinger('acct:leo@elsewhere.test', $hub, $pdo) === null);
$check('hub: own handle still wins',          (sv_webfinger('acct:photoblogs@photoblogs.fyi', $hub, $pdo)['links'][0]['href'] ?? '') === 'https://photoblogs.fyi/ap/actor');
$check('hub: no pdo = no alias branch',       sv_webfinger('acct:leo@photoblogs.fyi', $hub) === null);
$check('non-hub with pdo never answers for others', sv_webfinger('acct:leo@spoke.test', ['site_url'=>'https://other.test','fediverse_handle'=>'x'], $pdo) === null);

// ── policy, static ──
$relay = file_get_contents(dirname(__DIR__) . '/core/smackcast-relay.php');
$check('join claims the alias from preferredUsername', str_contains($relay, "sc_relay_claim_alias(\$pdo, \$settings, \$actor_url, (string)(\$actor['preferredUsername'] ?? ''));"));
$check('lookup is active-only', str_contains($relay, "WHERE alias_handle=? AND state='active' LIMIT 1"));
$check('held 90 days after leaving', str_contains($relay, "90 * 86400"));
$check('curator and hub handle reserved', str_contains($relay, "return \$handle === 'curator' || (\$own !== '' && \$handle === \$own);"));
$check('normalize: 1-60 [a-z0-9_]', sc_relay_alias_normalize(' Leo ') === 'leo' && sc_relay_alias_normalize('leo.nardo') === '' && sc_relay_alias_normalize(str_repeat('a', 61)) === '');
$actor_src = file_get_contents(dirname(__DIR__) . '/core/fediverse.php');
$doc_at = strpos($actor_src, 'function sv_actor_doc(');
$doc_fn = substr($actor_src, $doc_at, strpos($actor_src, "
function ", $doc_at + 1) - $doc_at);
$check('actor document never carries the alias in alsoKnownAs', !str_contains($doc_fn, 'alsoKnownAs') && !str_contains($doc_fn, 'sv_alias'));
$check('route passes pdo', str_contains(file_get_contents(dirname(__DIR__) . '/fediverse.php'), "sv_webfinger(\$_GET['resource'] ?? '', \$settings, \$pdo)"));
$admin = file_get_contents(dirname(__DIR__) . '/core/fediverse-admin-shared.php');
$check('enable verifies the hub answers with OUR actor', str_contains($admin, "rtrim(\$al_self, '/') !== rtrim(sv_actor_url(\$sv_settings), '/')"));
$check('enable requires relay joined', str_contains($admin, "(\$sv_settings['photoblogs_relay_joined'] ?? '0') !== '1') {\n        \$al_msg = 'ALIAS NOT ENABLED"));

foreach ($fails as $f) fwrite(STDERR, "FAIL: {$f}\n");
if ($fails) exit(1);
echo "PASS: @handle@photoblogs.fyi — both ends agree, active members only, never alsoKnownAs.\n";
// ===== SNAPSMACK EOF =====
