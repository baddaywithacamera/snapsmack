<!-- SNAPSMACK_EOF_HEADER: desktop automation usage and verification scope. -->

# Desktop app CLI

These commands let a local assistant work through the desktop engines without the apps making AI API calls. They do not implement subscription sign-in or embedded chat. Run them from the repository with the Python dependencies used by the relevant app installed.

```powershell
python tools/desktop_cli.py gyss capabilities
python tools/desktop_cli.py sybu --help
python tools/desktop_cli.py slapper --help
python tools/desktop_cli.py lewks --help
python tools/desktop_cli.py ohsnap --help
python tools/desktop_cli.py coldsnap --help
python tools/desktop_cli.py shots-fired --help
python tools/desktop_cli.py suyb --help
python tools/desktop_cli.py smackpress --help
```

Responses use `{ok, protocol, app, data}` or `{ok:false, error}` JSON. Exit 0 means success, 2 means invalid input/operation failure, and Python exit 3 means authorization is missing. A remote metadata batch with conflicts or failures returns nonzero and preserves the server's details, including any successful partial application.

Python desktop launchers also accept `--cli` before GUI startup. Their next packaged builds will include the new imports through the existing build recipes. Existing installed Python executables have not been replaced in this change. For windowed builds, use `--result FILE` to receive JSON without needing a console:

```powershell
python tools/gyss/gyss_qt.py --cli --result C:/temp/gyss-result.json capabilities
```

Use saved project copies and close/reload the relevant editor around external file edits. These interfaces do not attach to an open window's unsaved document or refresh a running queue automatically. Output projects are separate files; original photographs and input projects cannot be output targets. Existing CMS authorization remains required for protected SNAP SLAPPER editing and LEWK saving. Packaged Python CLI calls retain the SNAP HQ dependency gate.

## GYSS and retained enrichment

```powershell
python tools/desktop_cli.py gyss photos --site https://example.com --offset 0 --limit 100
python tools/desktop_cli.py gyss images --site https://example.com --limit 100
python tools/desktop_cli.py gyss enrichment-import --site https://example.com --image C:/photos/example.jpg --input C:/work/findings.json
python tools/desktop_cli.py gyss enrichment-import --site https://example.com --image C:/photos/example.jpg --input C:/work/findings.json --apply
python tools/desktop_cli.py gyss enrichment-list --site https://example.com
python tools/desktop_cli.py gyss enrichment-sync --site https://example.com
python tools/desktop_cli.py gyss enrichment-sync --site https://example.com --apply
```

`photos` reads the existing authenticated CMS endpoint using the saved GYSS connection. `images` reads the shared local catalogue and returns usable local image paths where installed. Its `next_after` value is the next `--after` cursor. Remote `photos` uses the existing offset interface; avoid simultaneous catalogue mutations while traversing it. `audit` exposes the existing server audit and its current 1,000-item ceiling; it is not a complete 10,000-image work scheduler.

`enrichment-import` accepts a bounded JSON object containing the assistant's findings, including descriptions, colours, suggested tags and sensitive-content assessments. It validates JSON and size, hashes the actual image, and uses the existing shared SQLite cache. Dry-run is the default. `--apply` stores findings locally with no fields marked accepted and no public metadata changes. `enrichment-sync --apply` explicitly forwards pending records to the existing CMS cache endpoint; ordinary imports remain offline.

An identical run is reused. To retain a different result without overwriting its existing run key, supply a new `--prompt` run identifier. `enrichment-list` includes expired records; it never invokes a provider. This is access to the existing cache, not implementation of the full durable-enrichment migration/spec: semantic sensitive-content validation, complete revision history, cross-derivative reuse and scalable queue claims remain separate work. The existing GUI/provider cache TTL behavior is unchanged.

Reviewed public metadata updates use `metadata-update --site URL --input FILE` to plan and add `--apply` to write. Input example:

```json
{"updates":[{"id":123,"expected_modified_at":"2026-10-26 12:00:00","hashtags":"#prairie #alberta"}]}
```

The timestamp must come from a fresh `photos` response. This uses the existing CMS batch-update service and its conflict detection; there is no CLI force override. It does not add stronger server transaction guarantees than that endpoint currently provides. Pending enrichment/review data and published metadata are separate commands. No CLI command falls back to `enrich-one` or a paid provider.

## SYBU

`queue --folder DIR` enumerates images and saved recovery metadata. `queue-import --folder DIR --input FILE` plans metadata edits; add `--apply` to save. For a source CLI reading an installed app's state, pass `--recovery-dir DIR` pointing at that app's existing recovery folder. Restart/reload SYBU to see externally saved queue values.

```json
{"items":[{"file":"example.jpg","title":"Prairie light","caption":"Late light across a field.","alt":"A field beneath clouds.","tags":"#prairie"}]}
```

Queue import supports the existing recovery fields, preserves unspecified fields, refuses paths outside the folder and refuses already-posted rows. It neither posts nor writes EXIF. Use the separate shared `enrichment-import` command for full retained findings beyond the legacy queue field set. The two commands are explicit separate operations; saving one does not imply the other completed.

## SNAP SLAPPER and LEWK AGAIN

```powershell
python tools/desktop_cli.py slapper lewks
python tools/desktop_cli.py lewks lewk-validate --input C:/work/lewk-proposal.json
python tools/desktop_cli.py lewks lewk-save --input C:/work/lewk-proposal.json --output C:/work/my.lewk
python tools/desktop_cli.py slapper project-create --image C:/photos/example.jpg --output C:/work/start.slapper
python tools/desktop_cli.py slapper project-edit --project C:/work/start.slapper --input C:/work/adjustments.json --output C:/work/edited.slapper
python tools/desktop_cli.py slapper apply-lewk --project C:/work/edited.slapper --builtin clean-slate --output C:/work/look.slapper
python tools/desktop_cli.py slapper render --project C:/work/look.slapper --output C:/work/preview.png
python tools/desktop_cli.py slapper export --project C:/work/look.slapper --output C:/work/final.png --strip-gps
```

An adjustment input is a dictionary such as `{"exposure":0.25,"contrast":14}`. Unspecified existing adjustments are preserved. `project-inspect --project FILE` returns the actual engine document. Protected actions use the same device authorization decision as the GUI. Portable embedded projects open normally; legacy external sources require the explicit `--trust-external-source` option supported by the engine.

Assistant LEWK input uses the existing safe generator format: name, description, adjustments, filters, explanation. For example `{"name":"WARM","adjustments":{"temperature":15},"filters":[]}`. Saved adjustment/filter recipes can also be supplied with `apply-lewk --recipe FILE`. Unsupported masks, layer blends/opacity/styles or image-layer recipes are rejected rather than silently dropped. First-party texture layers in built-in LEWKs resolve through the existing local texture service; missing textures fail without a network restore. SVG effects and Normal-mode baking are not introduced by this CLI.

## OH SNAP

The full design CLI requires Node.js, the `playwright` dependency declared in `tools/oh-snap/package.json`, and Microsoft Edge on Windows. Install the tool's dependencies with `npm ci` in `tools/oh-snap`. The default browser matches the existing tests; `OHSNAP_TEST_BROWSER` can select another installed Playwright browser channel.

```powershell
python tools/desktop_cli.py ohsnap new --mode SMACKTHEMUP --output C:/work/gallery.ohsnap
python tools/desktop_cli.py ohsnap edit --project C:/work/gallery.ohsnap --input C:/work/design.json --output C:/work/gallery-edited.ohsnap
python tools/desktop_cli.py ohsnap validate --project C:/work/gallery-edited.ohsnap
python tools/desktop_cli.py ohsnap render --project C:/work/gallery-edited.ohsnap --view archive --width 390 --output C:/work/gallery-phone.png
python tools/desktop_cli.py ohsnap export --project C:/work/gallery-edited.ohsnap --output C:/work/gallery.zip
```

Design JSON can contain `overrides`, appended `user_css`, `name`, `skin_slug`, `author`, `skin_version` and `preview_content`. This drives the actual UI project manager, deterministic CSS policy, preview and package generator in a headless browser. External browser requests and unsupported native commands are blocked. No API key is needed. Preview widths are 390, 768 or 1280; views are post, archive, landing or page. SMACKTHEMUP aliases the shared GRAMOFSMACK family. Exports retain the normal CMS review/signing requirement.

OH SNAP 0.3.2's native executable additionally supports `--cli capabilities`, `project-inspect --project FILE`, `package-validate --input FILE` and `package-export --input FILE --output NEW.zip`. Input for native package commands is an array of `{path,content}` objects, such as the files generated by the existing frontend tests. They call the actual Rust package validator/exporter, including the fixed-template check. Use `--result FILE` after the command for windowed builds. The native CLI does not embed Node or a browser: full design/render automation uses the source companion above.

## COLD SNAP

`batches`, `batch-inspect --batch ID`, `batch-create --name NAME --mode smacktalk`, `batch-draft-create --batch ID --input FILE` and `batch-draft-update --batch ID --id ID --expected-sha256 HASH --input FILE` use the actual `sumna_offline.SessionStore` read by the desktop batch rail. Add `--root DIR` to select a different existing session store; mutations are plans until `--apply` is supplied. Inspect returns each draft's `cli_sha256`. Updates refuse a stale hash, queued/synced drafts, and raw-caption replacement of BIGGIE block documents. These commands do not publish or change ready/sync status.

Draft text inputs support title, caption, alt, tags, colophon and post_date. New text-only drafts remain drafts; use the desktop UI to add photographs or BIGGIE blocks. `drafts`, `draft-inspect`, `draft-create` and revision-guarded `draft-save` are separate shared SQLite document-store operations for consumers of that repository; they are not the legacy desktop batch rail. Their saves use a SQLite write transaction and an expected revision.

## SHOTS FIRED, SUYB and SMACKPRESS

- SHOTS FIRED: `scheduled --site URL` and `reschedule --site URL --id ID --date DATE`. Rescheduling is a plan until `--apply`. Uses the existing schedule transport; unavailable server routes fail explicitly. This codebase's app is the scheduling calendar, not a new moderation CLI.
- SUYB: `backup-all` plans the operation; `backup-all --apply` runs the existing headless backup engine. Existing `--backup-all` launcher behavior is preserved.
- SMACKPRESS: `tracked --db FILE --after ID --limit 100` reads migration tracking; `set-note --db FILE --id ID --note TEXT` plans a local note and `--apply` saves it through the existing database helper. Existing migration/repair scripts remain available and unchanged. No migration or WordPress publishing occurs as a CLI side effect.

## Verification

`tests/test_desktop_cli.py` exercises the Python launcher dispatches, real project creation/edit/render, actual desktop batch/recovery stores, retained findings, source protection, authorization refusal and stale edits. Existing draft/cache tests remain applicable. `tools/oh-snap/tests/cli.cjs` exercises real design CLI operations and invalid CSS rejection. Rust tests exercise native validation/export plus CLI discovery. Verification deliberately uses fixture sites and temporary data, not paid AI calls or a fleet enrichment run.

<!-- ===== SNAPSMACK EOF ===== -->
