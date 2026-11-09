<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * SKIN INTEGRITY SURVEY (SS-1264). The read-only diagnostic must flag an
 * installed skin that has NO signed-package integrity record -- the refused-save
 * condition -- which a DB GROUP BY alone hides (a zero-row skin never appears in
 * the group). It enumerates installed skin dirs and left-joins the row counts.
 */

require_once dirname(__DIR__) . '/core/skin-integrity-survey.php';

$fails = 0;
function check(string $label, $got, $want): void
{
    global $fails;
    if ((string) var_export($got, true) === (string) var_export($want, true)) { echo "ok: $label\n"; }
    else { $fails++; fwrite(STDERR, "FAIL: $label -- got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"); }
}

// ---- a throwaway skins tree: one skin WITH rows, two WITHOUT ----
$root = sys_get_temp_dir() . '/ss-survey-' . getmypid();
foreach (['hasrecord' => '1.2.3', 'norecord' => '0.1.0', 'alsogone' => '2.0.0'] as $slug => $ver) {
    @mkdir("$root/skins/$slug", 0777, true);
    file_put_contents("$root/skins/$slug/manifest.json", json_encode(['version' => $ver]));
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE snap_file_manifest(id INTEGER PRIMARY KEY, file_path TEXT, skin_id TEXT)');
// only 'hasrecord' gets signed rows; the others have files on disk but zero rows
$pdo->exec("INSERT INTO snap_file_manifest(file_path,skin_id) VALUES ('skins/hasrecord/style.css','hasrecord'), ('skins/hasrecord/manifest.json','hasrecord'), ('core/x.php',NULL)");

try {
    $survey = snapsmack_skin_integrity_survey($pdo, "$root/skins");
    $by = [];
    foreach ($survey as $s) $by[$s['slug']] = $s;

    check('hasrecord has a record', $by['hasrecord']['has_record'], true);
    check('hasrecord row count', $by['hasrecord']['manifest_rows'], 2);
    check('norecord is flagged missing', $by['norecord']['has_record'], false);
    check('norecord row count is zero', $by['norecord']['manifest_rows'], 0);
    check('alsogone is flagged missing', $by['alsogone']['has_record'], false);
    check('version is read from the manifest', $by['hasrecord']['version'], '1.2.3');

    // zero-row skins sort FIRST (operator sees the problems at the top)
    check('zero-row skins listed before recorded ones', $survey[0]['has_record'], false);

    check('missing count = 2', snapsmack_skin_integrity_missing_count($survey), 2);

    // table absent -> rows -1, NOT counted as a missing record (distinct from zero)
    $empty = new PDO('sqlite::memory:');   // no snap_file_manifest table
    $survey2 = snapsmack_skin_integrity_survey($empty, "$root/skins");
    check('no manifest table => rows -1', $survey2[0]['manifest_rows'], -1);
    check('no manifest table => missing count 0 (unknown, not zero)', snapsmack_skin_integrity_missing_count($survey2), 0);
} finally {
    foreach (['hasrecord', 'norecord', 'alsogone'] as $slug) {
        @unlink("$root/skins/$slug/manifest.json");
        @rmdir("$root/skins/$slug");
    }
    @rmdir("$root/skins");
    @rmdir($root);
}

// structural: smack-back.php surfaces the survey read-only
$back = file_get_contents(dirname(__DIR__) . '/smack-back.php');
check('smack-back.php computes the survey', (int) (strpos($back, 'snapsmack_skin_integrity_survey(') !== false), 1);
check('smack-back.php shows the missing-record count', (int) (strpos($back, 'skin_missing_record_count') !== false), 1);

if ($fails) { fwrite(STDERR, "\n$fails assertion(s) FAILED\n"); exit(1); }
echo "\nskin integrity survey: all checks passed\n";

// ===== SNAPSMACK EOF =====
