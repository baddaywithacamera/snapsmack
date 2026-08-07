<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# TAKE YOUR SHIT WITH YOU — changelog

Versioning: patch increments only (`0.1.4` is followed by `0.1.5`). A minor bump
is a decision somebody makes on purpose. `build.bat` bumps the patch on every
build so no two exes carry the same number.

The **portable schema** is versioned separately from the application (spec
section 16). Additive optional fields are a minor schema version; a breaking
change to a field or its meaning is a major one.

## 0.1.0 — 2026-08-07 — first build

Portable schema version **1**. Stream format **1**. Server API **0.1**.

### The tool

- **Connect, preflight, pack.** Paste a site address and a read-only `tyswy`
  key, see what the site actually holds, choose a folder, and go. The counts
  come from indexed reads; the media total is deliberately reported as unknown,
  because the only way for the site to know it is to walk its own disk and that
  is precisely what this product exists to avoid.
- **Resumable, and honest about it.** Stopping is safe at any point. Record
  streams resume from the last verified row; media resumes mid-file with an HTTP
  Range request. The tool refuses to resume into a folder holding a different
  site, and refuses to overwrite a completed export.
- **Nothing is trusted.** Every record is re-serialised locally and its SHA-256
  compared with the server's; every chunk is checked against a rolling hash and
  a row count in its footer; a chunk with no footer was cut off and is discarded
  whole rather than counted as short-but-complete. Every downloaded file is
  hashed as it arrives.
- **Verification is a stage, not a claim.** After the streams finish the tool
  asks the site for fresh counts at the export snapshot and compares them with
  its own ledger. Anything the site changed mid-run is re-checked and listed in
  `verification.json` by id. An export is not labelled complete if something
  expected is missing; it finishes as *complete with warnings* only when every
  omission is named.

### The archive

- **It stays a folder.** JSON sidecars beside the actual photographs, readable
  with a text editor and a file manager, no SnapSmack and no TYSWY required.
  `README.txt` inside explains it without reference to us. Compression is local,
  optional, and only after verification.
- **Nothing is silently discarded.** Fields the portable mapping does not
  understand land in a namespaced `snapsmack` object rather than on the floor.
  A column added to the CMS next year survives this export even though this
  version has never heard of it.
- **Mode shape is preserved, never converted.** A GRAMOFSMACK carousel stays one
  post owning an ordered image array — carousel order is read from the source and
  never re-derived. A SMACKONEOUT image stays a primary published item. SMACKTALK
  bodies keep their inline `[img:ID]` / `[mosaic:ID]` references, listed
  explicitly so a reader does not need to know SnapSmack's shortcode syntax to
  find which files a piece of writing depends on.
- **A JSON Schema ships inside every archive**, so an archive found on a drive in
  ten years can still be machine-validated by someone who has never heard of any
  of this.
- **The manifest cannot carry a credential.** Every value written into it is
  walked and refused if it is credential-shaped. That is a backstop, not the
  main defence — the server's column allowlists are — but the manifest is the
  file most likely to be forwarded to a stranger.

### WordPress courtesy package

- WXR 1.2 with posts, pages, media attachments, comments, categories and tags;
  carousels become ordered galleries; longform becomes conservative HTML.
- **Every loss is written down** in `conversion-report.html`: albums flattened to
  tags, collections not imported at all, MOSAIC layouts, trigram slicing,
  per-image crop/focal/zoom framing, reaction counts, content warnings. The
  canonical archive keeps all of it; WordPress simply has nowhere to put it.
- Media is **hard-linked** into the package where the filesystem allows it, so a
  40 GB library is not copied to sit beside itself.
- The adapter reads only the canonical archive and writes only under
  `courtesy/wordpress/`. Running it twice leaves the canonical files
  byte-identical. It can be re-run without downloading the site again.

### Security

- The export key is a scoped, read-only `tyswy` key with a three-month expiry.
  It reaches the export API and nothing else, and no key minted for another tool
  reaches it.
- HTTPS is required. Plain `http://` is refused before the key is ever sent —
  loopback excepted, where there is no network path to intercept.
- The key at rest is sealed with the shared credential vault (scrypt + Fernet)
  when encryption is on. Saving while the vault is locked raises rather than
  quietly rewriting the key in the weaker base64 form.
- Downloads land in `.part` files and are renamed atomically, so a half file
  never looks like a finished one. Filenames are sanitised for Windows without
  losing the source title, which stays in the sidecar.

<!-- ===== SNAPSMACK EOF ===== -->
