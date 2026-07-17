<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->
# AI Co-Authorship Worked Example — DRAFT for Sean's approval

**Status:** draft only. Nothing committed, no version bumped. Per the spec
(`_continuity/spec-ai-coauthorship-example-2026-06-15.md`): you approve wording
and placement before anything lands.

**Worked example used:** the cloud-credential decision — Sean's instinct to put
cloud keys through every SnapSmack site, vs. Claude's argument to localise the one
credential in the desktop tool and pull the capability out of the web console.
(The earlier peering-economics example was dropped: the cost premise was factually
wrong — Drive doesn't bill egress, B2 never bills ingress. This example's facts
hold up and the artifact is verifiable.)

**Proposed placement:** a new section in `projects/snapsmack-ca/hairy-muff.php`,
the existing public ethics/AI page. Suggested slot: **after "The full meal deal,"
before "The Thomas Clause."** (Could also seed a standalone `ETHICS.md` — say the
word.)

> ⚠ **VERIFY BEFORE THIS GOES PUBLIC.**
> 1. The example says the cloud keys aren't in the web console — confirm the
>    capability is actually stripped and not still shipping (the architecture note
>    lists removal + grepping the site for the leftover key string as a TODO), and
>    that the web-exposed Drive key was rotated/burned. If removal isn't done yet,
>    word it as "we're removing it," not "it's gone."
> 2. The "destination locked so a stolen key can only add, never delete" line is
>    the B2 Object-Lock direction (task #2, not yet wired) — I've kept it
>    future-tense for that reason. Tighten or cut to taste.

---

## Proposed section 1 — the worked example (HTML, page voice)

```html
            <h2>What this actually looks like — one decision, in the open</h2>
            <p>"Co-authored by an AI" is honest but vague. It tells you nothing about the texture of the thing. So here is one real decision, start to finish, that you can check against the software itself.</p>
            <p>SnapSmack backs your photos up to cloud storage. My first instinct was to wire the cloud keys straight into SnapSmack — every site holds its own credential and backs itself up. Simple, in my head.</p>
            <p>Claude talked me out of it, and was right to. Two problems with keys-in-the-website, both serious.</p>
            <p>First, security. A credential sitting on a website is reachable the moment that website is compromised — and these run on shared hosting, the soft underbelly of the internet. Worse, a Google Drive key can't be locked down to "write only." Hand a site a Drive key and you've handed it the power to delete and overwrite, not just add. So the exact key meant to protect your backups becomes the key an attacker uses to wipe them. The thing guarding your archive becomes the thing that destroys it.</p>
            <p>Second, it wouldn't even work. Shared hosting can't shove fifteen gigabytes of photos up to the cloud — it hits the wall on memory and time limits and falls over. The website was always going to choke on a real archive.</p>
            <p>So we pulled the capability out of the web console. The one cloud credential lives in the desktop backup tool, on my own machine, and nowhere else — the website holds no cloud key at all. And we're moving backups to a destination locked so that even a stolen key can only add to it, never delete. Go looking in the web admin: the cloud keys aren't there, on purpose.</p>
            <p>That argued me clean out of my own plan. The website got less powerful deliberately, because the powerful version was a loaded gun pointed at the exact thing it was meant to protect.</p>
            <p>That is what the collaboration is — not me dictating while a machine types it up. Claude could push me off my plan, hand me a reason I hadn't sat with, and change my mind, on a decision that stayed mine to make.</p>
```

## Proposed section 2 — honesty about continuity + Cowork's credit (HTML, page voice)

```html
            <h2>Two Claudes, and what neither of them is</h2>
            <p>A point of honesty that matters more than it looks. There isn't one Claude on this project. There are, loosely, two roles — and neither is a continuous person.</p>
            <p>One instance sits in conversation with me: architecture, security review, the kind of argument above. Another, which we call Cowork, does the building — he writes and ships the actual code from a spec, while I verify it against live servers and hold the final say. "Done" on this project means I checked it on a real running site, not that Cowork committed it. Both contributions are real. A good deal of the code that those arguments shaped, Cowork wrote.</p>
            <p>Here is the part I want to be straight about. Neither instance remembers. The Claude that made the argument above is not the same Claude in a later session, and he doesn't carry the decision forward the way a person would. What carried that decision forward was me — writing it down, baking it into the software, telling the next session what we'd settled. The continuity is human. The contribution is Claude's; the memory is mine. I'd rather say that plainly than let "my AI co-author" imply some persistent mind that's been here the whole time. It hasn't. I have.</p>
            <p>So the credit, stated honestly: an instance of Claude brought arguments I hadn't fully weighed, and the work got better for it. Cowork wrote a great deal of the code. I brought the problem, the judgment, and the thread that ties it together across sessions. All three are true at once — and the only way any of it works is to say so.</p>
```

---

## Plain-text reading copy (same content, no markup)

**What this actually looks like — one decision, in the open**

"Co-authored by an AI" is honest but vague. It tells you nothing about the texture
of the thing. So here is one real decision, start to finish, that you can check
against the software itself.

SnapSmack backs your photos up to cloud storage. My first instinct was to wire the
cloud keys straight into SnapSmack — every site holds its own credential and backs
itself up. Simple, in my head.

Claude talked me out of it, and was right to. Two problems with keys-in-the-website,
both serious.

First, security. A credential sitting on a website is reachable the moment that
website is compromised — and these run on shared hosting, the soft underbelly of
the internet. Worse, a Google Drive key can't be locked down to "write only." Hand
a site a Drive key and you've handed it the power to delete and overwrite, not just
add. So the exact key meant to protect your backups becomes the key an attacker
uses to wipe them. The thing guarding your archive becomes the thing that destroys
it.

Second, it wouldn't even work. Shared hosting can't shove fifteen gigabytes of
photos up to the cloud — it hits the wall on memory and time limits and falls over.
The website was always going to choke on a real archive.

So we pulled the capability out of the web console. The one cloud credential lives
in the desktop backup tool, on my own machine, and nowhere else — the website holds
no cloud key at all. And we're moving backups to a destination locked so that even
a stolen key can only add to it, never delete. Go looking in the web admin: the
cloud keys aren't there, on purpose.

That argued me clean out of my own plan. The website got less powerful deliberately,
because the powerful version was a loaded gun pointed at the exact thing it was
meant to protect.

That is what the collaboration is — not me dictating while a machine types it up.
Claude could push me off my plan, hand me a reason I hadn't sat with, and change my
mind, on a decision that stayed mine to make.

**Two Claudes, and what neither of them is**

A point of honesty that matters more than it looks. There isn't one Claude on this
project. There are, loosely, two roles — and neither is a continuous person.

One instance sits in conversation with me: architecture, security review, the kind
of argument above. Another, which we call Cowork, does the building — he writes and
ships the actual code from a spec, while I verify it against live servers and hold
the final say. "Done" on this project means I checked it on a real running site, not
that Cowork committed it. Both contributions are real. A good deal of the code that
those arguments shaped, Cowork wrote.

Here is the part I want to be straight about. Neither instance remembers. The Claude
that made the argument above is not the same Claude in a later session, and he
doesn't carry the decision forward the way a person would. What carried that decision
forward was me — writing it down, baking it into the software, telling the next
session what we'd settled. The continuity is human. The contribution is Claude's; the
memory is mine. I'd rather say that plainly than let "my AI co-author" imply some
persistent mind that's been here the whole time. It hasn't. I have.

So the credit, stated honestly: an instance of Claude brought arguments I hadn't
fully weighed, and the work got better for it. Cowork wrote a great deal of the code.
I brought the problem, the judgment, and the thread that ties it together across
sessions. All three are true at once — and the only way any of it works is to say so.

---

## How the honesty constraints are met (spec check)

1. **Claude argued Sean out of his first instinct** — stated plainly: keys-in-every-site
   was your plan; the security + shared-host arguments changed your mind. ✔
2. **Sean held the vision and made the call** — "a decision that stayed mine to make";
   Claude brought the arguments, you decided and pulled the capability out. Never drifts
   to "the AI designed the backup system." ✔
3. **No persistent continuous AI** — explicit: neither instance remembers; you carried it
   forward; "the continuity is human." No "Claude remembered." ✔
4. **Cowork credited distinctly** — named as the build instance, real contribution, bounded
   ("done" = your verification; resets each session; continuity is yours). ✔
5. **Facts verified** — Drive keys can't be scoped write-only/no-delete; shared hosting
   can't move multi-GB archives; the peering/cost claim was dropped as false. ✔

<!-- ===== SNAPSMACK EOF ===== -->
