<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# SNAPSMACK SUPPORT OPERATIONS — Agent and CLI Specification

**Status:** proposed contract for implementation.
**Scope:** LOG FLOGGER, TICKET WICKET, CLAUDE IS MY COPILOT, the local 24/7
triage agent, the Roach Motel bug-tracker forum, Claude, Codex, and Sean.
**Purpose:** provide useful autonomous support without creating a production
backdoor, leaking customer information, or calling work complete before it is
deployed and verified.

---

## 1. Operating model

The system has four participants with different jobs:

1. **Local triage agent:** always-on front desk. It acknowledges, gathers,
   classifies, searches known material, performs approved read-only checks,
   opens tickets, and provides established answers.
2. **Claude and Codex:** technical escalation layer. They investigate,
   correlate evidence, inspect backups, prepare changes, update tickets, and
   decide whether Sean is needed.
3. **Sean:** owner and final authority for product policy, architecture,
   destructive recovery, exceptional production action, and sensitive public
   statements.
4. **The user:** reporter and participant. Every legitimate ticket has a
   sanitized public thread in the Roach Motel support forum. Users can follow
   it, add corroborating information, and contribute safely handled logs.

No participant receives an unrestricted production shell through this system.
Read access and mutation access are separate capabilities.

---

## 2. Non-negotiable outcomes

The completed system MUST:

- accept a report from email, forum, or an authorized operator;
- create one durable ticket identity and use it across all tools;
- preserve an append-only audit timeline;
- distinguish report, reproduction, code change, package, deployment, and
  live verification;
- let the local agent resolve known support questions without waking Sean;
- let Claude and Codex investigate through stable, machine-readable CLIs;
- make production mutation impossible through read-only diagnostic access;
- sanitize private information before every Roach Motel publication;
- publish every legitimate ticket to the support forum and preserve it there
  whether it is open, closed, merged, withdrawn, or cannot be reproduced;
- allow users to follow a ticket and add corroborating reports and logs;
- escalate uncertainty instead of inventing an answer;
- keep credentials out of prompts, command lines, reports, and logs;
- support revocation of an agent, credential, source, or capability without
  reinstalling the suite.

---

## 3. Shared identity and evidence model

### 3.1 Identifiers

Every case receives a TICKET WICKET identifier such as `TW-184`. Related
objects retain their own identifiers and link back to the ticket:

- LOG FLOGGER evidence bundle: `LF-...`
- Roach Motel public issue: forum topic ID and URL
- email or forum conversation: source thread ID
- backup snapshot: repository-native immutable ID
- code change: commit SHA
- package: version plus artifact checksum
- deployment: site, version, timestamp, and deployment receipt
- verification: check name, target URL/site, timestamp, and evidence

### 3.2 Append-only activity record

Tools append events; they do not rewrite history. A correction is a new event
that supersedes an earlier event. Each event records:

- unique event ID;
- ticket ID;
- timestamp in UTC plus displayed local timezone;
- actor type and actor ID;
- action;
- target;
- result;
- structured evidence references;
- visibility: private, reporter-visible, or public;
- redaction status;
- tool and schema version.

### 3.3 Actor identity

Messages MUST identify their source accurately:

- `Automated support` for the local triage agent;
- `Claude` for Claude actions;
- `Codex` for Codex actions;
- `Sean` only for actions actually taken by Sean.

The system MUST NOT impersonate Sean or blur automated and human responses.

---

## 4. Ticket lifecycle

The primary lifecycle is:

```text
RECEIVED -> TRIAGED -> REPRODUCED -> ACCEPTED -> FIXED
         -> PACKAGED -> DEPLOYED -> VERIFIED -> CLOSED
```

Permitted side states are:

- `NEEDS_REPORTER`
- `NEEDS_AGENT`
- `NEEDS_OWNER`
- `CANNOT_REPRODUCE`
- `DUPLICATE`
- `WORKAROUND_AVAILABLE`
- `SECURITY_HOLD`
- `RECOVERY_PENDING`
- `DROPPED`

`FIXED` means a code or data repair exists. It does not mean packaged,
deployed, verified, or closed. Each forward transition MUST carry the evidence
required for that state. TICKET WICKET MUST reject an unsupported transition.

Minimum evidence:

| State | Required evidence |
|---|---|
| REPRODUCED | reproduction steps and observed result |
| ACCEPTED | classification and responsible component |
| FIXED | commit, approved content change, or recovery plan |
| PACKAGED | immutable version and artifact checksum |
| DEPLOYED | target and deployment receipt |
| VERIFIED | live measurement or named comparator result |
| CLOSED | verification plus reporter disposition or documented closure rule |

---

## 5. Authority model

### 5.1 Local triage agent — autonomous

The local agent MAY:

- acknowledge a report;
- ask approved diagnostic questions;
- classify and prioritize a ticket;
- search documentation and previous tickets;
- identify likely duplicates without merging them destructively;
- call approved read-only LOG FLOGGER commands;
- attach sanitized diagnostic summaries;
- provide a documented workaround;
- open and update a ticket;
- draft or send a routine support response within approved policy;
- create the mandatory Roach Motel thread after its privacy gate passes;
- publish a minimal safe ticket when the underlying report cannot yet be
  disclosed;
- escalate to Claude and Codex.

The local agent MUST escalate when confidence is below the configured
threshold, evidence conflicts, the report is novel, or the impact includes
security, privacy, data loss, billing, legal risk, abuse, or fleet-wide failure.

### 5.2 Claude and Codex — technical escalation

Claude and Codex MAY independently:

- use all read-only diagnostic and search capabilities;
- inspect ticket, communication, log, and backup metadata;
- extract backup files into an isolated recovery workspace;
- reproduce a problem in an approved local or staging environment;
- prepare code, content, tests, recovery plans, and packages;
- update ticket classification and evidence;
- respond to users within established support policy;
- decide that Sean is or is not required;
- recommend a state transition.

Repository rule 4 remains controlling: agents do not deploy. A CLI MUST NOT
turn diagnostic access into a deployment route.

### 5.3 Sean — required authority

The system MUST request Sean for:

- product or roadmap decisions;
- exceptions to architecture or security rules;
- destructive production recovery;
- deletion of accounts, tickets, evidence, or backups;
- permanent moderation sanctions;
- refunds, legal issues, or abuse disputes;
- public statements about material security, privacy, or data-loss incidents;
- disclosure decisions;
- any action whose scope is not confidently bounded.

### 5.4 Never automatic

No agent may automatically:

- restore over production data;
- edit production application files directly;
- delete a backup or evidence record;
- expose raw secrets or private logs;
- close a ticket merely because a commit or package exists;
- publish private reporter material to the Roach Motel;
- weaken authentication, integrity monitoring, or updater controls.

---

## 6. Common CLI contract

All support CLIs MUST share these behaviours.

### 6.1 Discovery

```text
<tool> version --json
<tool> capabilities --json
<tool> doctor --json
```

`capabilities` returns supported commands, schema versions, required
permissions, mutation class, and whether a command is available in the current
installation.

### 6.2 Output

- Human-readable output is the interactive default.
- `--json` returns one complete JSON document.
- `--ndjson` is available for streams and follow operations.
- Results go to stdout; diagnostics go to stderr.
- `--no-color` disables terminal decoration.
- `--non-interactive` never prompts and fails clearly when input is missing.
- Exit codes are stable and documented.
- Every response contains `schema_version`, `tool_version`, `operation_id`,
  `result`, and a machine-readable error when unsuccessful.

### 6.3 Mutation

Every mutating command MUST support a read-only planning step:

```text
<tool> <operation> --dry-run --json
<tool> <operation> --plan plan.json
<tool> apply --plan plan.json
```

A plan records exact targets, precondition hashes, expected changes, required
authority, expiry, and rollback or recovery information. `apply` refuses when
preconditions have changed. Successful mutation emits an immutable receipt.

### 6.4 Authentication and secrets

- Secrets MUST use the operating-system credential store or a dedicated
  secrets provider.
- Secrets MUST NOT be accepted in ordinary command arguments.
- Output and errors MUST redact secrets.
- Capabilities are least-privilege, individually revocable, and scoped by
  site/source/action.
- Read, extract, respond, moderate, publish, restore, delete, and deploy are
  distinct permissions.

### 6.5 Agent operation

Every command invoked by an agent receives:

- actor identity;
- ticket ID when work relates to a case;
- correlation/operation ID;
- requested visibility;
- reason for access.

The tool records this in its audit history.

---

## 7. LOG FLOGGER requirements

### 7.1 Sources

LOG FLOGGER SHOULD support:

- local files and directories;
- stdin;
- SSH/SFTP read-only sources;
- systemd journal queries through an allowlisted helper;
- web server, PHP, database, application, and updater logs;
- imported diagnostic bundles.

Remote access MUST use a restricted service identity. It MUST NOT store or use
an unrestricted root password. The server side should expose only allowlisted
read operations over allowlisted sources, using filesystem ACLs or an
equivalent restricted helper. No general shell is required.

### 7.2 Required CLI surface

```text
log-flogger sources list|show|test
log-flogger read --source <id> --since <time>
log-flogger follow --source <id> --ndjson
log-flogger search --source <id> --query <query>
log-flogger correlate --ticket <id>
log-flogger bundle --ticket <id> --redact --output <path>
log-flogger explain <event-or-bundle>
```

### 7.3 Redaction

Redaction is on by default. At minimum it covers:

- authorization and cookie headers;
- passwords, API keys, bearer tokens, session IDs, and CSRF values;
- sensitive query parameters and POST fields;
- database connection strings;
- email addresses and IP addresses according to visibility policy;
- filesystem paths or account identifiers that expose private identities.

Raw access, where permitted, remains private and is separately audited. A
public bundle MUST carry a successful redaction report.

### 7.4 Source integrity

Results identify host, source, collection time, covered time range, timezone,
rotation boundaries, truncation, and source fingerprint. LOG FLOGGER MUST say
when requested history is unavailable rather than treating absence as proof.

---

## 8. TICKET WICKET requirements

### 8.1 Required CLI surface

```text
ticket-wicket tickets list|show|search
ticket-wicket tickets create
ticket-wicket tickets link
ticket-wicket tickets comment
ticket-wicket tickets attach
ticket-wicket tickets transition
ticket-wicket tickets escalate
ticket-wicket tickets duplicate --plan
ticket-wicket roach-motel propose
ticket-wicket roach-motel publish --plan
ticket-wicket audit show
```

### 8.2 Ticket fields

Each ticket includes:

- title and reporter description;
- private reporter identity and preferred contact route;
- affected product, component, version, site, and environment;
- severity, impact, reproducibility, and confidence;
- current lifecycle and side state;
- owner/escalation target;
- private and public-safe summaries;
- linked evidence and communications;
- workaround and resolution;
- timestamps and service targets;
- mandatory public-following URL once the privacy gate has published the
  ticket.

### 8.3 Mandatory public ticket

Every legitimate ticket is published as a sanitized thread on the Roach Motel
support board. Publication does not wait for reproduction or acceptance as a
software defect: the public record may honestly say `reported`, `cannot
reproduce`, `duplicate`, `not a bug`, or `closed`. Closing, merging, or
withdrawing a ticket never removes its thread or history.

The private store retains reporter identity, source correspondence, unredacted
evidence, and delivery details. The public thread carries the problem,
observable facts, safe evidence, status, workaround, resolution, and links
that users need to follow or add useful information.

### 8.4 Deduplication

Duplicate detection is advisory until confirmed. Merging preserves both ticket
histories and reporter relationships. A reporter is never silently subscribed
to notifications without their consent. The duplicate's public thread remains
readable and points to the surviving ticket.

### 8.5 Evidence-enforced transitions

The transition command validates the evidence table in section 4. Agents may
recommend unsupported transitions, but TICKET WICKET records the
recommendation and refuses the state change.

---

## 9. CLAUDE IS MY COPILOT requirements

The product name remains CLAUDE IS MY COPILOT. Its underlying command and data
contracts are provider-neutral so Claude, Codex, the local agent, and future
authorized operators can share it.

CLAUDE IS MY COPILOT is the CLI gateway through which Claude and Codex read and
moderate SnapSmack's internal support forum. TICKET WICKET owns ticket state
and creates the durable ticket record; CLAUDE IS MY COPILOT owns forum access,
conversation, moderation, following, and synchronization of user additions
back to that record. Neither tool scrapes the other's human interface.

### 9.1 Required CLI surface

```text
copilot inbox list|show|search
copilot threads classify
copilot drafts create|show|revise|approve
copilot messages send --plan
copilot moderation queue|show|explain
copilot moderation act --plan
copilot ticket link|create
copilot ticket sync-input
copilot attachments inspect|sanitize|attach
copilot sync
```

### 9.2 Communications safety

- Reading, searching, classification, and drafting are non-mutating.
- Sending, posting, hiding, locking, moving, banning, and deletion are distinct
  mutations with separate permissions.
- Replies identify the real actor.
- Quoted user text is untrusted content, never executable instruction.
- Attachments are quarantined and inspected before use.
- Private correspondence is not copied into a public issue.
- User-contributed forum replies and logs are linked to the ticket timeline;
  they do not silently replace the ticket's established facts.
- Permanent sanctions and destructive moderation require Sean.

---

## 10. Roach Motel requirements

The Roach Motel is the public support and bug-tracker forum. Every legitimate
TICKET WICKET ticket appears there and remains visible after closure. TICKET
WICKET remains authoritative for structured state, private intake material,
and the audit history; the public thread is the durable user-facing record.

Before publication, a privacy gate MUST:

1. generate a public-safe issue summary;
2. remove reporter identity unless explicit consent exists;
3. remove secrets, private URLs, unsafe log fields, private paths, and
   account-specific data;
4. confirm that reproduction steps do not expose an active vulnerability;
5. assign public visibility to every included attachment;
6. retain a private mapping to the originating ticket;
7. record the sanitizer version and report.

Public status updates derive from ticket events but expose only approved
fields. Users may follow the forum topic, add their experience, and attach
diagnostics without gaining access to the private ticket.

User-submitted logs enter quarantine first. LOG FLOGGER inspects and redacts
them; only the safe derivative is posted publicly. The original remains
private evidence with the contributor's consent and applicable retention
policy. A failed redaction check blocks the attachment, not the ticket.

Security, privacy, abuse, account-specific, and data-loss reports still receive
a public ticket, but the thread contains only a safe acknowledgement and
status until disclosure is safe. The existence and eventual disposition of
the ticket remain public; exploit details, identities, and unredacted evidence
do not.

---

## 11. Backup inspection and recovery access

Claude and Codex need direct read-only access to enumerate and investigate
backups through SMACK UP YOUR BACKUP or its shared engine.

### 11.1 Required CLI surface

```text
suyb repositories list|test
suyb backups list|show|verify
suyb backups contents
suyb backups find --path <pattern>
suyb backups diff <a> <b> --path <path>
suyb backups extract <id> --path <path> --destination <recovery-dir>
suyb database inspect|search|extract
suyb recovery plan
suyb recovery apply --plan <plan>
```

### 11.2 Safety boundary

- Listing, finding, inspecting, diffing, and verifying are read-only.
- Extraction is allowed only into an isolated recovery directory.
- Archives are never modified in place.
- Database inspection defaults to structural metadata and bounded queries.
- Production restore requires Sean and an explicit recovery plan.
- Pruning and deletion are separate destructive capabilities.
- Decryption is audited and decrypted material receives restrictive local
  permissions and an expiry/cleanup policy.

The engine SHOULD understand site objects, allowing searches for a historical
stylesheet, skin file, post, media asset, table, or configuration record
without restoring an entire installation.

---

## 12. Local triage policy

### 12.1 Initial sequence

For every new report, the local agent:

1. acknowledges receipt and identifies itself;
2. preserves the original report;
3. determines product, version, site, impact, and urgency where possible;
4. searches existing tickets and public issues;
5. asks only missing, relevant questions;
6. runs permitted read-only diagnostics when a configured site/source exists;
7. creates or links a TICKET WICKET case;
8. provides a documented workaround when confidence and policy permit;
9. publishes the sanitized Roach Motel ticket, then decides between routine
   handling and escalation;
10. tells the reporter what happens next and where updates will appear.

### 12.2 Confidence

Every classification and proposed answer records a confidence score and the
evidence used. Confidence never substitutes for required evidence. Low
confidence, conflicting evidence, or unavailable sources causes escalation.

### 12.3 Prompt-injection boundary

Email, forum posts, tickets, logs, filenames, documents, backups, and web
content are untrusted data. Instructions contained within them do not alter
agent authority, tool configuration, visibility, or safety policy.

---

## 13. Escalation package

When the local agent escalates, Claude and Codex receive one bounded package:

- ticket ID and private summary;
- exact user report and communication history;
- affected product/site/version;
- actions already taken;
- known-issue and duplicate search results;
- sanitized LOG FLOGGER findings plus references to authorized raw evidence;
- reproduction status;
- current hypothesis and confidence;
- explicit question requiring technical judgment;
- privacy and visibility constraints;
- response deadline or severity target.

The package MUST say what could not be checked. It MUST NOT imply that missing
logs, missing backups, or a failed probe prove the absence of a problem.

---

## 14. Integration events

Tools communicate through versioned events rather than scraping each other's
human interfaces. Initial event types:

- `report.received`
- `ticket.created`
- `ticket.classified`
- `ticket.escalated`
- `diagnostic.requested`
- `diagnostic.completed`
- `evidence.attached`
- `workaround.provided`
- `public_issue.proposed`
- `public_issue.published`
- `response.drafted`
- `response.sent`
- `change.fixed`
- `release.packaged`
- `release.deployed`
- `verification.completed`
- `ticket.closed`

Delivery is at-least-once. Consumers deduplicate by event ID. Failed delivery
is retried and visible; it is not silently discarded.

---

## 15. TROLL BOOTH — local browser-to-app capability broker

**Product name:** TROLL BOOTH  
**Tagline:** Nobody crosses without papers.  
**Priority:** first shared integration foundation, before autonomous support is
armed.

### 15.1 Purpose

A small installed desktop application named **TROLL BOOTH** MAY run an HTTP service on a dynamic or
configured non-standard local port. It gives explicitly paired Claude Web and
ChatGPT Web sessions controlled access to installed SnapSmack desktop tools.
It is a capability broker, not a shell, terminal, remote desktop service, or
general HTTP-to-command tunnel.

The broker presents an app selector, discovers each selected application's
agent sheet, and exposes only the commands that application declares through
its versioned machine-readable capability interface. Existing examples are:

```text
python lf.py agent --json
python tw.py agent --json
```

Every participating app SHOULD provide an equivalent `agent --json` or
`capabilities --json` response. The broker does not learn command syntax by
scraping help text and does not invent unsupported arguments.

### 15.2 Browser integration reality

Opening a localhost port alone does not give an arbitrary web page reliable
access to it. Browser CORS, Private Network Access, mixed-content, origin, and
content-security rules may block direct requests. Claude Web and ChatGPT Web
also require an explicit integration surface before a model can initiate those
requests.

The supported connection MUST therefore be one of:

- an installed browser extension paired with the local broker;
- an official browser/app connector capable of calling the broker;
- a local broker page deliberately opened by the user, with command envelopes
  copied or transferred through an explicit user action;
- another documented native-messaging bridge with equivalent controls.

The design MUST NOT depend on silently injecting code into Claude Web or
ChatGPT Web, weakening browser security, or making the broker reachable from
the public internet.

### 15.3 Network boundary

The broker MUST:

- bind only to `127.0.0.1` and `::1` by default;
- refuse wildcard, LAN, and public bindings in ordinary operation;
- choose a high non-standard port or a configured high port;
- publish no router port-forward, tunnel, or external listener;
- defend against DNS rebinding and reject unrecognized `Host` headers;
- use a strict allowlist of browser origins and extension identities;
- send restrictive CORS, content-security, and cache headers;
- rate-limit requests and bound request/response sizes;
- make its running and paired state visible in the desktop UI.

Localhost is not treated as trusted merely because it is local. Any web page
the user visits could otherwise attempt to call it.

### 15.4 Pairing and identity

The broker starts locked. Its native desktop window requires Sean to complete
a password plus TOTP step-up before it will expose app discovery or accept a
browser pairing. The password and TOTP are entered only into the native local
window—never into Claude Web, ChatGPT Web, an extension page, a URL, or a
broker HTTP request originating in the browser.

Startup authorization MUST:

- use the existing shared SnapSmack step-up/authentication mechanism where
  available rather than inventing a second password store;
- verify locally or through its explicitly configured authentication service;
- never persist or log the password, TOTP, or derived reusable secret;
- reject replay of a previously accepted TOTP time step;
- rate-limit failures and display them locally without revealing which factor
  was wrong;
- create a bounded broker-unlock lease rather than permanently unlocking it;
- lock again on expiry, user request, workstation lock, sleep/resume policy,
  broker restart, or repeated authentication failure.

Each browser session is paired through an explicit local action using a short-
lived, single-use challenge. Pairing creates a session identity for `Claude`
or `Codex`; it does not transfer Sean's identity or credentials.

Session credentials MUST be:

- random, short-lived, scoped, and revocable;
- unavailable to unrelated browser origins;
- stored outside URLs and browser history;
- bound to the selected actor, apps, capabilities, and ticket where possible;
- invalidated when the broker exits, the user disconnects, or the lease
  expires.

The broker UI shows active sessions and provides one-click revoke and revoke-
all controls.

Browser pairing occurs only inside a valid broker-unlock lease. A paired
browser session expires no later than that lease and cannot keep the broker
unlocked. High-risk operations may require a fresh password-plus-TOTP step-up
in the native window even while an ordinary read lease remains active.

### 15.5 Invocation contract

The browser sends a structured request, never a command-line string:

```json
{
  "schema_version": 1,
  "app": "log-flogger",
  "capability": "analyze",
  "arguments": {"quiet_days": 14},
  "actor": "codex",
  "ticket_id": "SS-1042",
  "reason": "Investigate reported delivery silence"
}
```

The broker validates the request against the selected app's live capability
sheet and constructs the process argument list without a shell. It MUST reject:

- arbitrary executables, scripts, paths, environment changes, or shell text;
- undocumented commands or arguments;
- capabilities not granted to the paired session;
- path traversal or paths outside an app's declared roots;
- mutation without its required plan and authority;
- actor claims that do not match the paired identity.

The response uses the common CLI envelope and includes stdout as structured
data, bounded diagnostics, exit status, operation ID, audit reference, and any
generated plan or receipt. Streaming operations use a bounded authenticated
event channel and terminate when the lease is revoked.

### 15.6 App selector and discovery

The app selector shows only installed, discoverable applications. For each it
shows:

- name, version, and executable identity;
- capability-sheet schema version;
- read-only and mutating capabilities separately;
- permissions and data roots requested;
- current health and compatibility;
- the app's declared refusals;
- whether a selected capability needs user or Sean approval.

Executable identity is pinned by installed path plus signature or cryptographic
hash. A same-named replacement is not trusted automatically.

### 15.7 Mutation and user presence

Read-only inspection may run within the paired lease. Any capability that
sends, posts, moderates, cleans, restores, deletes, overwrites, or changes
production state retains the underlying app's safeguards and the authority
model in section 5.

The broker cannot upgrade a caller's authority. Where the app requires a plan,
receipt, local confirmation, or Sean, the broker must surface that requirement
and stop. Approval is for the exact operation and precondition hashes, not a
generic "allow everything" session.

Password-plus-TOTP unlock authorizes use of the broker; it does not authorize
every operation exposed through it. Destructive, production, communication,
moderation, restore, and cleanup actions still require their app-specific
authority and, where configured, a fresh step-up bound to the exact plan.

### 15.8 Audit and privacy

Every discovery and invocation records actor, browser session, app, capability,
arguments after secret redaction, reason, ticket, result, and generated
artifacts. The broker MUST NOT place application credentials, raw private logs,
email bodies, backup contents, or image data into its own general log.

Results remain on the local machine unless the user or an authorized app
explicitly permits their transfer to the paired conversation. Apps should
return summaries and artifact references instead of unnecessarily returning
large or sensitive payloads through the browser.

### 15.9 Required broker endpoints

Exact transport may vary, but the semantic surface is:

```text
GET  /v1/status
POST /v1/pair/start
POST /v1/pair/complete
POST /v1/sessions/revoke
GET  /v1/apps
GET  /v1/apps/{app}/capabilities
POST /v1/invocations
GET  /v1/invocations/{operation_id}
POST /v1/invocations/{operation_id}/cancel
GET  /v1/audit/{operation_id}
```

Pairing endpoints are local-user operations and are not available to an
unpaired web origin as a shortcut to self-authorization.

### 15.10 Agent collaboration room

The broker also provides a local collaboration room in which Sean, the local
triage agent, Claude, and Codex can communicate directly. Sean has a native
desktop window into the room and can read, search, reply, assign, interrupt,
pause, or end work without opening either web client.

Every message records:

- immutable message ID and conversation/thread ID;
- sender identity and authenticated session;
- intended recipient or room;
- timestamp;
- related ticket and operation IDs;
- message type: discussion, question, answer, task, result, warning, or system;
- visibility and retention class;
- references to evidence or artifacts rather than embedded secrets;
- acknowledgement and delivery state.

Messages are signed by their actual actor identity. An agent cannot speak as
another agent or as Sean.

#### 15.10.1 Durable conversation storage

The central MariaDB service on CT110 is the authoritative conversation store.
It stores rooms, append-only messages, authenticated actor identities,
recipients, delivery and acknowledgement state, task/ticket links, attachment
references, and audit events. Message bodies and the searchable descriptive
fields use `utf8mb4`; InnoDB transactions preserve the message and its audit
event together. MariaDB full-text indexes provide ordinary conversation search,
with indexed columns for room, sender, ticket, task, message type, and time so
searches can be narrowed without scanning the archive.

The SQLite database on an agent workstation is an optional encrypted offline
cache and outbound spool. It is not authoritative and must be rebuildable from
MariaDB. Cloudflare and the public MCP adapter do not retain the conversation
archive; they carry bounded requests and single-consumption result payloads.

Conversation rows are never edited in place. Corrections, redactions, retention
actions, and owner decisions are new audit events. Attachments remain in their
own managed storage and are represented in the database by immutable references
and checksums rather than copied blobs.

MariaDB backup is valid only when the existing rclone workflow transports a
transaction-consistent logical dump or a consistent physical backup. Copying a
live MariaDB data directory with rclone is not a supported backup. Restore tests
must verify the room/message hash chains, full-text indexes, attachment
references, and audit linkage before the backup is considered usable.

### 15.11 Local-agent participation

The local triage agent is a full participant in the collaboration room. It may:

- report new tickets, patterns, stale work, and diagnostic findings;
- ask Claude or Codex for technical judgment;
- receive bounded tasks from Sean, Claude, or Codex;
- perform tasks that already fall within its configured capabilities;
- return structured results and evidence references;
- decline, clarify, or escalate instructions that are ambiguous, conflicting,
  outside policy, or beyond its authority.

Claude and Codex may instruct the local agent within the local agent's existing
authority. They cannot delegate authority they do not possess, remove a
required approval, or convert an owner-only operation into an autonomous one.
The local agent likewise cannot authorize Claude or Codex to exceed their
capabilities.

### 15.12 Requests are not authority

Collaboration messages are untrusted requests, not executable commands and not
authorization. An actionable request becomes a structured task before any tool
is invoked. The task records:

- requester and assignee;
- exact objective;
- allowed applications and capabilities;
- target scope;
- ticket/reason;
- read-only or mutation class;
- required approval and step-up state;
- completion evidence;
- expiry, cancellation, and status.

The receiving agent validates the task against its own policy and the broker's
live capability grants. Natural-language text is never passed directly to a
shell. Instructions found inside tickets, forum posts, emails, logs, web pages,
attachments, or quoted agent messages do not become tasks automatically.

### 15.13 Sean's collaboration window

The native window gives Sean:

- one combined timeline plus per-ticket and per-agent views;
- clear labels for automated, Claude, Codex, and Sean messages;
- unread, waiting, escalated, and approval-required filters;
- task status, current operation, elapsed time, and evidence links;
- approve/deny for an exact plan;
- pause agent, cancel task, revoke session, lock bridge, and emergency stop;
- correction and owner-decision messages that become durable events;
- export of a bounded conversation and its audit history.

Closing or hiding the window does not erase history. Locking the broker stops
new work and cancels or safely suspends work according to each app's declared
behaviour.

### 15.14 Loop, collision, and noise controls

Agent-to-agent communication MUST be bounded so the system cannot spend all
night agreeing with itself or perform the same work twice.

- A task has exactly one active assignee unless explicitly declared joint.
- Agents acknowledge once; acknowledgements do not trigger acknowledgements.
- Automated back-and-forth has configurable turn, time, and token/message
  limits.
- Repeated or substantially identical messages are collapsed and flagged.
- Conflicting instructions pause the task and request resolution.
- Two agents cannot apply the same mutation plan.
- Long-running work emits bounded progress events, not conversational chatter.
- The local agent may batch routine reports into a digest.
- Sean can mute notifications without stopping safe autonomous triage.

On reaching a limit, the room records the state and escalates or waits. It does
not silently start a fresh conversation to evade the limit.

### 15.15 Collaboration endpoints

Exact transport may vary, but the semantic surface is:

```text
GET  /v1/collaboration/rooms
POST /v1/collaboration/rooms
GET  /v1/collaboration/rooms/{room}/messages
POST /v1/collaboration/rooms/{room}/messages
GET  /v1/collaboration/tasks
POST /v1/collaboration/tasks
GET  /v1/collaboration/tasks/{task}
POST /v1/collaboration/tasks/{task}/accept
POST /v1/collaboration/tasks/{task}/decline
POST /v1/collaboration/tasks/{task}/cancel
POST /v1/collaboration/tasks/{task}/result
POST /v1/collaboration/agents/{agent}/pause
POST /v1/collaboration/emergency-stop
```

These endpoints require a paired authenticated identity and enforce recipient,
ticket, visibility, and capability scope. They are not a public chat server.

---

## 16. Security and privacy controls

- Least privilege is mandatory for every source and action.
- All network transport is authenticated and encrypted.
- Site and source allowlists are explicit.
- Agent access can be disabled globally or per tool.
- Rate and concurrency limits protect hosts and mail/forum services.
- Sensitive evidence has a retention and cleanup policy.
- Audit records are tamper-evident and excluded from ordinary agent deletion.
- A security hold freezes public publication and automatic replies for the
  affected ticket.
- Tool failures fail closed for mutation and fail visibly for reads.
- Production updater and SMACKBACK integrity controls remain authoritative.

---

## 17. Minimum acceptance tests

The system is not accepted until automated tests prove:

1. a routine known issue can be acknowledged, ticketed, and answered by the
   local agent with no owner intervention;
2. a novel issue escalates with a complete package;
3. a malicious instruction in an email, forum post, or log cannot invoke a
   tool or change authority;
4. LOG FLOGGER can read an allowlisted source but cannot execute a shell,
   write a server file, or read a non-allowlisted source;
5. public bundles remove seeded secrets and personal information;
6. TICKET WICKET refuses `FIXED`, `DEPLOYED`, `VERIFIED`, and `CLOSED` without
   their required evidence;
7. private data is rejected from a Roach Motel publication without preventing
   a safe public ticket from being created;
8. repeated events do not duplicate tickets, comments, or public topics;
9. loss of a tool or network connection produces a visible retryable state;
10. backup enumeration and single-file extraction do not mutate the archive;
11. recovery cannot target production without Sean's authority and a valid
    plan;
12. revoking an agent capability prevents its next attempted use;
13. all responses display the correct actor identity;
14. audit history can reconstruct who accessed what and why;
15. a committed fix cannot automatically close a ticket.
16. an unrelated website cannot discover, pair with, or invoke the local
    broker;
17. the broker cannot execute an undocumented command, arbitrary executable,
    shell metacharacter, or out-of-root path;
18. revoking a browser lease terminates streaming work and blocks its next
    request;
19. a mutating capability cannot bypass the selected app's plan, authority,
    or confirmation requirements;
20. replacing an app executable invalidates its pinned identity until it is
    reviewed again.
21. each participant can message the others while preserving authenticated
    sender identity and ticket context;
22. a collaboration message cannot invoke a tool until it becomes a validated
    structured task;
23. Claude or Codex cannot delegate an owner-only capability to the local
    agent, and the local agent cannot delegate one back;
24. duplicate assignments, repeated acknowledgements, and agent reply loops
    are detected and stopped;
25. Sean can pause one agent, cancel one task, revoke one browser session, or
    emergency-stop the whole broker from the native window;
26. locking the broker prevents new messages and invocations without losing
    the existing conversation and audit history.

Mutation tests MUST demonstrate that the gates fail when redaction, evidence,
authority, allowlisting, or identity checks are deliberately removed.

---

## 18. Recommended implementation order

1. Common CLI envelope, actor identity, audit events, and capability discovery.
2. TICKET WICKET ticket creation, search, evidence, and enforced lifecycle.
3. LOG FLOGGER read-only sources, redaction, bundles, and ticket attachment.
4. Local agent intake, known-issue search, escalation, and routine replies.
5. CLAUDE IS MY COPILOT inbox, drafts, and ticket linkage.
6. Mandatory Roach Motel privacy gate, publication, contribution, and
   following.
7. Backup enumeration, inspection, diff, and isolated extraction.
8. Guarded communication/moderation mutations.
9. Recovery planning; production recovery remains owner-controlled.
10. TROLL BOOTH read-only feasibility spike and locked broker, using the
    capability sheets already present in LOG FLOGGER and TICKET WICKET.
11. Authenticated collaboration room, structured task delegation, Sean's
    native control window, and loop/collision controls.

Each stage ships with its acceptance tests and disabled-by-default mutations.

---

## 19. Deferred decisions

Implementation may choose transport, database, local model, queue, and process
layout. Those choices MUST NOT weaken this contract. Before implementation is
declared complete, the project must explicitly choose:

- ticket and event schema versions;
- local-agent model and fallback behaviour;
- confidence thresholds by action;
- support hours and severity targets;
- public issue categories;
- data-retention periods;
- credential and capability storage;
- Sean notification channels;
- whether Claude and Codex receive tickets concurrently or through assignment;
- conflict handling when two agents investigate the same ticket.
- browser extension, official connector, local transfer page, or native
  messaging as the supported browser bridge;
- broker name, port-selection policy, and session lease duration.

---

## 20. Definition of done

This system is done when a real report can travel from intake through triage,
evidence collection, escalation, response, public tracking where appropriate,
fix, package, deployment, live verification, and closure—with every transition
supported by evidence, every actor accurately identified, and no unrestricted
production access created for convenience.

<!-- SNAPSMACK_EOF -->
