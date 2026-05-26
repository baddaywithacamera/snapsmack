# Phase 1 Git Commands — Run These in the Morning

Prerequisite: 0.7.184 code is ready on master. Run from git bash in C:\dev\snapsmack.

## Step 1 — Tag and push master as stable 0.7.184

```bash
git add -A
git commit -m "0.7.184 — multisite version fix, skin JS scanner, archive landing page, Phase 2 track infrastructure"
git tag -f v0.7.184
git push Github master
git push Github --tags --force
```

## Step 2 — Create dev branch from that exact commit

```bash
git checkout -b dev
```

## Step 3 — Bump version to 0.7.184D on dev

Edit `core/constants.php`:
- `SNAPSMACK_VERSION`       → `'Alpha 0.7.184D'`
- `SNAPSMACK_VERSION_SHORT` → `'0.7.184D'`

Then commit:

```bash
git add core/constants.php
git commit -m "dev: bump to 0.7.184D — first dev branch commit"
git push Github dev
```

## Done

- `master` = stable 0.7.184 — what live sites pull
- `dev`    = 0.7.184D     — where all future work goes

All future Cowork sessions work on `dev`. Merges to `master` need joint sign-off.

<!-- ===== SNAPSMACK EOF ===== -->
