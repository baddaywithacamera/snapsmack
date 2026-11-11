<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string SNAPSMACK EOF, a space, and five equals.
-->

# SECAUDIT 065 - Injection and prepared-statement sweep

| Field | Value |
|---|---|
| Audit ID | 2026-11-08-065 |
| Audit date | 2026-11-08 |
| Read point | `origin/dev` at `fa8c92fe7` (SnapSmack 0.7.988D) |
| Scope | Production PDO call sites in the SnapSmack repository: prepared statements, direct queries, direct execution, interpolation-shaped SQL, superglobal flow, dynamic ordering and limits, `LIKE` construction, and package-derived schema execution. |
| Auditor | Muse, with every HIGH and door item personally re-read before reporting. |
| Filing verification | Codex checked the supplied classification table's arithmetic and preserved its method, findings, qualifications, and limits. This filing is not an independent repeat of the 2,877-site source sweep. |
| Status | AUDIT COMPLETE; PUBLICATION PENDING OWNER APPROVAL. No working injection path was found in the classified call sites. This is not a claim that injection is impossible. |
| Door rule | NOT TRIGGERED. No finding carried a door flag. |

## Executive summary

The sweep classified 2,877 production PDO call sites by argument shape and then hand-read the value-carrying patterns that automated classification surfaced. It found no working SQL-injection path in the classified call sites. No superglobal was found reaching SQL text, every database connection disabled emulated prepares, and user values at the inspected prepared-statement sites reached SQL through placeholders.

The result is deliberately narrower than “the code cannot be injected.” The review was a source-pattern and hand-tracing exercise at one fixed commit. It did not run attack payloads against a live site, did not use a production database, and could miss a stored or multi-function flow whose assembled query was not visible to the method.

The audit recorded one MEDIUM semantic/performance finding and several LOW structural or brittle-shape findings. The MEDIUM item is not SQL injection: bound `LIKE` terms do not escape `%` and `_`, so a visitor can widen a search pattern and its cost without changing the SQL grammar.

## Method

The review combined:

1. automated classification of every production PDO call site by method and argument shape;
2. pattern searches for superglobals, interpolated `ORDER BY` and `LIMIT`, `LIKE` construction, date-wrapped columns, random ordering, and queries inside loops;
3. hand-reading of every surfaced value-carrying pattern;
4. a second personal read of every HIGH candidate and every item that could have triggered the project's door rule.

The method separated static SQL text from pre-built fixed-string assemblies, placeholder-list builders, whitelisted fragments, integer-cast limits, schema-definition DDL, and other dynamic-looking but bounded shapes. It treated a signed update package as the trust boundary for package-derived schema execution rather than labelling every dynamic DDL call as injection.

## Call-site census

| PDO shape | Total | Fully static SQL text | Other classified shapes |
|---|---:|---:|---:|
| `prepare()` | 1,743 | 1,582 | 161: 30 pre-built fixed-string SQL values and 131 interpolated-fragment sites |
| `query()` | 889 | 796 | 93 |
| `exec()` | 245 | 205 | 40, mostly schema and compatibility DDL |
| **Total** | **2,877** | **2,583** | **294** |

Additional API-shape counts:

- `execute()`: 1,796
- `bindValue()`: 44
- `bindParam()`: 0
- `$pdo->quote()`: 11, all settings-key lookups in the supplied classification

The supplied table is arithmetically consistent: 1,743 + 889 + 245 = 2,877, and the three static counts total 2,583. The 1,582 static `prepare()` sites are 90.8% of 1,743, reported as 91% after rounding. “186 interpolation-shaped sites” is a hand-read subset of the 294 non-static-text sites, not another total for that column; the balance includes fixed-string variables, DDL, and other classified argument shapes.

## Interpolation-shaped sites

The 186 interpolation-shaped sites were hand-categorised. The reported categories were approximate because several builders contain more than one bounded shape:

- placeholder lists for `IN (...)`: about 60;
- DDL and column fragments selected from fixed literals, hard-coded maps, or schema-existence branches: about 35;
- whitelisted `ORDER BY` fragments: 11;
- integer-cast and clamped `LIMIT` / `OFFSET`: about 20;
- installer prefix use: 6, with the prefix fixed to `snap_` rather than user-configurable;
- `$where` / `$joins` builders made from fixed fragments plus placeholders and parallel parameter arrays: about 15;
- authentication key-type fragments checked with `in_array()` before query construction: 5;
- `$pdo->quote()` settings-key lookups: 11;
- variable-argument `query()` / `exec()` sites: about 35, consisting of fixed-string assemblies or DDL derived from schema definitions.

No superglobal was found reaching SQL text in the classified sites.

## Findings

### A0. No central query wrapper - LOW structural

`core/db.php` exposes the raw PDO connection. The strong result in this audit therefore comes from project-wide discipline rather than an architectural boundary that prevents a future caller from interpolating a value.

No urgent rewrite is recommended. A thin query wrapper or a gate that rejects newly introduced raw dynamic-query shapes would make the convention enforceable.

### A1. `LIKE` wildcard characters are not escaped - MEDIUM semantic/performance

The sweep identified 289 `LIKE` sites and no SQL `ESCAPE` clause. Search terms are bound, so this is not SQL injection. A visitor can nevertheless type `%` or `_` and widen a search beyond the literal characters requested, including on public endpoints. That can change results and increase scan cost.

Recommended follow-up: escape `%`, `_`, and the chosen escape character in user-supplied search terms and add an explicit SQL `ESCAPE` clause at the affected search sites. Inventory and batching should precede a broad mechanical edit.

### A2. Server datetime interpolated into public landing query - LOW

At the audited read point, `index.php` around lines 1064-1068 built `$now_local` with `date('Y-m-d H:i:s')`, interpolated it into `$where_live`, and executed the resulting string with `query()`.

The value is server-generated and the reviewed shape is safe. Binding it would remove a brittle dynamic-query shape and make later changes safer.

### A3. Prepared delete followed by an interpolated re-query - LOW

`smack-central/sc-assets.php` around lines 231-236 used the boolean result of a prepared statement as the branch condition for a second query. The second query interpolated `$del_id`, but that value was integer-cast and was not injectable.

The concern is control flow: a failed execute silently changes which query runs. The prepared statement should supply the result directly rather than gating a second query.

### A4. Update-package schema block passed to `exec()` - design note, suspected boundary risk

`core/updater.php` around lines 2095-2103 executed a `CREATE TABLE` block extracted from the update package's schema text. The security boundary is the package signature and verification path, not user input to this call.

No injection fix is proposed by this audit. The finding records that package authenticity is the control on which this dynamic DDL depends.

### A5. Table identifiers from `SHOW TABLES` enumeration - LOW recorded shape

`smack-backup.php` around lines 54 and 70 and `core/export-engine.php` around lines 421 and 433 constructed `SELECT * FROM \`{table}\`` using names returned by the database's own `SHOW TABLES` enumeration. The paths are administrator-only and the reviewed source of the identifiers is bounded.

The shape is safe as reviewed. If the identifier source changes, validate it against the enumeration before constructing SQL.

## What this audit did not prove

- No live site or live database was exercised.
- No `EXPLAIN` plans or production timings were collected.
- Stored and second-order flows were outside the method: a value written by one endpoint and later concatenated by another may not appear as an immediate source-to-sink path.
- Query fragments assembled across more than one function were not guaranteed to be followed end to end.
- The report describes `fa8c92fe7`; later code requires its own review or a delta gate.
- “No working injection path found in the classified call sites” must not be shortened to “injection is impossible” or “the product is injection-proof.”

## Disposition

The injection-review scope is complete at the stated read point. No door condition was found and no working injection path was demonstrated. A1 remains a real, non-injection MEDIUM finding about wildcard semantics and query cost; A0, A2, A3 and A5 are LOW hardening or control-flow items; A4 records the signed-package trust boundary.

Public listing on BUZZERS is held until Sean approves the finding list and publication wording. That approval may describe this as a closed injection audit only if the public summary retains the exact limited headline and does not imply that the remaining findings were fixed or that injection is impossible.

<!-- ===== SNAPSMACK EOF ===== -->
