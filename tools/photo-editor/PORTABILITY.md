<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER portability and shared-library boundary

PIXEL TICKLER uses `SNAPSMACK_HOME` as the portable suite root. Windows installation
places the exact HUB launch target at `pixel-tickler/pixel-tickler.exe`; Linux places
its launcher at `pixel-tickler/linux/run.sh`. No wildcard executable discovery is used.

Autosave and recovery state lives at
`config_files/pixel-tickler/autosave.json` under that same root. Moving the suite to a
USB drive therefore moves editor state with it and does not require a fixed drive letter.

The editor does not read or mutate `shared_library` for standalone work. COLD SNAP and
GYSS integrations provide explicit input working copies through the versioned job
contract; the calling app remains responsible for catalog and upload behavior. This
keeps PIXEL TICKLER useful by itself and prevents hidden coupling to library databases.

Windows install from `windows/`:

```text
powershell -ExecutionPolicy Bypass -File install-portable.ps1 -SnapSmackRoot E:\snapsmack
```

Linux install from `linux/`:

```text
./install-portable.sh /media/user/SNAPSMACK/snapsmack
```

<!-- ===== SNAPSMACK EOF ===== -->
