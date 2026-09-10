<?php
/**
 * [pc_prompt] static-page shortcode reads the PHOTO CHALLENGE queue live.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/../core/parser.php';

// Two queued rows: the one dropping next (Numbers) and the one whose window
// opens next (Vroom). Both dates are UTC, as the queue stores them.
$rows = [
    'next'    => ['prompt' => 'Numbers', 'tag' => 'photofrinumbers', 'tag_display' => 'PhotoFriNumbers',
                  'drop_at' => '2026-09-10 10:00:00', 'submit_start' => '2026-09-17 10:00:00',
                  'submit_end' => '2026-09-19 12:00:00', 'friday' => '2026-09-18', 'status' => 'queued'],
    'current' => ['prompt' => 'Vroom', 'tag' => 'photofrivroom', 'tag_display' => 'PhotoFriVroom',
                  'drop_at' => '2026-09-03 10:00:00', 'submit_start' => '2026-09-10 10:00:00',
                  'submit_end' => '2026-09-12 12:00:00', 'friday' => '2026-09-11', 'status' => 'live'],
];

$pdo = new class($rows) extends PDO {
    public array $rows; public bool $empty = false;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (strpos($query, 'pc_prompts') === false) throw new PDOException('only the prompt queue is faked here');
        $which = strpos($query, "status='queued' AND drop_at>") !== false ? 'next' : 'current';
        $row = $this->empty ? false : $this->rows[$which];
        return new class($row) extends PDOStatement {
            public function __construct(private $row) {}
            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return $this->row; }
        };
    }
};
$parser = new SnapSmack($pdo);

$html = $parser->parseContent(
    'Next: [pc_prompt which="next" field="prompt"] [pc_prompt which="next" field="tag"] drops [pc_prompt which="next" field="drop"] post on [pc_prompt which="next" field="friday"]. '
    . 'Now: [pc_prompt which="current" field="tag"] opens [pc_prompt which="current" field="open" format="M j H:i"] closes [pc_prompt which="current" field="close"]. '
    . 'Default: [pc_prompt] Bad: [pc_prompt field="nope" empty="n/a"]'
);

$checks = [
    'next prompt name'              => 'Next: Numbers',
    'next tag uses display casing'  => '#PhotoFriNumbers',
    'next drop, UTC, default format'=> 'drops Thursday, September 10, 2026 at 10:00 UTC',
    'friday post-on date'           => 'post on Friday, September 18, 2026',
    'current tag'                   => 'Now: #PhotoFriVroom',
    'custom format honoured'        => 'opens Sep 10 10:00',
    'current close'                 => 'closes Saturday, September 12, 2026 at 12:00 UTC',
    'bare tag defaults to next prompt' => 'Default: Numbers',
    'unknown field falls to empty=' => 'Bad: n/a',
];
foreach ($checks as $label => $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, "FAIL: {$label}\n{$html}\n");
        exit(1);
    }
}

// Nothing queued: blank by default, or the empty= text — never a warning or a stale date.
$pdo->empty = true;
$parser2 = new SnapSmack($pdo);
$none = $parser2->parseContent('A[pc_prompt which="next" field="drop"]B [pc_prompt which="current" field="tag" empty="nothing queued"]');
if (trim(strip_tags($none)) !== 'AB nothing queued') {
    fwrite(STDERR, "FAIL: empty queue handling\n{$none}\n");
    exit(1);
}

// A blog with no challenge tables at all (query throws) stays blank, not fatal.
$dead = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException("Table 'pc_prompts' doesn't exist");
    }
};
$plain = (new SnapSmack($dead))->parseContent('X[pc_prompt which="next" field="prompt"]Y');
if (trim(strip_tags($plain)) !== 'XY') {
    fwrite(STDERR, "FAIL: non-challenge site should render blank\n{$plain}\n");
    exit(1);
}

echo "PASS: [pc_prompt] challenge schedule shortcode regression\n";
// ===== SNAPSMACK EOF =====
