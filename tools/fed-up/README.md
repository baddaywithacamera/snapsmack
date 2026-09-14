# FED UP — fediverse backup, migration, and identity

Fed(iverse) + "fed up with your current server."

Three functions (spec: `_spec/fed-up-spec-v0_1.md` + `v0_2.md`):

1. **BACK UP** your fediverse profile — posts with real dates, media, counts, followers and
   following — into a folder of plain files you keep. Optional copy to your own cloud storage
   via SMACK UP YOUR BACKUP's transport.
2. **RESTORE** a FED UP archive into a SnapSmack site (rides UNZUCKER's poster).
3. **ALIAS** — `@you@photoblogs.fyi`, a handle on a domain we keep alive (a CMS build, not this tool).

## State: 0.0.1 — shell only
The window exists, is honest about what is built (nothing), and is launched from SNAP HQ's
MIGRATION CENTRE. Build order per spec v0.2: function 3 (CMS) → 1 → 2.

## Run from source
    python app.py          # needs tools/_shared on the path; app.py adds it

## Build
    build.bat              # tests → PyInstaller (fed-up.spec, local) → C:\snapsmack\fed-up\fed-up.exe
