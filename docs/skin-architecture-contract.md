<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# Skin architecture contract

## The rule

**Skins present. The CMS decides and acts.**

A SnapSmack skin is a presentation layer. It may provide declarative manifest data,
markup templates, and CSS. It must not own or implement application behavior.

In particular, a skin must not:

- query or mutate the database;
- receive a database connection or construct SQL;
- read request globals or decide routes and response formats;
- set headers, cookies, sessions, status codes, or cache policy;
- authorize a request or decide whether content is published, scheduled, private, or
  visible;
- handle uploads, write files, invoke processes, or call remote services;
- infer the semantic role of content from prose, filenames, metadata, or legacy source
  conventions;
- implement feature-specific behavior that another skin might need;
- ship its own JavaScript or bypass the CMS engine registry.

The CMS owns those responsibilities in central, reusable, testable code. It validates
inputs, enforces authorization and visibility, performs queries and mutations, and passes
the selected skin a bounded display-ready view model.

If PHP remains in a skin template during migration, its permitted role is deliberately
small: escape CMS-provided values, iterate over prepared collections, and make trivial
display-only conditionals. General PHP capability is not part of the skin contract.

## Why this is a security boundary

PHP executes with the web process's authority. A signed PHP package is authentic but not
sandboxed: it can potentially reach the database, credentials, filesystem, network,
session, and process APIs available to the CMS. Keeping behavior in skins also duplicates
publication, scheduling, authorization, and validation rules, allowing one skin to miss a
security fix made elsewhere.

Every data-access and action boundary therefore lives in CMS code where it can be reviewed,
tested, logged, and monitored once. Appearance must not change application authority.

## Enforcement direction

The package and installation gates must eventually reject any skin containing database
access, SQL, request handling, response control, filesystem or network I/O, state mutation,
dynamic execution, or other non-presentation PHP. Migration may use an explicit,
reviewed, shrinking exception inventory for existing skins; exceptions are debt and may
not be copied into new work.

The target state is that skins receive no PDO handle and no raw request globals. A future
inert template format or constrained renderer should replace general-purpose skin PHP.

See private working SECAUDIT 059 for the baseline inventory and remediation order.

<!-- ===== SNAPSMACK EOF ===== -->
