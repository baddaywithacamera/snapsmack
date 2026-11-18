<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string SNAPSMACK EOF, a space, and five equals.
-->

# SECAUDIT 066 - API key generation requires step-up authentication

| Field | Value |
|---|---|
| Audit ID | 2026-11-17-066 |
| Audit date | 2026-11-17 |
| Severity | HIGH |
| Component | `smack-api-keys.php` (generation handler and form) and `core/reauth.php` |
| Read point | SnapSmack 0.7.998D at `83e7d155f`; fix commits `e77ff5bbe` and `821672c9b`, both also ancestors of `origin/dev` at filing. |
| Method | Muse read-only code inspection of the affected handler, shared step-up helper, regression coverage, fix diff, and release ancestry. No PHP was executed by Muse and no live site or exploit was used. |
| Status | FIXED - shipped in SnapSmack 0.7.998D. |
| Reporter | Sean McCormick |

## 1. Finding

An authenticated administrator session could mint a new API key without re-entering the account password or supplying a current two-factor code. API keys can act for a site outside the browser session and remain valid for weeks or months, so creating one is a consequential action that requires fresh proof of control.

Three other actions on the same page already used the shared step-up check. The visible password and two-factor fields above the key controls belonged to offline posting, not API-key generation, which made the missing protection easy to mistake for a protected flow. Sean found the discrepancy while reviewing the live administrator page on foundtextures.ca on 2026-11-17.

## 2. Fix verified

The generation handler now calls the existing `reauth_verify()` boundary before any key material is created or stored. A failed check stops the request before the database insert, and the generation form now has its own labelled password and two-factor fields.

The existing cross-site request-forgery control remains on every administrator POST. Password-only authentication remains a refusal when two-factor authentication is configured. The existing post-install grace case remains login-only and records that two-factor enrolment is still required.

## 3. Regression evidence

The fix added `tests/api-key-generation-requires-step-up-regression.php`. The implementation report records 39 checks, including 10 executed against a real in-memory user table and a real TOTP secret. The exercised outcomes include correct credentials, wrong or empty passwords, missing, invalid, or expired codes, an unenrolled account, and an expired session. Structural checks pin the generation handler's ordering, ensure every path to the insert is inside the successful step-up branch, and ensure passwords, codes, and plaintext keys are not written to output or logs.

The test also keeps the page's existing CSRF check in scope. Four deliberate mutations were used to prove that weakening or moving the gate makes the regression fail. The implementation report records the existing 427-test suite as passing. A wording error in the expired-code case was corrected by `821672c9b` before the release tag.

## 4. Follow-up

Further step-up hardening is in review and will be published separately.

## 5. Authorship and review

Sean found the missing protection. Claude Code built the repair, with Claude Opus 5 recorded as co-author. Muse independently reviewed the fix and its regression evidence; the one wording finding from that review was corrected before release.

## 6. Limits

- This was a single-reviewer source and evidence review.
- Muse did not execute the PHP suite, exercise a live site, or inspect the rendered administrator page.
- The release and development-line claims were confirmed by Git ancestry, not by runtime inspection of an installed site.
- Installed-build verification and owner acceptance were not checked by this audit.

<!-- ===== SNAPSMACK EOF ===== -->
