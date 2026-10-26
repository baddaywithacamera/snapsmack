<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# SNAP HQ OPS — Specification

**Status:** approved direction; first implementation boundary defined.
**Product:** administrative edition of SNAP HQ.
**Relationship:** one launcher engine and one signed catalogue, presented as two
separately authorized editions. This is not a copied launcher.

---

## 1. Purpose

SNAP HQ is the ordinary desktop suite for creating, organizing, publishing,
backing up, and migrating a user's own work. SNAP HQ OPS is the operator suite
for support, fleet health, diagnostics, moderation, evidence, and controlled
agent access.

Administrative tools are not merely hidden from ordinary users. Installation,
catalogue membership, entitlement, credentials, and executable authorization
enforce the boundary.

---

## 2. Shared foundation

Both editions reuse:

- the responsive SNAP HQ Qt launcher;
- the shared SnapSmack home and credential vault;
- device identity and signed entitlement verification;
- exact-path executable discovery and trust receipts;
- updater and version reporting;
- icons, layout, accessibility, logs, and single-instance controls;
- a single versioned tool catalogue.

An edition changes branding, authorized catalogue view, and administrative
settings. It does not fork launcher behaviour.

---

## 3. Initial catalogue

| Tool | SNAP HQ | SNAP HQ OPS | Reason |
|---|:---:|:---:|---|
| SNAP SLAPPER | yes | no | personal photo manager/editor |
| SMACK YOUR BATCH UP | yes | no | personal batch publishing |
| GET YOUR SHIT SORTED | yes | no | personal library management |
| COLD SNAP | yes | no | personal offline publishing |
| SMACK UP YOUR BACKUP | yes | yes | personal backup and authorized recovery investigation |
| OH SNAP | yes | no | skin design tool |
| SMACK YOUR MOUTH | yes | no | site-owner comment moderation |
| SHOTS FIRED | yes | no | site-owner scheduling |
| CRONOMETER | yes | yes | personal job health and operator fleet health |
| TROLL BOOTH | no | yes | authenticated local agent bridge and collaboration room |
| LOG FLOGGER | no | yes | support diagnostics and evidence |
| TICKET WICKET | no | yes | durable public support-ticket management |
| CLAUDE IS MY COPILOT | no | yes | support-forum, email, and moderation CLI |

Migration tools remain an optional SNAP HQ user section unless a later OPS
workflow has a demonstrated need for one.

CRONOMETER is deliberately in both suites. The executable and health engine are
the same. Ordinary SNAP HQ displays the user's configured sites; SNAP HQ OPS
may receive fleet-scoped profiles and permissions. The edition must not change
the truth rules, health computation, or silent-failure reporting.

SUYB is deliberately in both suites. Ordinary SNAP HQ manages the user's own
backups. OPS can enumerate and inspect authorized support/fleet repositories,
but production restore still requires its separate recovery plan and authority.

---

## 4. Authorization

SNAP HQ and SNAP HQ OPS use distinct signed role claims. A valid ordinary HQ
entitlement does not imply OPS access.

SNAP HQ OPS:

1. verifies its device-bound entitlement;
2. opens locked;
3. requests password plus TOTP in its native window;
4. creates a bounded local OPS session;
5. exposes only catalogue entries granted by the signed role claims;
6. preserves per-tool step-up and plan requirements.

Closing, expiry, workstation lock, revocation, or authorization failure ends
the OPS session. Removing OPS authorization makes installed OPS executables
inert; hiding launcher cards is not considered enforcement.

---

## 5. TROLL BOOTH relationship

TROLL BOOTH launches from SNAP HQ OPS. It may accept a short-lived assertion
that an authenticated OPS session exists, but it does not receive the OPS
password, TOTP, vault contents, or a permanent bearer credential.

TROLL BOOTH performs its own browser pairing and capability scoping. Its lease
expires no later than the parent OPS session. Locking either application stops
new bridge work; emergency stop in TROLL BOOTH is always available.

The complete TROLL BOOTH authority and protocol contract is section 15 of
`docs/support-operations-agent-cli-spec.md`.

---

## 6. Catalogue contract

Each entry defines:

- stable tool ID;
- display name and description;
- exact executable location under the shared root;
- edition membership: `user`, `ops`, or `both`;
- install/optional state;
- icon identity;
- entitlement feature claim;
- whether an agent capability sheet is expected;
- executable trust identity.

The launcher never uses a wildcard path for an executable. A catalogue entry
cannot make an untrusted or replaced executable launchable.

The catalogue is data consumed by the launcher. It must not accumulate tool-
specific behaviour or credentials.

---

## 7. Native OPS surface

The first OPS dashboard provides:

- SYSTEM HEALTH — CRONOMETER;
- SUPPORT — TICKET WICKET and CLAUDE IS MY COPILOT;
- DIAGNOSTICS — LOG FLOGGER;
- AGENT ACCESS — TROLL BOOTH;
- RECOVERY — SMACK UP YOUR BACKUP;
- session state, active agents, approvals waiting, and emergency lock.

The dashboard is a launcher and status surface. Each tool continues to own its
function. OPS must not reimplement ticketing, log analysis, backup, forum, or
bridge logic inside the launcher.

---

## 8. Acceptance tests

1. Both editions consume the same catalogue and launcher components.
2. CRONOMETER appears and launches in both editions.
3. SUYB appears and launches in both editions.
4. OPS-only tools never appear in ordinary SNAP HQ.
5. Ordinary user tools do not appear in OPS unless catalogued `both`.
6. An ordinary HQ entitlement cannot open OPS or an OPS-only tool.
7. Removing OPS entitlement makes an already installed OPS tool inert.
8. Password plus TOTP values never enter logs, settings, command arguments, or
   TROLL BOOTH.
9. A replaced executable fails its trust check in both editions.
10. Locking OPS prevents new launches and invalidates its TROLL BOOTH lease.
11. CRONOMETER's health verdict for identical input is the same in either
    edition.
12. SUYB cannot restore production merely because it was launched from OPS.

---

## 9. Implementation order

1. Extract the hard-coded roster into the shared versioned catalogue.
2. Preserve current SNAP HQ rendering and behaviour byte-for-behaviour where
   possible; this is a relocation, not a redesign.
3. Add edition selection as a build/authorized-launch property.
4. Add OPS branding and role gate.
5. Render the initial OPS sections from catalogue data.
6. Integrate TROLL BOOTH's bounded session assertion.
7. Package and test the editions independently from the same source.

<!-- SNAPSMACK_EOF -->
