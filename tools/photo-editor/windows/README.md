<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Windows host

The Windows product is a thin Tauri 2 host using the shared `web/` interface. Native
dialogs choose input and Save Copy destinations. The shared Rust core owns decode,
recipes, bounded preview generation and export; the webview never receives full-size
source pixels.

Run a development build from `src-tauri`:

```text
cargo run --offline
```

The first usable host embeds the shared core crate in-process. Moving that same API
behind the already-defined supervised sidecar transport remains packaging hardening;
processing code must not migrate into Tauri command handlers.

The sidecar is not granted network access or SnapSmack credentials. THE HUB, COLD SNAP
and GYSS will launch the editor as a separate executable through the versioned job contract.

<!-- ===== SNAPSMACK EOF ===== -->
