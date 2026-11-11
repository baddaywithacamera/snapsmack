# OPAUDIT 030 — SQL and search query costs

> **DRAFT — unpublished.** Prepared from the retained 8 November SQL sweep and subsequent verified branch history. It is not installed-fleet acceptance.

| Field | Value |
|---|---|
| Audit ID | 2026-11-08-030 |
| State | OPEN. Nine source changes have evidence; random selection, search semantics, release assembly and installed behavior remain open. |
| Scope | The SQL/search sweep triggered by OPAUDIT 029, covering query shapes that can become table scans or unbounded work on public and machine-polled paths. |
| Related record | OPAUDIT 029 records the CT110 incident and the `imageFilter` timing guard. |

## What was found

A pattern sweep of 433 PHP files identified eleven risky query shapes: correlated per-row checks, random ordering, date functions on indexed columns, unbounded metadata reads, missing reverse relationship indexes, schema work on a GET path, and search behavior whose semantics had not been fully measured.

The initial report ranked three items HIGH, four MEDIUM and four LOW. Severity was based on mechanism, table size and call frequency. It was not a production timing measurement.

## Source changes and measured checks

Commit `27235e0c` repaired the three HIGH hot paths. The query-cost branch repaired four more items: calendar filters now use half-open date ranges, the reverse category relationship has an index, justified metadata batches are bounded, and schema alteration was removed from an archive GET path.

An isolated MariaDB benchmark with 40,000 rows passed nine checks, including calendar result parity, indexed query plans and bounded repository batches. Current `dev` contains those changes. This is isolated database evidence, not installed-fleet acceptance.

The review also corrected earlier claims: listing exclusion flags concern discoverability and were not demonstrated to expose private content; the archive repository cap was 5,000, not 10,000; an existing checksum index did not prove efficient computed duplicate grouping; and a slug-index repair already belonged to separate work.

## What remains open

1. Measure remaining random-selection costs while preserving publication eligibility and uniqueness.
2. Verify search enablement, token matching, hashtags and literal wildcard behavior through the complete public route.
3. Decide and measure search semantics before considering FULLTEXT; the earlier experiment lost substring matches and was not a drop-in replacement.
4. Extend production-sized timing coverage beyond the single OPAUDIT 029 predicate, or record the gap as accepted.
5. Verify the assembled release and installed behavior independently of branch tests.

## What to check in your own project

1. Do public list pages contain correlated subqueries that repeat a table scan for every returned row?
2. Do indexed date columns stay bare in filters, or are they wrapped in functions that defeat the index?
3. Are random-order and search queries measured with production-sized data and real user semantics?
4. Are metadata and relationship reads bounded, with indexes in both directions used by the application?
5. Does your release gate run the expensive-query tests against the same database family used in production?

## What this audit does not claim

- It does not claim the eleven items are exhaustive.
- It does not claim production timings for the sweep items.
- It does not claim random selection or search semantics are resolved.
- It does not claim isolated benchmark results prove the assembled or installed release.

Prepared by Codex from the Muse SQL sweep and repository evidence. Status: **OPEN**.

<!-- ===== SNAPSMACK EOF ===== -->
