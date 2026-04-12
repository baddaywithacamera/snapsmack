const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  HeadingLevel, AlignmentType, LevelFormat, BorderStyle, WidthType,
  ShadingType, PageNumber, Header, Footer, TableOfContents
} = require('docx');
const fs = require('fs');

// ── Helpers ────────────────────────────────────────────────────────────────

const FONT = "Arial";
const border = { style: BorderStyle.SINGLE, size: 1, color: "333333" };
const borders = { top: border, bottom: border, left: border, right: border };
const headerBorder = { style: BorderStyle.SINGLE, size: 1, color: "222222" };
const headerBorders = { top: headerBorder, bottom: headerBorder, left: headerBorder, right: headerBorder };

function p(text, opts = {}) {
  return new Paragraph({
    spacing: { before: opts.before ?? 0, after: opts.after ?? 160 },
    children: [new TextRun({ text, font: FONT, size: opts.size ?? 22, bold: opts.bold ?? false, italics: opts.italic ?? false, color: opts.color ?? "222222" })]
  });
}

function h1(text) {
  return new Paragraph({
    heading: HeadingLevel.HEADING_1,
    pageBreakBefore: true,
    spacing: { before: 0, after: 200 },
    children: [new TextRun({ text, font: FONT, size: 34, bold: true, color: "111111" })]
  });
}

function h2(text) {
  return new Paragraph({
    heading: HeadingLevel.HEADING_2,
    spacing: { before: 280, after: 120 },
    children: [new TextRun({ text, font: FONT, size: 26, bold: true, color: "222222" })]
  });
}

function h3(text) {
  return new Paragraph({
    heading: HeadingLevel.HEADING_3,
    spacing: { before: 200, after: 80 },
    children: [new TextRun({ text, font: FONT, size: 23, bold: true, color: "333333" })]
  });
}

function bullet(text) {
  return new Paragraph({
    numbering: { reference: "bullets", level: 0 },
    spacing: { before: 0, after: 80 },
    children: [new TextRun({ text, font: FONT, size: 22, color: "222222" })]
  });
}

function bulletMixed(parts) {
  // parts = [{text, bold?}]
  return new Paragraph({
    numbering: { reference: "bullets", level: 0 },
    spacing: { before: 0, after: 80 },
    children: parts.map(pt => new TextRun({ text: pt.text, font: FONT, size: 22, bold: pt.bold ?? false, color: "222222" }))
  });
}

function numbered(text) {
  return new Paragraph({
    numbering: { reference: "numbers", level: 0 },
    spacing: { before: 0, after: 80 },
    children: [new TextRun({ text, font: FONT, size: 22, color: "222222" })]
  });
}

function note(text) {
  return new Paragraph({
    spacing: { before: 120, after: 120 },
    indent: { left: 480 },
    border: { left: { style: BorderStyle.SINGLE, size: 12, color: "888888", space: 8 } },
    children: [new TextRun({ text, font: FONT, size: 20, italics: true, color: "555555" })]
  });
}

function code(text) {
  return new Paragraph({
    spacing: { before: 60, after: 60 },
    indent: { left: 360 },
    children: [new TextRun({ text, font: "Courier New", size: 18, color: "2a6496" })]
  });
}

function gap(size = 160) {
  return new Paragraph({ spacing: { before: 0, after: size }, children: [] });
}

function tableRow(cells, isHeader = false) {
  return new TableRow({
    tableHeader: isHeader,
    children: cells.map(([text, width]) => new TableCell({
      width: { size: width, type: WidthType.DXA },
      borders: isHeader ? headerBorders : borders,
      shading: isHeader ? { fill: "222222", type: ShadingType.CLEAR } : { fill: "FFFFFF", type: ShadingType.CLEAR },
      margins: { top: 80, bottom: 80, left: 120, right: 120 },
      children: [new Paragraph({
        children: [new TextRun({ text, font: FONT, size: 20, bold: isHeader, color: isHeader ? "EEEEEE" : "222222" })]
      })]
    }))
  });
}

function twoColTable(rows, colWidths = [3000, 6360]) {
  const total = colWidths[0] + colWidths[1];
  return new Table({
    width: { size: total, type: WidthType.DXA },
    columnWidths: colWidths,
    rows: rows.map((row, i) => tableRow(
      row.map((cell, j) => [cell, colWidths[j]]),
      i === 0
    ))
  });
}

function threeColTable(rows, colWidths = [2400, 2400, 4560]) {
  const total = colWidths.reduce((a, b) => a + b, 0);
  return new Table({
    width: { size: total, type: WidthType.DXA },
    columnWidths: colWidths,
    rows: rows.map((row, i) => tableRow(
      row.map((cell, j) => [cell, colWidths[j]]),
      i === 0
    ))
  });
}

function statusBadge(built, todo) {
  const items = [];
  if (built.length) {
    items.push(new Paragraph({
      spacing: { before: 80, after: 40 },
      children: [new TextRun({ text: "Built: ", font: FONT, size: 20, bold: true, color: "336633" })]
    }));
    built.forEach(t => items.push(new Paragraph({
      numbering: { reference: "checks", level: 0 },
      spacing: { before: 0, after: 40 },
      children: [new TextRun({ text: t, font: FONT, size: 20, color: "336633" })]
    })));
  }
  if (todo.length) {
    items.push(new Paragraph({
      spacing: { before: 80, after: 40 },
      children: [new TextRun({ text: "Still needed: ", font: FONT, size: 20, bold: true, color: "993333" })]
    }));
    todo.forEach(t => items.push(new Paragraph({
      numbering: { reference: "boxes", level: 0 },
      spacing: { before: 0, after: 40 },
      children: [new TextRun({ text: t, font: FONT, size: 20, color: "993333" })]
    })));
  }
  return items;
}

// ── Document ───────────────────────────────────────────────────────────────

const doc = new Document({
  styles: {
    default: { document: { run: { font: FONT, size: 22 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 34, bold: true, font: FONT, color: "111111" },
        paragraph: { spacing: { before: 0, after: 200 }, outlineLevel: 0 } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 26, bold: true, font: FONT, color: "222222" },
        paragraph: { spacing: { before: 280, after: 120 }, outlineLevel: 1 } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 23, bold: true, font: FONT, color: "333333" },
        paragraph: { spacing: { before: 200, after: 80 }, outlineLevel: 2 } },
    ]
  },
  numbering: {
    config: [
      { reference: "bullets",
        levels: [{ level: 0, format: LevelFormat.BULLET, text: "\u2022", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 540, hanging: 260 } } } }] },
      { reference: "numbers",
        levels: [{ level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 540, hanging: 260 } } } }] },
      { reference: "checks",
        levels: [{ level: 0, format: LevelFormat.BULLET, text: "\u2713", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 540, hanging: 260 } } } }] },
      { reference: "boxes",
        levels: [{ level: 0, format: LevelFormat.BULLET, text: "\u25A1", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 540, hanging: 260 } } } }] },
    ]
  },
  sections: [{
    properties: {
      page: {
        size: { width: 12240, height: 15840 },
        margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
      }
    },
    headers: {
      default: new Header({ children: [
        new Paragraph({
          tabStops: [{ type: "right", position: 9360 }],
          border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: "444444", space: 4 } },
          children: [
            new TextRun({ text: "SNAPSMACK \u2014 Feature Spec 0.8.x", font: FONT, size: 18, color: "666666" }),
            new TextRun({ text: "\tApril 2026", font: FONT, size: 18, color: "666666" }),
          ]
        })
      ]})
    },
    footers: {
      default: new Footer({ children: [
        new Paragraph({
          alignment: AlignmentType.CENTER,
          border: { top: { style: BorderStyle.SINGLE, size: 4, color: "444444", space: 4 } },
          children: [
            new TextRun({ text: "Page ", font: FONT, size: 18, color: "888888" }),
            new TextRun({ children: [PageNumber.CURRENT], font: FONT, size: 18, color: "888888" }),
          ]
        })
      ]})
    },
    children: [

      // ── COVER ──────────────────────────────────────────────────────────
      new Paragraph({
        spacing: { before: 2000, after: 200 },
        children: [new TextRun({ text: "SNAPSMACK", font: FONT, size: 60, bold: true, color: "111111" })]
      }),
      new Paragraph({
        spacing: { before: 0, after: 120 },
        children: [new TextRun({ text: "Feature Specification \u2014 0.8.x Development Cycle", font: FONT, size: 32, color: "444444" })]
      }),
      new Paragraph({
        spacing: { before: 0, after: 80 },
        children: [new TextRun({ text: "April 2026 \u00B7 Alpha v0.7.9e \u2192 v0.8.x", font: FONT, size: 22, italics: true, color: "666666" })]
      }),
      gap(400),
      new Paragraph({
        spacing: { before: 0, after: 80 },
        border: { top: { style: BorderStyle.SINGLE, size: 6, color: "333333", space: 1 } },
        children: []
      }),
      gap(120),
      p("This document covers four interconnected feature areas for the 0.8.x cycle. They were designed together because they share a common theme: putting the photographer\u2019s content, not the software\u2019s structure, at the centre of everything.", { after: 160 }),
      p("The four areas:", { bold: true, after: 80 }),
      bullet("Settings Architecture Restructure \u2014 reorganise admin controls around pages and contexts, not around implementation boundaries"),
      bullet("Archive Page Overhaul \u2014 new config tool, calendar sidebar engine, category visibility, grid controls"),
      bullet("Solo Post Improvements \u2014 long-form layout, inline image references, drop caps, pull quotes"),
      bullet("Media Architecture \u2014 images as first-class objects referenced by posts, not owned by them; unified media manager"),
      gap(200),

      // ── TABLE OF CONTENTS ──────────────────────────────────────────────
      new TableOfContents("Contents", { hyperlink: true, headingStyleRange: "1-3" }),

      // ══════════════════════════════════════════════════════════════════
      // 1. SETTINGS RESTRUCTURE
      // ══════════════════════════════════════════════════════════════════
      h1("1. Settings Architecture Restructure"),

      h2("1.1 The Problem"),
      p("Settings are scattered across Global Config and Skin Admin with no clear logic to where things live. The person doing the configuring \u2014 a photographer who designed the skin \u2014 has to context-switch between two admin areas to accomplish one task. There is no mental model that predicts where a given setting will be found."),
      p("Specific pain points:"),
      bullet("Font controls appear in both global settings and skin admin"),
      bullet("Archive grid options (crop, columns, gutter) are split or missing"),
      bullet("There is no dedicated place for per-page-type configuration"),
      bullet("New controls (calendar engine, archive config) have nowhere logical to live"),

      h2("1.2 Four-Section Model"),
      p("Replace the current Global / Skin Admin split with four sections organised around page context. Each section controls everything that affects that page type, regardless of whether it was previously a global or skin-level setting."),
      gap(80),
      threeColTable([
        ["Section", "Controls", "Notes"],
        ["Global", "Site name, tagline, timezone, API keys, AI provider, upload limits, maintenance mode", "Truly universal. Not visual."],
        ["Solo Image", "Single post layout, EXIF display, caption style, drop caps, pull quotes, info panel", "Everything about how one post looks"],
        ["Archive", "Grid layout, crop style, border/shadow, gutter, columns, calendar sidebar, category visibility", "Everything about the library view"],
        ["Static Page", "Page layout options, sidebar, header style", "For non-post pages"],
      ], [2000, 4000, 3360]),
      gap(120),
      p("Fonts are Global. A font choice affects every page type simultaneously and has no business being per-skin or per-page."),
      note("Skin Admin does not disappear \u2014 it becomes the skin\u2019s structural settings (which template files it uses, which engines it loads). Visual configuration moves to the four-section model."),

      h2("1.3 What Goes Where"),
      p("Settings that currently live in the wrong place:"),
      gap(80),
      twoColTable([
        ["Setting", "Moves to"],
        ["Font family (skin admin)", "Global"],
        ["EXIF display on/off (global)", "Solo Image"],
        ["Archive crop style (skin admin)", "Archive"],
        ["Archive columns/gutter (missing)", "Archive"],
        ["Drop caps / pull quotes (missing)", "Solo Image"],
        ["Category visibility (missing)", "Archive"],
        ["Calendar sidebar config (new)", "Archive"],
        ["Static page layout (missing)", "Static Page"],
      ], [3500, 5860]),
      gap(120),

      h2("1.4 Admin Structure"),
      p("The four sections live in SnapSmack admin as a top-level nav group \u2014 \u201cAppearance\u201d or similar. Each section is its own page. The existing skin admin remains for structural/engine settings only."),
      p("The new Archive section is where the Archive Config Tool (Section 2) lives. The new Solo Image section is where drop cap and pull quote controls live (Section 3)."),

      // ══════════════════════════════════════════════════════════════════
      // 2. ARCHIVE OVERHAUL
      // ══════════════════════════════════════════════════════════════════
      h1("2. Archive Page Overhaul"),

      p("The archive page is the front door of the photo blog. Right now it has almost no configuration. This section adds three things: an Archive Config tool in admin, a calendar sidebar engine available to all skins, and category visibility controls."),

      h2("2.1 Archive Config Tool"),

      h3("Location"),
      p("New admin page: Appearance \u2192 Archive. Listed under the \u201cGood Shit\u201d section in the sidebar (alongside Traffic Stats, Multisite Management). All skins use this page \u2014 it is not skin-specific."),

      h3("Grid Controls"),
      gap(80),
      twoColTable([
        ["Control", "Detail"],
        ["Crop style", "2-way or 3-way sliding toggle: Square / Natural / Flickr (Flickr = fixed-ratio landscape). Toggle only shows options the active skin supports, declared via manifest."],
        ["Columns", "Number picker: 2, 3, 4 across. Skin manifest declares allowed range."],
        ["Gutter", "Slider: 0px to 24px in 2px steps."],
        ["Image width", "Max width of each grid tile in px. Skin provides sensible default."],
        ["Border style", "Dropdown: None / Hairline / Solid / Shadow / Double shadow. Skin CSS handles the actual rendering."],
        ["Shadow depth", "Shown when a shadow option is selected. Slider: subtle to dramatic."],
      ], [2800, 6560]),
      gap(120),

      h3("Manifest Integration"),
      p("Skin manifest declares which options it supports:"),
      code("'archive_options' => ["),
      code("    'crop_styles'   => ['square', 'natural', 'flickr'],"),
      code("    'columns_range' => [2, 4],"),
      code("    'border_styles' => ['none', 'hairline', 'shadow', 'double_shadow'],"),
      code("]"),
      p("The Archive Config Tool reads the active skin\u2019s manifest and only shows controls the skin supports. A skin that only does square crops gets no toggle \u2014 just the information that the skin is square-only."),
      note("Settings are stored in snap_settings, not per-skin. If you switch skins the archive config persists. The new skin may not support all options \u2014 unsupported settings are ignored, not deleted."),

      h2("2.2 Calendar Sidebar Engine"),

      h3("Overview"),
      p("A JS engine (ss-engine-calendar.js) that any skin can opt into. Renders a fixed sidebar panel with a monthly calendar and a post list. Slides in from left or right. Triggered by a fixed calendar icon button at the vertical midpoint of the viewport edge."),

      h3("Manifest Declaration"),
      p("Skins that want the calendar engine declare it in require_scripts[]:"),
      code("'require_scripts' => ['ss-engine-calendar'],"),
      code("'calendar_side'   => 'right',   // or 'left'"),
      note("calendar_side is a manifest key, not a snap_settings key. It\u2019s a structural skin decision, not a user preference."),

      h3("User Controls (in Archive Config Tool)"),
      gap(80),
      twoColTable([
        ["Control", "Detail"],
        ["Months displayed", "Number picker in Archive Config UI: 1, 2, or 3 months stacked vertically in the panel. Default: 1."],
        ["Posts in list", "Number picker in Archive Config UI: 5 to 20. Default: 10. Controls how many recent posts appear in the list below the calendar(s)."],
        ["Default open", "Toggle: calendar starts open on archive pages. Default: closed."],
      ], [2800, 6560]),
      gap(120),

      h3("Panel Behaviour"),
      bullet("Fixed position, full viewport height, slides over content (does not push)"),
      bullet("Panel width: 280px"),
      bullet("Trigger button fixed to viewport edge at vertical midpoint, always visible"),
      bullet("CSS transition: transform translateX, 250ms ease. No JS animation library needed."),
      bullet("Semi-transparent backdrop on mobile when panel is open"),

      h3("Calendar Display"),
      bullet("Current month shown by default (or multiple months stacked if configured)"),
      bullet("Days with posts: subtle background tint, cursor pointer"),
      bullet("Days without posts: not interactive, no cursor change"),
      bullet("Today highlighted with a different tint"),
      bullet("Prev / Next month buttons. AJAX \u2014 no page reload. Calendar and post list update in place."),
      bullet("Month/year heading is clickable \u2014 filters post list to that whole month"),

      h3("Post List"),
      bullet("Appears below the calendar(s)"),
      bullet("Default state: most recent N posts (N = user\u2019s configured count)"),
      bullet("Click a highlighted day: list filters to posts from that day"),
      bullet("Click a month heading: list filters to posts from that month"),
      bullet("Small \u201cShow all recent\u201d link resets the filter"),
      bullet("Each list item: post title (linked) + date stamp. Text only. No thumbnails."),

      h3("Files"),
      gap(80),
      twoColTable([
        ["File", "Purpose"],
        ["assets/js/ss-engine-calendar.js", "Panel render, calendar grid, AJAX month nav, post list filter, slide toggle"],
        ["assets/css/ss-engine-calendar.css", "Panel layout, calendar grid, trigger button, transitions, post list"],
        ["core/calendar-api.php", "AJAX endpoint: post dates by month (for highlighted days), post list by date/month filter"],
        ["core/manifest-inventory.php", "Register ss-engine-calendar as available engine"],
      ], [3200, 6160]),
      gap(120),

      h3("Data"),
      p("Two AJAX calls:"),
      numbered("On month change: fetch all days in that month that have posts. Returns array of date strings. Used to highlight days on the calendar grid."),
      numbered("On day/month select (or initial load): fetch post titles and dates for the filter. Returns title, date, permalink."),
      p("Both calls are lightweight. No image data. No post bodies."),

      h2("2.3 Category Visibility"),

      h3("The Feature"),
      p("Each category can be flagged as hidden from the public archive page. Hidden categories are still functional \u2014 posts can be assigned to them, they appear in admin, the calendar engine sees them \u2014 but they do not appear in the archive grid."),
      p("Use case: internal organisation categories, behind-the-scenes posts, draft-quality work that is published but not prominently displayed."),

      h3("UI"),
      p("In the existing category management page, add a \u201cShow in archive\u201d checkbox per category. Default: checked (all categories visible). Uncheck to hide from the public archive."),

      h3("Schema"),
      p("One new column on the categories table:"),
      code("ALTER TABLE snap_categories ADD COLUMN show_in_archive TINYINT(1) NOT NULL DEFAULT 1;"),
      p("Migration file required. Archive query gains a WHERE clause filtering hidden categories."),

      // ══════════════════════════════════════════════════════════════════
      // 3. SOLO POST IMPROVEMENTS
      // ══════════════════════════════════════════════════════════════════
      h1("3. Solo Post Improvements"),

      p("The solo post view was designed for one image and a short caption. It needs to also work for long-form writing with multiple images. These are not different post types \u2014 they\u2019re the same post with different amounts of content. The skin handles both gracefully."),

      h2("3.1 Two Content Modes, One Post"),
      gap(80),
      twoColTable([
        ["Mode", "Characteristics"],
        ["Photo-first", "Hero image dominant, short caption, EXIF, tags. Current behaviour. No changes needed for this mode."],
        ["Writing-forward", "Long body text, drop caps, pull quotes, additional inline images from the media library. New capability."],
      ], [2400, 6960]),
      gap(120),
      p("The distinction is emergent \u2014 determined by how much content the post has, not by a post type flag. A post with one image and two sentences looks like a photo post. A post with five paragraphs, a pull quote, and three inline images looks like an essay. The skin adapts."),

      h2("3.2 Drop Caps and Pull Quotes"),

      h3("Drop Caps"),
      p("First letter of the post body rendered large, spanning 2\u20133 lines. CSS-only implementation \u2014 ::first-letter pseudo-element. No markup required in the post body."),
      p("Control lives in Appearance \u2192 Solo Image: Enable drop caps (toggle). Drop cap size (2 or 3 lines). Drop cap font (inherits body font or use heading font)."),
      p("Skin must declare support in manifest:"),
      code("'supports' => ['drop_caps', 'pull_quotes']"),

      h3("Pull Quotes"),
      p("A passage pulled from the body and rendered larger, set off from the text. Two syntaxes supported:"),
      bullet("Shortcode: [pullquote]The text to pull.[/pullquote] \u2014 explicit, placed inline in the post body"),
      bullet("Auto-pull: if a paragraph is exactly one sentence and under 30 words, it can be auto-styled as a pull quote. Toggle in Solo Image settings. Off by default."),
      p("Pull quote styling (font size, colour, border or no border) controlled in Appearance \u2192 Solo Image."),
      p("Pull quotes are rendered by the parser (core/parser.php) \u2014 shortcode is replaced with a <blockquote class=\"ss-pullquote\"> wrapper. Skin CSS handles the visual treatment."),

      h2("3.3 Inline Images from the Media Library"),

      h3("The Problem"),
      p("There is currently no way to reference an existing image from the media library inside a post body. You can set a cover image. You cannot pull in a second image from two years ago without re-uploading it."),

      h3("The Solution"),
      p("A shortcode: [image id=142] or [image id=142 align=right caption=\"Fuji X100, 2019\"]"),
      p("The parser resolves the image ID against snap_images, generates the appropriate img tag with the correct thumbnail size, and wraps it in the skin\u2019s image frame markup. The image is referenced, not duplicated."),

      h3("Editor Integration"),
      p("A small \u201cInsert Image\u201d button in the post editor toolbar opens the Media Gallery in picker mode (see Section 4). User browses, selects an image, optionally sets align and caption, clicks Insert. The shortcode is written into the post body at the cursor position."),
      p("This is the same picker mode as the Media Gallery \u2014 not a separate UI. One consistent interface for finding and selecting images across the whole admin."),

      h3("Parser Integration"),
      p("Processing order in core/parser.php:"),
      numbered("Replace [image id=N ...] shortcodes with placeholder tokens"),
      numbered("Run existing shortcode processing (columns, pullquote, etc.)"),
      numbered("Run auto-paragraph"),
      numbered("Replace placeholder tokens with resolved img HTML"),
      p("Same placeholder approach already used for oEmbed. Prevents auto-paragraph from wrapping image markup in <p> tags."),

      h3("Schema"),
      p("No schema changes. snap_images already has all the data needed. The shortcode resolves against the existing table."),

      h2("3.4 Solo Image Settings Page"),
      p("New page at Appearance \u2192 Solo Image. Collects all solo post visual controls in one place:"),
      bullet("EXIF display on/off (moved from Global)"),
      bullet("Caption style (below image, overlay, none)"),
      bullet("Info panel position (below, sidebar, none)"),
      bullet("Drop caps: enable, size, font"),
      bullet("Pull quotes: enable, auto-pull toggle, font size, border style"),
      bullet("Inline image default alignment (left, right, centre)"),
      bullet("Inline image frame style (inherits from archive setting or override)"),

      // ══════════════════════════════════════════════════════════════════
      // 4. MEDIA ARCHITECTURE
      // ══════════════════════════════════════════════════════════════════
      h1("4. Media Architecture"),

      h2("4.1 The Problem"),
      p("Images are currently owned by posts. An image uploaded as part of a post is attached to that post and can only be managed by editing that post. It cannot be found in a central media browser, it cannot be reused in another post without re-uploading, and it cannot be renamed or re-tagged without hunting through the post list to find it."),
      p("This is the same mistake WordPress made before version 2.5. The media library exists in SnapSmack but it is a parallel system, not the primary one. Post images and library images are two different things."),

      h2("4.2 The Correct Model"),
      p("Every image that exists on the site lives in snap_images. Posts reference images; they do not own them. The cover image of a post is a reference. An inline image in a post body is a reference. An image uploaded directly to the library is in snap_images with no post reference."),
      p("This is a foreign key relationship, not a file path embedded in a post row."),

      h2("4.3 Schema Changes"),
      p("Current model (simplified):"),
      code("snap_posts.img_id   \u2014 the post\u2019s image (direct column)"),
      code("snap_images         \u2014 library images (separate table, not linked)"),
      gap(80),
      p("Target model:"),
      code("snap_images                  \u2014 ALL images, whether from posts or library"),
      code("snap_post_images             \u2014 join table: post_id, image_id, role (cover/inline), sort_order"),
      gap(80),
      p("Migration path:"),
      numbered("Add snap_post_images join table"),
      numbered("For every existing post with an img_id, insert a row in snap_post_images with role=\u2018cover\u2019"),
      numbered("Ensure the image record exists in snap_images (it should already)"),
      numbered("Post queries JOIN snap_post_images to get cover image"),
      numbered("Deprecate snap_posts.img_id column (keep for one release for backwards compat, remove in following release)"),
      note("Carousel posts (The Grid) already use snap_post_images for their image sequence. This change makes solo posts consistent with that model."),

      h2("4.4 Unified Media Manager"),
      p("The Media Gallery spec (snapsmack-feature-spec-079b.docx) describes the visual DAM. That spec is unchanged. This section adds the requirement that it shows ALL images \u2014 library images and post images \u2014 in one view."),
      p("Additional capabilities unlocked by the reference model:"),
      bullet("Rename or re-tag an image once \u2014 all posts referencing it see the change"),
      bullet("See which posts reference an image (shown in the quick-edit panel)"),
      bullet("Detach an image from a post without deleting it (it stays in snap_images)"),
      bullet("Delete an image only if no posts reference it, or with an explicit override warning"),
      bullet("Reuse any existing image in a new post via the picker \u2014 no re-upload"),

      h2("4.5 Implementation Order"),
      p("The schema migration (4.3) must happen before the unified media manager (4.4) and before inline image references (3.3). The picker UI (3.3 editor integration) can be built against the existing library in parallel since it is a UI layer."),
      gap(80),
      twoColTable([
        ["Step", "Dependency"],
        ["snap_post_images join table + migration", "None \u2014 do first"],
        ["Update post queries to JOIN snap_post_images", "Requires join table"],
        ["Media Gallery visual DAM (from 079b spec)", "Can run in parallel"],
        ["Gallery picker mode", "Requires gallery DAM"],
        ["Inline image shortcode parser", "Requires snap_images to be unified"],
        ["Editor Insert Image button", "Requires picker mode"],
        ["Deprecate snap_posts.img_id", "Last step, after all queries migrated"],
      ], [3500, 5860]),
      gap(200),

      // ══════════════════════════════════════════════════════════════════
      // 5. BUILD ORDER
      // ══════════════════════════════════════════════════════════════════
      h1("5. Recommended Build Order"),

      p("Sequenced to minimise rework and respect dependencies. Each item is a shippable unit that can go out as a point release."),
      gap(80),
      threeColTable([
        ["Release", "Work", "Why this order"],
        ["0.7.9f", "Settings restructure \u2014 admin UI reorganisation only. No schema changes. Move existing settings to new four-section pages.", "Pure UI refactor. No risk to data. Establishes the structure everything else plugs into."],
        ["0.7.9g", "Archive Config Tool \u2014 grid controls (crop, columns, gutter, border). Schema: show_in_archive on categories.", "Small schema change. High value. Photographers want this now."],
        ["0.8.0", "Media architecture \u2014 snap_post_images join table, migration, update post queries.", "Schema migration. Needs to be stable before building on top of it."],
        ["0.8.1", "Media Gallery DAM (from 079b spec) \u2014 visual browser, filters, bulk ops, picker mode.", "Depends on unified snap_images model from 0.8.0."],
        ["0.8.2", "Inline image shortcode + editor Insert Image button.", "Depends on picker mode from 0.8.1."],
        ["0.8.3", "Drop caps, pull quotes, Solo Image settings page.", "Pure CSS + parser. No schema. Satisfying to ship."],
        ["0.8.4", "Calendar sidebar engine.", "Standalone JS engine. No schema changes beyond the AJAX endpoint."],
        ["0.8.5", "oEmbed (from 079b spec). Photo Editor (from 079b spec).", "Round out the content creation story."],
      ], [1400, 3200, 4760]),
      gap(400),

      // ── FOOTER NOTE ────────────────────────────────────────────────────
      new Paragraph({
        spacing: { before: 0, after: 0 },
        border: { top: { style: BorderStyle.SINGLE, size: 4, color: "444444", space: 6 } },
        children: [new TextRun({ text: "SNAPSMACK \u00B7 Feature Spec 0.8.x \u00B7 GPL v3 \u00B7 No ads. Ever.", font: FONT, size: 18, italics: true, color: "888888" })]
      }),
    ]
  }]
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync('/sessions/youthful-eager-lovelace/mnt/snapsmack_codebase/_spec/snapsmack-feature-spec-080.docx', buf);
  console.log('Written.');
});
