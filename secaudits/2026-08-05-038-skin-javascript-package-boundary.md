<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# SECAUDIT 038 - Gallery skin JavaScript package boundary

| Field | Value |
| --- | --- |
| **Audit ID** | 2026-08-05-038 |
| **Date** | 2026-08-05 |
| **Severity** | **MEDIUM (defense-in-depth)** - signed gallery skin packages could carry browser-executable JavaScript outside the shared, reviewed engine inventory |
| **Component** | Gallery skin source trees, `tools/skin-scan.php`, Smack Central Skin Packager, shared JavaScript engines, manifest inventory |
| **Status** | **REMEDIATED** - clean gate and skin purge included in 0.7.500D |
| **Reporter** | Sean (identified the missing audit record) + Claude (implemented the clean gate and purge) + Codex (independent verification and closure documentation) |
| **Related** | 019 (deep review), 025/026 (skin attack surface), 030/034 (manifest execution boundary and closure) |
| **Disclosure** | No exploitation is known. This report records a trust-boundary hardening change and verifies that official gallery skins no longer ship JavaScript. |

---

## 1. Summary

SnapSmack's skin architecture already had a reviewed JavaScript engine inventory,
but the Gallery packaging boundary did not fully enforce that design. Several
official skin directories still contained their own `.js` files or direct script
references. The existing security checks looked for dangerous JavaScript
patterns; they did not categorically reject every JavaScript carrier inside a
skin package.

That distinction matters. A signature proves which release process produced a
package and that its bytes were not changed afterward. It does not prove that
browser-executable code inside the signed package is safe. Skin-local copies also
survive fixes made to the shared engine, creating stale and inconsistent code
paths across installed sites.

The policy is now explicit and enforced: a Gallery skin ships **no JavaScript**.
Reusable browser behaviour lives in core-owned `assets/js/`, is registered in
`core/manifest-inventory.php`, and is requested by a skin through its inert JSON
manifest. A repository scanner detects executable JavaScript carriers, and the
Smack Central packager refuses to sign or publish a skin with any blocking
finding.

All skin-shipped JavaScript found during the remediation was removed or moved to
the shared engine library. The completed scan reports zero blocking findings
across the official skin set.

## 2. Finding - the package boundary was descriptive, not mandatory

### 2.1 What existed

SnapSmack already encouraged skins to declare shared engines with
`require_scripts`. The footer loader then resolved those identifiers through the
core manifest inventory and loaded core-owned files.

However, a skin could still contain any of the following and reach the packager:

- a bundled `.js` file;
- an inline `<script>` block;
- an HTML event handler such as `onclick` or `onerror`;
- a `javascript:` URI;
- an external or skin-relative `<script src>` reference;
- an active `<iframe>`, `<object>`, or `<embed>` element.

The official tree contained multiple examples of skin-local JavaScript, including
navigation and presentation engines. These files were not evidence of malicious
code. They demonstrated that the intended architectural boundary was not yet an
enforced publishing rule.

### 2.2 Security impact

JavaScript in a skin executes in the site's browser origin. If a malicious or
compromised package introduced it, the code could:

- read page content and browser-accessible security tokens;
- alter links, forms, moderation controls, or administrative interfaces;
- send data to a remote service under the visitor's credentials;
- persist as an overlooked per-skin copy after the shared engine was repaired;
- make the same feature behave differently across skins and releases.

This was not an unauthenticated remote-code-execution path. Installing and
publishing Gallery skins remained a privileged, signed workflow. The issue was a
missing defense at that workflow's trust boundary, so the finding is rated
MEDIUM defense-in-depth. No exploitation is known.

## 3. Remediation

### 3.1 Repository clean scanner

`tools/skin-scan.php` now scans each skin directory and emits blocking findings
for browser-executable carriers. The scanner rejects bundled `.js` files, inline
event handlers, inline script blocks, `javascript:` URIs, external or skin-local
script sources, and active embedded-content elements.

The one permitted loading mechanism is the core-owned footer loader. It resolves
script paths from the manifest inventory rather than from a skin-controlled URL.
A direct tag pointing at a known core engine is reported as a warning so it can
be migrated to the declared loader without treating reviewed core bytes as a
skin-local payload.

The command-line scanner reports every official skin, returns a non-zero exit
code when any blocking finding exists, and can emit structured JSON for automated
checks.

### 3.2 Fail-closed packaging gate

Smack Central now invokes the same scanner immediately before packaging a skin.
If any blocking finding exists, the build is rejected before ZIP creation,
signature generation, or registry publication. The error identifies the finding
type and source location so it can be corrected without bypassing the rule.

This is the essential control: a clean repository is useful evidence, but the
packaging gate prevents a later regression from becoming a signed Gallery
release.

### 3.3 Shared engine consolidation

Reusable behaviour was moved from skin directories into core-owned engines,
including the Alfred navigation, community, and Glide engines. The shared files
are registered in `core/manifest-inventory.php`; affected skin manifests request
them through `require_scripts`.

Remaining skin-local copies and direct loading fragments were removed from the
official skins. This reduced the skin trees by more than 500 lines of duplicate
or misplaced JavaScript and restored a single patch point for each engine.

## 4. Verification

The closure review verified the following:

- the scanner covers bundled files and the known inline, URI, external-script,
  and embedded-content carriers;
- the scanner exits unsuccessfully when a blocking fixture is introduced and
  successfully when the official skin tree is clean;
- Smack Central calls the scanner before package creation, signing, and registry
  publication;
- official skin directories contain no `.js` files after the purge;
- moved engines are present under `assets/js/` and registered in the core
  manifest inventory;
- affected skin manifests request shared engines rather than loading local
  copies;
- the complete official skin scan reports zero blocking findings.

The remediation is present in commits `df694083` and `8e649b4c`, both included in
the development release tag `v0.7.500D`.

## 5. Residual trust and limitations

Skins still contain PHP presentation templates. The JavaScript clean gate does
not make an arbitrary third-party skin harmless and does not replace signature,
manifest, ZIP-path, or server-side code review controls.

The scanner is intentionally a publishing-policy gate, not a general-purpose
HTML or PHP parser. Its line-based rules provide precise source locations and
cover the supported skin authoring patterns. Future template syntax that can
produce executable browser content must be added to the scanner before that
syntax is accepted in Gallery skins.

Owner-authored custom code outside the Gallery packaging workflow remains a
separate, explicitly privileged capability. It must not become an exception that
allows JavaScript back into signed Gallery packages.

## 6. Closure

The architecture now says one thing in both design and enforcement: skin packages
choose shared behaviour; they do not carry browser code. Signatures establish
provenance, the clean gate establishes package content policy, and the shared
engine inventory provides one reviewed and repairable implementation.

The official skin set is clean, the packager fails closed, and no exploitation is
known.

**Disposition: CLOSED in 0.7.500D.**

<!-- ===== SNAPSMACK EOF ===== -->
