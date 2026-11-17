# SnapSmack 0.7.993D — serialized rollout verification

<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical Markdown EOF marker.
-->

> Verification-only runbook. Sean builds, signs, publishes, deploys, supplies credentials/2FA, and decides whether to continue or roll back. This document does not authorize any of those actions.

## Non-negotiable rollout rule

**Do not use Update All for the first 0.7.993D rollout. Update exactly one site, verify it completely, and only then choose the next site. Never update two sites in parallel.**

## 1. Preflight

Run from a clean repository checkout:

```powershell
$expectedTag = '8efdabcc0d69d04cc54607dafac79237608b374e'
$actualTag = (git ls-remote --tags Github refs/tags/v0.7.993D).Split("`t")[0]
if ($actualTag -ne $expectedTag) { throw "STOP: v0.7.993D is $actualTag, expected $expectedTag" }
```

Before selecting a site, Sean confirms:

- the owner-built, signed 0.7.993D package is the release being offered;
- the normal updater recognizes its signature and version;
- a tested site-specific recovery path exists;
- no other update is running and no other site will be started until this one passes;
- the chosen site's current version, URL, UTC start time, and baseline SMACKBACK state are recorded;
- any pre-existing SMACKBACK warning is understood and captured before the update.

**STOP** if the tag differs, the package identity or signature is uncertain, the site is already in an unexplained breach/lockout, recovery is unavailable, or another update is active.

## 2. Record the before-state

For the one selected site, save:

1. a screenshot of the installed-version/update screen;
2. a screenshot of SMACKBACK showing status, response mode, last verification time, and any warning;
3. the latest updater log excerpt;
4. a public home-page response check:

```powershell
$site = 'https://REPLACE-WITH-ONE-SITE.example'
$before = Invoke-WebRequest -Uri $site -MaximumRedirection 5
if ($before.StatusCode -ne 200) { throw "STOP: public page returned $($before.StatusCode) before update" }
```

Do not put credentials, passwords, 2FA codes, cookies, or database contents in the evidence.

## 3. Owner performs one update

Sean updates **only the recorded site** through the normal authenticated updater. Do not use Update All. Do not begin a second site while the first site's result is incomplete.

## 4. Verify the one site

After the updater finishes, perform all checks before continuing:

- The admin update screen reports installed version **0.7.993D** and success for this site.
- The update log contains the normal successful completion and baseline-refresh result. A missing staged package, missing baseline result, or success without baseline confirmation is a failure.
- SMACKBACK is not in an unexpected BREACH or LOCKOUT. Record its post-update status and last verification time.
- The scheduled verifier did not report shipped 0.7.993D files as tampered during the update.
- A normal verification after the update lock clears completes without a false breach.
- The public home page and admin login route respond normally:

```powershell
$adminEntry = 'https://REPLACE-WITH-OWNER-CONFIRMED-ADMIN-ENTRY.example'
$public = Invoke-WebRequest -Uri $site -MaximumRedirection 5
$admin = Invoke-WebRequest -Uri $adminEntry -MaximumRedirection 5
if ($public.StatusCode -ne 200 -or $admin.StatusCode -ne 200) {
    throw "STOP: public/admin route failed after update"
}
```

- The site's version evidence, updater receipt, SMACKBACK screenshot, and UTC finish time are saved together.

The SS-1284 failure mode is specifically tested by the evidence above: one site must retain its own staged workspace, refresh its own baseline, report that outcome, and let scheduled verification resume only after the update lock clears.

## 5. Pass/fail gate

Mark the site **PASS** only when every item in section 4 has evidence. Anything unmeasured is **NOT CHECKED**, never a pass.

Mark **FAIL** and stop the rollout for any of these:

- wrong version, tag, package, signature, or package identity;
- updater success without a confirmed baseline refresh;
- missing staged package or evidence of a shared/changed update workspace;
- new SMACKBACK warning, false tamper report, BREACH, or LOCKOUT;
- verification running through an active update lock;
- public or admin route failure, PHP error, incomplete page, or unexpected file warning;
- recovery uncertainty or evidence that cannot be attributed to this one site.

On failure: do not update another site, do not click Restore or Re-bless merely to clear the alert, and do not erase logs or incident evidence. Sean chooses the recovery or rollback path. After recovery, repeat the before-state checks; a recovered site does not authorize continuing the fleet.

## 6. Continue serially

Only Sean may decide to proceed after a documented PASS. Select one next site, repeat sections 2–5, and keep Update All unused for this first rollout. Record each site independently as PASS, FAIL, or NOT CHECKED.

<!-- ===== SNAPSMACK EOF ===== -->
