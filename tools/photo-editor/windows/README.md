<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Windows host — Packet 01 boundary

The Windows product will be a thin Tauri host using the shared `web/` interface. It
starts one `snapsmack-photo-core.exe` sidecar with explicit `--allowed-root` arguments,
maps `editor_call` to framed JSON requests, validates response IDs, supervises shutdown
and never processes pixels itself. Packet 02 creates the actual window and supervisor.

The sidecar is not granted network access or SnapSmack credentials. THE HUB, COLD SNAP
and GYSS launch the editor as a separate executable through the versioned job contract.

<!-- ===== SNAPSMACK EOF ===== -->
