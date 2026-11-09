<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Read-only survey: which INSTALLED skins have no signed-package integrity
 * record, so an operator can see the refused-save condition before hitting it.
 *
 * The save gate (skin_registry_verify_installed_provenance) refuses every setting
 * for a skin with zero snap_file_manifest rows (SS-1264). A DB GROUP BY alone
 * HIDES that case -- a skin with zero rows simply doesn't appear in the group. So
 * this enumerates the installed skin directories (the source of truth for "is this
 * skin present") and left-joins the row count, flagging any installed skin with no
 * record. Read-only: no install, recovery, re-baseline or mutation.
 *
 * Returns a list, zero-row skins first, each:
 *   slug, version, manifest_rows (int; -1 if the table is absent), has_record (bool)
 */
function snapsmack_skin_integrity_survey(PDO $pdo, ?string $skinsDir = null): array
{
    $skinsDir = $skinsDir ?: dirname(__DIR__) . '/skins';
    $out = [];
    foreach (glob($skinsDir . '/*/manifest.json') ?: [] as $manifest) {
        $slug = basename(dirname($manifest));
        $version = '?';
        try {
            $m = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($m) && isset($m['version'])) $version = (string) $m['version'];
        } catch (Throwable $e) { /* unreadable manifest: leave version '?' */ }

        $rows = -1;   // -1 = manifest table absent / unreadable
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM snap_file_manifest WHERE skin_id = ?");
            $st->execute([$slug]);
            $rows = (int) $st->fetchColumn();
        } catch (PDOException $e) { $rows = -1; }

        $out[] = [
            'slug'          => $slug,
            'version'       => $version,
            'manifest_rows' => $rows,
            'has_record'    => $rows > 0,
        ];
    }
    // The refused-save cases (no record) first, then alphabetical.
    usort($out, static function (array $a, array $b): int {
        if ($a['has_record'] !== $b['has_record']) return $a['has_record'] <=> $b['has_record'];
        return strcmp($a['slug'], $b['slug']);
    });
    return $out;
}

/** Count of installed skins with no integrity record (the refused-save cases). */
function snapsmack_skin_integrity_missing_count(array $survey): int
{
    return count(array_filter($survey, static fn (array $s): bool => $s['manifest_rows'] === 0));
}
// ===== SNAPSMACK EOF =====
