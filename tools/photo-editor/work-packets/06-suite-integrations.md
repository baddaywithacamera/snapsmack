<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 06 — THE HUB, COLD SNAP and GYSS integrations

Register the standalone editor in THE HUB's explicit safe launcher roster. Add `Edit`
actions to COLD SNAP drafts and GYSS library records using the frozen job/result contract.
The calling app grants one input and one output directory, launches the editor, validates
the random return token and result checksum, then updates its own draft/library state.
The editor never receives a site credential and never uploads anything.

COLD SNAP edits a local working copy before posting. GYSS defaults to a new derivative;
replacement of a managed photo is explicit, recoverable, and remains subject to its
server conflict rules and thumbnail regeneration. Both must reject stale/missing results.

Acceptance covers paths with Unicode/spaces, cancelled edits, crashes, forged result
files, changed originals, concurrent app sessions and successful offline round trips.

<!-- ===== SNAPSMACK EOF ===== -->
