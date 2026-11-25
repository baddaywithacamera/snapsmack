# The grant carries a live ticket, and the loopback call that checks it

<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
-->

SS-1507. Muse's condition (c) on `aa169f80`: review the grant-carries-ticket
design and the unverified loopback TLS **before live use**. This is that
review, written down rather than said in the room, because the room scrolls
and the next person to touch the writer needs to find it.

Nothing here has run against the real host.

## What a grant is

A grant is a JSON object the desktop client mints after Sean has logged in to
Proxmox with his password and authenticator. It travels once, over the SSH
channel, to the forced command on the host:

```
{"user": "...", "ticket": "<live Proxmox ticket>", "expires": <unix>,
 "target": "host", "path": "/var/www/...", "sha256": "<content hash>"}
```

The host checks every field against the write actually asked for, and refuses
on any mismatch.

## The finding: anyone holding a grant holds the session ticket

Muse is right, and this is the real cost of the design. The ticket inside a
grant is a live Proxmox credential. Until it expires, whoever has that grant
can act as Sean against the Proxmox API — not only perform the one write the
grant describes.

**Why it is built this way.** The host must not take the client's word for the
authorisation. Sean's ruling was that the SSH account authorises nothing on
its own: a write needs proof of a fresh owner login. The host therefore has to
verify something with Proxmox itself, and the only thing Proxmox will verify
is a ticket. A signature over the grant would prove the client signed it, not
that Sean logged in — which is the question being asked.

**What bounds it today:**

- The ticket's own lifetime. A Proxmox ticket is valid for two hours.
- `MAX_GRANT_SECONDS = 900` on the host: a grant claiming a longer life than
  fifteen minutes is refused outright, and the client refuses to mint one.
- The client caps the grant at the remaining session life, so a grant cannot
  outlive the login it rests on.
- A grant is bound to one target, one path and one content hash. Replaying it
  against another file or other bytes is refused by the host.
- It is never written to disk by either side. The host's audit log replaces it
  with `(grant presented, not logged)`; the client's audit never records it.
- It crosses one hop: an SSH connection to a forced command, as root, on the
  machine that already holds every secret in question.

**What does NOT bound it:** the grant is not single-use. The host does not
remember which grants it has seen, so the same grant can be presented twice
inside its window for the same write. The write is idempotent — same path,
same content hash — so a replay rewrites identical bytes, but it is a replay.

**What would remove the finding rather than bound it.** Proxmox would need to
issue a credential that proves a login without being usable as one — a short,
audience-restricted assertion. It does not. The alternatives:

1. **Host-side login.** The host prompts for Sean's password and TOTP itself.
   Removes the ticket from the grant entirely. Rejected: agents must never
   hold or submit Sean's credentials, and a prompt inside a forced command is
   a prompt an agent would have to answer.
2. **Single-use grants.** The host records a hash of each accepted grant for
   `MAX_GRANT_SECONDS` and refuses a repeat. Cheap, removes replay, does not
   remove the finding — the ticket is still in the grant. **Worth doing**, and
   it is the one improvement I would make next.
3. **A separate, narrower Proxmox account.** A ticket for an account that may
   do nothing but read `/access/ticket`. Then a leaked grant is worth almost
   nothing. But the account would not be Sean, and the whole point is to prove
   SEAN authorised the write. Rejected for that reason.

**My assessment:** acceptable for the first release, on the specific scope it
has — one file, on one container, with Sean present and watching. It is not
acceptable as a general-purpose root write channel, and if this writer is ever
widened beyond a single allowlisted path, option 2 should land first and this
section should be read again.

## The loopback TLS, which was worse and is now fixed

Muse flagged `ssl._create_unverified_context()` on the call to
`https://127.0.0.1:8006`. She was right to. That call is the host's **only**
proof that a ticket is genuine, and an unverified context accepts any
certificate at all — so anything that could hold port 8006 could have answered
"yes" and the writer would have believed it.

It now verifies the chain against the Proxmox cluster CA and **fails closed**:
if no CA file is readable the verification returns false and the write is
refused, rather than quietly falling back to an unverified connection.

Hostname checking stays off, deliberately: the connection is to `127.0.0.1`
and the node's certificate is issued for the node's own name, so a hostname
check could only ever fail. The chain check is the part that matters.

**Two candidate CA paths are listed in the code and I am not guessing which
is live:**

```
/etc/pve/pve-root-ca.pem
/etc/pve/local/pve-ssl.pem
```

My last guess about this node was `pct set --cmode tty`, and Codex measured
the live container as `cmode=shell` — a guess that would have made Sean's
console worse. **Codex, please confirm from the node which of those two exists
and is readable by root.** Until one is, the verification refuses, which is
the correct failure but would stop the writer working.

## The other finding fixed alongside it

Codex found that the verifier relied on HTTP 200 without checking **who** the
ticket belonged to. That was a privilege escalation, not a loose end: 200
proves a ticket is valid for somebody, and the username it was compared
against came out of the grant, which the caller writes. Anyone holding any
valid Proxmox ticket could have claimed to be `root@pam`.

It now renews the ticket through `/access/ticket`, which Proxmox accepts only
when the ticket genuinely belongs to the username given, and compares the
principal the reply carries. Two independent checks on one call. Renewal mints
a new ticket and does not alter the one presented; the new one is discarded
unread.

## Before live use

- [ ] Codex confirms the live CA path.
- [ ] Sean's explicit go, in his own session with his own TOTP.
- [ ] `WRITER_COMMIT` / `WRITER_SHA256` pinned in the provisioner, which
      refuses to install while they are placeholders.
- [ ] Single-use grants (option 2) if the allowlist ever grows beyond the one
      path it holds today.

<!-- ===== SNAPSMACK EOF ===== -->
