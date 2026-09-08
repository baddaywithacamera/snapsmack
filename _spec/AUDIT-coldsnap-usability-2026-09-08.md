# COLD SNAP usability audit

Date: 2026-09-08

Audited build: installed Windows build 0.7.18

Scope: launch state, site context, COLD ONE, COLD STACK, COLD TAKE/mosaic, COLD STORAGE discoverability, BIGGIE block editor, drafts/queue/send model.
Method: direct interaction with the installed Qt app plus source inspection. No live post was transmitted.

## Executive finding

COLD SNAP is not merely visually rough. Its primary workflow is inverted and its interface exposes implementation concepts before user goals. The user opens a posting application but sees an empty writing form, hidden photo controls, hidden post options, two different editors, two different save concepts, and two separate send actions. The app requires the user to understand its internal modes and queue model before it helps them make a post.

The block editor is presently a structured shortcode form, not a visual block editor. It is safe and lossless at the serialization layer, but its interaction model is substantially harder than the SIMPLE editor and does not provide enough visual feedback to justify the added complexity.

The recommended repair is a workflow redesign around a persistent post canvas and contextual inspector—not another set of isolated controls added to the existing screens.

### Non-negotiable product target: genuinely luscious desktop WYSIWYG

BIGGIE must not resemble a CMS form merely because the CMS ultimately receives HTML and shortcodes. COLD SNAP is a native desktop application with local CPU, memory, storage, image decoding, and GPU-backed Qt painting available to it. The authoring surface must use those resources to make the final post tangible while it is being created.

WYSIWYG means the canvas itself displays and directly edits paragraphs, typography, pull quotes, drop caps, images, columns, spacers, dividers, and mosaics in their real geometry. Selecting an object reveals contextual controls without replacing the object with a settings form. Dragging changes layout immediately. Images retain their uncropped composition unless the selected layout explicitly requires a crop, and crop/fit decisions are adjustable on the canvas. The selected site's skin, content width, typography, colours, and responsive breakpoints should be represented faithfully enough that Preview is verification, not revelation.

The acceptable feel is closer to a polished desktop page-layout tool than WordPress blocks: instant insertion, generous writing surfaces, smooth direct manipulation, visible hierarchy, fluid zoom, undoable experimentation, and no shortcode syntax exposed during normal use. Shortcodes remain an export format and compatibility escape hatch only.

## Severity scale

- P0: prevents posting or creates a serious wrong-site/wrong-content risk.
- P1: repeatedly blocks or derails ordinary use.
- P2: creates substantial friction, ambiguity, or cognitive load.
- P3: polish and consistency.

## Confirmed findings

### P0 — Site selection and connection state are not one atomic, trusted action

The header separates the site picker from the connection details and relies on a small sentence (“will post to …”) as the safety signal. Given the prior wrong-site incident, changing the picker must synchronously establish and visibly verify the active connection—or disable all posting actions. The posting target needs to be repeated beside the final send control and inside its confirmation.

Acceptance criterion: changing site immediately enters a visible `Connecting…` state; Queue/Send remain disabled until the newly selected site returns its own identity and capabilities; the final confirmation names the verified destination.

### P1 — The opening screen starts in the wrong place

COLD ONE initially gives most of the window to empty title/body/ALT/tag fields while “PHOTO — none yet” is collapsed in a narrow rail. A photo post should begin with the photo. The first obvious action should be `Add photo`, followed by a large preview. Metadata and writing should grow around the selected asset.

### P1 — The four modes are product vocabulary, not task vocabulary

`COLD ONE`, `COLD STACK`, and `COLD TAKE` are brand-consistent but do not explain what the user is choosing. Tooltips are insufficient because mode selection is prerequisite knowledge. The user must remember that ONE means a single-image post, STACK means a gallery/gram post, and TAKE means a long-form essay.

Recommended labels: `Single photo`, `Gallery / carousel`, `Essay with photos`, `Library`, with the branded names as secondary text if desired.

### P1 — Queueing and sending create an avoidable two-stage mental trap

The screen has `Save as draft`, `QUEUE POST`, a collapsed `BATCH`, and `SEND QUEUED POSTS`. The difference is documented in help/tooltips, but is not self-evident in the working surface. “Queue post” can sound like scheduling or publishing. The actual state transition is “mark this draft ready inside the current batch.”

Recommended primary flow: `Add to batch` (with current batch name and count), then a persistent batch drawer with `Review and publish N posts`. Keep `Save draft` as a quiet secondary action. Never require the user to infer the batch from an accordion header.

### P1 — Critical state is hidden in accordions

Photo choice, orientation, colour/B&W, status, download policy, selected-photo controls, and batch contents are placed behind collapsed rail sections. These are not advanced details; several are required to produce a valid post. A closed accordion provides no clear indication that a value is missing, inferred, overridden, or invalid.

Recommended pattern: show a compact visible summary for every required section (`Photo: missing`, `Colour: Colour`, `Orientation: Portrait`, `Batch: Test · 3 ready`). Missing or conflicting values should be visible without expanding anything.

### P1 — BIGGIE is not yet a visual block editor

Direct interaction confirms that BIGGIE opens an empty canvas with a single `+ ADD BLOCK` menu. Adding a paragraph produces another framed form with a fixed-height text box, tiny arrow buttons, a tiny delete symbol, and a drop-cap checkbox. Source inspection confirms the same form-driven pattern for headings, quotes, lists, images, columns, spacers, dividers, and mosaics.

Missing editor fundamentals:

- No rendered post preview or trustworthy approximation of the selected skin.
- No drag-and-drop block ordering.
- No insertion control between blocks.
- No duplicate block action.
- No undo/redo for structural changes.
- No multi-select or grouping.
- No collapse/outline view for long documents.
- No keyboard-first block insertion.
- No obvious autosave/status indicator.
- Fixed 72px paragraph/quote/list editors make longer writing cramped.
- Image blocks require a numeric Media Library ID rather than a visual picker.
- Destructive block deletion is a one-click symbol with no undo.

### P1 — BIGGIE exposes two representations but no confidence bridge

SIMPLE and BIGGIE edit the same serialized body, but the only explanation is that both render identically. The user cannot see the serialized source and rendered result together, cannot preview conversion before switching, and cannot inspect which content became a RAW block. “Lossless in code” does not produce confidence in the interface.

Recommended design: one editor with three optional views—`Write`, `Structure`, and `Preview`. Switching views must not feel like changing editors. RAW or unsupported content should be visibly flagged in Structure view, not silently represented as another block type.

### P1 — Columns amplify the weak block interaction

Columns permit one to four columns and useful ratio presets, and nested columns are correctly prohibited. However, each column contains another miniature BIGGIE editor. This produces dense blocks-inside-forms with no visual correspondence to the final layout. It is especially poor at narrow window widths and makes moving content between columns laborious.

Recommended design: add a column layout as a visual row on the canvas, show actual proportional widths, and allow blocks to be dragged between cells. One column is not a meaningful column layout and should not be offered.

### P1 — Mosaic is separated from the post canvas

The mosaic dialog now has selection, layout choices, rotation, a live tile preview, ordering, and an arrangement suggestion in source. But the result is inserted into the body as a textual marker. After closing the dialog, the canvas does not continue to show the mosaic. The user loses visual context precisely when composing text around it.

Recommended design: the mosaic remains a live visual block in the post canvas. Double-click/Edit reopens its inspector. Selected images, order, layout, crop/fit choice, and ALT completeness remain visible. The textual marker is an export detail only.

### P1 — Photo order, lead image, mosaic order, and included images are separate concepts without a clear model

COLD TAKE has a bucket order, a lead image, a mosaic subset, and a mosaic-specific order. The implementation intentionally keeps some of these independent, but the UI does not provide a stable explanation of those relationships. This caused the observed “selected three, received four” and manual-removal workflow.

Recommended model: one photo tray with explicit badges for `Lead`, `In post`, and `In mosaic`. The mosaic block owns a visible ordered subset. Dragging inside the mosaic changes only mosaic order; dragging in the tray changes post order. State changes should be reversible.

### P2 — AI enrichment is positioned as a button, not a comprehensible workflow

AI actions appear in different places and with varying labels. The user cannot see which fields will be filled, which existing values will be preserved, which prompt is active, or whether the result is post-level or per-image. Progress and partial failure are not presented as a reviewable result.

Recommended pattern: `Enrich metadata` opens or reveals a result review showing the active site prompt and every target field. It fills the complete canonical metadata record for every image, marks inferred/defaulted fields, preserves authored values unless explicitly replaced, and exposes per-field retry/accept.

### P2 — Acronyms and compact labels make the editor hostile to discovery

`B`, `I`, `U`, `BQ`, `PULL`, `HR`, `UL`, `OL`, `IMG`, `COL 2`, `COL 3`, and `DROP` optimize for toolbar width, not recognition. Tooltips help only after exploration. `DROP` is particularly misleading because drop cap is a paragraph attribute, not a standalone content object; BIGGIE correctly models it as a paragraph checkbox, exposing the mismatch in SIMPLE.

Recommended pattern: keep familiar icon buttons only for inline formatting. Use named insertion choices for structural elements: `Heading`, `Quote`, `Pull quote`, `Image`, `Columns`, `Divider`, `Spacer`, `Mosaic`.

### P2 — The layout wastes space while simultaneously feeling cramped

The installed 0.7.18 screen uses a very wide empty canvas, a narrow inspector rail, short input fields, fixed-height editors, and small type. Information density is not the same as useful density. SNAP SLAPPER feels cleaner because it gives the asset center stage and uses the right rail as a continuous inspector; COLD SNAP gives the empty form center stage and hides the asset.

### P2 — Destructive and non-destructive actions are visually ambiguous

`Clear` sits between `Save as draft` and the primary queue action. Source inspection shows mode clear handlers directly reset the current form. Block deletion is a one-click `✕`. Even if draft recovery exists elsewhere, the working surface does not promise undo or recovery.

Recommended pattern: support undo and automatic local draft recovery. Move `Discard changes…` into an overflow menu and name it explicitly. Do not place it in the main action cluster.

### P2 — Validation happens too late

Required-state problems are largely discovered when Save/Queue/Send is attempted. The app should continuously show readiness and focus the first unresolved item. The batch should show why a post is not ready without opening it.

### P3 — Help is compensating for interface design

The help dialog explains essential workflow semantics, including the distinction between queueing and sending and how mosaics work. Essential posting knowledge belongs on the surface at the moment it matters. Help should explain edge cases, not the basic state machine.

## Recommended target interaction

### Persistent shell

1. Site selector with verified connected identity and capability badge.
2. Task selector using plain language.
3. Main post canvas with the image(s) and content blocks.
4. Right inspector that changes with the selected photo/block/post.
5. Persistent batch drawer at the bottom with visible count, problems, and destination.

### Single-photo flow

1. `Add photo` is the empty-state primary action.
2. Large photo preview appears in the canvas.
3. AI enrichment runs automatically if enabled, or via one clear action.
4. Caption and metadata appear adjacent to the preview.
5. Readiness summary explains any missing values.
6. `Add to batch` moves the complete post into the visible batch drawer.

### Essay/block flow

1. Photos live in a persistent filmstrip/tray.
2. The body is a visual canvas; typing in an empty canvas creates a paragraph without opening a menu.
3. `/` or a between-block `+` inserts structural blocks.
4. Blocks drag to reorder; images drag from the tray into the canvas.
5. Columns and mosaics render in place and remain interactive.
6. A preview toggle renders the actual server/skin result when possible and a clearly labelled approximation otherwise.

## Repair order

### Phase 1 — Make ordinary posting possible

1. Atomic site switch plus verified destination lockout.
2. Photo-first empty states in all posting modes.
3. Visible required metadata/readiness summaries.
4. Replace queue/send language and expose the current batch.
5. Automatic local draft recovery and undo for clear/delete.

### Phase 2 — Replace BIGGIE interaction, keep its serializer

1. Preserve the existing parse/serialize model and tests.
2. Replace stacked form cards with a canvas/outline editor.
3. Add rendered preview, drag order, between-block insertion, duplicate, undo/redo.
4. Use visual image selection and live columns/mosaic blocks.
5. Make paragraph the implicit default and drop cap a paragraph style.

### Phase 3 — Consistency and polish

1. Plain-language mode names.
2. One enrichment workflow and vocabulary across modes.
3. Consistent photo tray, metadata inspector, status feedback, shortcuts, and error presentation.
4. Reduce chrome and dead space using SNAP SLAPPER as the visual interaction reference.

## Verification plan

Do not call the redesign usable based on screenshots or successful unit tests alone. Run task-based usability checks on a clean installation:

1. Connect/switch site and identify the destination without opening Connection details.
2. Create and batch a single-photo post without Help.
3. Create a gallery, change lead/order, and understand what will publish.
4. Create a three-photo mosaic from four photos, reorder it, and verify its in-canvas appearance.
5. Write an essay with paragraphs, heading, pull quote, drop cap, image, unequal columns, and mosaic.
6. Close and reopen mid-edit and recover all work.
7. Review the batch, identify invalid posts, and publish to the intended site.
8. Repeat at 980×680, 1240×860, and 1920×1080.

For each task record completion, wrong turns, help use, unrecoverable loss, and confidence in the final destination/output. A task is not accepted merely because the underlying shortcode is correct.

## Bottom line

The mosaic problems are a symptom of a broader issue: COLD SNAP currently mirrors its storage and posting machinery more closely than it mirrors the user's act of making a post. The serialization and offline architecture can remain. The working surface needs to be rebuilt around photos, a visual canvas, a contextual inspector, visible readiness, and a batch the user can always see and trust.
