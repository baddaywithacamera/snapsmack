<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 01 architecture decisions

1. **Original Rust sidecar.** Rust supplies a small cross-platform native boundary with
   no runtime installation. Packet 01 depends only on permissive JSON serialization.
2. **JSON lines over inherited stdio.** No port is opened. Hosts own the child process,
   correlate request IDs and receive progress events. Pixel buffers never use JSON.
3. **Explicit approved roots.** The host launches the sidecar with existing canonical
   roots. The engine canonicalizes every input/output and refuses escape or symlink exit.
4. **Shared web interface, different thin hosts.** Windows Tauri and Linux `snap_blink`
   implement one logical adapter. Browser code never owns full-resolution pixels.
5. **Versioned data contracts.** Version 1 is negotiated by `hello`. Unknown required
   contracts fail closed. Project recipes are data, never code.
6. **No network or credentials.** The editor edits local files. Calling tools publish.
7. **No macOS obligation.** Windows and Linux builds only. User compilation elsewhere
   is unsupported and cannot influence architecture.

Memory budgets, preview dimensions and image dependencies are deliberately deferred to
Packet 02 because Packet 01 decodes no pixels. Their contract fields may be added only
compatibly.

<!-- ===== SNAPSMACK EOF ===== -->
