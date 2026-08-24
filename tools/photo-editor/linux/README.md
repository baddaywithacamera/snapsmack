<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Linux Chrome/Blink host — Packet 01 boundary

The Linux product reuses the shared `web/` interface. A thin Python `snap_blink` host
starts the Linux `snapsmack-photo-core` sidecar, implements the same `editor_call`
contract as Tauri and supervises progress, cancellation and shutdown. Chromium is UI
only; full-resolution pixels and 16-bit processing never enter browser JavaScript.

Packet 07 completes and hardware-tests this host. macOS is unsupported: there is no
binary, package, signing, compatibility work or promise.

<!-- ===== SNAPSMACK EOF ===== -->
