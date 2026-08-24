<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER Linux Chrome/Blink host

The Linux product reuses the shared `web/` interface. A thin Python `snap_blink` host
launches the Linux `snapsmack-photo-core` engine with only user-approved parent folders,
then implements the same `editor_call` contract as Tauri. Chromium is UI only;
full-resolution pixels and 16-bit processing never enter browser JavaScript.

Launch with Rust, Python 3 and Chrome/Chromium installed:

```text
./run.sh
```

Native dialogs use the shared Zenity/KDialog/Qarma adapter. Packet 07 completes Linux
hardware and packaging tests. macOS remains unsupported.

<!-- ===== SNAPSMACK EOF ===== -->
