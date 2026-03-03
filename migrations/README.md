# SnapSmack Schema Migrations

This directory holds SQL migration files that the updater applies automatically
when upgrading between versions.

## Naming Convention

```
migrate_FROM_TO.sql
```

Examples:
- `migrate_0.6_0.7.sql` — changes needed when upgrading from 0.6 to 0.7
- `migrate_0.7_0.8.sql` — changes needed when upgrading from 0.7 to 0.8

## How It Works

1. The updater reads the current `installed_version` from `snap_settings`.
2. It scans this directory for any migration files chained from the current
   version to the target version.
3. Migrations are applied in order. If upgrading from 0.6 → 0.8, both
   `migrate_0.6_0.7.sql` and `migrate_0.7_0.8.sql` will run sequentially.
4. Each migration runs inside a transaction where possible.
5. A forced backup is taken BEFORE any migration runs.

## Writing Migrations

- Use `ALTER TABLE` and `CREATE TABLE IF NOT EXISTS` — never `DROP TABLE`.
- Keep migrations idempotent where possible (e.g., check column existence).
- Comment each change so the admin UI can display a human-readable changelog.
- Test on both MySQL 5.7+ and MariaDB 10.3+.

## Important

Never delete old migration files. The updater needs the full chain for users
who skip versions.
