<?php
/**
 * SNAPSMACK - Disaster Recovery
 *
 * Serious-business recovery operations: full Recovery Kit export/import.
 * Separated from routine backup tools so the stakes are clear before you
 * click anything.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SS-1414. NO API KEY REACHES THIS PAGE. SESSION ADMINS ONLY.
//
// This page used to accept the 'suyb' backup key as a Bearer token, because
// core/api-auth.php takes an accepted key type and returns before session auth
// ever runs. That made the KEY PATH MORE PERMISSIVE THAN THE BROWSER PATH: a
// browser needed an admin session (smack-disaster.php is in the admin-only
// list, core/auth-smack.php:327), while a key needed nothing but the key.
// With it, an active backup key could pull a full Recovery Kit -- the whole
// database including snap_users password hashes and any 2FA secrets, plus
// media and skin -- and could drive import_recovery, which RESTORES over the
// live site. No role check, and csrf_exempt() below removed the CSRF token
// from the import for the key path's sake.
//
// A backup key is now backup-only, which is what its name always promised.
// Backups do not go through this page: SUYB builds its Recovery Kit
// CLIENT-SIDE (tools/smack-up-your-backup/backup_engine.py:1147, "Recovery
// kit built client-side") from the SQL dumps and media it fetches through
// suyb-data.php. Its download_recovery_kit() method, the only thing in the
// tool that ever posted here, is defined and never called -- I checked every
// call site in the tool, not just the one Muse named. Nothing else in the
// repository posts to this page either.
//
// csrf_exempt() IS GONE WITH IT. It existed only so the keyed export could
// work without a session token; with no key path there is nothing to exempt,
// and both the export and the import now carry the token like every other
// admin POST. SECAUDIT 047 had already noticed the import half of this and
// patched around it with a conditional verify; the condition can go now
// because the case it guarded cannot happen.
require_once 'core/auth-smack.php';

// --- RECOVERY KIT EXPORT ---
if (isset($_POST['action']) && $_POST['action'] === 'export') {

    // RECOVERY KIT EXPORT: Complete site backup with database, branding, media, and skin.
    if ($_POST['type'] === 'recovery_kit') {
        require_once 'core/export-engine.php';
        try {
            $exporter = new SnapSmackExport($pdo, __DIR__);
            $kitPath  = $exporter->exportRecoveryKit();

            header('Content-Type: application/x-gzip');
            header('Content-Disposition: attachment; filename="' . basename($kitPath) . '"');
            header('Content-Length: ' . filesize($kitPath));
            readfile($kitPath);
            unlink($kitPath);
            exit;
        } catch (Exception $e) {
            die("RECOVERY_KIT_ERROR: " . $e->getMessage());
        }
    }

    // USER CREDENTIALS EXPORT: REMOVED 0.7.440. It dumped the entire snap_users
    // table (password hashes + any 2FA secrets, via SELECT *) as a one-click / one-
    // POST download — an unsafe exfil path, and redundant with the full recovery-kit
    // DB dump above (which already contains snap_users). Do NOT reinstate a bare
    // table dump here; credential recovery belongs in the encrypted Break-Glass Card.
}

// --- RECOVERY KIT IMPORT ---
if (isset($_POST['action']) && $_POST['action'] === 'import_recovery') {
    // SECAUDIT 047 wanted the CSRF token on a session import, and had to make it
    // conditional because the keyed export path could not supply one. SS-1414
    // removed that path, so the condition is gone and the token is simply
    // required -- of the export too, via core/auth-smack.php. (The recovery
    // engine also blocks executable and traversal restore targets, so a forged
    // import could not reach RCE even before this.)
    csrf_verify();

    // SS-1430. THE MOST DESTRUCTIVE ACTION IN THE PRODUCT HAD THE WEAKEST GATE.
    // This overwrites the database and the files, and it cannot be undone from
    // here. Until now it asked for a CSRF token -- which every form on this
    // page carries -- and a browser confirm() dialog, which is a line of
    // JavaScript that any client can simply not run. A POST from a script,
    // or a mis-clicked button on a page left open, reached the SQL.
    //
    // Two controls, both enforced HERE on the server, because a control that
    // lives in the form is a suggestion:
    //
    //  1. PASSWORD PLUS TOTP, through the same reauth_verify() every other
    //     consequential action uses (smack-api-keys.php, the fediverse admin
    //     actions, schema sync). Not a new mechanism -- the shared one, so it
    //     inherits the SS-1399 attempt budget and lockout rather than needing
    //     its own.
    //
    //  2. A TYPED CONFIRMATION NAMING THIS SITE. Step-up proves who you are;
    //     it does not prove you meant THIS site. An owner with several
    //     installs open has the same password and the same authenticator for
    //     all of them, so step-up alone cannot stop a kit going into the
    //     wrong one -- and restoring site A's kit over site B is the exact
    //     accident that has no undo. site_url is used rather than site_name
    //     because two installs can share a name and cannot share a URL.
    //
    // Compared with hash_equals on the trimmed value: this is not a secret,
    // but the comparison costs nothing and keeps one habit for all of them.
    require_once 'core/reauth.php';

    // A REFUSAL MUST RENDER AS A REFUSAL. Codex's SS-1430 review FAIL, and it
    // is mine coming and going.
    //
    // The template used to decide success with `empty($import_result['errors'])`
    // -- PLURAL, because that is the shape importRecoveryKit() returns. Every
    // refusal I wrote set 'error' SINGULAR and no plural key, so `empty()` was
    // true and a refused restore rendered the SUCCESS block: "SQL statements:
    // 0, Files restored: 0, Checksums verified: 0". The gate refused correctly
    // on the server and then told the owner it had worked.
    //
    // That is the SS-1427 fault -- a restore reporting success it did not have
    // -- introduced by the commit that was adding safety. Two shapes for one
    // outcome is the whole defect, so there is one constructor now and no
    // branch hand-rolls the array.
    //
    // ONE CONTRACT, THREE KEYS, NO SINGULAR 'error'. My first repair kept a
    // singular 'error' alongside the plural list "in case something reads it".
    // Nothing does -- grep says so -- and a second key carrying the same text
    // is how the first fault happened: two shapes for one outcome, and the
    // next person picks the wrong one. Removed.
    //
    //   success  bool   true ONLY for a completed import with no errors
    //   refused  bool   true when nothing was attempted, as against half-done
    //   errors   array  every reason, which both failure branches print
    $refuse = static function (string $why): array {
        return [
            'success' => false,
            'refused' => true,
            'errors'  => ['NOTHING WAS RESTORED — ' . $why],
        ];
    };
    // READ site_url FROM THE DATABASE, NOT FROM $settings. $settings is
    // populated by core/admin-header.php, which this file includes BELOW the
    // POST handler -- so at this point it does not exist. Using it here would
    // have made the typed-confirmation check fail closed on every restore,
    // permanently, with a message about a missing site address. Found by
    // checking the variable's scope rather than by running the page, which is
    // the only reason it is not in this commit as a bug.
    $restore_target = '';
    try {
        $target_stmt = $pdo->prepare(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'site_url' LIMIT 1"
        );
        $target_stmt->execute();
        $restore_target = trim((string)($target_stmt->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        // Leave it empty: the branch below refuses rather than proceeding.
        error_log('SnapSmack restore: site_url lookup failed - ' . $e->getMessage());
    }
    $typed_target   = trim((string)($_POST['confirm_target'] ?? ''));
    $ra = reauth_verify(
        $pdo,
        (string)($_POST['reauth_password'] ?? ''),
        (string)($_POST['reauth_totp'] ?? '')
    );

    if (!$ra['ok']) {
        $import_result = $refuse($ra['error']);
    } elseif ($restore_target === '') {
        // Fail closed. With no site_url recorded there is no target to name,
        // so the second control cannot be satisfied and must not be skipped.
        $import_result = $refuse('this install has no site address recorded, so '
            . 'the restore target cannot be confirmed. Set the site address in '
            . 'settings first.');
    } elseif (!hash_equals($restore_target, $typed_target)) {
        $import_result = $refuse('the confirmation did not match this site '
            . 'address exactly. Type it as it appears above.');
    } elseif (empty($_FILES['recovery_file']['tmp_name'])) {
        $import_result = $refuse('no recovery kit was uploaded.');
    } else {
        require_once 'core/recovery-engine.php';
        try {
            $recovery      = new SnapSmackRecovery($pdo, __DIR__);
            $import_result = $recovery->importRecoveryKit($_FILES['recovery_file']['tmp_name']);
            // ONE CONTRACT, decided here rather than inferred in the template.
            // importRecoveryKit() does not report success itself -- that is
            // SS-1427's subject and its home is the engine, not this page --
            // so the boundary states it explicitly.
            // SS-1427. Success is COMPLETENESS, not the absence of errors. A
            // missing photograph is not an error, so `empty($errors)` called a
            // restore that left four hundred photographs behind a success.
            // The engine decides this at its step 5 and says so; the page does
            // not re-derive it, and a result that does not claim completeness
            // is not complete.
            $import_result['success'] = (($import_result['complete'] ?? false) === true);
            // AND 'refused' COMES FROM THE ENGINE, not from here. SS-1425: the
            // engine refuses several things before it touches anything -- a
            // corrupt manifest, an incompatible kit, a pre-restore snapshot it
            // could not write -- and this page cannot tell those from a
            // half-done import by looking at the error list. It used to hardcode
            // false, which meant every one of them rendered "IMPORT COMPLETED
            // WITH ERRORS": the owner told their database had been touched when
            // nothing ran. Only the engine knows, so the engine says.
            $import_result['refused'] = !empty($import_result['refused']);
        } catch (Throwable $e) {
            // PRE-EXISTING, same contract mismatch, fixed while I am here: an
            // exception mid-import also rendered as success. Throwable rather
            // than Exception because a TypeError or Error in the engine is not
            // a successful restore either.
            $import_result = $refuse('the import failed: ' . $e->getMessage());
        }
    }
}

// The address the typed confirmation must match, for the form below. Read
// here as well as in the POST handler because the handler only runs on a
// POST and the form is rendered on every GET.
$restore_target_display = '';
try {
    $rt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'site_url' LIMIT 1");
    $rt->execute();
    $restore_target_display = trim((string)($rt->fetchColumn() ?: ''));
} catch (PDOException $e) {
    $restore_target_display = '';
}

$page_title = "Disaster Recovery";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>DISASTER RECOVERY</h2>
        <div class="header-actions">
            <a href="smack-backup.php" class="btn-clear">← Backup &amp; Recovery</a>
        </div>
    </div>

    <?php if (isset($import_result) && is_array($import_result)): ?>
    <div class="box">
        <?php
        // THREE OUTCOMES, NOT TWO, and success must be CLAIMED not inferred.
        //
        // This used to read `empty($import_result['errors'])`, so any result
        // without that key rendered as a successful import -- which is how
        // every gate refusal came to print RECOVERY KIT IMPORTED SUCCESSFULLY.
        // Deciding on an explicit success flag fails closed instead: a shape
        // nobody has thought about is not a success.
        //
        // And a refusal is its own outcome. "IMPORT COMPLETED WITH ERRORS"
        // tells the owner their database was touched; after a refusal it was
        // not, and whether the restore half-ran is the first thing they need
        // to know about a destructive operation.
        // SS-1427 adds a FOURTH outcome, and the wording is the point of it.
        //
        // "The database is back and your photographs are not here yet" is not
        // an error and must not be printed as one. The ordinary kit
        // inventories media rather than carrying it, so on a rebuilt site that
        // is the NORMAL outcome -- and "COMPLETED WITH ERRORS" would teach the
        // owner that the word error means nothing. It is also not a success,
        // which is what it used to render as.
        //
        // So: refused, complete, partial, failed. The partial branch names
        // what is outstanding rather than leaving four counters to be read.
        $__ok      = (($import_result['success'] ?? false) === true);
        $__refused = !empty($import_result['refused']);
        $__remaining = array_values(array_filter((array)($import_result['remaining'] ?? [])));
        // SS-1427, SECOND PASS. Codex's FAIL, and it is the fault this ticket
        // was written to remove, reappearing in the state I added to remove it.
        //
        // This used to be (!$__ok && !$__refused && empty($errors)). An
        // unrecognised result -- ['sql_imported' => 0], a shape nobody planned
        // for -- has no success, no refusal and no errors, so it satisfied
        // every term and the page printed SOME FILES ARE NOT HERE YET above
        // the words "Your database is restored. Nothing failed." for an import
        // that reported nothing at all.
        //
        // PARTIAL NOW REQUIRES THE ENGINE TO HAVE SAID WHAT IS OUTSTANDING. An
        // empty remaining list is not a partial recovery; it is an answer
        // nobody gave. The reassuring branch is the one that must be hardest
        // to reach, and it was the easiest.
        $__partial = (!$__ok && !$__refused
                      && empty($import_result['errors'])
                      && $__remaining !== []);
        ?>
        <?php if ($__refused): ?>
            <div class="alert">> NOTHING WAS RESTORED — THIS SITE IS UNCHANGED<br>
                <?php foreach (($import_result['errors'] ?? []) as $err): ?>
                    <?php echo htmlspecialchars($err); ?><br>
                <?php endforeach; ?>
            </div>
        <?php elseif ($__ok): ?>
            <div class="alert">> RECOVERY KIT IMPORTED SUCCESSFULLY<br>
                SQL statements: <?php echo $import_result['sql_imported'] ?? 0; ?><br>
                Files restored: <?php echo $import_result['files_restored'] ?? 0; ?><br>
                Checksums verified: <?php echo $import_result['checksum_ok'] ?? 0; ?><br>
                <?php if (($import_result['checksum_fail'] ?? 0) > 0): ?>
                    Checksum failures: <?php echo $import_result['checksum_fail']; ?><br>
                <?php endif; ?>
                <?php if (($import_result['not_bundled'] ?? 0) > 0): ?>
                    Not bundled (restore via FTP/Cloud): <?php echo $import_result['not_bundled']; ?><br>
                <?php endif; ?>
                <?php if (($import_result['missing'] ?? 0) > 0): ?>
                    Missing files: <?php echo $import_result['missing']; ?><br>
                <?php endif; ?>
            </div>
        <?php elseif ($__partial): ?>
            <div class="alert">> RECOVERY KIT IMPORTED — SOME FILES ARE NOT HERE YET<br>
                Your database is restored. Nothing failed.<br>
                SQL statements: <?php echo $import_result['sql_imported'] ?? 0; ?><br>
                Files restored: <?php echo $import_result['files_restored'] ?? 0; ?><br>
                <?php foreach ($__remaining as $what): ?>
                    STILL TO DO: <?php echo htmlspecialchars($what); ?><br>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert">> RECOVERY KIT IMPORT COMPLETED WITH ERRORS<br>
                SQL statements: <?php echo $import_result['sql_imported'] ?? 0; ?><br>
                Files restored: <?php echo $import_result['files_restored'] ?? 0; ?><br>
                <?php if (empty($import_result['errors'])): ?>
                    <?php /* SS-1427: the fail-closed landing place. Reached when
                             the result claims no success, no refusal, no errors
                             and nothing outstanding -- which is not an outcome,
                             it is a missing answer. Saying "nothing failed"
                             here would be inventing reassurance out of
                             silence. */ ?>
                    The import did not report what it did. That is not the same
                    as it having worked.<br>
                    Check this site before you trust it, and keep the
                    pre-restore copy of your database until you have.<br>
                <?php endif; ?>
                <?php foreach (($import_result['errors'] ?? []) as $err): ?>
                    ERROR: <?php echo htmlspecialchars($err); ?><br>
                <?php endforeach; ?>
                <?php foreach ($__remaining as $what): ?>
                    STILL TO DO: <?php echo htmlspecialchars($what); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="dash-grid">

        <div class="box box-flex">
            <h3>BREAK-GLASS CARD</h3>
            <p class="skin-desc-text">
                The signed, site-bound, one-use recovery hatch for losing your password,
                authenticator, and recovery codes together. Creating a replacement revokes
                the previous card and requires password-plus-TOTP confirmation.
            </p>
            <a href="smack-break-glass.php" class="btn-smack btn-block">MANAGE BREAK-GLASS CARD</a>
        </div>

        <div class="box box-flex">
            <h3>EXPORT RECOVERY KIT</h3>
            <p class="skin-desc-text">
                Full SQL dump plus a complete file manifest with SHA-256 checksums
                for every media file, branding asset, and skin file. Media files are
                inventoried (path, size, hash) but not bundled — use FTP or Cloud
                Backup to push actual files separately.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type"   value="recovery_kit">
                <button type="submit" class="btn-smack btn-block">DOWNLOAD RECOVERY KIT</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>IMPORT RECOVERY KIT</h3>
            <p class="skin-desc-text">
                Upload a previously exported .tar.gz to restore your database
                and verify your file inventory. The SQL dump is imported directly;
                media files listed in the manifest must be restored separately
                via FTP or Cloud Backup.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_recovery">
                <div class="file-upload-wrapper" onclick="document.getElementById('recovery-input').click()">
                    <div class="file-custom-btn">SELECT FILE</div>
                    <div class="file-name-display" id="recovery-name">SELECT .TAR.GZ FILE</div>
                    <input type="file" name="recovery_file" id="recovery-input" accept=".tar.gz,.gz"
                           class="file-input-hidden"
                           onchange="document.getElementById('recovery-name').innerText = this.files[0].name;">
                </div>
                <!-- SS-1430. The two controls the server now requires. They are
                     here so the action is reachable; they are NOT the control.
                     The enforcement is in the POST handler above, because a
                     requirement that lives in a form is a suggestion. -->
                <label class="skin-desc-text" for="restore-target">
                    TYPE THIS SITE'S ADDRESS TO CONFIRM THE TARGET:
                    <strong><?php echo htmlspecialchars($restore_target_display, ENT_QUOTES); ?></strong>
                </label>
                <input type="text" name="confirm_target" id="restore-target"
                       autocomplete="off" spellcheck="false" required
                       placeholder="<?php echo htmlspecialchars($restore_target_display, ENT_QUOTES); ?>">

                <label class="skin-desc-text" for="restore-pass">ACCOUNT PASSWORD</label>
                <input type="password" name="reauth_password" id="restore-pass"
                       autocomplete="current-password" required>

                <label class="skin-desc-text" for="restore-totp">6-DIGIT AUTHENTICATOR CODE</label>
                <input type="text" name="reauth_totp" id="restore-totp"
                       inputmode="numeric" autocomplete="one-time-code"
                       pattern="[0-9]{6}" maxlength="6" required>

                <button type="submit" class="btn-smack btn-block"
                        onclick="return confirm('This will overwrite your database and files. It cannot be undone from this page. Continue?');">
                    IMPORT RECOVERY KIT
                </button>
            </form>
        </div>

    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
