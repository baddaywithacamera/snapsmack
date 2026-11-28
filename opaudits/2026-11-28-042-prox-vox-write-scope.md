<!-- SNAPSMACK_EOF_HEADER -->
# OPAUDIT 042 — PROX VOX write scope and desktop workflow

28 November 2026. Draft for independent review. No live permissions were changed for this audit.

## What happened

The live SnapSmack FAQ loaded the older standalone Security page ahead of BRASS TACKS. The correct local copy needed a one-file restore. I read the live PROX VOX scope and reported that CT101 permitted only its homepage. That interpretation delayed the repair. The same response labels the single path as the unauthenticated rehearsal scope and separately lists what an authorized session can do. The installed shared desktop launcher also has its own fixed list of publication files, which did not include the Security FAQ.

## When the limit was introduced

- On 24 November at 23:33 Edmonton time, commit `169b4ce0` added the measured CT101 homepage as the only path in the initial rehearsal list. The commit describes a single approved Desktop Tools edit and the corresponding room message 7543 explicitly states that the whole list was one file. I found the limit disclosed to the room. I did not find evidence that Sean acknowledged it as a product-wide limit.
- After the 25 November ruling, `36f450db` changed the server so a host-attested session with an operation nonce could replace regular files beyond the rehearsal list. The list remained for rehearsal without owner authorization. The live scope response on 28 November describes that distinction and the matching source enforces the list for unauthenticated requests. The shared desktop publication list remained fixed. The initial report conflated the rehearsal list with the authorized path and missed the separate desktop limit.
- In the supervised PhotoFri repair on 28 November, PROX VOX backed up, replaced and verified the CT106 constants file. That path is outside the published rehearsal list. This verifies an authorized write beyond the list. The subsequent authorized CT101 FAQ restore also passed. The writer verified its private backup and the installed hash. A browser confirmed BRASS TACKS leads the page, Security is sixth, and the old URL redirects to that section (room 9346 and independent check 9348).

## Current capability

| Operation | Evidence and limits |
| --- | --- |
| File rehearsal without owner authorization | One path on CT101. The live scope lists no host path and no path on another container. |
| File replacement with owner authorization | The live writer advertises regular-file support on the host and containers with per-operation authorization, an expected original hash and a verified replacement hash. CT106 was verified in a supervised write. An authorized CT101 FAQ restore also passed. Coverage of every container remains unverified. |
| File backup | Matching writer source takes backups outside the public document root, preserving file metadata on container copies. The writer uses private backups. The PM approved that convention for the FAQ restore in room 9329. The source explains that a backup beside a PHP file may expose source code to web visitors. |
| Database writes | The live writer advertises the audited row-update operation under an authorized session. It previews and backs up the selected row and uses the site's configured database login. General SQL, arbitrary command execution and a bulk backfill are outside this operation. The proposed McCormick settings change still needs an exact row preview. |
| Maintenance | The live writer advertises attested chmod, chown, reload, restart and status. It exposes no arbitrary command operation. |
| Desktop workflow | The shared launcher publication list omits the FAQ. Full handling of the pending FAQ restore, McCormick setting and Bad Day backfill through the shared app has not been demonstrated. |

## Desktop issues from the FAQ restore

- SS-1656. The reviewed shared app exposes a fixed set of choices, so it cannot select the pending FAQ or settings actions.
- SS-1657. The dedicated FAQ action ends its authorization after a single operation. The PM recorded Sean's 27 November request for one 24-hour authorization and the earlier instruction to reuse authorization across approved writes. I found no owner decision approving a single-operation limit. SS-1623 separately covers reusing an active session across launchers.
- Code owns both fixes. Use the existing per-operation attestation and audit log while retaining the owner-authorized session until explicit end or expiry.

## Closure

Code owns the app and writer changes. Preserve the existing session and audit checks. Make the rehearsal and authorized scopes visible separately. Verify that the installed app supports the approved operations, beginning with the exact FAQ restore and a backup outside public reach or a sibling whose HTTP access is verified blocked. Verify the restored FAQ starts with BRASS TACKS, Security is sixth and the old URL returns a redirect. Test database and backfill support through the app on the separately approved scope. Muse reviews the evidence before closure. The FAQ was restored during the separately approved repair. The audit itself changed no live permissions.

<!-- ===== SNAPSMACK EOF ===== -->
