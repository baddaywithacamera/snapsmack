<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 01 security boundary

The editor is local and offline. Hosts pass no API keys, cookies or site credentials.
The sidecar accepts JSON on inherited stdin and writes JSON on stdout. It opens no socket.

Trust rules:

- Host-approved roots must exist before launch and are canonicalized once.
- Job paths must be absolute, exist, resolve inside an approved root and have the expected
  file/directory kind. Symlink escapes fail after canonicalization.
- HEIC/HEIF is rejected by extension and HEVC-backed ISO-BMFF brands, including renamed
  files. Packet 02 adds decoder-level format verification for all accepted formats.
- Outputs will use a temporary sibling plus flush/sync and atomic rename. Replacement is
  never implicit. Packet 02 must test interrupted writes.
- Requests have IDs; errors have stable codes. Unknown methods and contract versions fail.
- Long jobs are cancellable. Hosts kill orphaned sidecars after a bounded shutdown window.
- Future decoders must enforce dimensions, allocation budgets, tile limits and fuzz tests.

Packet 01 does not claim sandboxing. OS sandbox/permissions and binary signing are release
work. The narrow sidecar contract is the security boundary even when a host is compromised.

<!-- ===== SNAPSMACK EOF ===== -->
