<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# Secure skin runtime rebuild

Status: normative rebuild specification for `0.7.773D`.

## Outcome

SnapSmack must make this statement true in implementation, packaging, installation,
and tests:

> Skins present. The CMS decides and acts.

A skin may select and arrange presentation. Installing or selecting a skin must never
change the authority available to public requests, the rules used to select content,
or the code that reads or mutates persistent state.

The rebuild is complete only when every shipped skin passes the strict package gate
with no legacy exceptions and the public runtime does not give a skin a database
handle or raw request state.

## Trust zones

### CMS (`core/` and the public entry points)

Trusted server code. The CMS exclusively owns:

- routing and request parsing;
- authentication, authorization, CSRF, rate limits, and visibility policy;
- SQL, transactions, persistence, and cache invalidation;
- headers, redirects, cookies, sessions, and response status;
- filesystem, upload, network, process, and secret access;
- content parsing and sanitization;
- construction of bounded, display-ready view models;
- the registry of browser assets that may execute.

Reusable PHP is organized by responsibility under `core/`, not under `assets/`:

- `core/controllers/` for HTTP orchestration;
- `core/services/` for reusable application behaviour;
- `core/repositories/` for database access;
- `core/view-models/` for bounded presentation data;
- `core/support/` for low-authority, side-effect-free utilities.

Existing flat `core/*.php` files may be migrated incrementally. Directory placement is
less important than CMS ownership and an explicit dependency direction.

### Skin (`skins/<slug>/`)

Untrusted presentation package. A target-state skin contains:

- `manifest.json` with `schema_version: 2`;
- PHP templates limited to escaping, iteration, and trivial display conditionals;
- CSS, images, fonts, and other inert presentation resources;
- declarations naming CMS controllers, view-model capabilities, and registered shared
  browser assets.

A skin must not contain or use:

- PDO, mysqli, SQL, transactions, or a database handle;
- request globals, environment variables, or request-body reads;
- authorization or publication/visibility decisions;
- headers, redirects, response status, cookies, or sessions;
- file reads/writes, directory traversal, uploads, archives, or deletion;
- sockets, cURL, URL fetching, mail, or other network access;
- process creation, shell commands, eval, reflection-based invocation, or dynamic
  includes;
- PHP-defined application functions/classes or reusable business logic;
- inline JavaScript, event-handler attributes, remote scripts, or skin-bundled JS;
- direct execution as an HTTP endpoint.

Static inclusion of another template inside the same skin is transitional only. Shared
CMS components must be exposed through an approved renderer rather than arbitrary file
inclusion.

### Browser assets (`assets/`)

`assets/css` and `assets/js` are publicly served browser resources, never a home for
trusted PHP.

- CSS may express presentation only. Remote imports and remote asset URLs are forbidden.
- JavaScript may enhance browser interaction. It is not an authorization boundary and
  must not contain secrets or make trusted policy decisions.
- Shared assets are registered once in the CMS asset inventory and requested by a
  manifest capability name.
- Public, admin, and tool assets must have explicit ownership; page-specific code must
  not be loaded globally.
- Inline scripts/styles and duplicate private copies are removed during migration.

Owner-supplied custom JavaScript is a separate, explicit high-risk feature. It is off by
default, labelled as arbitrary code, constrained by Content Security Policy where
possible, and never represented as safe merely because a scanner found no known pattern.

## Runtime contract

The target request flow is:

```text
public entry point
  -> CMS router/controller
  -> CMS service
  -> CMS repository
  -> immutable/bounded view model
  -> constrained skin renderer
  -> registered browser assets
```

The controller chooses the route and response. The repository selects explicit columns
and applies publication policy. The service derives reusable presentation data. The skin
receives one view-model array and approved render helpers; it receives neither `$pdo`
nor raw superglobals.

CMS-rendered HTML fragments are typed as trusted only when produced by a central,
tested sanitizer/renderer. All other skin output is escaped at its context boundary.

Skin templates are included only after the CMS defines `SNAPSMACK_SKIN_RENDER` and must
return immediately without it. Web-server rules deny direct requests to PHP below both
`core/` and `skins/`; the in-file guard is defence in depth.

## Manifest version 2

Version 2 manifests replace implicit PHP behaviour with declarations:

- `cms_controller`: the CMS-owned public controller;
- `view_model`: the versioned data contract supplied to templates;
- `templates`: route-kind to template mapping;
- `require_scripts` and `require_styles`: names from the CMS asset registry;
- `capabilities`: presentation features the skin consumes, never server permissions;
- declarative navigation, options, variants, and media slots.

Unknown declarations fail closed. A manifest cannot name filesystem paths outside its
own package and cannot register executable server code.

## Enforcement

One shared policy engine is used by development scans, the package builder, registry
publication, and installation. It reports stable machine-readable finding types.

For schema version 2, any forbidden capability is a hard failure. For schema version 1,
the repository carries a generated legacy inventory. The gate fails if a finding appears
that is not in that inventory. The inventory may only shrink; adding or broadening an
exception requires an explicit security decision and may not occur as part of ordinary
feature work.

The gate covers PHP authority as well as browser code. Signing proves provenance; it
does not make executable PHP safe.

## Migration plan

1. **Freeze and measure.** Add the shared policy engine, generate the legacy inventory,
   and enforce the no-new-debt ratchet in tests and package tooling.
2. **Central public models.** Build CMS controllers/repositories/view models for the
   common photoblog, SMACKTALK, archive, page, search, hashtag, navigation, comments,
   and landing-page surfaces.
3. **Convert skin families.** Convert duplicated skin families together. Each converted
   manifest becomes schema version 2 and its legacy exceptions are deleted.
4. **Constrain rendering.** Stop exposing `$pdo`, request globals, and broad settings to
   templates. Replace arbitrary core includes with approved render helpers.
5. **Asset cleanup.** Inventory ownership and references under `assets/js` and
   `assets/css`; split public/admin/tool bundles; remove duplicates, dead files, inline
   code, remote loads, and unregistered direct references.
6. **Installation hardening.** Strictly validate packages before they enter the live
   skins directory. Keep inactive settings but remove inactive package code.
7. **Remove compatibility.** Delete the legacy preload path and exception inventory.
   Only schema version 2 skins remain installable.

## Required evidence

Before this rebuild can be called complete or beta-ready:

- every shipped skin has zero strict-policy findings;
- every shipped skin uses manifest schema version 2;
- no skin receives `$pdo` or reads a request superglobal;
- direct HTTP execution of skin/core PHP is denied and regression-tested;
- package, registry, and installer tests prove the same policy is applied;
- route tests prove unpublished, scheduled, private, and missing content cannot be
  exposed by changing skins;
- mutation tests prove appearance changes cannot create a new write path;
- the asset inventory has no unknown, unreferenced, duplicated, remote, or inline
  executable asset without an explicit reviewed exception;
- the legacy exception inventory and legacy preload path are absent;
- the release report names the exact tests and commit, and does not use “audited” as a
  substitute for this evidence.

## Release policy during rebuild

`0.7.773D` is a rebuild line, not a beta candidate. No skin or feature may add a policy
finding. A touched legacy area must preserve or reduce its finding set. No deployment or
publication occurs merely because a phase is committed; release remains a separate,
explicit decision.

<!-- ===== SNAPSMACK EOF ===== -->
