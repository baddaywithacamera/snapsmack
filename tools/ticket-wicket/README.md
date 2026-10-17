# TICKET WICKET

A real issue becomes a numbered, tracked thing with one home and one honest
history — posted publicly, carrying nobody's name.

## How it works

Every ticket goes in the **tickets board on the forum**, whatever channel it
arrived on. Email intake does not make a private ticket; it makes a ticket,
which is posted like any other.

**Tickets never contain information that identifies who submitted them.** The
reporter, their channel and their original message live in the store and never
travel. The ticket is written *about the problem*, never as a reprint of what
arrived. The tool refuses to post anything that still identifies someone.

Someone reporting a thing already being worked on gets *"we're aware — here's
the ticket, follow it for updates"* instead of a promise that evaporates. The
forum's existing **follow** and **react** features do that work: an "affected"
reaction is the I'm-affected-too button, and the count feeds urgency.

## Urgency is computed, not picked

From security, usability and how many are affected. Security outranks the other
two (ETHICS.md: ethics first, security second). The other two multiply, so
cosmetic-across-the-fleet and stopped-dead-on-one-site can land in the same band
— which is correct, and is why a flat ladder would not do.

    LOSING WORK · DOOR OPEN · STOPPED · WORKING ROUND IT · WANTED

Urgency exists so *staleness* means something. Without bands every old ticket
looks equally like neglect, the warnings become noise, and they get ignored.

## Command line — for Claude and for ChatGPT

    tw queue --as claude
    tw show SS-1042 --private
    tw new --title "..." --usability stopped --affected 3
    tw triage SS-1042 --security --body "..."
    tw post SS-1042 --dry-run
    tw reply SS-1042 --text "..." --as chatgpt
    tw ack SS-1042                      # the fixed acknowledgement
    tw escalate SS-1042 --why "security report"
    tw stale
    tw outbox
    tw mod-check                        # does my forum key actually moderate?

Everyone signs as themselves. The store stamps who is driving; a caller cannot
claim to be someone else.

## What it will not do

**There is no `send`.** The CLI and the window queue; a separate armed sender
holds the only credential. A compromised tool — or an agent talked into
something by a malicious support message — can write a bad draft and cannot
deliver one.

**There is no `delete`.** Close, withdraw, merge. The record survives all three.

**Nothing auto-sends on a ticket with a pulse.** Frustration, anger, security,
payment, a first-time poster: those reach the human.

## Not built yet, named rather than faked

The forum API has no endpoint to move a thread between boards, and no
breadcrumb when one moves. Both are small and both are in code we own — threads
already carry `category_id`. `forum.py` raises with that explanation rather than
pretending.

## Tests

    .python-build/python.exe -m pytest tools/ticket-wicket/tests -q
