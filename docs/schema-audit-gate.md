<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->
<!-- ===== SNAPSMACK EOF ===== -->
# SnapSmack — Schema Completeness Audit Gate

*Context doc for web Claude discussion. Written 2026-06-07 after 0.7.215.*

---

## What we built and why

SnapSmack is a self-hosted PHP photoblog platform with a canonical schema system:
`database/schema/snapsmack_canonical.sql` defines all 50 expected tables. On
update, `updater_canonical_diff()` diffs a live DB against that schema and
creates any missing tables. The idea is that any install — regardless of how
many versions were skipped — comes up complete.

The problem: multiple versions shipped where PHP code referenced tables that
were never actually defined in the canonical schema. Installs accumulated silent
gaps. No one noticed because SnapSmack falls back gracefully on unused features.
One install was found to be missing 33 tables.

The fix was two-part: a migration to create the 33 missing tables, and a
structural gate to make this impossible going forward.

---

## The gate: two layers

### Layer 1 — `tools/check-schema.php` (standalone CLI)

Run from repo root before any push:

```
php tools/check-schema.php
```

Scans all `.php` files (excluding `smack-central/`, `vendor/`, `node_modules/`)
for SQL keyword + `snap_*` table-name references using this regex family:

```
\b(?:FROM|JOIN|INTO|UPDATE|ALTER TABLE|TRUNCATE|CREATE TABLE|DROP TABLE)\s+`?(snap_[a-z_]+)`?
```

Parses `database/schema/snapsmack_canonical.sql` for `CREATE TABLE` names.
Cross-references. Reports any tables in PHP not in schema. Exits 1 on gaps,
0 if clean.

### Layer 2 — `smack-central/sc-release.php` Step 4d

The Smack Central release packager builds zips from the GitHub tag. After the
zip is built, it reads `database/schema/snapsmack_canonical.sql` from inside
the zip, then calls `sc_audit_schema_completeness($schema_content, $zip_dest)`,
which scans every `.php` entry in the same zip.

If gaps are found, `$build_error` is set and the build aborts before:
- Publishing the canonical schema to snapsmack.ca
- Writing `latest.json`
- Saving the release record to DB

The build log will show:
```
→ SCHEMA AUDIT FAILED: snap_whatever, snap_something_else
```

The zip is already written to disk but nothing downstream runs, so it cannot
be distributed.

---

## What happens when the build fails

### What the operator sees on SC

The build log shows `→ SCHEMA AUDIT FAILED: snap_whatever` with the missing
table names. No download link appears. No `latest.json` is written.

### Recovery — there is one path

```
php tools/check-schema.php
```

That tells you exactly which tables are missing and which PHP files reference
them. Fix what it says:

1. Add the missing table(s) to `database/schema/snapsmack_canonical.sql` —
   full `CREATE TABLE IF NOT EXISTS` DDL, `utf8mb4_unicode_ci`, PKs, indexes,
   before the `-- ===== SNAPSMACK EOF =====` marker.

2. Write `migrations/migrate-<name>.sql` with the same DDL (IF NOT EXISTS —
   idempotent).

3. Register the migration filename in `core/updater.php` →
   `UPDATER_KNOWN_MIGRATIONS`.

4. `php tools/check-schema.php` exits 0.

5. Push + rebuild on SC. Passes.

### What about already-deployed installs?

The migration handles them. `updater_find_migrations()` runs pending migrations
in alphabetical order on every update. The new migration creates the table IF
NOT EXISTS — idempotent, safe to run on installs that already have it.

---

## How the schema stays current going forward

The gate closes the loop:

```
Developer adds PHP query referencing snap_new_table
         ↓
php tools/check-schema.php → exits 1
         ↓
Developer adds table to canonical schema + writes migration
         ↓
SC build → sc_audit_schema_completeness() → passes
         ↓
Release ships. Installs get table via migration on update.
```

The canonical schema is not a living document that auto-updates — it is a
manually maintained file that the audit tool verifies is complete. The gate
means "complete" is enforced by the build process, not by human memory.

---

## Files involved

| File | Role |
|---|---|
| `database/schema/snapsmack_canonical.sql` | Source of truth — 50 tables as of 0.7.215 |
| `tools/check-schema.php` | CLI audit; run before every push |
| `smack-central/sc-release.php` | Build packager; `sc_audit_schema_completeness()` function + Step 4d call |
| `core/updater.php` | `updater_canonical_diff()` applies schema at update time; `UPDATER_KNOWN_MIGRATIONS` gates migration runner |
| `migrations/migrate-create-missing-tables.sql` | One-time fix for 33 tables missing from old installs |

---

## Gotchas

- The bash/WSL FUSE mount at `/sessions/.../mnt/snapsmack/` is stale. Always
  use `php tools/check-schema.php` from Windows git bash, not WSL.
- `smack-central/` is excluded from the PHP scan — SC is its own app with its
  own schema (`smack-central/schemas/sc-smackcent-canonical.sql`).
- The regex catches table names following SQL keywords. Dynamic table name
  construction (e.g. `"snap_" . $type`) won't be caught. None currently exist
  in the codebase but add to `$known_dynamic` in check-schema.php if needed.
- Migration files must be in `UPDATER_KNOWN_MIGRATIONS` or they are skipped as
  ghost files — this is a security control, not a bug.

<!-- ===== SNAPSMACK EOF ===== -->
