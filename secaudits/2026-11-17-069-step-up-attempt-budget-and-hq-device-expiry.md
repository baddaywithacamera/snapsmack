<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string SNAPSMACK EOF, a space, and five equals.
-->

# SECAUDIT 069 - Step-up attempt budget and SNAP HQ device expiry

| Field | Value |
|---|---|
| Audit ID | 2026-11-17-069 |
| Audit date | 2026-11-17 |
| Severity | HIGH |
| Components | `core/reauth.php`, `core/desktop-auth.php`, `tools/_shared/snap_device_auth.py`, and SNAP HQ |
| Read point | Fixes merged as `d5eae1b9` (SS-1399) and `ac1ef1e0` (SS-1418), including the final legacy-state repair `215c895d`; all are ancestors of SnapSmack 0.7.999D. |
| Method | The public SnapSmack security-audit method: read-only source inspection, independent review, executed regressions where available, mutation or before-state checks, release-ancestry verification, and explicit separation of source, installed-build, live, and owner acceptance evidence. No live exploit was used. |
| Status | FIXED - shipped in SnapSmack 0.7.999D. |
| Reporters | Muse and Sean McCormick |

## 1. Findings

Two related authorization controls did not enforce the limits they displayed or documented.

The shared password-plus-two-factor step-up check had no effective attempt budget. It protected API-key minting, device activation, bulk-import authorization, and offline posting, but repeated wrong answers were not reliably bounded. An early repair added escalating lock periods but could reach only its first tier, and a later review found a request interleaving that could report an ordinary credential failure while a lock was already active.

SNAP HQ device authorization also wrote expiry information without enforcing it end to end. An expired device could renew outside the documented window, renewal could restart its term from the moment of asking, and a cached signed authorization could outlive server-side disablement until the desktop checked in. On older local state, moving the computer clock forward and then correcting it could also bypass the intended rollback check because no high-water baseline had been recorded.

## 2. Fixes verified

Step-up verification now gives five attempts before a lock and escalates repeated lock episodes from 15 minutes to 30 minutes and then one hour. A clear 24-hour period returns the account to the first tier, and a successful verification clears the history. The repair uses separate failure-cycle and lock-episode records so an expired lock returns a legitimate owner a full attempt budget without erasing the escalation history. It also re-checks for a standing lock after the failure-counter update, removes any attempt recorded by the losing request, and returns the locked response consistently.

SNAP HQ now enforces device expiry and its 14-day grace period on the server. Renewal extends the previous term instead of starting a fresh term from the current day. The renewal path stays reachable so an expired owner device can recover, and a device already beyond grace when the fix arrived receives one clear renewal window instead of a hard stop. The desktop opens with a renewal prompt rather than becoming unusable.

The desktop authorization decision now records and checks a trusted clock baseline. For legacy state that predates that field, the baseline is derived from timestamps inside the already verified signed entitlement. This closes the forward-jump-then-correction case without trusting an unsigned local value. A server-disabled device loses its cached authorization when it next reaches the site.

## 3. Regression and review evidence

The SS-1399 repair added executed regressions for the attempt budget, escalating lock episodes, and the exact mid-request race found during independent review. The implementation record reports the repaired regression set passing and deliberate before-state or mutation variants failing, including the stale-read interleaving. Codex independently failed the earlier revisions, then passed the final repair. Muse independently reviewed the final lockout design.

The SS-1418 repair added PHP coverage for server-side expiry and Python coverage for the desktop decision path, renewal, grace, revocation refresh, clock rollback, and the legacy no-baseline state. Codex executed the final reproduction on the exact r3 head and passed it; Muse independently hand-traced and passed the same revision. The fixes were then merged and included in the deployed 0.7.999D tag.

## 4. Remaining boundary

A signed authorization cannot learn about a server-side revocation while the machine is offline. A machine that has not run SNAP HQ since its last online check can also be set to a later time within the interval since that check. The repair narrows the trusted window to the last time HQ ran; it does not claim to make an offline token aware of events that happened after its last contact. This is a stated property of offline authorization, not an unfixed implementation defect.

## 5. Authorship and review

Muse identified the missing step-up attempt budget while developing the INSTANT REPLAY threat model and identified the SNAP HQ expiry and revocation gaps during read-only review. Sean McCormick identified the real-world symptom that prompted the HQ review. Claude Code built the final repairs with AI assistance recorded in the commit history. Codex performed independent runtime and source reviews, including findings that rejected earlier revisions. Muse performed independent review and recorded her own earlier misses in the audit history rather than removing them from the record.

## 6. Limits

- The source fixes, focused regressions, independent reviews, release ancestry, and fleet version were checked.
- The authenticated fleet board reports the hub and all 27 spokes on 0.7.999D.
- No live site was deliberately locked, expired, revoked, or subjected to credential guessing for this audit.
- Installed SNAP HQ behavior and owner acceptance were not checked by this audit.

<!-- ===== SNAPSMACK EOF ===== -->
