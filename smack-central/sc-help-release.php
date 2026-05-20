<?php
/**
 * SMACK CENTRAL - Release Systems Reference
 *
 * Documents the full release pipeline: version bumping, git tagging,
 * the SnapSmack release packager, and the Smack Central self-updater.
 */

require_once __DIR__ . '/sc-auth.php';

$sc_page_title = 'Release Systems Reference';
$sc_active_nav = 'sc-help-release.php';
include __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
    <h1 class="sc-page-title">Release Systems Reference</h1>
    <p class="sc-page-sub">How SnapSmack and Smack Central releases are built, tagged, and deployed.</p>
</div>

<div class="sc-help-toc sc-card" style="margin-bottom:24px;">
    <strong>Jump to:</strong>
    <a href="#version-numbering">Version numbers</a> ·
    <a href="#release-script">Release script</a> ·
    <a href="#git-workflow">Git workflow</a> ·
    <a href="#release-packager">Release Packager</a> ·
    <a href="#sc-updater">Smack Central updater</a> ·
    <a href="#bootstrapping">Bootstrapping a new server</a>
</div>


<!-- ── VERSION NUMBERING ───────────────────────────────────────────── -->
<div class="sc-card sc-help-section" id="version-numbering">
    <h2 class="sc-card-title">Version Numbers</h2>

    <p>SnapSmack uses <code>MAJOR.MINOR.PATCH[letter]</code> with a codename. The letter is a patch increment — <code>a</code> means first patch, <code>b</code> second, and so on. No letter means the base release.</p>

    <table class="sc-help-table">
        <thead><tr><th>Segment</th><th>Example</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td>Major</td><td><code>0</code></td><td>Pre-release era. Will flip to 1 at stable launch.</td></tr>
            <tr><td>Minor</td><td><code>7</code></td><td>Feature milestone.</td></tr>
            <tr><td>Patch</td><td><code>9</code></td><td>Increment within a milestone.</td></tr>
            <tr><td>Letter</td><td><code>e</code></td><td>Sub-patch fix or small addition. <code>a=1, b=2</code>…</td></tr>
            <tr><td>Codename</td><td><code>Recliner</code></td><td>All codenames are sitting-related. Decorative only.</td></tr>
        </tbody>
    </table>

    <p class="sc-help-note">PHP's <code>version_compare()</code> treats trailing letters as alpha (lower than no suffix). SnapSmack's <code>snap_version_compare()</code> in <code>core/constants.php</code> normalises the letter to a fourth numeric segment before comparing. Always use that function — never raw <code>version_compare()</code>.</p>

    <p>The version string lives in three places. The release script updates all three at once:</p>
    <ul class="sc-help-list">
        <li><code>core/constants.php</code> — <code>SNAPSMACK_VERSION</code>, <code>SNAPSMACK_VERSION_SHORT</code>, <code>SNAPSMACK_VERSION_CODENAME</code></li>
        <li><code>smack-central/sc-version.php</code> — <code>SC_VERSION</code>, <code>SC_CODENAME</code></li>
        <li><code>CHANGELOG.md</code> — prepends a new section header</li>
    </ul>
</div>


<!-- ── RELEASE SCRIPT ─────────────────────────────────────────────── -->
<div class="sc-card sc-help-section" id="release-script">
    <h2 class="sc-card-title">Release Script — <code>tools/release.py</code></h2>

    <p>Bumps the version string across all three files in one command. Run it from the repo root.</p>

    <pre class="sc-help-code">python3 tools/release.py 0.7.9f "Footrest"</pre>

    <p>Output:</p>
    <pre class="sc-help-code">SnapSmack release: 0.7.9f "Footrest"  (2026-04-10)

  patched  core/constants.php
  patched  smack-central/sc-version.php
  patched  CHANGELOG.md

Done. Review the diff, fill in CHANGELOG.md, then:

  git add core/constants.php smack-central/sc-version.php CHANGELOG.md
  git commit -m "Bump to Alpha v0.7.9f \"Footrest\""
  git tag 0.7.9f
  git push Github master &amp;&amp; git push Github 0.7.9f</pre>

    <p>The script is idempotent — running it again with the same version is safe. The CHANGELOG entry is only prepended once. If you test-run with a dummy version, run it again with the real version to revert.</p>
</div>


<!-- ── GIT WORKFLOW ───────────────────────────────────────────────── -->
<div class="sc-card sc-help-section" id="git-workflow">
    <h2 class="sc-card-title">Git Workflow</h2>

    <p>The release packager and the Smack Central updater both read <strong>git tags</strong>. Tags must be pushed to GitHub before either system can see a new release.</p>

    <p>Full sequence for a new release:</p>

    <ol class="sc-help-ordered">
        <li>Do the work. Commit freely to master.</li>
        <li>Run <code>python3 tools/release.py &lt;version&gt; "&lt;Codename&gt;"</code>.</li>
        <li>Fill in the CHANGELOG.md entry — what actually changed.</li>
        <li>Stage and commit the version bump:<br>
            <code>git add core/constants.php smack-central/sc-version.php CHANGELOG.md</code><br>
            <code>git commit -m "Bump to Alpha v0.7.9f \"Footrest\""</code></li>
        <li>Tag it: <code>git tag 0.7.9f</code></li>
        <li>Push commits and tag: <code>git push Github master &amp;&amp; git push Github 0.7.9f</code></li>
        <li>Run the Release Packager here in Smack Central. It will see the new tag.</li>
    </ol>

    <p class="sc-help-note">If the packager is not seeing the new version, the tag was not pushed. Run <code>git tag --list | sort -V | tail -5</code> locally and <code>git ls-remote --tags Github | tail -5</code> to compare local vs remote tags.</p>

    <h3 class="sc-help-subhead">Tagging a commit that already happened</h3>
    <p>If you need to retroactively tag an older commit (e.g. you forgot to tag 0.7.9d):</p>
    <pre class="sc-help-code">git log --oneline          # find the commit SHA
git tag 0.7.9d &lt;sha&gt;
git push Github 0.7.9d</pre>

    <h3 class="sc-help-subhead">Remote name</h3>
    <p>The GitHub remote is <code>Github</code> (capital G). This is a proxy limitation — the VM cannot push directly. Push from your local machine.</p>
</div>


<!-- ── RELEASE PACKAGER ───────────────────────────────────────────── -->
<div class="sc-card sc-help-section" id="release-packager">
    <h2 class="sc-card-title">Release Packager</h2>

    <p>The Release Packager (<a href="sc-release.php">Operations → Release Packager</a>) reads the git tags from the GitHub repo and builds a downloadable release package for SnapSmack installs to pull via the updater.</p>

    <p>The packager reads tags — not branch tips, not commit messages. A version is invisible to the packager until its tag is pushed to GitHub. This is intentional: work-in-progress commits on master do not accidentally become available to updaters.</p>

    <p>Run the packager after pushing the tag. It handles the rest — zip assembly, manifest injection, making the release visible to the SnapSmack update checker.</p>
</div>


<!-- ── SMACK CENTRAL UPDATER ──────────────────────────────────────── -->
<div class="sc-card sc-help-section" id="sc-updater">
    <h2 class="sc-card-title">Smack Central Updater</h2>

    <p>The updater (<a href="sc-update.php">System → Update</a>) pulls Smack Central itself from GitHub. It is separate from the SnapSmack release packager — it updates this dashboard, not the blog software.</p>

    <h3 class="sc-help-subhead">How it works</h3>
    <ol class="sc-help-ordered">
        <li>Fetches the latest git tag from the GitHub API (<code>repos/{repo}/tags</code>).</li>
        <li>Compares it against the installed tag stored in <code>sc_settings.sc_installed_ref</code>.</li>
        <li>If different, shows an "Update available" button.</li>
        <li>On pull: downloads the tag's zip, extracts the <code>smack-central/</code> subtree, copies files into place, runs <code>sc-schema.sql</code> idempotently, records the new tag.</li>
    </ol>

    <p><strong>What is never touched:</strong> <code>sc-config.php</code>. Your database credentials and GitHub token are always preserved.</p>

    <p><strong>What is always updated:</strong> every other file in <code>smack-central/</code>, including this help page.</p>

    <h3 class="sc-help-subhead">Tag-based, not branch-based</h3>
    <p>The updater pulls from the latest <em>tagged</em> release, not from the master branch tip. This means work-in-progress commits on master never land on a live server until you tag and push a release. If you push commits but not a new tag, the updater sees no update available — that is correct behaviour.</p>

    <h3 class="sc-help-subhead">Schema updates</h3>
    <p><code>sc-schema.sql</code> is a canonical full schema. The updater runs it statement-by-statement on every pull, ignoring errors that mean "already applied" (table exists, duplicate column, duplicate key). New tables and columns appear automatically. Nothing is ever dropped.</p>
</div>


<!-- ── BOOTSTRAPPING ──────────────────────────────────────────────── -->
<div class="sc-card sc-help-section" id="bootstrapping">
    <h2 class="sc-card-title">Bootstrapping a New Server</h2>

    <p>On a fresh server, the Smack Central updater is not yet present — so you can't use the updater to install itself. The initial install is a one-time manual step.</p>

    <ol class="sc-help-ordered">
        <li>FTP the full <code>smack-central/</code> directory to the server. Include <code>sc-version.php</code>.</li>
        <li>Create <code>sc-config.php</code> from <code>sc-config.sample.php</code> and fill in credentials.</li>
        <li>Run <code>sc-setup.php</code> once to initialise the database tables.</li>
        <li>Log in. Navigate to System → Update and click Pull to record the installed tag baseline.</li>
    </ol>

    <p>After step 4, all future Smack Central updates come through the updater — no more FTP.</p>

    <p class="sc-help-note">If you skip the initial Pull in step 4, the updater will show the installed ref as "Not recorded" and always report an update available. It will still work correctly — it just won't know what version it started from.</p>
</div>

<style>
.sc-help-section { margin-bottom: 24px; }
.sc-help-section p { font-size: 0.88rem; line-height: 1.6; margin: 0 0 10px; color: var(--sc-text, #ccc); }
.sc-help-subhead { font-size: 0.85rem; font-weight: 600; color: var(--sc-text, #ccc); margin: 18px 0 6px; }
.sc-help-table { border-collapse: collapse; width: 100%; font-size: 0.82rem; margin: 10px 0 14px; }
.sc-help-table th,
.sc-help-table td { padding: 7px 12px; text-align: left; border-bottom: 1px solid var(--sc-border, #2a2a2a); }
.sc-help-table th { color: var(--sc-text-muted, #888); font-weight: 500; }
.sc-help-table code { font-size: 0.8rem; }
.sc-help-list,
.sc-help-ordered { font-size: 0.88rem; line-height: 1.7; margin: 8px 0 12px 20px; color: var(--sc-text, #ccc); }
.sc-help-list li,
.sc-help-ordered li { margin-bottom: 4px; }
.sc-help-code { background: var(--sc-input-bg, #1a1a1a); border: 1px solid var(--sc-border, #2a2a2a); border-radius: 4px; padding: 12px 14px; font-family: monospace; font-size: 0.78rem; line-height: 1.6; color: #b0c4b0; white-space: pre; overflow-x: auto; margin: 8px 0 14px; }
.sc-help-note { background: rgba(230, 168, 23, 0.07); border-left: 3px solid #e6a817; padding: 8px 12px; border-radius: 0 4px 4px 0; font-size: 0.82rem !important; color: var(--sc-text-muted, #888) !important; }
.sc-help-toc { font-size: 0.84rem; }
.sc-help-toc a { color: var(--sc-accent, #5b9bd5); margin: 0 2px; }
</style>

<?php include __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // EOF
