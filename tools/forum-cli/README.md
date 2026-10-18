<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
-->

# FORUM CLI

The SnapSmack forum from a command line, so the desktop tools, Claude and
Codex can all work it without a browser.

```
fc whoami                     which key is this, and what may it do?
fc boards                     the active boards
fc threads --board 3          what is being discussed
fc thread 42                  one thread, with its replies
fc post --board 3 --title "Upload fails" --body-file bug.txt
fc reply 42 --body "Fixed in 0.7.903D."
fc lock 42                    pin / move / follow / react
fc search "upload fails"
fc notifications              replies to you and to threads you follow
fc agent                      what an agent can do here, refusals first
```

Every command prints plain text, or JSON with `--json`. The flag works in
either position — `fc --json whoami` and `fc whoami --json` both do.

## Credentials

```
--forum-url   or  SNAPSMACK_FORUM_URL    default https://snapsmack.ca/api/forum/
--forum-key   or  SNAPSMACK_FORUM_KEY
```

Nothing is written to disk. A key belongs to the environment that holds it,
not to a config file a tool leaves behind on a machine somebody else may use.
Without a key the tool is inert, and says so: a stolen copy is a dead thing.

## Ask before you act

`fc whoami` is the first call any script should make. The forum has a role
ladder and most commands are gated on it:

| role | name | what it may do |
|-----:|------|----------------|
| 0 | member | read, post, reply, react, follow |
| 1 | power | the same, with roomier rate limits |
| 2 | moderator | pin, lock, move, tag, edit and delete others' posts |
| 3 | admin | create boards, set roles below its own |
| 4 | god | the `FORUM_MOD_KEY` itself — not an install |

`whoami` answers with `can: {post, moderate, administer}` so a caller never
has to know which numeric gate gets checked where.

This question used to be unanswerable. The only read gated near the top was
`GET /installs`, which sits at **admin**, so a tool probing it to ask "can I
moderate?" told a working moderator key that it could not. `GET /me` exists
because of that, and this tool is why it was built.

## Rate limits

Per install, enforced by the API. Moderators are exempt.

| action | ordinary | role >= 1 |
|--------|---------:|----------:|
| thread | 3/hour | 12/hour |
| reply | 10/hour | 40/hour |
| reaction | 30/10min | 120/10min |

## What it will not do

- **No delete.** Threads and replies soft-delete through the API; this tool
  does not expose it. Ask a human.
- **No breadcrumb on a move.** `fc move` moves the thread and leaves nothing
  behind in the old board. That gap is named in `snap_forum.py` rather than
  faked, and it stays named until somebody builds it.

## Where the client lives

`tools/_shared/snap_forum.py`, shared with TICKET WICKET, which imports it
through a re-export at `ticketwicket/forum.py`. There was one copy per tool
before this; two copies of an HTTP client drift apart, and the copy that
drifts is always the one nobody is reading.

## Tests

```
cd tools/forum-cli && python -m pytest -q
```

Nothing touches the network. The tests drive the parser and a fake client,
and two of them compare the agent sheet against the real command list in both
directions — a sheet that promises a command which does not exist, or hides
one that does, fails the build.
<!-- ===== SNAPSMACK EOF ===== -->
