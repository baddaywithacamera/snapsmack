# OPAUDIT 029 — Database overload and recovery

> **DRAFT — unpublished.** Prepared from the retained 7 November incident record and its follow-up. This is dated evidence, not a fresh fleet inspection.

| Field | Value |
|---|---|
| Audit ID | 2026-11-07-029 |
| State | OPEN. Recovery and source checks are recorded; acceptance work remains. |
| Scope | The CT110 database overload incident, recovery, statement limit, and the missing operational guard that allowed one query shape to run for minutes. |
| Ticket | No single Ticket Wicket record was identified for the incident at filing time. |

## What happened

The owner stopped the shared database container after server load made its shell difficult to open. Sites depending on that database then became unavailable. The container was restarted through the authenticated administration shell.

## Evidence recorded during the incident

Database process inspection showed archive visibility queries running for 363, 305 and 280 seconds. Their repeated correlated visibility checks were observed. Random ordering was a separate hypothesis, not the demonstrated cause of those statements.

Following restart, recorded memory use was approximately 848 MiB of 4 GiB, with no swap in use. A CPU percentage quoted earlier was a lifetime average and is not presented here as an instantaneous measurement.

The offending archive predicate was repaired in commit `5c42ae83`; the measured replacement query completed in 28 ms in the retained incident record. A production-sized MariaDB timing test now guards that predicate, but the test reports NOT CHECKED when no MariaDB connection is supplied and no repository CI workflow was found that supplies one.

## Recovery and limits

A 30-second database statement limit was applied and read back, then persisted and verified through the server configuration parser. The incident record reports CMS 0.7.986D installed. Those steps are recovery evidence, not proof that every expensive query or long-running maintenance job is safe.

Limited host inspections did not establish either compromise or a clean bill of health. Full swap alone also does not establish active swapping.

## What remains open

1. Exercise backup, import and migration jobs against the statement limit.
2. Prove slow-query monitoring, deduplicated failure alerts and recovery alerts on an isolated installation.
3. Verify sustained site availability under representative load.
4. Run the query budget test on the release path with a real MariaDB so NOT CHECKED cannot silently satisfy the gate.

## What to check in your own project

1. Can one public request trigger a query that scans the same large table once for every result row?
2. Do query-time limits protect interactive traffic without breaking backups, imports or migrations?
3. Does a production-sized timing check actually run, or can it pass as NOT CHECKED when its database is absent?
4. Will operators receive one clear alert when a query fails and another when service recovers?
5. After recovery, do you verify sustained availability rather than treating a successful restart as closure?

## What this audit does not claim

- It does not claim the database was compromised.
- It does not claim the statement limit has been exercised against every maintenance job.
- It does not claim the timing guard currently runs in CI.
- It does not claim the remaining SQL sweep is complete; that work is OPAUDIT 030.

Prepared by Codex with AI assistance. Status: **OPEN**.

<!-- ===== SNAPSMACK EOF ===== -->
