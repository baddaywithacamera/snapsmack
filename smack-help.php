<?php
/**
 * SNAPSMACK - User Manual
 *
 * In-admin documentation system covering every feature of the CMS.
 * Topics are organised into sections and filtered by user role — editors
 * only see topics relevant to content management, admins see everything.
 * Active skins can inject their own help topics via a help.php file.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$page_title = 'User Manual';
require_once 'core/auth-smack.php';

// Detect user role for contextual filtering
$_help_user_role = $_SESSION['user_role'] ?? 'editor';

include 'core/admin-header.php';
include 'core/sidebar.php';

// =========================================================================
//  HELP TOPICS — CORE CMS
// =========================================================================

$help_topics = [];

// ── THE GOOD SHIT ────────────────────────────────────────────────────────

$help_topics['dashboard'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Dashboard',
    'icon'     => '&#x25A3;',
    'content'  => <<<'HTML'
<h3>System Dashboard</h3>
<p>The dashboard is your command centre. It loads automatically after login and provides
an at-a-glance summary of everything happening on your site.</p>

<h4>Content Statistics</h4>
<p>The top row shows counts for published transmissions, drafts, and pending signals
(comments awaiting moderation). Click any statistic to jump directly to the relevant
management page.</p>

<h4>System Vitals</h4>
<p>The vitals panel displays server software, PHP version, memory limits, load average,
and disk usage. If any value is outside the recommended range, it will be highlighted.
This is useful for diagnosing performance issues on shared hosting.</p>

<h4>Network &amp; RSS</h4>
<p>Shows your blogroll peer count and RSS feed status. If the RSS fetcher cron job is
registered, you'll see the last fetch timestamp. If not, a registration button appears.</p>

<h4>Update Notifications</h4>
<p>When a new version of SnapSmack is available, a notification banner appears at the top
of the dashboard with a link to the System Updates page. The check runs automatically
every 24 hours (or via cron if registered).</p>

<h4>Quick Actions</h4>
<p>Shortcut buttons at the bottom of the dashboard for common tasks: New Post, Backup,
Settings, and View Live Site.</p>
HTML
];

$help_topics['new-post'] = [
    'section'  => 'The Good Shit',
    'title'    => 'New Post (Transmissions)',
    'icon'     => '&#x25B2;',
    'content'  => <<<'HTML'
<h3>Creating a Transmission</h3>
<p>Transmissions are the core content type in SnapSmack — each one is a photograph with
associated metadata, description, and technical details.</p>

<h4>Image Upload</h4>
<p>Drag an image file onto the upload area or click to browse. Supported formats: JPEG, PNG,
WebP. The system automatically reads EXIF metadata from JPEG files (camera, lens, aperture,
shutter speed, ISO, focal length, flash) and populates the fields for you.</p>

<h4>Automatic Processing</h4>
<p>On upload, SnapSmack performs several operations automatically:</p>
<ul>
    <li><strong>Orientation correction</strong> — reads EXIF rotation data and physically
    rotates the image so it displays correctly regardless of how the camera was held.</li>
    <li><strong>Resizing</strong> — if the image exceeds the maximum dimensions set in
    Configuration, it is proportionally scaled down.</li>
    <li><strong>Thumbnail generation</strong> — two thumbnails are created:
    a 400&times;400 centre-cropped square (for grid views) and a 400px aspect-ratio-preserved
    version (for archive layouts).</li>
</ul>

<h4>Title &amp; Description</h4>
<p>The title appears in the gallery, archive, and single-image views. The description
supports rich formatting via the toolbar (see <em>Formatting Toolbar</em> topic) and
shortcodes for images, columns, and dropcaps.</p>

<h4>Categories &amp; Albums</h4>
<p>Assign the transmission to one or more categories and albums using the multi-select
dropdowns. These are used for filtering on the public archive page and for organising
your work into collections.</p>

<h4>Tags</h4>
<p>The <strong>Tags</strong> field lets you add hashtags to the transmission. Enter them
space-separated with the # prefix, e.g. <code>#concrete #rust #peeling</code>. Tags are
also extracted automatically from hashtags in the title and description, so you can tag
from either place — the dedicated field just makes them visible and easy to manage.</p>
<p>Tags create browsable archive pages on the public site. Visitors can click any tag to
see all transmissions sharing that tag. Tags also appear in search results.</p>

<h4>Publication Settings</h4>
<ul>
    <li><strong>Status</strong> — Published (visible on the site) or Draft (hidden).</li>
    <li><strong>Timestamp</strong> — set when the transmission appears in chronological order.
    Defaults to now, but you can backdate or future-date.</li>
    <li><strong>Orientation Override</strong> — force the system to treat the image as
    landscape, portrait, or square regardless of actual dimensions.</li>
    <li><strong>Allow Signals</strong> — enable or disable comments on this specific
    transmission (independent of the global comments toggle).</li>
    <li><strong>Allow Downloads</strong> — enable the download button for this image.</li>
    <li><strong>External Download URL</strong> — point the download button to a Dropbox,
    Google Drive, or other external link instead of serving the file directly.</li>
</ul>

<h4>EXIF Override</h4>
<p>If automatic EXIF extraction doesn't pick up the right data (common with scanned film,
drone footage, or phone cameras), you can manually set any metadata field. Manual entries
override EXIF data.</p>
HTML
];

$help_topics['manage-archive'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Manage Archive',
    'icon'     => '&#x25A0;',
    'content'  => <<<'HTML'
<h3>Archive Management</h3>
<p>The archive manager lists all transmissions with thumbnail previews. Use it to find,
edit, or delete posts.</p>

<h4>Search &amp; Filter</h4>
<p>Type in the search box to filter by title. Use the status dropdown to show only
published, draft, or scheduled transmissions. Category and album filters narrow results
further.</p>

<h4>Actions</h4>
<ul>
    <li><strong>Edit</strong> — opens the transmission editor with all fields pre-populated.</li>
    <li><strong>Delete</strong> — permanently removes the transmission, its thumbnails, and
    all associated category/album mappings. This cannot be undone.</li>
    <li><strong>View</strong> — opens the live public page for this transmission.</li>
</ul>

<h4>Pagination</h4>
<p>Results display 15 per page. Navigate between pages using the controls at the bottom.</p>
HTML
];

$help_topics['categories'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Categories',
    'icon'     => '&#x25C6;',
    'content'  => <<<'HTML'
<h3>Category Management</h3>
<p>Categories are the primary taxonomy for organising your transmissions. Each transmission
can belong to multiple categories. Categories appear as filter options on the public archive
page and in the sidebar navigation.</p>

<h4>Creating a Category</h4>
<p>Enter a name and click Create. Category names should be descriptive and concise
(e.g., "Street Photography", "Portraits", "Landscapes").</p>

<h4>Category Descriptions</h4>
<p>Each category has an optional description field. Descriptions can be used by skins
for category archive pages and SEO meta descriptions. Enter a sentence or two explaining
what the category contains.</p>

<h4>Editing &amp; Deleting</h4>
<p>Click Edit to rename a category or update its description. Deleting a category removes
the association with all transmissions but does not delete the transmissions themselves.</p>
HTML
];

$help_topics['albums'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Albums (Missions)',
    'icon'     => '&#x25CB;',
    'content'  => <<<'HTML'
<h3>Album Management</h3>
<p>Albums (also called Missions) are curated collections of transmissions. Unlike categories,
which are broad organisational buckets, albums are designed for intentional groupings — a
specific shoot, project, series, or exhibition.</p>

<h4>Creating an Album</h4>
<p>Give the album a name and optional description. The description appears on the public
album page if your active skin supports it.</p>

<h4>Assigning Transmissions</h4>
<p>Transmissions are assigned to albums from the New Post or Edit page using the album
multi-select dropdown. A single transmission can belong to multiple albums.</p>
HTML
];

$help_topics['collections'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Collections',
    'icon'     => '&#x25BD;',
    'content'  => <<<'HTML'
<h3>Collections</h3>
<p>Collections are heterogeneous containers — they can hold posts, albums, and categories in any
combination. A collection might represent a portfolio theme, a season, or a cross-cutting
editorial concept that doesn't map neatly to a single category or album.</p>

<h4>Live Membership</h4>
<p>Collections are not snapshots. Member albums and categories resolve to their current content
at render time — if you add posts to an album that's in a collection, those posts are
automatically part of the collection. Only individual posts are pinned directly.</p>

<h4>Featured Image</h4>
<p>Each collection has an optional hero thumbnail. Select any published post and its first
image is used as the representative thumbnail in gallery views.</p>

<h4>Ordering Members</h4>
<p>Drag and drop members in the editor to set their display order. Changes save automatically
via the reorder endpoint.</p>
HTML
];

$help_topics['mosaics'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Mosaics',
    'icon'     => '&#x25A6;',
    'content'  => <<<'HTML'
<h3>MOSAIC Engine</h3>
<p>Mosaics are justified image panels that flow inline inside post body content — a row-based
bin-packing layout that arranges images into clean rows of equal height, similar to the
Jetpack justified gallery for WordPress.</p>

<h4>Building a Mosaic</h4>
<p>In the Mosaics admin page, give the mosaic a title, choose images from the media library,
set the gap (0–20px), and save. Each saved mosaic gets a shortcode like
<code>[mosaic:3]</code>.</p>

<h4>Inserting Into a Post</h4>
<p>In the SmackTalk longform editor, click the MOSAIC button in the toolbar to open the mosaic
picker. Click a mosaic to insert its shortcode at the cursor position. The mosaic renders
at the full column width when the post is viewed.</p>

<h4>Multiple Panels Per Post</h4>
<p>Any number of MOSAIC shortcodes can appear in a single post, interspersed with writing.
Each is an independent panel.</p>
HTML
];

$help_topics['longform-post'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Longform Post (SmackTalk)',
    'icon'     => '&#x270E;',
    'content'  => <<<'HTML'
<h3>SmackTalk — Longform Posts</h3>
<p>Longform posts are the SmackTalk post type — writing at equal billing with photography.
They use the full shortcode toolbar and support embedded MOSAIC panels inline.</p>

<h4>Structure</h4>
<p>A typical SmackTalk post has a hero image, an opening section of writing, one or more
MOSAIC panels with images, and more writing between and after them. There is no limit on
length or the number of embedded panels.</p>

<h4>Hero Image</h4>
<p>Select a hero image from the media library. This image is used as the post thumbnail
in archive and collection views. It is separate from the images embedded via shortcodes
in the body.</p>

<h4>Categories and Albums</h4>
<p>Longform posts are assigned to categories and albums using the same multi-select dropdowns
as standard posts. These associations are stored in dedicated junction tables
(<code>snap_post_cat_map</code>, <code>snap_post_album_map</code>) for direct
post-to-container mapping.</p>

<h4>Toolbar</h4>
<p>The full formatting toolbar is available: Bold, Italic, Underline, Link, H2, H3,
Blockquote, HR, lists, columns, dropcap, spacer, image shortcodes, and the MOSAIC insert
button. Shortcode insertion is the same as on static pages and post descriptions.</p>
HTML
];

$help_topics['media-library'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Media Library',
    'icon'     => '&#x25A8;',
    'content'  => <<<'HTML'
<h3>Media Library</h3>
<p>The media library is a general-purpose file repository for assets that aren't
transmissions — logos, illustrations, supplementary images, documents, etc. Files
uploaded here are stored in the <code>media_assets/</code> directory.</p>

<h4>Uploading</h4>
<p>Drag files onto the upload area or click to browse. Any file type is accepted.</p>

<h4>Shortcode Generation</h4>
<p>Each uploaded asset gets an ID number. The media library page shows the shortcode
for each asset, which you can copy and paste into any transmission description or
static page:</p>
<pre>[img:42|full|center]</pre>
<p>See the <em>Formatting Toolbar</em> topic for details on image shortcode syntax.</p>

<h4>Swapping Assets</h4>
<p>Click the <strong>SWAP</strong> button on any asset card to replace the file on disk
with a new upload. The asset's ID is preserved, so any <code>[img:ID|...]</code> shortcodes
already embedded in pages or descriptions continue to work without editing.</p>

<h4>Deleting Assets</h4>
<p>Deleting an asset removes the file from disk and the database record. Any shortcodes
referencing the deleted asset will render as empty space on the public site.</p>
HTML
];

$help_topics['media-gallery'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Media Gallery',
    'icon'     => '&#x25A6;',
    'content'  => <<<'HTML'
<h3>Media Gallery</h3>
<p>The Media Gallery is a visual digital asset manager for your entire transmission
library. Unlike Manage Archive (which is a table), the gallery displays your images
as a thumbnail grid with instant search, filtering, and inline editing.</p>

<h4>Browsing &amp; Filtering</h4>
<p>The filter bar at the top provides multiple ways to narrow your view:</p>
<ul>
    <li><strong>Search</strong> — searches titles, descriptions, and tags with a 300ms debounce.</li>
    <li><strong>Album / Category / Status</strong> — dropdown filters.</li>
    <li><strong>Camera</strong> — populated automatically from your EXIF data.</li>
    <li><strong>Date Range</strong> — from/to date pickers.</li>
    <li><strong>Clear</strong> — resets all filters to show everything.</li>
</ul>
<p>Results update instantly without a full page reload.</p>

<h4>Selection</h4>
<p>Select images in three ways:</p>
<ul>
    <li><strong>Ctrl+Click</strong> or <strong>Cmd+Click</strong> — toggle individual images.</li>
    <li><strong>Rubber band</strong> — click and drag on empty grid space to lasso multiple images.</li>
    <li><strong>Ctrl+A</strong> — select all currently visible images.</li>
</ul>

<h4>Quick Edit Panel</h4>
<p>Click any image (without Ctrl) to open the quick-edit slide-out panel on the right.
From here you can change the title, status, tags, categories, and albums without leaving
the gallery. Click <strong>Full Edit</strong> to jump to the full editor.</p>

<h4>Bulk Operations</h4>
<p>When one or more images are selected, the bulk actions bar appears with options to:</p>
<ul>
    <li>Set status to Published or Draft</li>
    <li>Assign a category or album</li>
    <li>Delete selected images (with confirmation)</li>
</ul>
HTML
];

$help_topics['photo-editor'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Photo Editor',
    'icon'     => '&#x270E;',
    'content'  => <<<'HTML'
<h3>Photo Editor</h3>
<p>The built-in photo editor lets you make quick adjustments to your images without
leaving the admin. It opens as a full-screen overlay from the <strong>Edit Image</strong>
button on the single-image editor and the carousel editor.</p>

<h4>Tools</h4>
<ul>
    <li><strong>Crop</strong> — freeform or fixed aspect ratios (1:1, 4:3, 16:9, 3:2).
        The crop overlay shows a rule-of-thirds grid. Drag corners to resize, drag the
        centre to reposition. Click <em>Apply</em> to commit or <em>Cancel</em> to exit
        crop mode without changes.</li>
    <li><strong>Rotate</strong> — 90&deg; clockwise or counter-clockwise.</li>
    <li><strong>Flip</strong> — horizontal or vertical mirror.</li>
    <li><strong>Brightness</strong> — slider from &minus;100 to +100.</li>
    <li><strong>Contrast</strong> — slider from &minus;100 to +100.</li>
    <li><strong>Sharpen</strong> — slider from 0 to 100 (3&times;3 unsharp mask).</li>
    <li><strong>B&amp;W</strong> — toggle black-and-white conversion using the luminosity method.</li>
</ul>

<h4>Undo &amp; Reset</h4>
<p><strong>Undo</strong> steps back through each change. <strong>Reset</strong> reverts to
the original image. Keyboard shortcut: <kbd>Ctrl+Z</kbd> (or <kbd>Cmd+Z</kbd>) to undo,
<kbd>Escape</kbd> to close without saving.</p>

<h4>Saving</h4>
<p>Click <strong>Save</strong> to overwrite the web-size copy of the image. The editor
respects your configured maximum image size setting and regenerates both square and
aspect-ratio thumbnails automatically. The original full-resolution upload is not modified.</p>
HTML
];

$help_topics['signals'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Signals (Comments)',
    'icon'     => '&#x25AC;',
    'content'  => <<<'HTML'
<h3>Signal Moderation</h3>
<p>Signals are what SnapSmack calls comments. All new signals are held in a pending queue
until you approve them.</p>

<h4>Pending vs. Live</h4>
<p>The two-tab interface separates signals awaiting review from those already visible on
the site. Each signal shows the author name, email (if provided), the comment text, and
which transmission it belongs to.</p>

<h4>Actions</h4>
<ul>
    <li><strong>Approve</strong> — makes the signal visible on the public transmission page.</li>
    <li><strong>Delete</strong> — permanently removes the signal.</li>
</ul>

<h4>Global Toggle</h4>
<p>Signals can be disabled site-wide from the Configuration page. When disabled, comment
forms are hidden on all public pages. Individual transmissions can also have signals
disabled regardless of the global setting.</p>
HTML
];

// ── PIMP YOUR RIDE ───────────────────────────────────────────────────────

$help_topics['global-vibe'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Global Vibe',
    'icon'     => '&#x266B;',
    'content'  => <<<'HTML'
<h3>Theme Management</h3>
<p>Global Vibe is where you choose the visual identity of your site. It controls both
the public-facing skin (what visitors see) and the admin theme (what you see in the
control panel).</p>

<h4>Public Skin</h4>
<p>Select from installed skins. Each skin has its own visual language, layout options,
and feature set. Some skins support the floating gallery, others don't. Some offer multiple
archive layouts. The active skin determines what manifest options are available in
Smooth Your Skin.</p>

<h4>Admin Theme</h4>
<p>Choose from 16+ admin colour themes. These only affect the control panel — your
public site is unaffected. Admin themes change colours and accents but share the same
geometric layout (admin-theme-geometry-master.css).</p>

<h4>Available Admin Themes</h4>
<p>50 Shades of Greymatter, Amber Phosphorus, Blue Skies, Bumblebee, Caribbean Blue,
Dapper Dan, Green Arrow, Green Phosphorus, Inspector Clouseau, Mi Casa Es Su Picasa,
Midnight Lime (default), Minty Fresh, Peach Melba, Pixelpast, Purple Rain,
The Black Pearl.</p>

<h4>Archive Display Mode</h4>
<p>Controls how the public archive grid looks. Options depend on what the active skin
supports, but all skins offer at least Square. Four options exist:</p>
<ul>
    <li><strong>Square Grid</strong> — uniform 1:1 cropped tiles.</li>
    <li><strong>Cropped Grid</strong> — tiles at a constrained aspect ratio (max 3:2).</li>
    <li><strong>Justified (Masonry)</strong> — full aspect-ratio images in filled rows,
    Flickr-style. Only available on skins that declare masonry support.</li>
    <li><strong>Disabled</strong> — removes the Archive View link from the public navigation
    entirely. Any direct visit to <code>archive.php</code> redirects to the homepage.
    Available on all skins regardless of layout support.</li>
</ul>

<h4>Page Content Width</h4>
<p>Sets the maximum width of text content on static pages, across all skins. The range
slider goes from 400 px to 1400 px. Each skin has its own built-in default (typically
640–850 px) which is used as the fallback if no override is set — move the slider to
override.</p>

<h4>Floating Gallery Controls</h4>
<p>All floating gallery engine settings live here, not in Smooth Your Skin:</p>
<ul>
    <li><strong>Reflection</strong> — toggle a subtle below-tile reflection (Chromium and Safari only; Firefox degrades gracefully).</li>
    <li><strong>Scroll Friction</strong> — how quickly the wall decelerates after a drag (0.80 = ice, 0.99 = molasses).</li>
    <li><strong>Drag Weight</strong> — resistance when dragging (0.5 = featherlight, 5.0 = heavy).</li>
    <li><strong>Gallery Link</strong> — show or hide the Floating Gallery link in the public nav.</li>
</ul>
<p>These are global because they control engine physics, not aesthetics. The wall background
colour stays in Smooth Your Skin since it's part of each skin's visual palette.</p>
HTML
];

$help_topics['smooth-skin'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Smooth Your Skin',
    'icon'     => '&#x2726;',
    'content'  => <<<'HTML'
<h3>Skin Customizer &amp; Gallery</h3>
<p>This page has two tabs: <strong>Customize</strong> and <strong>Gallery</strong>.</p>

<h4>Customize Tab</h4>
<p>Adjust the active skin's settings. The available options depend on which skin is active —
each skin defines its own manifest with sections like Canvas &amp; Layout, Framing &amp;
Presentation, Typography, Floating Gallery, and Content. Changes are applied immediately and
generate CSS that is injected into the public site.</p>
<p>Option types include colour pickers, range sliders, dropdowns, and number fields. Each
option targets a specific CSS selector and property, so you can see exactly what it affects.</p>

<h4>Typography Controls</h4>
<p>The Typography section gives you control over fonts, sizes, and colours for distinct
parts of your site. These are treated as separate concerns:</p>
<ul>
    <li><strong>Masthead Font</strong> — the publication identity font. Appears in the site
    header on every page. This is your brand.</li>
    <li><strong>Heading / Intertitle Font</strong> — used for post headings and intertitle
    cards. Distinct from the masthead.</li>
    <li><strong>Page Title Font</strong> — the h1 on section pages like Blogroll, Archive
    View, and static pages. Separate from the masthead by design — a masthead is a
    publication identity, a page title is a navigation landmark.</li>
    <li><strong>Body / Description Font</strong> — post descriptions, blogroll entries, and
    static page body text. The body size slider cascades to all of these.</li>
    <li><strong>Nav Font &amp; Nav Color</strong> — top navigation link font, size, and
    colour. Colour pickers on monochrome skins show a greyscale swatch selector
    (seven steps from black to white) rather than a free colour picker.</li>
    <li><strong>Footer Font</strong> — the system footer and bottom navigation.</li>
</ul>
<p>Each font picker has an adjacent size slider. Moving the slider and saving updates
the font size site-wide without touching any CSS.</p>

<h4>Gallery Tab</h4>
<p>Browse the skin registry for new skins and updates. Each skin listing shows up to three
screenshots (landing page, archive grid, and text page) in a carousel with navigation
arrows and dot indicators. Install, update, or remove skins from this tab. All downloads
are cryptographically verified. You cannot remove the currently active skin or protected
skins (like Photogram, the mandatory mobile skin).</p>
HTML
];

$help_topics['pimpotron'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Pimpotron',
    'icon'     => '&#x2605;',
    'content'  => <<<'HTML'
<h3>Slideshow &amp; Carousel Engine</h3>
<p>The Pimpotron is an advanced slideshow builder. It only appears in the sidebar when
the active skin declares Pimpotron support in its manifest.</p>

<h4>Creating a Slideshow</h4>
<p>Give your slideshow a name and unique slug. The slug is used in templates and URLs.
Configure global settings: transition speed, glitch effects, stage shift animation,
and typography.</p>

<h4>Managing Slides</h4>
<p>Add slides from your existing transmissions, external image URLs, or video embeds.
Each slide can have:</p>
<ul>
    <li>Overlay text with X/Y positioning</li>
    <li>Custom background colour</li>
    <li>Individual animation timing</li>
</ul>
<p>Drag slides to reorder them. The slideshow renders via a JSON API payload that the
skin's frontend JavaScript consumes.</p>

<h4>Glitch Effects</h4>
<p>The Pimpotron includes optional visual glitch effects (distortion, colour shift) that
can be configured by frequency (rare/occasional/frequent) and intensity
(subtle/normal/extreme). These are purely cosmetic and intended for creative effect.</p>
HTML
];

$help_topics['css-editor'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Smack Your CSS Up!',
    'icon'     => '&#x270E;',
    'content'  => <<<'HTML'
<h3>Custom CSS Editor</h3>
<p>Write your own CSS overrides for the public site or admin panel. The editor has two tabs:
<strong>Public</strong> and <strong>Admin</strong>.</p>

<h4>How It Works</h4>
<p>Your custom CSS is loaded <em>after</em> all skin and theme stylesheets, so it wins in
the CSS cascade. You can override any style on the site without modifying skin files
directly.</p>

<h4>Protected Block</h4>
<p>At the top of the editor you may see a read-only block between <code>/* SKIN_START */</code>
and <code>/* SKIN_END */</code> markers. This is auto-generated by the skin customizer.
Do not attempt to edit this block — your changes will be overwritten the next time you
save skin settings. Write your overrides below it.</p>

<h4>Tips</h4>
<ul>
    <li>Use your browser's developer tools (F12) to inspect elements and find the right selectors.</li>
    <li>Prefix selectors with <code>.static-transmission</code> or <code>.description</code>
    to target only the public content area.</li>
    <li>Test changes before saving — there's no undo.</li>
</ul>
HTML
];

$help_topics['scripts'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Smack Your Scripts Up!',
    'icon'     => '&#x26A1;',
    'content'  => <<<'HTML'
<h3>Third-Party Script Manager</h3>
<p>Paste tracking scripts, analytics pixels, and newsletter loaders into the database
instead of the codebase. Each site has its own scripts — nothing touches git.</p>

<h4>Head Scripts</h4>
<p>Anything you paste here is injected before <code>&lt;/head&gt;</code> on every public
page. Use it for universal loaders like MailerLite, Google Analytics, or any script
that needs to run site-wide. The scripts load after all CSS so they don't block rendering.</p>

<h4>Embed Codes</h4>
<p>Define reusable HTML snippets with named keys. Each block starts with a key line:</p>
<pre>[key:mailerlite]
&lt;div class="ml-embedded" data-form="Ixs8uR"&gt;&lt;/div&gt;

[key:youtube-subscribe]
&lt;div class="g-ytsubscribe" data-channelid="UC..."&gt;&lt;/div&gt;</pre>
<p>Then place them on any static page with the <code>[embed:mailerlite]</code> shortcode.
You can define as many keys as you need.</p>

<h4>Typical Setup</h4>
<p>For a MailerLite newsletter form: paste the universal script tag in <strong>Head Scripts</strong>,
define the form div in <strong>Embed Codes</strong> with a key, then drop
<code>[embed:mailerlite]</code> into your About page or wherever you want the form.</p>
HTML
];

$help_topics['social-dock'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Social Dock',
    'icon'     => '&#x2764;',
    'content'  => <<<'HTML'
<h3>Social Profile Dock</h3>
<p>A floating dock of social media profile links that appears on every public page.
Visitors can click through to your profiles on Flickr, SmugMug, Instagram, YouTube,
Bluesky, and 10 other platforms. No X/Twitter — by design.</p>

<h4>Enabling the Dock</h4>
<p>Toggle the dock on from <strong>Pimp Your Ride &rarr; Social Dock</strong>. Then enter
your profile URLs for each platform you want to show. Platforms with empty URLs are
automatically hidden.</p>

<h4>Positioning</h4>
<p>Eight placement options in two groups:</p>
<ul>
    <li><strong>Corners</strong> — Top Left, Top Right, Bottom Left, Bottom Right. Icons display
    in a horizontal row.</li>
    <li><strong>Side Edges</strong> — Left/Right Side, Top or Bottom. Icons stack vertically
    and slide out of view during scrolling, then slide back when you stop.</li>
</ul>

<h4>Appearance</h4>
<ul>
    <li><strong>Light &amp; Dark Colors</strong> — set two icon colours. Light is for dark
    backgrounds, dark is for light backgrounds. Choose which mode is active by default.</li>
    <li><strong>Icon Shape</strong> — Round (circles) or Square (rounded corners).</li>
    <li><strong>Icon Style</strong> — Outline (transparent, fills on hover) or Solid (filled
    background with icon inside, brightens on hover).</li>
    <li><strong>Drop Shadow</strong> — optional shadow behind each icon for better contrast
    against busy photo backgrounds.</li>
    <li><strong>Dock Opacity</strong> — how opaque the dock backdrop is (0% = fully transparent,
    100% = solid dark glass).</li>
</ul>

<h4>Supported Platforms</h4>
<p>Flickr, SmugMug, Instagram, Facebook, YouTube, 500px, Vero, Threads, Bluesky,
LinkedIn, Pinterest, Tumblr, DeviantArt, Behance, and a generic Website link.</p>
HTML
];

$help_topics['sticky-header'] = [
    'section'  => 'Pimp Your Ride',
    'title'    => 'Sticky Header',
    'icon'     => '&#x2B06;',
    'content'  => <<<'HTML'
<h3>Sticky Header</h3>
<p>The sticky header engine pins your site's navigation bar to the top of the screen
when you scroll down. It uses a glass-morphism transparency effect to stay visible
without blocking content.</p>

<h4>How It Works</h4>
<ol>
    <li>You scroll past the header's natural position — it locks to the top.</li>
    <li>After a brief pause, the header transitions to a transparent frosted-glass state.</li>
    <li>Hover over the header — it snaps back to full opacity instantly.</li>
    <li>Scroll back to the top — it returns to its normal position.</li>
</ol>

<h4>Settings</h4>
<ul>
    <li><strong>Background Opacity</strong> — how see-through the header is while in its
    transparent state (0% = invisible, 100% = fully opaque).</li>
    <li><strong>Backdrop Blur</strong> — the frosted-glass blur amount in pixels. Higher values
    create a stronger glass effect.</li>
</ul>

<h4>Skin Compatibility</h4>
<p>The engine auto-detects the header element in each skin. Skins that already have their
own fixed header (like Pocket Operator) are automatically skipped — no conflicts. The
sticky header is disabled on mobile screens (below 480px) where vertical space is limited.</p>
HTML
];

// ── FORMATTING TOOLBAR ───────────────────────────────────────────────────

$help_topics['formatting-toolbar'] = [
    'section'  => 'Content Creation',
    'title'    => 'Shortcode Toolbar',
    'icon'     => '&#x2630;',
    'content'  => <<<'HTML'
<h3>Shortcode Toolbar</h3>
<p>The shortcode toolbar appears above the content textarea on New Post, Edit, and
Static Pages. Each button inserts tags or shortcodes at the cursor position. If you
select text first, wrapping tags (bold, italic, link, headings, blockquote, dropcap)
will wrap your selection.</p>

<h4>Text Formatting</h4>
<ul>
    <li><strong>B</strong> — Bold. Wraps selected text in <code>&lt;strong&gt;</code> tags.</li>
    <li><strong>I</strong> — Italic. Wraps selected text in <code>&lt;em&gt;</code> tags.</li>
    <li><strong>LINK</strong> — Prompts for a URL, then wraps selected text in an
    <code>&lt;a href="..."&gt;</code> tag.</li>
</ul>

<h4>Block Formatting</h4>
<ul>
    <li><strong>H2</strong> — Heading level 2. Wraps the line or selection.</li>
    <li><strong>H3</strong> — Heading level 3.</li>
    <li><strong>BQ</strong> — Blockquote. Wraps the selection in
    <code>&lt;blockquote&gt;</code> tags.</li>
    <li><strong>HR</strong> — Inserts a horizontal rule (<code>&lt;hr&gt;</code>).</li>
</ul>

<h4>Layout &amp; Media</h4>
<ul>
    <li><strong>IMG</strong> — Prompts for an image ID, size (full/wall/small), and
    alignment (center/left/right), then inserts the <code>[img:ID|size|align]</code>
    shortcode. Get image IDs from the Media Library page.</li>
    <li><strong>COL 2 / COL 3</strong> — Inserts a multi-column layout block with
    placeholder content. Edit the text between <code>[col]</code> markers. See the
    <em>Shortcodes</em> topic for full syntax.</li>
    <li><strong>DROP</strong> — Wraps the selection in a dropcap shortcode for
    decorative first-letter display.</li>
</ul>

<h4>Data Shortcodes</h4>
<p>The <strong>&lsaquo;SC&rsaquo;</strong> button opens a dropdown of data shortcodes — dynamic
values like post count, current year, years-since calculations, and embed codes.
Click any item to insert it at the cursor. See the <em>Shortcodes</em> topic for the
full list.</p>

<h4>Preview</h4>
<p>The <strong>PREVIEW</strong> button (far right of the toolbar) opens a new browser tab
showing your content rendered through the active skin, exactly as it will appear on
the public site. All shortcodes are processed — images, columns, and dropcaps will
display in their final form. The preview is read-only and marked "NOT PUBLISHED".</p>
HTML
];

$help_topics['shortcodes'] = [
    'section'  => 'Content Creation',
    'title'    => 'Shortcodes',
    'icon'     => '&#x2039;&#x203A;',
    'content'  => <<<'HTML'
<h3>Shortcode Reference</h3>
<p>Shortcodes are special tags you can type in any description or static page content.
They are processed by the parser and converted to HTML when the page is displayed.</p>

<h4>Image Insertion</h4>
<pre>[img:ID|size|align]</pre>
<p>Inserts an image from the media library or your transmissions.</p>
<ul>
    <li><strong>ID</strong> — the numeric ID of the asset (shown in Media Library).</li>
    <li><strong>size</strong> — <code>full</code> (original), <code>wall</code> (gallery variant),
    or <code>small</code> (400&times;400 thumbnail). Default: full.</li>
    <li><strong>align</strong> — <code>center</code>, <code>left</code> (floats left with
    text wrap), or <code>right</code> (floats right). Default: center.</li>
</ul>
<p>Examples:</p>
<pre>[img:42]
[img:42|small|left]
[img:7|wall|right]</pre>
<p>Inline images open a full-screen lightbox viewer when clicked or tapped. The lightbox
always shows the full-size original file, regardless of the size variant specified in
the shortcode.</p>
<p>In the Impact Printer skin, archive thumbnails always display the ASCII box border
(no picker — it's hardcoded for consistency). Hero and inline page images each have
independent style and weight controls under
<strong>Smooth Your Skin → PRINT HEAD</strong>.</p>

<h4>Multi-Column Layout</h4>
<pre>[columns=N]
First column content here.

[col]

Second column content here.
[/columns]</pre>
<p>Creates a CSS grid with N columns (2, 3, or 4). Use <code>[col]</code> markers to
separate content between columns. You can put images, text, headings — anything —
inside columns. The layout collapses to a single column on mobile screens.</p>

<h4>Dropcap</h4>
<pre>[dropcap]T[/dropcap]he story begins...</pre>
<p>Wraps a character in a decorative enlarged dropcap. The visual style is controlled
by the active skin's dropcap setting (configurable in Smooth Your Skin). Three styles
are available: None, Simple Bold, and Tactical Block.</p>

<h4>Spacer Shortcode</h4>
<pre>[spacer:20]</pre>
<p>Adds explicit vertical spacing (1–100px) within your content. Specify the pixel height
as a number between the colons.</p>


<h4>Data Shortcodes</h4>
<p>Dynamic values pulled from the database or server at render time. Use the
<strong>&lsaquo;SC&rsaquo;</strong> dropdown on the static page toolbar to insert these.</p>
<ul>
    <li><code>[post_count]</code> — total number of published images.</li>
    <li><code>[site_name]</code> — your site name from Configuration.</li>
    <li><code>[site_url]</code> — your site URL.</li>
    <li><code>[current_year]</code> — four-digit current year.</li>
    <li><code>[years_since year="1970" month="6" day="15"]</code> — years elapsed since a
    specific date. Ticks over on the exact day, not January 1. Month and day are optional
    (default to 1 if omitted).</li>
    <li><code>[newest_post]</code> — formatted date of the most recent published image.</li>
    <li><code>[oldest_post]</code> — formatted date of the first published image.</li>
    <li><code>[archive_link]</code> — clickable link to the archive (blank if archive is disabled).</li>
    <li><code>[gallery_link]</code> — clickable link to the floating gallery (blank if disabled).</li>
    <li><code>[random_image]</code> — displays a random published image with lightbox.</li>
    <li><code>[latest_image]</code> — displays the most recent published image with lightbox.</li>
</ul>

<h4>Embed Shortcode</h4>
<pre>[embed:mailerlite]</pre>
<p>Inserts a named HTML snippet defined in <strong>Pimp Your Ride &rarr; Smack Your Scripts Up!</strong>.
Use this for newsletter forms, subscribe buttons, chat widgets, or any third-party embed
that you want to place on specific pages without hardcoding HTML into your content.</p>

<h4>Auto-Paragraph</h4>
<p>You don't need to type <code>&lt;p&gt;</code> tags. The parser automatically converts
double line breaks (pressing Enter twice) into paragraphs. Single line breaks become
<code>&lt;br&gt;</code> tags. Existing HTML block elements (headings, divs, blockquotes)
are left untouched.</p>
HTML
];

// ── BORING ASS STUFF ─────────────────────────────────────────────────────

$help_topics['configuration'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Configuration',
    'icon'     => '&#x2699;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Global Configuration</h3>
<p>Site-wide settings that affect the entire installation.</p>

<h4>Site Identity</h4>
<ul>
    <li><strong>Site Name</strong> — appears in the browser title bar, RSS feed, and
    wherever the skin displays a site title.</li>
    <li><strong>Description</strong> — a short tagline, used in meta tags and RSS.</li>
    <li><strong>Logo</strong> — upload an SVG, PNG, or JPG. Used in the navigation bar
    if the active skin supports it.</li>
    <li><strong>Favicon</strong> — the small icon that appears in browser tabs.</li>
</ul>

<h4>Image Processing</h4>
<ul>
    <li><strong>Max Width (Landscape)</strong> — images wider than this are scaled down on upload.</li>
    <li><strong>Max Height (Portrait)</strong> — images taller than this are scaled down.</li>
    <li><strong>JPEG Quality</strong> — compression level (0–100) for generated images
    and thumbnails. Higher = better quality, larger files. Default: 85.</li>
</ul>

<h4>Homepage Mode</h4>
<p>Controls what visitors see when they hit your root URL. Four modes:</p>
<ul>
    <li><strong>Latest Post (default)</strong> — the most recently published transmission
    is shown via the active skin's single-image layout.</li>
    <li><strong>Archive Page</strong> — the root URL redirects to <code>/archive</code>.
    Visitors land directly in the browsable photo archive. Two sub-options appear: opening
    layout (<em>Masonry</em> justified grid or <em>Thumbs</em> grid) and, in Thumbs mode,
    thumbnail crop style (<em>Cropped</em> or <em>Square</em>). These settings override the
    skin manifest defaults for the archive page.</li>
    <li><strong>Skin Landing Page</strong> — shows the active skin's built-in landing page
    (slider, grid, or portfolio intro). Not all skins have a landing page; if the active
    skin doesn't, it falls back to Latest Post behaviour.</li>
    <li><strong>Static Page</strong> — a page you've built in the Pages section becomes
    the homepage. A <em>Homepage Page</em> picker appears to choose which page. The image
    feed moves to a configurable Blog URL Slug (default: <code>blog</code>), and a BLOG
    link is added to the navigation automatically.</li>
</ul>

<h4>Landing Page Only</h4>
<p>Available when Homepage Mode is set to Skin Landing Page or Static Page. When enabled,
the navigation bar and site header are suppressed entirely — only the page content is shown.
No nav, no footer, no admin chrome. Designed for coming-soon pages, splash screens, and
single-page portfolio installs. The active skin's background, fonts, and textures are all
preserved; only the nav wrapper is removed.</p>

<h4>Navigation</h4>
<p>Assign static pages to header navigation slots. The number of available slots depends
on the active skin.</p>

<h4>Footer</h4>
<p>Configure footer content slots. Each slot can be on (shows default content), custom
(your HTML), or off.</p>

<h4>Comments &amp; Downloads</h4>
<ul>
    <li><strong>Global Comments</strong> — master switch for the entire signal system.
    When off, no comment forms appear anywhere.</li>
    <li><strong>Global Downloads</strong> — master switch for the download button.
    Individual transmissions can still be toggled independently.</li>
</ul>

<h4>Public Blogroll</h4>
<p>Controls whether the public blogroll page (<code>/blogroll.php</code>) is accessible to visitors. When disabled, the page returns a 404 and the blogroll link is removed from navigation. Your blogroll entries and peer connections are preserved — re-enabling restores the page immediately. You can still manage blogroll entries in the admin regardless of this setting.</p>

<h4>AI Training Crawlers</h4>
<p>Controls whether AI companies can scrape your site content for model training.
Three options:</p>
<ul>
    <li><strong>No Opinion (default)</strong> — no special directives are emitted. Standard
    crawlers like Googlebot are unaffected. AI bots follow whatever default behaviour their
    operators have set.</li>
    <li><strong>Allow</strong> — explicitly permits known AI training crawlers (GPTBot,
    ChatGPT-User, CCBot, Google-Extended, anthropic-ai, ClaudeBot, Bytespider) via
    <code>Allow: /</code> directives in <code>robots.txt</code>.</li>
    <li><strong>Disallow</strong> — blocks the same crawlers via <code>Disallow: /</code>
    in <code>robots.txt</code> and adds <code>&lt;meta name="robots" content="noai, noimageai"&gt;</code>
    to every page as a belt-and-suspenders measure for crawlers that honour the meta directive.</li>
</ul>
<p>The <code>robots.txt</code> file is regenerated every time you save Global Configuration.
It also blocks public access to admin pages (<code>/smack-*</code>), <code>/core/</code>,
<code>/backups/</code>, and <code>/migrations/</code> regardless of the AI policy setting.</p>
HTML
];

$help_topics['api-keys'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'API Key Access',
    'icon'     => '&#x1F5DD;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Tool API Key</h3>
<p>Companion tools (SYBU, SUYB, and others) can authenticate with SnapSmack using a
64-character hex API key instead of a browser session. The key is sent in the
<code>X-Snap-Key</code> request header. Endpoints that accept the API key also accept a
normal session cookie, so your browser-based admin access is unchanged.</p>

<h4>Generating a Key</h4>
<p>Go to <strong>Admin &rarr; Settings &rarr; API Access</strong>. Click
<strong>Generate Key</strong> to create a cryptographically random 64-char hex key.
Copy it immediately — it is shown only once. The key is stored as a salted hash; the
plaintext is never saved.</p>

<h4>Revoking or Regenerating</h4>
<p>Click <strong>Regenerate</strong> to invalidate the current key and issue a new one.
Any tool using the old key will immediately lose access. Click <strong>Revoke</strong>
to delete the key entirely and disable API access until a new one is generated.</p>

<h4>Key Types</h4>
<p>Each key has a type that restricts which API endpoints it can reach. Choose the right
type for the tool you are setting up:</p>
<ul>
    <li><strong>Oh Snap!</strong> — for the Oh Snap! skin designer desktop app</li>
    <li><strong>SmackPress</strong> — for the SmackPress WordPress migration workbench</li>
    <li><strong>FLKR FCKR Import</strong> — for the FLKR FCKR Flickr import tool. Revoke
    this key when your import is complete — it only needs to exist long enough to run the
    migration.</li>
</ul>

<h4>Pasting Into a Tool</h4>
<p>In SYBU: Settings tab &rarr; API Key field. Paste the key and save. SYBU will use it
for all subsequent requests instead of prompting for a username and password.</p>
<p>In FLKR FCKR: paste into the <em>API Key</em> field in the settings bar at the top of
the window. Click <strong>Test</strong> to verify the connection before starting your
import.</p>
HTML
];

$help_topics['akismet-spam'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Akismet Spam Filter',
    'icon'     => '&#x1F6AB;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Akismet Spam Filter</h3>
<p>SnapSmack integrates with <a href="https://akismet.com" target="_blank">Akismet</a>
to automatically identify and reject spam comments. Akismet is a cloud service that
checks submissions against a global spam database built from millions of sites.</p>

<h4>Setup</h4>
<ol>
    <li>Get a free API key at <a href="https://akismet.com/signup/" target="_blank">akismet.com/signup</a>
    (free for personal/non-commercial sites).</li>
    <li>Go to <strong>Admin → Settings → Global Comments → Architecture &amp; Interaction</strong>.</li>
    <li>Paste your key into the <strong>Akismet API Key</strong> field.</li>
    <li>Click <strong>TEST KEY</strong> — you'll see a ✓ or ✗ inline without a page reload.</li>
    <li>Save settings.</li>
</ol>

<h4>How It Works</h4>
<p>When a visitor submits a comment, SnapSmack sends the comment text, author name, email,
URL, IP address, and user-agent to Akismet's API. If Akismet classifies it as spam, the
comment is silently rejected — the submitter sees a success message but the comment is
never stored. This prevents spammers from learning they've been blocked and adjusting
their technique.</p>

<h4>Multisite / Hub Installs</h4>
<p>On a hub installation, the Akismet key is configured once on the hub and automatically
distributed to all connected spokes via the heartbeat. Each spoke checks its own comments
independently against the shared key — no spam reaches any site in the network.</p>

<h4>Akismet vs. SnapSmack's Own Spam Tools</h4>
<p>Akismet handles content-based spam (gibberish, SEO links, bot-generated text). It works
alongside — not instead of — SnapSmack's fingerprint bans, keyword filters, semantic
analysis, and SMACKATTACK network reputation. Use all layers together for best results.</p>
HTML
];

$help_topics['fingerprints-bans'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Fingerprints & Troll Bans',
    'icon'     => '&#x1F511;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Fingerprints &amp; Troll Ban System</h3>
<p>SnapSmack automatically collects a passive browser fingerprint from every commenter.
This fingerprint is a hash of their browser characteristics (canvas, WebGL, screen resolution,
timezone, language, etc.) and allows you to ban persistent trolls across IP changes, VPNs,
and incognito browsing.</p>

<h4>How It Works</h4>
<p>When someone submits a comment, JavaScript on the public site extracts their browser
fingerprint and includes it in the submission. The server stores this fingerprint alongside
the comment. If the fingerprint (or IP or email) matches an entry in your ban list, the
submission is silently rejected — the user sees a success message but the comment is never stored.</p>

<h4>The Ban Manager</h4>
<p>Navigate to <strong>Fingerprints &amp; Troll Bans</strong> in the admin sidebar to manage bans:</p>
<ul>
    <li><strong>Banned Tab</strong> — shows all active bans. Click <em>Unban</em> to remove a ban.</li>
    <li><strong>Fingerprints Tab</strong> — search by fingerprint hash or IP. See comment counts per fingerprint.
        Click <em>Ban</em> to ban that fingerprint with a custom reason.</li>
    <li><strong>Semantic Tab</strong> — detect related accounts using AI writing style analysis.
        Enter a fingerprint to see all similar fingerprints (sorted by similarity %). Essential
        for catching persistent trolls who rotate VPNs — their fingerprint changes but their
        writing style (vocabulary, phrasing, patterns) remains signature. Similarity threshold is 55%.
        One click to ban all related fingerprints at once.</li>
    <li><strong>Keywords Tab</strong> — manage banned phrases and words. Add keywords with three
        match types (exact word, substring, regex) and two severity levels (flag for review, or
        silent reject). Silent rejection blocks the submission without alerting the submitter.</li>
    <li><strong>Add Ban Tab</strong> — issue a new ban by fingerprint, IP, or email address.</li>
    <li><strong>Shared Bans Tab</strong> <em>(hub installs only)</em> — view the consolidated ban registry
        collected from all connected spokes. Shows report counts per hash, which spoke first reported it,
        and a Clear button for false-positive removal. Clearing a ban removes it from distribution but
        preserves the audit row.</li>
</ul>

<h4>Ban Types</h4>
<ul>
    <li><strong>Fingerprint</strong> — blocks a specific browser/device. Best for repeat trolls.
        They'd need a new device or browser to bypass.</li>
    <li><strong>IP Address</strong> — blocks an IP. Easiest to evade (VPN, proxy, public WiFi).
        Use in combination with fingerprint bans.</li>
    <li><strong>Email Address</strong> — blocks an email hash. Stored as SHA-256 so the address
        itself is never exposed in the database.</li>
</ul>

<h4>False Positives</h4>
<p>Similar browsers (same device, same network) may share similar fingerprints. If you
accidentally ban a legitimate user, simply navigate to the <em>Banned</em> tab and click <em>Unban</em>
for that entry. It takes effect immediately.</p>

<h4>Privacy</h4>
<p>Fingerprints contain no personally identifiable information — just browser characteristics.
Email addresses are hashed, so only the hash is stored. IP addresses are stored in plain text
because they're necessary for blocking; they're visible only to admins.</p>
HTML
];

$help_topics['shield-ban-sync'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Shield — Hub/Spoke Ban Sync',
    'icon'     => '&#x1F6E1;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>SnapSmack Shield — Hub/Spoke Ban Sync</h3>
<p>Shield Tier 1 lets the hub and all connected spokes share hashed ban lists automatically.
When a troll gets banned on one site, that ban propagates to every site in your multisite
installation on the next heartbeat sweep — without any manual action on your part.</p>

<h4>How It Works</h4>
<p>The hub runs a heartbeat sweep roughly once per admin page load on the multisite management
page. During each sweep it calls every active spoke's <code>ban-sync</code> endpoint. Two things happen
in that one call:</p>
<ul>
    <li>The hub sends its consolidated ban list to the spoke. The spoke merges these with
        <code>INSERT IGNORE</code> so duplicates are harmless and nothing ever overwrites a local ban.</li>
    <li>The spoke returns any bans it has issued since its last sync. These flow back to the hub
        registry, where they become available for distribution to other spokes.</li>
</ul>
<p>The sync is delta-based: each spoke has a cursor timestamp. Only bans added or updated
after the cursor are transmitted on each cycle, so the payload stays small even with large
ban lists.</p>

<h4>Enabling Ban Sync</h4>
<p>Ban sync is disabled by default. To enable it: go to <strong>Interaction</strong> in the admin sidebar,
scroll to the <strong>Shield — Ban Sync</strong> section, and toggle it on. The section also shows the
last sync timestamp per spoke so you can confirm the sweep is running.</p>

<h4>Privacy Model</h4>
<p>Only SHA-256 hashes are transmitted between sites — never raw IP addresses, email addresses,
or any other identifying information. The original values are hashed locally before being stored
or sent. Even if a sync payload were intercepted, the hashes cannot be reversed.</p>
<p>Bans distributed from the hub to a spoke are marked internally with a <code>hub-sync:</code> prefix
on their reason field. This prevents them from being echoed back to the hub as "new" bans
on the next sync cycle.</p>

<h4>The Shared Bans Registry</h4>
<p>The hub maintains a <code>snap_hub_shared_bans</code> table that acts as the central registry. Each
entry tracks the ban type, SHA-256 hash, which spoke first reported it, the first and last seen
timestamps, and a <strong>report count</strong> — how many distinct spokes have reported the same identifier.
High report counts (3+ spokes) signal a confirmed cross-network threat.</p>
<p>To view the registry, go to <strong>Fingerprints &amp; Troll Bans</strong> and click the <strong>Shared Bans</strong>
tab (visible on hub installs only). You can manually clear any entry — this sets it to <code>removed = 1</code>
so it stops being distributed to spokes. The audit row is preserved for reference.</p>

<h4>Spoke Compatibility</h4>
<p>Spokes must be running 0.7.9O or later to support the <code>ban-sync</code> endpoint. If a spoke
returns a 404, the hub silently skips it and retries on the next sweep — no errors, no downtime.
Older spokes continue to receive their regular heartbeat; they simply won't participate in ban sync
until updated.</p>
HTML
];

$help_topics['bigwheel-pimpmobile'] = [
    'section'  => 'Settings',
    'title'    => 'Big Wheel &amp; Pimpmobile Modes',
    'icon'     => '&#x1F697;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Big Wheel &amp; Pimpmobile Modes</h3>
<p>SnapSmack ships in <strong>Big Wheel</strong> mode — a streamlined admin that shows only what
you need to start publishing right away. Everything is already set up with sensible defaults;
you don't have to configure anything to get going.</p>

<p>Once you've got some posts under your belt, the admin will offer to unlock
<strong>Pimpmobile</strong> mode. Pimpmobile is the full admin interface with everything visible:</p>
<ul>
    <li>Media Library &amp; Media Gallery</li>
    <li>Blogroll network tools</li>
    <li>Community / Interaction settings</li>
    <li>Static Pages</li>
    <li>Companion Tools</li>
    <li>Social Dock, custom CSS, custom scripts</li>
    <li>Archive, Solo, and Static appearance editors</li>
    <li>User Manager, Maintenance, Troll Control</li>
    <li>Backup &amp; Recovery, Cloud Backup, Disaster Recovery</li>
    <li>Traffic Stats, API Keys, Multisite Management</li>
</ul>

<h4>When Does the Offer Appear?</h4>
<p>The offer card appears on the dashboard once you hit 100 published posts. If you click
<strong>Not Yet</strong>, the offer backs off and reappears at the next 100-post milestone.
After three passes, the cadence relaxes to every 200 posts. On the third offer you'll also
see a <strong>Leave Me Alone</strong> button — click that and the offer never appears again.</p>

<h4>Switching Manually</h4>
<p>You don't have to wait for the offer. The sidebar has an <strong>Unlock Pimpmobile</strong>
link at the bottom that switches you immediately. If you're already in Pimpmobile and want to
go back, the same link reads <strong>Switch to Big Wheel</strong>.</p>

<h4>Nothing Is Hidden Permanently</h4>
<p>Big Wheel doesn't remove any features — it just keeps the sidebar uncluttered while you're
getting started. All pages are accessible by direct URL regardless of mode, and switching is
instantaneous with no data loss.</p>
HTML
];

$help_topics['maintenance-mode'] = [
    'section'  => 'Settings',
    'title'    => 'Maintenance Mode',
    'icon'     => '&#x1F527;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Maintenance Mode</h3>
<p>Maintenance mode lets you take a site offline for visitors while you work on it — useful
when you're setting up a new install, applying a major redesign, or running migrations you don't
want the public to stumble into mid-process.</p>

<h4>Turning It On</h4>
<p>Go to <strong>Settings</strong> and find the <strong>Maintenance Mode</strong> section near the
top. Set the toggle to <strong>ON — Show maintenance page</strong> and save. The site immediately
starts showing the maintenance page to anyone who isn't logged in.</p>

<h4>What Visitors See</h4>
<p>A self-contained holding page with your site name, a slowly rocking wrench icon, and whatever
title and message you've written in the two fields below the toggle. The page returns an HTTP
<code>503 Service Unavailable</code> with a <code>Retry-After: 30</code> header so search engines
and feed readers know to come back later rather than deindex the site. It also carries
<code>&lt;meta name="robots" content="noindex,nofollow"&gt;</code> as a belt-and-suspenders
signal to crawlers.</p>

<h4>Logged-In Users Are Never Blocked</h4>
<p>If you're logged in, maintenance mode is invisible — you see the normal site. This means you
can check your work in a real browser at full resolution while the public sees the holding page.
No preview toggle needed; just log in.</p>

<h4>Customising the Message</h4>
<p>The <strong>Page Title</strong> field (defaults to "Under Maintenance") sets the large heading
on the holding page. The <strong>Message</strong> textarea is a short paragraph — use it to give
visitors an ETA, a contact address, or whatever context makes sense for your situation. Both
fields are plain text; no HTML.</p>

<h4>Turning It Off</h4>
<p>Set the toggle back to <strong>OFF — Site is live</strong> and save. The site returns to
normal immediately — no cache to flush, no restart required.</p>

<h4>Hub Toggle (Multisite)</h4>
<p>If you're running a SnapSmack multisite network, the <strong>Multisite Management</strong>
page on the hub lets you toggle maintenance mode on individual spokes — or across the whole
fleet at once — without logging into each site separately.</p>

<p>The dashboard shows a <strong>MAINT</strong> column for every spoke. An orange dot means
the spoke is currently in maintenance mode; a green dot means it's live. This state is refreshed
automatically on every heartbeat sweep, so what you see reflects the spoke's current setting.</p>

<p>To toggle a single spoke, click its <strong>MAINT ON</strong> or <strong>MAINT OFF</strong>
button in the Action column. To act on all active spokes at once, use the
<strong>MAINTENANCE ALL ON</strong> or <strong>MAINTENANCE ALL OFF</strong> buttons above the
table — both prompt for confirmation before firing. The hub sends the command to each spoke
over the existing API channel; no re-login required.</p>

<p>The hub cannot remotely toggle maintenance mode on a spoke that shows as
<strong>OFFLINE</strong> — the API call would time out. Bring the spoke back online first,
or log in locally and toggle it from that site's Settings page.</p>

<p><strong>Note:</strong> The hub controls the spoke's <em>visitor-facing</em> maintenance
gate only. Admins already logged into a spoke are never blocked by maintenance mode,
whether it was set locally or triggered from the hub.</p>
HTML
];

$help_topics['smack-the-enemy'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'SMACKATTACK — Network Reputation',
    'icon'     => '&#x2620;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>SMACKATTACK — Network Reputation</h3>
<p>SMACKATTACK is an optional network-wide reputation system. Participating SnapSmack blogs
share hashed fingerprint reports with a central server. The server scores each fingerprint based
on how many trusted sites have reported it, and makes those scores available to all members.
You can use them to auto-reject known trolls before they even get into your moderation queue.</p>

<h4>Getting Started</h4>
<p>Go to <strong>Configuration</strong> (Settings section in the sidebar) and scroll to the
<strong>SMACKATTACK</strong> section at the bottom. Click <strong>Join the Network</strong>.
Your site registers with the central server using your site URL and display name — the same
pattern as the community forum — and you receive a Bearer token API key stored in your database.
No personal data about you is transmitted.</p>

<h4>Threat Levels</h4>
<p>The network issues five colour-coded threat levels based on weighted report counts.
You choose your auto-ban threshold in the same Settings section:</p>
<ul>
    <li><strong>Yellow</strong> (1 strike) — one trusted site has flagged this fingerprint.</li>
    <li><strong>Orange</strong> (2 strikes) — multiple sites agree.</li>
    <li><strong>Red</strong> (3 strikes) — serious cross-network threat.</li>
    <li><strong>Black</strong> (4+ strikes) — confirmed persistent offender.</li>
    <li><strong>Never</strong> — receive scores for information only; never auto-ban.</li>
</ul>
<p>Comments at or above your threshold are silently rejected. They still appear in
<strong>Troll Control</strong> so you can review them.</p>

<h4>Colour Dots in the Moderation Queue</h4>
<p>When SMACKATTACK is enabled and you are in Pimpmobile mode, a coloured dot appears
next to each pending comment showing its current network threat level. Green means clean;
anything else means at least one other site has reported that fingerprint or IP.</p>

<h4>Allow Votes</h4>
<p>If you approve a comment from a flagged submitter, an allow-vote is automatically sent
to the network. Allow-votes reduce the fingerprint's score and can eventually clear it.
This is how false positives get corrected without any central intervention.</p>

<h4>Bans Feed the Network</h4>
<p>When you ban a fingerprint or IP through Troll Control, the ban is automatically reported
to the network. You don't need to do anything extra — it's wired into the same ban function
that powers local bans.</p>

<h4>Score Sync</h4>
<p>Scores are fetched on demand via <strong>Sync Scores Now</strong> in Settings. A cursor
timestamp is stored so only scores that have changed since your last sync are downloaded —
the sync stays fast even as the network grows.</p>

<h4>Opting Out</h4>
<p>Click <strong>Opt Out</strong> in the SMACKATTACK section of Settings. Your site is
removed from the network, your API key is cleared, and no further data is sent or received.
Your local <code>snap_ste_scores</code> cache is left in place — it won't be updated but it
won't affect anything either.</p>
HTML
];

$help_topics['smackback'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'SMACKBACK — File Integrity Monitoring',
    'icon'     => '&#x26A1;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>SMACKBACK — File Integrity Monitoring</h3>
<p>SMACKBACK is automated sentinel software that ships in every SnapSmack install. It hashes
every PHP, CSS, and JavaScript file at install and update time, then re-verifies those hashes
on every admin login, every cron run, and optionally on public page loads. If a file has been
modified since the last known good state, SMACKBACK catches it and won't let it go quietly.</p>

<h4>What Gets Monitored</h4>
<p>All PHP, CSS, and JS files in the install root and subdirectories are monitored — including
skin files. Skins contain PHP templates that execute on every page load and are a prime target
for backdoor insertion. Excluded: uploads/, user content, minified third-party assets, and
anything in tools/ or smack-central/.</p>

<h4>How to Enable It</h4>
<p>Go to <strong>Admin → SMACK-BACK</strong> (in the sidebar under Boring Ass Stuff) and
toggle Enable on. Choose a response mode (Lockout is recommended). Optionally enable the
pageload stat check — it's a filesystem metadata-only operation that costs essentially
nothing per request. Save.</p>
<p>On a multisite fleet, if the hub controls SMACKBACK via Push It, the enabled state and
response mode are locked and managed centrally. You can still set your local alert email and
pageload check independently.</p>

<h4>Response Modes</h4>
<p><strong>Alert:</strong> Tamper detected → email fires → a prominent warning banner appears
in your admin header on every page. You can still use the admin. The banner won't go away
until you resolve the tamper.</p>
<p><strong>Lockout (recommended):</strong> Tamper detected → email fires → all admin pages
redirect to the SMACKBACK breach page. You can't post, configure, or navigate anywhere else
until the tampered files are resolved. This is what it's supposed to do.</p>
<p><strong>Paranoid:</strong> Same as Lockout, plus a hook for hub/spoke breach reporting
(Phase 2 — coming in a future release).</p>

<h4>When a Breach Fires</h4>
<p>You'll get an email immediately. In Lockout mode, every admin page redirects to a
high-contrast BREACH screen that lists every affected file with its status. From there you
can restore each file individually from the update server, or run a full update instead.
The breach cannot be dismissed — resolving it clears it.</p>

<h4>EOF Sentinel — What the Status Labels Mean</h4>
<p>SMACKBACK doesn't just detect that a file changed — it tells you how. The status label on
each affected file tells you what kind of change was detected:</p>
<p><strong>TAMPERED:</strong> Hash changed but the file's last line still matches the baseline.
The content was modified but the file is structurally intact. This is what active backdoor
insertion looks like — a PHP file with injected code at the top that ends with the same EOF
line it always had.</p>
<p><strong>TRUNCATED:</strong> Hash changed and the last line doesn't match the baseline. The
file ends earlier than expected. This is what a partial write, a failed FTP transfer, or a
write failure during an update looks like — not necessarily malicious, but the file is broken
and needs to be restored regardless.</p>
<p><strong>CORRUPTED:</strong> Null bytes found near the end of the file. This is what a
filesystem fault, a disk-full write failure, or an interrupted atomic write looks like. The
file is unreadable or partially zeroed.</p>
<p><strong>MISSING:</strong> File not found on disk at all.</p>
<p>TRUNCATED and CORRUPTED usually indicate an infrastructure problem rather than an attacker.
After restoring, check disk health, FTP logs, and whether a botched update caused the damage.</p>

<h4>Restoring Files</h4>
<p>SMACKBACK downloads the current release package from the update server, extracts the
specific file, verifies its SHA-256 hash matches the expected baseline, then writes it to
disk. If the hash doesn't match (i.e. the update server has a different version than what
you installed), it'll tell you and suggest running a full update instead.</p>

<h4>After a Breach</h4>
<p>Restoring files fixes the immediate problem. You still need to figure out how the files got
modified. Check your FTP access logs, your hosting panel audit trail, and any other server
access logs you have. Change your FTP credentials. If you can't explain the modification,
treat it as an active compromise and act accordingly.</p>

<h4>False Positives</h4>
<p>SMACKBACK is designed to avoid false positives. Oh Snap! CSS customisations are written to
the database, not to disk — they won't trigger SMACKBACK. Skin installs and updates via the
Skin Gallery automatically refresh the file manifest before writing new files. SnapSmack
updates refresh the manifest before cleanup. The Re-initialise Baseline button in SMACKBACK
settings lets you re-hash from disk after any legitimate manual edit — use it carefully, and
never during an active breach.</p>

<h4>Skin JS Security Scanner</h4>
<p>The Skin JS Security Scanner is a separate scan from the file integrity monitor. Where
SMACKBACK checks whether a file has been <em>changed</em>, the JS scanner checks whether
an installed skin contains <em>suspicious code patterns</em> regardless of whether the
files have been modified.</p>
<p>It scans every non-base installed skin (base skins — 50-shades-of-noah-grey and
new-horizon — are always trusted). For each skin it checks all PHP, HTML, and JS files
for five pattern types:</p>
<ul>
    <li><strong>eval() — Violation</strong>: Always flagged regardless of settings.
    <code>eval()</code> in a skin is a severe red flag with no legitimate use case.</li>
    <li><strong>External scripts from untrusted domains — Violation</strong>: Scripts
    loaded from domains not on the trusted CDN list (cdnjs.cloudflare.com,
    fonts.googleapis.com, fonts.gstatic.com, code.jquery.com, cdn.jsdelivr.net,
    unpkg.com). A malicious actor who compromises a skin could use this to load
    a remote payload.</li>
    <li><strong>atob() — Warning</strong>: Base64 decoding. Legitimate in some contexts
    but commonly used to obfuscate injected code.</li>
    <li><strong>document.write() — Warning</strong>: Deprecated API still used by some
    injection scripts for DOM manipulation.</li>
    <li><strong>Inline &lt;script&gt; — Info</strong>: Inline script tags. Most
    legitimate skins don't need them; third-party skins that do should be reviewed.</li>
</ul>
<p>The <strong>Allow Custom JS in Skins</strong> toggle demotes inline-script findings
from info to acceptable and external-script findings from violation to warning.
<code>eval()</code> is never demoted.</p>
<p>Click <strong>Scan Now</strong> to run a manual scan. Results persist between scans
so you don't need to re-scan every visit.</p>

<h4>Network Alert — Layer 2 (Global SC Broadcast)</h4>
<p>SMACKBACK's local detection is Layer 1 — RED alerts that never leave your server. Layer 2
is the global network: Smack Central watches for coordinated breach activity across the
SnapSmack install fleet and can broadcast a YELLOW advisory to every opted-in site.</p>
<p>Layer 1 and Layer 2 are entirely separate. A local RED breach never triggers a global
alert automatically, and a global YELLOW alert does not lock down your admin — it shows an
advisory banner only.</p>

<h4>Receiving Network Alerts</h4>
<p>In Admin → SMACK-BACK → Network Alert, check <strong>Receive Yellow Alerts</strong>.
Your site will poll Smack Central every 30 minutes and display a pulsing yellow banner at
the top of every admin page if an advisory is active. The banner links to this page.
You can also hit <strong>Check Now</strong> to force an immediate poll.</p>

<h4>Contributing Breach Reports</h4>
<p>Check <strong>Contribute Breach Reports to the Network</strong> to send your SMACKBACK
breach data to Smack Central when a local breach fires. Reports contain: site name, server
IP, affected file paths, timestamps, and SHA-256 hashes. No visitor data, no post content,
no admin credentials. If enough distinct sites report breaches within a short window, SC
automatically escalates the global alert — helping warn the rest of the fleet.</p>

<h4>Immediate Push Notifications</h4>
<p>The 30-minute poll means a new global alert might take up to half an hour to reach you.
Enable <strong>Immediate Breach Push Notifications</strong> to receive alerts within seconds.</p>
<p>When enabled, Smack Central will POST directly to your site the moment a coordinated
breach is detected — no waiting for the next poll. A local file (<code>network-alert-push.php</code>)
receives the push, validates it, and updates your alert state immediately.</p>
<p><strong>Privacy trade-off:</strong> To push to your site, SC needs to know where it is.
Enabling this transmits your site URL and site name to Smack Central, where they are stored.
A unique push token — generated on your server, never derived from your URL — is also stored
so SC can authenticate pushes and no third party can spoof a network alert. Turning this off
sends a deletion request to SC on every admin page load until SC confirms removal.</p>
<p>This is opt-in and transparent. You can verify your data has been removed by contacting
privacy@snapsmack.ca. Contributing breach reports and receiving push alerts are independent
options — you can mix and match as you see fit.</p>

<h4>What It Does Not Prevent</h4>
<p>SMACKBACK detects tampering — it does not prevent it. Total server compromise (shell,
database, and filesystem access) can defeat software-only integrity monitoring. Your hosting
choice, SSH key hygiene, and server firewall configuration are the first line of defence.
SMACKBACK is what catches it when those lines are crossed.</p>
HTML
];

$help_topics['gobsmacked'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'GOBSMACKED — Stylometric Evasion Detection',
    'icon'     => '&#x270D;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>GOBSMACKED — Stylometric Evasion Detection</h3>
<p>GOBSMACKED detects ban evasion by writing style. When a commenter is banned, a compact
numeric fingerprint of how they write is extracted from their comment history and reported
to SMACKATTACK. If the same person returns on a new device, new IP, or new email, their
writing style still matches the banned signature.</p>

<p>Raw comment text never leaves your server. Only a 25-dimension numeric vector is
transmitted — sentence rhythm, punctuation habits, function word frequencies, capitalisation
patterns. The original words are not recoverable from it.</p>

<h4>How It Works</h4>
<p>You don't need to do anything. When you ban a commenter through Troll Control and you are
connected to SMACKATTACK, GOBSMACKED automatically extracts the style vector and includes
it in the ban report. The central server stores it and runs periodic clustering analysis to
identify fingerprints that appear to be the same person across different accounts.</p>

<h4>Minimum Text Threshold</h4>
<p>A style vector is only extracted if the banned commenter has at least 30 words across all
their comments on your site. Below that threshold there is not enough text for a reliable
signature, and no vector is sent.</p>

<h4>Cluster Analysis</h4>
<p>The SMACKATTACK admin panel (on the hub) runs cosine similarity analysis across stored
style vectors. Fingerprints that score above 0.80 similarity are grouped into clusters and
presented with confidence labels: <strong>POSSIBLE MATCH</strong> (0.80–0.89),
<strong>LIKELY MATCH</strong> (0.90–0.95), <strong>STRONG MATCH</strong> (0.95+). The hub
admin can escalate all fingerprints in a cluster to a higher threat level, or dismiss a
cluster as a false positive.</p>

<h4>Privacy</h4>
<p>GOBSMACKED is disclosed in the TWIG N BERRIES privacy policy on snapsmack.ca. If you
participate in SMACKATTACK you should disclose that your site uses stylometric analysis
in your own site's privacy policy. The SnapSmack Settings page includes a privacy policy
field for this purpose.</p>

<h4>Requirements</h4>
<p>GOBSMACKED requires SMACKATTACK participation. It has no effect on standalone installs
that are not connected to the network.</p>
HTML
];

$help_topics['user-manager'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'User Manager',
    'icon'     => '&#x263A;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>User Management</h3>
<p>SnapSmack supports multiple user accounts with two roles.</p>

<h4>Roles</h4>
<ul>
    <li><strong>Admin</strong> — full access to everything: configuration, themes, users,
    backups, updates.</li>
    <li><strong>Editor</strong> — can create and edit transmissions, moderate signals, and
    manage categories/albums. Cannot access system settings, user management, backups, or updates.</li>
</ul>

<h4>Creating a User</h4>
<p>Provide a username, email address, password, and role. Passwords must be at least
12 characters. They are stored using bcrypt hashing and cannot be recovered — only reset.</p>

<h4>Editing a User</h4>
<p>You can change a user's email, role, and password. Usernames cannot be changed after
creation (they are used for referential integrity in the database).</p>

<h4>Deleting a User</h4>
<p>You can delete any user except yourself. This is a safety measure to prevent
accidentally locking yourself out.</p>
HTML
];

$help_topics['two-factor-auth'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Two-Factor Authentication',
    'icon'     => '&#x1F510;',
    'content'  => <<<'HTML'
<h3>Two-Factor Authentication (2FA)</h3>
<p>2FA adds a second layer of security to your account. After enabling it, every login
requires your password <em>plus</em> a 6-digit code from an authenticator app. Even if
someone gets your password, they cannot log in without your phone.</p>

<h4>Setting Up 2FA</h4>
<p>Go to <strong>Settings → Two-Factor Auth</strong> and click <em>Set Up
Two-Factor Authentication</em>. SnapSmack generates a secret key and displays it as a QR
code. Open any TOTP-compatible authenticator app — Google Authenticator, Authy, 1Password,
Bitwarden — and scan the code. Then enter the 6-digit code the app shows to confirm setup.</p>

<p>Can't scan? Every QR code screen also shows the raw key as text so you can enter it
manually into your app.</p>

<h4>Recovery Codes</h4>
<p>When you activate 2FA, SnapSmack generates eight one-time recovery codes. These codes
let you log in if you ever lose access to your authenticator app. Each code works once and
is removed after use.</p>
<p><strong>Save your recovery codes somewhere safe — they are shown only once.</strong>
A password manager is a good place. If you lose both your authenticator and your recovery
codes, contact your host to reset the database column directly.</p>

<h4>Logging In with 2FA Active</h4>
<p>After entering your username and password, you will see a verification screen. Enter
the 6-digit code currently shown in your authenticator app. Codes rotate every 30 seconds
and there is a ±30 second window to account for clock drift.</p>

<h4>Using a Recovery Code to Log In</h4>
<p>On the verification screen, click the <em>Recovery Code</em> tab and enter one of your
saved codes. The code is consumed and cannot be reused. You will still be logged in with
full access, but consider setting up a new authenticator device and regenerating fresh
recovery codes soon after.</p>

<h4>Regenerating Recovery Codes</h4>
<p>If your recovery codes are lost or you suspect they have been compromised, go to
<strong>Settings → Two-Factor Auth</strong> and use the <em>Regenerate Recovery Codes</em>
section. You will need a valid authenticator code to confirm. Old codes are invalidated
immediately.</p>

<h4>Disabling 2FA</h4>
<p>You can turn off 2FA at any time from the same page. You will need your current
authenticator code to confirm the action. Once disabled, logins require only your password.</p>
HTML
];

$help_topics['maintenance'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Maintenance',
    'icon'     => '&#x2692;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Database &amp; Asset Maintenance</h3>
<p>Housekeeping tools for keeping your installation healthy.</p>

<h4>Purge Orphaned Mappings</h4>
<p>Removes category and album associations that point to deleted transmissions or
deleted categories/albums. These orphans accumulate naturally over time and are harmless
but wasteful.</p>

<h4>Optimize Tables</h4>
<p>Runs MySQL's OPTIMIZE TABLE command on all SnapSmack tables. This defragments the
data files and reclaims disk space after large deletions. Safe to run at any time.</p>

<h4>Asset Sync</h4>
<p>Scans the image directory and regenerates any missing thumbnails. Also identifies
database records that point to files that no longer exist on disk (orphaned records).</p>

<h4>Regenerate All Thumbnails</h4>
<p>Force-regenerates square and aspect thumbnails for every image in the database,
overwriting existing ones. Run this after changing thumbnail quality settings (such as
the masonry image source setting in Archive Appearance) to apply the new sizes to all
existing images. Processes in batches of 25 to avoid timeouts.</p>

<h4>Delete Orphaned Files</h4>
<p>Removes physical image files that have no corresponding database record. These can
appear after failed uploads or manual file deletions.</p>
HTML
];

$help_topics['schema-recovery'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Schema Recovery',
    'icon'     => '&#x1F527;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Schema Recovery &amp; Auto-Discovery</h3>
<p>SnapSmack automatically maintains your database structure by comparing it against a
canonical schema file stored in the codebase.</p>

<h4>How It Works</h4>
<p>When you visit the System Updates page, SnapSmack runs a schema sync operation that:</p>
<ul>
    <li>Reads the canonical schema definition from <code>database/schema/snapsmack_canonical.sql</code></li>
    <li>Checks your database against it using MySQL's INFORMATION_SCHEMA</li>
    <li>Auto-creates any missing tables</li>
    <li>Auto-adds any missing columns</li>
    <li>Repairs stale enum definitions on existing columns</li>
</ul>

<h4>Single Source of Truth</h4>
<p>The canonical schema file is the single source of truth. When SnapSmack adds new tables
(like fingerprint storage for troll banning), the developer adds them once to the canonical
SQL file. Schema recovery then auto-discovers them on all installs — no manual sync needed.</p>

<h4>Idempotent &amp; Safe</h4>
<p>Schema recovery uses <code>IF NOT EXISTS</code> and INFORMATION_SCHEMA checks, so it's
completely safe to run multiple times. It will never drop tables, overwrite data, or cause
disruption to your site.</p>

<h4>Why It Matters</h4>
<p>Earlier versions required developers to manually sync new table definitions in two places
(the SQL file AND a hardcoded PHP array), which led to deployments where new features arrived
without their database tables. Schema recovery now reads directly from the canonical schema,
so new tables are auto-discovered and created without manual intervention.</p>

<h4>When It Runs</h4>
<p>Schema recovery runs automatically when you visit System Updates. If your installation
ever reports missing tables or blank admin pages, a schema recovery run usually fixes it.</p>
HTML
];

$help_topics['backup'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Backup & Recovery',
    'icon'     => '&#x2B07;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Backup &amp; Recovery</h3>
<p>The backup page is organised into four sections covering everything from quick exports
to full disaster recovery.</p>

<h4>Recovery Kit</h4>
<ul>
    <li><strong>Export Recovery Kit</strong> — generates a single encrypted ZIP file containing
    your database dump, settings, user credentials, and metadata. This is the fastest way to
    do a full restore. The ZIP is password-protected with AES-256 encryption.</li>
    <li><strong>Import Recovery Kit</strong> — upload a previously exported recovery kit ZIP
    to restore your site. This overwrites the database with the kit's contents.</li>
</ul>

<h4>Database Dumps</h4>
<ul>
    <li><strong>Full SQL Dump</strong> — complete export of all tables with data.
    The most important backup to have. Restore using phpMyAdmin or the MySQL CLI.</li>
    <li><strong>User Credentials</strong> — exports the users table separately.</li>
    <li><strong>Schema Only</strong> — table structure without data, for creating a blank
    installation with the same schema.</li>
</ul>

<h4>Data Liberation &amp; Maintenance</h4>
<ul>
    <li><strong>WXR Export</strong> — WordPress-compatible XML export. Lets you migrate
    content to WordPress or other CMS platforms that support WXR import.</li>
    <li><strong>JSON Export</strong> — structured JSON export of all transmissions with
    metadata. Useful for custom migrations or data analysis.</li>
    <li><strong>Verify Integrity</strong> — scans all uploaded images against their database
    records and SHA-256 checksums. Reports missing files, orphaned records, and hash
    mismatches.</li>
    <li><strong>Source Archive</strong> — downloads a compressed archive (tar.gz) of the
    SnapSmack codebase (code only, no uploaded images).</li>
</ul>

<h4>Remote Push</h4>
<p>Push backups to remote storage. See the <em>FTP Backup</em> and <em>Cloud Backup</em>
help topics for details.</p>

<h4>Recommended Strategy</h4>
<p>Export a recovery kit weekly (or before any update). For off-site redundancy, configure
either FTP or cloud push to automatically send the backup to a remote location. Keep at
least one recovery kit stored off-server at all times.</p>
HTML
];

$help_topics['ftp-backup'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'FTP Backup',
    'icon'     => '&#x21C5;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>FTP Backup</h3>
<p>Push database dumps to a remote FTP/FTPS server for off-site storage.</p>

<h4>Configuration</h4>
<p>Enter your FTP server hostname, port, username, and password. The password is stored
encrypted (AES-256-CBC) in the database — it is never stored in plaintext.</p>
<ul>
    <li><strong>Remote Path</strong> — the directory on the FTP server where backups will be
    uploaded (e.g., <code>/backups/snapsmack/</code>).</li>
    <li><strong>Mode</strong> — FTP (unencrypted) or FTPS (TLS-encrypted). FTPS is strongly
    recommended.</li>
    <li><strong>Passive Mode</strong> — enable if your server is behind a firewall that blocks
    active FTP connections.</li>
</ul>

<h4>Testing</h4>
<p>Use the Test Connection button to verify your credentials and remote path before pushing
a real backup.</p>

<h4>Pushing a Backup</h4>
<p>Click "Push to FTP" on the Backup &amp; Recovery page. A fresh SQL dump is generated and
uploaded to your remote path. The file is named with a timestamp for easy identification.</p>
HTML
];

$help_topics['cloud-backup'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Cloud Backup',
    'icon'     => '&#x2601;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Cloud Backup</h3>
<p>Push database dumps to Google Drive or OneDrive using OAuth 2.0 authentication.</p>

<h4>Linking a Provider</h4>
<p>Click "Link" next to Google Drive or OneDrive. You will be redirected to the provider's
consent screen to authorize SnapSmack. Once authorized, a refresh token is stored encrypted
in your database — you do not need to re-authorize each session.</p>

<h4>Provider Status</h4>
<ul>
    <li><strong>LINKED</strong> — refresh token stored, ready to push. The system will
    automatically obtain a fresh access token when needed.</li>
    <li><strong>ACTIVE</strong> — linked and a session access token is currently valid.</li>
    <li><strong>NOT LINKED</strong> — no credentials stored, needs authorization.</li>
</ul>

<h4>Unlinking</h4>
<p>Click "Unlink" to revoke access and delete the stored refresh token. You can re-link
at any time by going through the OAuth flow again.</p>

<h4>Pushing a Backup</h4>
<p>Click "Push to Google Drive" or "Push to OneDrive" on the Backup &amp; Recovery page.
A fresh SQL dump is generated and uploaded to the root of your cloud storage. The file is
named with a timestamp.</p>
HTML
];

$help_topics['updates'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'System Updates',
    'icon'     => '&#x21BB;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Update System</h3>
<p>SnapSmack can update itself from the official update server. All update packages are
cryptographically signed with Ed25519 to prevent tampering.</p>

<h4>Checking for Updates</h4>
<p>Click "Check Now" to query the update server. If a cron job is registered, this happens
automatically every 6 hours. A 24-hour fallback check runs on dashboard load even without
cron.</p>

<h4>Applying an Update</h4>
<p>Click <strong>APPLY UPDATE →</strong> to start. The pipeline runs automatically from
there — backup, verify, extract, migrate — without further input. A status log scrolls
as each stage completes. The only thing you do is click Apply once.</p>
<p>If a stage fails, auto-advance stops and the manual controls stay active so you can
review the error and decide how to proceed. A full backup is always taken before any
files are touched; if anything goes wrong the system rolls back automatically.</p>

<h4>Protected Paths</h4>
<p>The updater never overwrites your site-specific files: database configuration, upload
directories, .htaccess, robots.txt, and the signing public key. These are listed in
<code>protected_paths.json</code>.</p>

<h4>Schema Recovery</h4>
<p>The Schema Recovery panel lets you run a schema sync or inspect migration state
without triggering a full update. Use it after a failed update or when bringing an
older installation current manually. Schema sync compares your live database against
the reference schema and adds any missing columns — it never removes or modifies
existing data.</p>

<h4>Ghost Migration Files</h4>
<p>A ghost file is a <code>.sql</code> file present in <code>/migrations/</code> that is not
listed in the updater's known migration registry. These appear when a migration is
renamed or removed. The <strong>Purge Ghost Files</strong> button deletes them from disk.
Ghost files are harmless but can clutter the Schema Recovery panel.</p>

<h4>Key Rotation Detection</h4>
<p>If the release signing key is ever rotated (i.e. the public key on your install no
longer matches the key used to sign the latest packages), the updater detects the mismatch
and presents an amber <strong>KEY ROTATION DETECTED</strong> panel. It fetches a
<code>key-rotation.json</code> file from the release server, verifies it against a
hardcoded root public key, and pre-fills the new key. Click <strong>Accept &amp; Continue</strong>
to apply the new key in one step. No manual key paste is needed. If the rotation file
cannot be verified, the panel falls back to a manual paste field.</p>

<h4>Signing Enforcement</h4>
<p>Updates are verified with an Ed25519 signature using <code>core/release-pubkey.php</code>.
If the public key file is absent or contains an all-zeros placeholder, signature checking
falls back to SHA-256 checksum only. On any install that received 0.7.41 or later, a real
key is present and Ed25519 verification is fully enforced.</p>

<h4>Update Track</h4>
<p>The <strong>UPDATE TRACK</strong> setting in <em>Settings → Configuration</em> controls
which release stream the updater offers:</p>
<ul>
    <li><strong>BORING (default)</strong> — stable tagged releases only. This is the right
    choice for production sites. Updates that ship are tested and signed.</li>
    <li><strong>BITCHIN' (opt-in)</strong> — receives dev builds (versions with a
    <code>D</code> suffix, e.g. <code>0.7.184D</code>) in addition to stable releases.
    Dev builds may contain known issues, half-finished features, or experimental changes.
    Only enable this if you know what you're doing and have a backup strategy.</li>
</ul>
<p>Changing the track does not modify any installed files — it only affects what the
updater offers the next time it checks for updates. You can flip back to Boring at any
time. The updater's safety filter prevents a D-suffixed build from ever being offered to
a stable-track install even if the dev endpoint is accidentally queried.</p>
<p>In a multisite fleet, each spoke sets its own track independently. The hub's multisite
roster shows each spoke's current track as a BORING or BITCHIN' badge in the fleet table.
The hub itself always displays its own version; hub track is set the same way as any other
site via its own Configuration page.</p>

<h4>Skin Updates</h4>
<p>The update page also shows notifications about new or updated skins available in the
registry. These are installed separately from core updates via the Skin Gallery.</p>
HTML
];

$help_topics['key-rotation'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Key Rotation',
    'icon'     => '&#x1F511;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Signing Key Rotation</h3>
<p>SnapSmack uses a two-tier Ed25519 signing system to protect update packages. Understanding
the key hierarchy helps if you ever need to intervene manually.</p>

<h4>Key Hierarchy</h4>
<p><strong>Root key</strong> — A long-lived keypair used only to sign key rotation events.
The root public key is hardcoded in <code>core/updater.php</code> and never changes across
releases. The root private key is held offline by the release maintainer.</p>
<p><strong>Release key</strong> — The key that actually signs update packages. This key can
be rotated (e.g. if it is compromised). Its public half lives in
<code>core/release-pubkey.php</code> on your server.</p>

<h4>Automatic Rotation</h4>
<p>When the release key changes, the updater detects the signature mismatch on next update
check. It fetches <code>key-rotation.json</code> from the release server, verifies the
rotation event against the hardcoded root public key, and presents a
<strong>KEY ROTATION DETECTED</strong> panel with the new key pre-filled. Click
<strong>Accept &amp; Continue</strong> — the new key is written to
<code>core/release-pubkey.php</code> and the update proceeds normally.</p>

<h4>Manual Rotation</h4>
<p>If automatic rotation fails (e.g. the release server is unreachable), you can paste
the new public key manually into the text field on the Update page. The key is a 64-character
hex string. After saving, re-check for updates.</p>
HTML
];

$help_topics['ip_shield'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'IP Shield & Login Security',
    'icon'     => '&#x1F6E1;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>IP Shield & Login Security</h3>
<p>SnapSmack has three layers of protection on the login endpoint.</p>

<h4>1. Non-Standard Login Path</h4>
<p>The login page lives at a URL you configure in Configuration &rarr; Security (default: <code>/snap-in</code>).
Direct access to <code>snap-in.php</code> returns a 403. Bots scanning for <code>wp-login.php</code>
and similar standard paths hit dead ends without ever finding the door.</p>

<h4>2. User-Agent Filtering</h4>
<p>Requests with a blank, curl, Python, or other scripted User-Agent are silently rejected with a 403
before any login logic runs. Real browsers always send a UA string.</p>

<h4>3. Auto IP Ban (Brute-Force Detection)</h4>
<p>Every failed login attempt is counted per IP in a 10-minute sliding window. After 5 failures,
the IP is automatically banned for 7 days. Subsequent requests from that IP are blocked before
any credential check runs.</p>
<p>View and lift active bans in <strong>Troll Control &rarr; IP Shield</strong>.</p>

<h4>If You Lock Yourself Out</h4>
<p>If your own IP is auto-banned, lift it from the IP Shield tab in Troll Control, or run:
<code>DELETE FROM snap_ip_bans WHERE ip = 'YOUR.IP';</code> in your database console.</p>
HTML
];

$help_topics['probe-guard'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Probe Guard',
    'icon'     => '&#x26D4;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Probe Guard</h3>
<p>Probe Guard automatically bans IP addresses that probe for common scanner targets —
WordPress login pages, xmlrpc, shell upload paths, <code>.env</code> files, phpMyAdmin,
and similar. No configuration required; it fires before any application code runs.</p>

<h4>How It Works</h4>
<p>A rewrite rule in <code>.htaccess</code> routes known scanner paths to
<code>probe-ban.php</code>. That file records a 30-day ban in <code>snap_ip_bans</code>
and returns a 403. Subsequent requests from the banned IP are rejected at the
<code>.htaccess</code> level by IP Shield's block list — no PHP execution needed.</p>

<h4>Managing Probe Bans</h4>
<p>Probe bans land in the same <code>snap_ip_bans</code> table as login brute-force bans.
You can view and lift them in <strong>Troll Control &rarr; IP Shield</strong>. Probe bans
are tagged with the path that triggered them, so you can see what was being scanned.</p>

<h4>Adding or Removing Paths</h4>
<p>The list of banned paths lives in <code>.htaccess</code> as a <code>RewriteRule</code>
pattern. Edit it directly if you need to add paths specific to your environment. The
<code>.htaccess</code> file is gitignored and server-specific — changes won't be overwritten
by updates.</p>
HTML
];

$help_topics['updater_modal'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Applying Updates',
    'icon'     => '&#x21BB;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Applying Updates</h3>
<p>Updates run as a step-by-step process directly on the System Updates page
(<code>smack-update.php</code>). Each stage requires a manual button click — nothing
advances automatically.</p>

<h4>How to Start an Update</h4>
<p>When an update is available, a banner appears on the dashboard. Click
<strong>VIEW UPDATES</strong> to go to the Updates page, review the changelog and file
change summary, then click <strong>APPLY UPDATE</strong> to begin.</p>

<h4>What Each Stage Does</h4>
<p><strong>Download</strong> — Fetches the signed release package from the update server.<br>
<strong>Verify</strong> — Confirms the package hasn't been tampered with (SHA-256 checksum +
Ed25519 signature).<br>
<strong>Backup</strong> — Creates a full zip backup of your installation before touching any
files. Cannot be skipped.<br>
<strong>Extract</strong> — Unpacks the package in chunks, skipping protected paths.<br>
<strong>Migrate</strong> — Runs any new database migrations and stamps the new version.</p>

<h4>Protected Paths</h4>
<p>The updater never overwrites your site-specific files: <code>core/db.php</code>,
<code>core/constants.php</code>, the <code>uploads/</code> directory, <code>.htaccess</code>,
and others listed in <code>protected_paths.json</code>. Your configuration is safe.</p>

<h4>If an Update Fails</h4>
<p>If any stage fails, a <strong>Rollback</strong> button appears. This restores the
pre-update backup. After a rollback, check the error message and retry — most failures
are network timeouts or disk permission issues that clear on a second attempt.</p>

<h4>Dismiss Banner Without Updating</h4>
<p>If you see the update banner but aren't ready to update, click <strong>DISMISS</strong>
to hide it for the rest of the session. It will reappear on next login.</p>
HTML
];

$help_topics['blogroll'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Blogroll',
    'icon'     => '&#x2661;',
    'content'  => <<<'HTML'
<h3>Blogroll &amp; Peer Network</h3>
<p>The blogroll is a directory of fellow photographers and sites you admire. It appears
as a public page on your site (if linked in navigation) and powers the RSS feed
aggregation system.</p>

<h4>Adding a Peer</h4>
<p>Provide a name, URL, optional description, optional category, and optional RSS feed URL.
If an RSS feed is provided, SnapSmack will periodically check it for new content (via the
cron RSS fetcher).</p>

<h4>Categories</h4>
<p>Blogroll entries can be grouped into categories (e.g., "Street Photographers",
"Film Shooters", "Inspiration"). These display as sections on the public blogroll page.</p>

<h4>RSS Fetching</h4>
<p>When the RSS cron job is registered, SnapSmack checks each peer's feed hourly and
records the latest publication date. This lets you see at a glance who has posted
recently. The fetcher does not import or display the actual content — it only tracks
activity.</p>
HTML
];

// ── PAGES & FEATURES ─────────────────────────────────────────────────────

$help_topics['static-pages'] = [
    'section'  => 'Public Features',
    'title'    => 'Static Pages',
    'icon'     => '&#x25AD;',
    'content'  => <<<'HTML'
<h3>Static Pages</h3>
<p>Create standalone pages like About, Contact, or Portfolio that are not part of the
transmission timeline. Static pages have their own URL based on a slug (e.g.,
<code>yoursite.com/page.php?slug=about</code>).</p>

<h4>Creating a Page</h4>
<p>Set a title, write content (with full shortcode and formatting toolbar support),
and assign a menu order if you want the page in the navigation.</p>

<h4>Featured Image</h4>
<p>Optionally assign a featured image (hero) that appears at the top of the page.
This uses the media library asset system. When a hero image is set, three additional
controls appear:</p>
<ul>
    <li><strong>Image Size</strong> — Full Width, Medium (60%), or Small (35%).</li>
    <li><strong>Image Alignment</strong> — Centre, Left, or Right. Applies when size is Medium or Small.</li>
    <li><strong>Image Shadow</strong> — adds a subtle drop shadow under the hero image.</li>
</ul>

<h4>Data Shortcodes</h4>
<p>The content toolbar includes a shortcode picker (— INSERT SHORTCODE —) for inserting
live site data into your page text. Available shortcodes:</p>
<ul>
    <li><code>[post_count]</code> — total number of published posts</li>
    <li><code>[site_name]</code>, <code>[site_url]</code> — site identity values from settings</li>
    <li><code>[current_year]</code> — the current four-digit year (updates automatically)</li>
    <li><code>[years_since year="" month="" day=""]</code> — years elapsed since a given date</li>
    <li><code>[newest_post]</code>, <code>[oldest_post]</code> — dates of first and last posts</li>
    <li><code>[archive_link]</code>, <code>[gallery_link]</code> — auto-generated URLs</li>
    <li><code>[random_image]</code>, <code>[latest_image]</code> — inline image from your archive</li>
    <li><code>[embed:key]</code> — inserts a named embed code from Smack Your Scripts Up!</li>
</ul>

<h4>Navigation</h4>
<p>Pages can be assigned to header navigation slots in the Configuration page. The
number of available slots depends on the active skin.</p>

<h4>Homepage Static Page</h4>
<p>Any page can be set as the site homepage via Global Settings → Homepage Mode → Static Page.
When set, that page is served at your root URL. The image feed moves to a configurable
Blog URL Slug (default: <code>blog</code>).</p>

<h4>Landing Page Only (Coming Soon Mode)</h4>
<p>When Homepage Mode is Static Page and the Landing Page Only toggle is on, the active
skin's navigation and footer are suppressed. The page content fills the screen with only
the skin's background, fonts, and colours — no nav bar, no site header, nothing else.
This is the intended way to run a coming-soon page or a single-message splash screen.
Turn it off when you're ready to open the rest of the site.</p>

<h4>Content Width</h4>
<p>The width of the text column on static pages can be adjusted globally in
Global Vibe → Page Content Width (400–1400 px). Each skin has its own default;
the slider overrides it across all skins at once.</p>
HTML
];

$help_topics['privacy-policy'] = [
    'section'  => 'Public Features',
    'title'    => 'Privacy Policy Page',
    'icon'     => '&#x2611;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Privacy Policy Page</h3>
<p>SnapSmack includes a built-in privacy policy page manager. When enabled, a link appears
in the public site footer and the policy is accessible at <code>/privacy-policy.php</code>.</p>

<h4>Enabling It</h4>
<p>Go to <strong>Privacy Policy</strong> in the sidebar (under The Good Shit), check the
enable box, write your content, and save. The footer link appears immediately.</p>

<h4>What to Include</h4>
<p>At minimum, your privacy policy should tell visitors:</p>
<ul>
    <li>What data your site collects (SnapSmack collects visit statistics using a daily-rotating hash — no IP addresses are stored).</li>
    <li>Whether you participate in SMACKATTACK (if so, ban hashes are shared with the network).</li>
    <li>Whether GOBSMACKED is active (if so, stylometric writing vectors are extracted from banned commenters' comment histories and shared with the network — raw text is never transmitted).</li>
    <li>Whether you use any third-party analytics or tracking scripts (configured in Smack Your Scripts Up!).</li>
</ul>

<h4>HTML Accepted</h4>
<p>The content field accepts HTML. Use headings, paragraphs, and lists to structure
a readable policy. The page renders inside your active skin like any other static page.</p>

<h4>Page Title</h4>
<p>The title field controls both the heading displayed on the public page and the text
of the footer link.</p>
HTML
];

$help_topics['gallery-wall'] = [
    'section'  => 'Public Features',
    'title'    => 'Floating Gallery',
    'icon'     => '&#x25A6;',
    'content'  => <<<'HTML'
<h3>Interactive Floating Gallery</h3>
<p>The floating gallery is a 3D interactive experience that displays your photographs as
draggable tiles on a virtual wall. It is desktop-only — mobile visitors are automatically
redirected to the standard archive view.</p>

<h4>Requirements</h4>
<p>The floating gallery only appears when the active skin declares <code>supports_wall</code>
in its manifest. Not all skins include floating gallery support.</p>

<h4>Physics &amp; Engine Settings</h4>
<p>The wall uses simulated physics for dragging and momentum. All engine settings live in
<strong>Global Vibe</strong> (not Smooth Your Skin) because they control behaviour, not aesthetics:</p>
<ul>
    <li><strong>Reflection</strong> — toggle a below-tile reflection effect (Chromium/Safari only).</li>
    <li><strong>Scroll Friction</strong> — how quickly the wall decelerates after a drag (0.80 = ice, 0.99 = molasses).</li>
    <li><strong>Drag Weight</strong> — resistance when dragging (0.5 = featherlight, 5.0 = heavy).</li>
    <li><strong>Gallery Link</strong> — show or hide the Floating Gallery nav link.</li>
</ul>

<h4>Visual Settings</h4>
<p>The wall background colour is the only gallery setting that stays in Smooth Your Skin,
since it's part of each skin's visual palette.</p>
HTML
];

$help_topics['archive'] = [
    'section'  => 'Public Features',
    'title'    => 'Archive View',
    'icon'     => '&#x25A4;',
    'content'  => <<<'HTML'
<h3>Public Archive</h3>
<p>The archive is the browsable gallery of all published transmissions. Its appearance
depends heavily on the active skin, but most skins offer multiple layout options.</p>

<h4>Layout Modes</h4>
<ul>
    <li><strong>Square</strong> — uniform 1:1 ratio tiles in a clean grid.</li>
    <li><strong>Cropped</strong> — tiles maintain a constrained aspect ratio (max 3:2 or 2:3).</li>
    <li><strong>Masonry</strong> — full aspect-ratio images in justified rows, similar to
    Flickr or 500px. By default uses pre-generated aspect thumbnails for faster loads;
    toggle <em>Masonry Image Source</em> in Archive Appearance to switch to full-size images.</li>
    <li><strong>Disabled</strong> — removes the Archive View link from the public navigation
    entirely. Direct visits to <code>archive.php</code> redirect to the homepage. Use this
    on single-page or coming-soon installs where you don't want the archive exposed.
    Set in Global Vibe → Archive Display Mode.</li>
</ul>

<h4>Thumbnail Size</h4>
<p>Choose from five sizes: XS, S, M, L, XL. This affects how many images appear per row
at any given viewport width.</p>

<h4>Filtering</h4>
<p>Visitors can filter by category or album using the dropdowns in the archive toolbar.
A search box also appears in the toolbar — type any keyword to search across titles,
descriptions, and tags. Queries starting with <code>#</code> redirect straight to the
hashtag archive for that tag.</p>
<p>When searching, matching tags appear as clickable chips above the results with post
counts, making it easy to discover related content.</p>

<h4>Calendar View</h4>
<p>Skins that include <code>croppedwithcalendar</code> in their archive layouts show a
<strong>Cal</strong> toggle in the layout switcher. Selecting it slides a calendar panel in
from the right. Click any day to browse that date; click a second day to define a date range.
The calendar auto-sizes to fit the viewport height. Select another layout mode to dismiss it.</p>
<p>Date ranges can also be set via URL: <code>archive.php?from=YYYY-MM-DD&amp;to=YYYY-MM-DD</code>.</p>
HTML
];

$help_topics['archive-calendar'] = [
    'section'  => 'Public Features',
    'title'    => 'Archive Calendar',
    'icon'     => '&#x1F4C5;',
    'content'  => <<<'HTML'
<h3>Archive Calendar</h3>
<p>The calendar is an optional archive layout available on skins that declare
<code>croppedwithcalendar</code> in their manifest. It lets visitors browse your posts
by date using a visual month grid.</p>

<h4>Using the Calendar</h4>
<p>Select the <strong>Cal</strong> option in the archive layout switcher. A calendar slides
in from the right, showing as many months as fit the viewport height. Click a single day to
jump to that date's posts. Click a second day to filter a date range — the archive updates
instantly to show only transmissions from that span.</p>
<p>The calendar colour scheme inherits from the active skin's CSS custom properties, so it
always matches your site. To dismiss the calendar, select any other layout mode.</p>

<h4>Date-Range URL</h4>
<p>Date ranges can also be applied directly via URL parameters:</p>
<p><code>archive.php?from=2025-01-01&amp;to=2025-03-31</code></p>
<p>Dates are sanitised and sorted server-side, so the order you pass them doesn't matter.</p>

<h4>Enabling on a Skin</h4>
<p>The Cal layout is only available on skins that have both <code>smack-calendar</code> in
<code>require_scripts</code> and <code>croppedwithcalendar</code> in <code>archive_layouts</code>
in their manifest. If a skin doesn't declare these, the Cal button is suppressed automatically.</p>
HTML
];

$help_topics['tags'] = [
    'section'  => 'Public Features',
    'title'    => 'Tags & Hashtags',
    'icon'     => '&#x0023;',
    'content'  => <<<'HTML'
<h3>Tagging System</h3>
<p>Tags let visitors browse your site by topic. Every transmission can have any number of
tags, and each tag generates a dedicated archive page listing all transmissions that share it.</p>

<h4>Adding Tags</h4>
<p>There are two ways to tag a transmission:</p>
<ul>
    <li><strong>Tags field</strong> — on the New Post and Edit pages, enter space-separated
    hashtags in the Tags field (e.g. <code>#concrete #rust #peeling</code>).</li>
    <li><strong>Inline hashtags</strong> — write <code>#hashtags</code> anywhere in the title
    or description. They are extracted automatically on save.</li>
</ul>
<p>Both methods feed into the same tagging engine. The Tags field simply makes existing tags
visible and easy to edit without digging through the description text.</p>

<h4>Tag Archives</h4>
<p>Each tag gets a public archive page at <code>?tag=slug</code>. Visitors reach these by
clicking a tag on a post, clicking a tag chip in search results, or typing
<code>#tagname</code> in the archive search bar (which redirects to the tag archive).</p>
<p>Tag archives use the same grid layout as the main archive (square, cropped, or masonry
depending on skin settings) with pagination.</p>

<h4>Tag Display</h4>
<p>How tags appear on individual posts depends on the active skin. Skins that support tag
display show clickable tag chips below the image description. Tags link to their archive page.</p>

<h4>Search Integration</h4>
<p>The archive search bar matches against tags in addition to titles and descriptions.
Matching tags appear as clickable chips above the search results with post counts.</p>
HTML
];

$help_topics['downloads'] = [
    'section'  => 'Public Features',
    'title'    => 'Image Downloads',
    'icon'     => '&#x2913;',
    'content'  => <<<'HTML'
<h3>Download System</h3>
<p>SnapSmack can serve original-resolution image files for download. The system uses
HMAC-SHA256 tokens to prevent URL guessing — download links are unique and time-limited.</p>

<h4>Enabling Downloads</h4>
<p>Two switches must both be on:</p>
<ol>
    <li><strong>Global Downloads</strong> — the master switch in Configuration.</li>
    <li><strong>Per-Transmission</strong> — each transmission has its own download toggle
    on the Edit page.</li>
</ol>
<p>Both must be enabled for the download button to appear on a given transmission.</p>

<h4>External Downloads</h4>
<p>Instead of serving the file directly, you can point the download button to an external
URL (Dropbox, Google Drive, etc.) by entering the URL on the Edit page. This is useful
for serving high-resolution files that are too large for your hosting storage.</p>

<h4>Require Download URL</h4>
<p>Sites that use the batch poster with Google Drive can enable <strong>Require Download
Link</strong> in Settings → Downloads. When on, any attempt to publish a post without a
download URL is blocked — both from the manual post form and from the SYBU batch poster.
This ensures no post ever goes live without its Drive original linked.</p>
<p>To enable: Admin → Settings → Downloads → Require Download Link → YES — BLOCK PUBLISH IF MISSING.</p>
HTML
];

$help_topics['rss-feed'] = [
    'section'  => 'Public Features',
    'title'    => 'RSS Feed',
    'icon'     => '&#x25C9;',
    'content'  => <<<'HTML'
<h3>RSS Feed</h3>
<p>Your site publishes an RSS 2.0 feed at <code>rss.php</code> containing the 20 most
recent published transmissions. The feed includes the image in each entry's description,
so feed readers can display thumbnails.</p>

<h4>Feed URL</h4>
<p>Share your feed URL with visitors or submit it to feed directories:
<code>https://yoursite.com/rss.php</code></p>

<h4>Blogroll Integration</h4>
<p>When other SnapSmack sites add your feed to their blogroll, the RSS fetcher tracks
your latest publication date, showing your peers when you last posted.</p>
HTML
];

// ── CRON & AUTOMATION ────────────────────────────────────────────────────

$help_topics['cron'] = [
    'section'  => 'System',
    'title'    => 'Scheduled Tasks (Cron)',
    'icon'     => '&#x23F0;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Cron Jobs &amp; Scheduled Tasks</h3>
<p>SnapSmack uses cron jobs for tasks that need to run periodically without manual
intervention. Cron requires a Linux/Unix server with <code>crontab</code> access
(available on most shared hosting plans).</p>

<h4>Available Cron Jobs</h4>
<ul>
    <li><strong>RSS Feed Fetcher</strong> — checks your blogroll peers' feeds hourly for
    new content. Register from the Dashboard.</li>
    <li><strong>Version Checker</strong> — checks for core updates and new skins every
    6 hours. Register from the System Updates page.</li>
</ul>

<h4>Registering a Cron Job</h4>
<p>Click the "Register" button on the Dashboard (for RSS) or System Updates page (for
version checking). The system detects your PHP CLI path and registers the job automatically.</p>

<h4>Fallback Behaviour</h4>
<p>If cron is not available or not registered, SnapSmack falls back to checking on page
load: the dashboard triggers an update check if the last one was more than 24 hours ago.
This is less reliable than cron but ensures the system still functions on hosting without
cron support.</p>

<h4>Manual Removal</h4>
<p>Click "Remove" next to a registered job to unregister it. This removes the crontab
entry but does not delete the PHP script.</p>
HTML
];

// ── SMACK YOUR BATCH UP ──────────────────────────────────────────────────

$help_topics['smackyourbatchup'] = [
    'section'  => 'The Good Shit',
    'title'    => 'Smack Your Batch Up',
    'icon'     => '&#x25B6;',
    'content'  => <<<'HTML'
<h3>Smack Your Batch Up</h3>
<p>Smack Your Batch Up is a standalone Windows desktop app for bulk-posting images
to SnapSmack. It connects to your site, pulls your categories and albums, and lets
you queue up dozens of images for posting in a single batch.</p>

<h4>Getting Started</h4>
<p>Download the tool from the Companion Tools page in the admin panel or from
<code>https://snapsmack.ca/tools/smackyourbatchup.zip</code>. Extract and run the
.exe — no installation required. On first launch, enter your SnapSmack site URL and
admin credentials. The app connects to your site and loads your categories and albums.</p>

<h4>Loading Images</h4>
<p>Load images in two ways: point to a folder of images, or load a manifest file. A
manifest is a text file that pairs image filenames with titles, descriptions, tags,
categories, and albums — useful for pre-planned batch uploads. You can load multiple
manifests and they accumulate in the queue.</p>

<h4>Queue Management</h4>
<p>Each image appears as a row with a thumbnail preview. You can drag rows to reorder
them, change the category or album per row, and edit titles and tags inline. The queue
shows everything that will be posted before you commit.</p>

<h4>EXIF &amp; Copyright</h4>
<p>The app automatically embeds EXIF copyright metadata into every image using piexif
(pure Python — no external tools needed). Copyright text, artist name, image
description, and tags are all written into the EXIF data before upload. This metadata
survives on the server because SnapSmack preserves EXIF through its image processing
pipeline.</p>

<h4>Downloads &amp; Cloud Storage (Optional)</h4>
<p>The batch poster works perfectly fine without any cloud storage. If you just want to
post images, skip this section entirely — leave the Google Drive fields blank and the
app ignores them. Downloads will be disabled on those posts.</p>

<p>If you <em>do</em> want a download button on your posts linking to full-resolution
originals, SnapSmack supports any public URL — Google Drive, OneDrive, Dropbox, or any
direct link. The batch poster automates this for Google Drive specifically. For other
services, upload your originals manually and paste the share link into the Download URL
field on the New Post or Edit Transmission page.</p>

<h4>Setting Up Google Drive (Optional)</h4>
<p>If you want the batch poster to automatically upload originals to Google Drive and
attach the share link to each post:</p>
<ol>
    <li>Go to <code>console.cloud.google.com</code> and create a project (or use an existing one).</li>
    <li>Enable the <strong>Google Drive API</strong> for that project (APIs &amp; Services → Library → search "Drive").</li>
    <li>Go to APIs &amp; Services → Credentials → Create Credentials → <strong>OAuth client ID</strong>.</li>
    <li>Application type: <strong>Desktop app</strong>. Name it anything you want.</li>
    <li>Download the resulting <code>credentials.json</code> file and save it somewhere permanent on your machine.</li>
    <li>In Smack Your Batch Up, click the credentials file picker and select your <code>credentials.json</code>.</li>
    <li>Enter the Google Drive folder ID where you want originals stored. The folder ID is the last segment
        of the folder URL — e.g. in <code>drive.google.com/drive/folders/1aBcDeFgHiJkLmN</code>, the ID is
        <code>1aBcDeFgHiJkLmN</code>. Make sure this folder is set to "Anyone with the link can view" so
        download links work for visitors.</li>
    <li>Click <strong>Auth Drive</strong>. A browser window opens for Google sign-in. Approve access.</li>
    <li>The status dot turns green and shows "Authenticated". A <code>token.json</code> file is saved next
        to the exe — subsequent launches reconnect automatically without re-auth.</li>
</ol>
<p>Once connected, every image in the batch gets its original uploaded to Drive with EXIF
metadata embedded, and the public share link is attached to the post automatically. If
you later want to stop using Drive, just clear the credentials and folder ID fields —
the app goes back to posting without downloads.</p>

<h4>Admin Theme Sync</h4>
<p>On connect, the app pulls your active admin colour scheme and applies it to the
desktop UI. If you switch admin themes, reconnect and the app updates to match.</p>

<h4>Building From Source</h4>
<p>Source lives in <code>tools/ft-batch-poster/</code>. Run <code>build.bat</code> to
compile with PyInstaller. The build reads the version from <code>main.py</code> and
outputs a versioned exe to <code>C:\tools\</code>. Requires Python 3.11+ and
<code>pip install -r requirements.txt</code>.</p>
HTML
];

// ── WHAT SNAPSMACK IS NOT ─────────────────────────────────────────────────

$help_topics['what-snapsmack-is-not'] = [
    'section'  => 'System',
    'title'    => 'What SnapSmack Is Not',
    'icon'     => '&#x2717;',
    'content'  => <<<'HTML'
<h3>What SnapSmack Is Not</h3>
<p>SnapSmack is a photoblogging platform. It is not a studio management system, a client
portal, or a commercial photography business tool.</p>

<p>It will never include password-protected client galleries, booking systems, calendar
integration, proofing workflows, watermarking, print ordering, or e-commerce. Photographers
who need those features should use Pixieset, HoneyBook, or similar dedicated tools.</p>

<p>SnapSmack is not professionally supported software. It is a personal project maintained
by one person. It works because it has to work for the person who built it. There is no
support contract, no SLA, no guaranteed response time.</p>

<p>SnapSmack is GPL v3. The source is yours. Fork it, modify it, build the commercial
studio tool you need. We won't help you build it but we won't stop you either.</p>

<p>This software was built by a photographer for photographers who want to own their work
and their platform. If that's you, welcome.</p>

<p>SnapSmack was built with a wish and a good AI tool (Claude AI) by someone who is not a
programmer. What didn't stop him won't stop you if you need this and you're determined
enough. You've even been given a head start. Good luck.</p>
HTML
];

// ── INSTALLER ────────────────────────────────────────────────────────────

$help_topics['installer'] = [
    'section'  => 'System',
    'title'    => 'Installer',
    'icon'     => '&#x2316;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>First-Run Installer</h3>
<p>The installer (<code>install.php</code>) guides you through setting up a fresh
SnapSmack installation. It runs once and self-deletes on completion.</p>

<h4>Steps</h4>
<ol>
    <li><strong>Environment Check</strong> — verifies PHP version (8.0+), required extensions
    (PDO, GD, libsodium), and directory write permissions.</li>
    <li><strong>Database Setup</strong> — enter your MySQL hostname, database name, username,
    and password. The installer tests the connection immediately.</li>
    <li><strong>Schema Creation</strong> — creates all database tables automatically.</li>
    <li><strong>Admin Account</strong> — create your first admin user. Password must be at
    least 12 characters.</li>
    <li><strong>Site Configuration</strong> — set your site name and URL.</li>
    <li><strong>Completion</strong> — the installer generates configuration files, creates
    upload directories, writes .htaccess security rules, and then self-deletes.</li>
</ol>

<h4>Safety</h4>
<p>The installer refuses to run if SnapSmack is already installed (it checks for existing
settings in the database). It also rate-limits database connection attempts to prevent
brute-force credential testing.</p>
HTML
];

// =========================================================================
//  DESKTOP TOOLS
// =========================================================================

$help_topics['smack-your-batch-up'] = [
    'section'  => 'Desktop Tools',
    'title'    => 'Smack Your Batch Up',
    'icon'     => '&#x25B6;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Smack Your Batch Up</h3>
<p>Smack Your Batch Up (SYBU) is a standalone Windows desktop application for posting large
batches of images to SnapSmack. It handles EXIF embedding, Gemini AI metadata enrichment,
Google Drive upload, and bulk posting in one workflow — without touching the web interface.</p>

<h4>Installation</h4>
<p>The application lives at <code>C:\SmackYourBatchUp\</code>. That folder contains:</p>
<ul>
    <li><strong>smackyourbatchup-{version}.exe</strong> — the application</li>
    <li><strong>config.ini</strong> — saved settings (site URL, username, folder paths, Gemini key,
    Drive folder ID). Created automatically on first use.</li>
    <li><strong>gemini_prompts.json</strong> — your saved AI prompt presets.</li>
    <li><strong>credentials.json</strong> — your Google OAuth client file (downloaded separately
    from Google Cloud Console; see Google Drive section below).</li>
    <li><strong>token.json</strong> — written automatically after first Drive auth. Do not delete —
    it allows silent reconnection on every subsequent launch.</li>
</ul>
<p>To rebuild from source, run <code>tools\ft-batch-poster\build.bat</code>. The compiled exe
is deployed to <code>C:\SmackYourBatchUp\</code> automatically.</p>

<h4>The Status Bar</h4>
<p>Three panels run across the top of the window in a dot-matrix display. Read these before
doing anything else — they tell you whether everything is connected and how long your session
has left.</p>
<ul>
    <li><strong>SITE CONNECTION</strong> — green means you are logged in. The countdown shows
    the 48-minute PHP session window. SYBU pings the server every 10 minutes to keep the session
    alive and resets the countdown automatically, so it should never reach zero while the app is
    open. If you lose network and it expires, click Connect again — your queue is not lost.</li>
    <li><strong>CLOUD DRIVE</strong> — green means Google Drive is authenticated and download
    links will be attached to every post. If this is red when you post, images will be uploaded
    to SnapSmack but will not have download links. Use Fix Your Batch Up to recover those links.</li>
    <li><strong>AI ENGINE</strong> — green means a Gemini API key is saved. Without a key
    the Enrich button will prompt you to add one in the configuration panel.</li>
</ul>

<h4>Basic Workflow</h4>
<ol>
    <li><strong>Connect</strong> — enter your site URL, username, and password, then click
    Connect. SYBU logs in and loads your categories and albums. Credentials are saved and
    reconnection happens automatically on the next launch.</li>
    <li><strong>Set image folder</strong> — click the <code>…</code> button next to Image Folder
    and choose the folder containing the images you want to post.</li>
    <li><strong>Scan Folder</strong> — click Scan Folder to load every JPG, PNG, and WebP in
    that folder into the queue. Default category, album, and orientation (set in the Manifest
    &amp; Defaults panel) are applied to all rows.</li>
    <li><strong>Enrich with Gemini</strong> — click this button to send each image to Gemini AI.
    Gemini examines the photo and fills in a title, tags, category, and album for each row.
    Rows that already have a title are skipped automatically.</li>
    <li><strong>Review the queue</strong> — collapse the configuration panel to see the queue.
    Click any field in a row to edit it directly. Drag rows to reorder them.</li>
    <li><strong>Post Batch</strong> — click Post Batch to upload and publish everything.
    Progress is shown row by row; failed rows stay red and can be retried.</li>
</ol>

<h4>Gemini AI Enrichment</h4>
<p>Gemini looks at each image and returns a haiku-style title, 5–8 descriptive hashtags,
and picks the best matching category and album from your site's actual lists.</p>
<p>Configure it in the <em>Gemini AI (Optional)</em> section of the configuration panel:</p>
<ul>
    <li><strong>API Key</strong> — paste your Gemini API key and click Test Connection to verify.</li>
    <li><strong>Prompt</strong> — leave blank to use the built-in default, or write your own to
    control the style, tone, or output format. Click Save As… to name and store a prompt preset
    for reuse.</li>
</ul>
<p>Your API key and last-used prompt are saved in <code>config.ini</code>. Named presets are
stored in <code>gemini_prompts.json</code>.</p>

<h4>Load Manifest (Advanced)</h4>
<p>A manifest is a plain text file that lists images with optional pre-filled metadata. Use
Load Manifest instead of Scan Folder if you have already prepared one. Each image block looks
like this:</p>
<pre><code>FILE: photo.jpg
TITLE: Dark stone holds the rain
TAGS: #stone #texture #macro #urban
CATEGORY: Feeling Knotty
ALBUM: Morning Wood</code></pre>
<p>Blank fields are filled in from the defaults. You can run Gemini enrichment after loading a
manifest to fill in any missing titles or tags.</p>

<h4>Google Drive</h4>
<p>Drive support attaches a permanent public download link to every post so visitors can
download the original-quality file. It requires a one-time OAuth setup:</p>
<ol>
    <li>Go to Google Cloud Console → APIs &amp; Services → Credentials.</li>
    <li>Create an OAuth 2.0 client ID (Desktop application type) and download the JSON file.</li>
    <li>In SYBU, point the Credentials File field at that JSON file.</li>
    <li>Enter the ID from the end of your Google Drive folder URL in the Folder ID field.</li>
    <li>Click Auth Drive — a browser tab opens for consent. After approval, <code>token.json</code>
    is written and Drive reconnects silently from then on.</li>
</ol>
<p><strong>Do not delete <code>token.json</code></strong> — it is what keeps Drive connected
without re-authorising every session.</p>

<h4>What Gets Saved to the Database</h4>
<p>For every image posted via SYBU, SnapSmack stores the title, tags, category, album,
orientation, the Google Drive download URL, and the original source filename. The source
filename is particularly useful for recovery — if Drive links are ever lost,
Fix Your Batch Up can re-match originals to server copies using visual feature matching.</p>

<h4>Keeping Originals</h4>
<p>Do not delete your source image files after posting. The copy on the server is
web-optimised and resized. The original in your folder is what gets uploaded to Drive.
If Drive was not connected when images were posted, use the <strong>Repair tab</strong>
in Smack Your Batch Up to backfill missing Drive links.</p>
HTML
];

$help_topics['sybu-repair'] = [
    'section'  => 'Desktop Tools',
    'title'    => 'SYBU — Repair Tab',
    'icon'     => '&#x2692;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Smack Your Batch Up — Repair Tab</h3>
<p>The Repair tab fixes three categories of data quality issues in bulk. Pull audit data
first on the Audit tab, then switch to Repair to run any of the three actions independently.</p>

<h4>1. Rename Drive Files to {id}.jpg</h4>
<p>Early versions of Smack Your Batch Up named Drive files after the image's haiku title.
Because Gemini could generate duplicate titles, this caused filename collisions in Drive
that silently corrupted the source map. This action renames every Drive file to its numeric
post ID (e.g. <code>1042.jpg</code>). Drive share URLs are file-ID-based and are not affected
by the rename — no blog links will break. Processes at a rate-limited 150ms per file to stay
within Drive API quotas. Stop and resume at any time.</p>

<h4>2. Re-enrich Duplicate Titles</h4>
<p>Finds all posts that share a title with at least one other post, downloads the original
image from Drive, sends it to Gemini, and writes a new unique haiku title back to the blog.
Retries up to four times per image to guarantee uniqueness against both the live title list
and titles already generated in the current repair run. Rate-limited at 500ms between Gemini
calls. Marks the audit as stale on completion so the next Audit pull reflects the changes.</p>

<h4>3. Backfill Missing Drive Links</h4>
<p>Lists every published post without a download URL. For each one, paste the Google Drive
share link and click Save — the link is written to the database immediately and
<code>allow_download</code> is enabled. Populated automatically from the most recent Audit
pull. No batch operation here: each link requires a manual paste because the originals must
be located and uploaded by hand.</p>

<h4>Requirements</h4>
<ul>
    <li><strong>Audit data</strong> — pull the audit on the Audit tab before running any repair.</li>
    <li><strong>Drive auth</strong> — required for Rename and Re-enrich (not needed for Backfill).</li>
    <li><strong>Gemini key</strong> — required for Re-enrich only.</li>
    <li><strong>Site connection</strong> — required for all three actions.</li>
</ul>
HTML
];

// ── SMACK UP YOUR BACKUP ─────────────────────────────────────────────────

$help_topics['smack-up-your-backup'] = [
    'section'  => 'Desktop Tools',
    'title'    => 'Smack Up Your Backup',
    'icon'     => '&#x25BC;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Smack Up Your Backup</h3>
<p>Smack Up Your Backup (SUYB) is a standalone Windows/Linux desktop app that performs
complete, verifiable backups of your SnapSmack blog. It downloads your recovery kit,
SQL database exports, and all media files via FTP, packages everything into a dated
ZIP, and optionally uploads it to Google Drive or OneDrive.</p>

<h4>What It Backs Up</h4>
<ul>
    <li><strong>Recovery kit</strong> — a .tar.gz archive containing the manifest (a
    complete inventory of every media file with paths, sizes, and SHA-256 checksums),
    branding assets, skin files, and a bundled database export.</li>
    <li><strong>SQL dumps</strong> — full database export and schema-only export.</li>
    <li><strong>Media files</strong> — every image and asset tracked in the manifest,
    downloaded via FTP. Differential mode skips files that haven't changed since the
    last backup (verified by checksum).</li>
</ul>

<h4>Setting Up</h4>
<p>Download SUYB from the <a href="smack-tools.php">Companion Tools</a> page. On first
launch the setup wizard walks you through creating a profile: site URL, admin credentials
(same ones you use to log into this panel), FTP connection details, and a local folder
for backup storage.</p>
<p>Use the <strong>Test Login</strong> and <strong>Test FTP</strong> buttons in the app's
Settings tab to verify your credentials before running a backup.</p>

<h4>Running a Backup</h4>
<p>Select a blog profile from the dropdown, choose Differential (fast — skips unchanged
files) or Full (re-downloads everything), and click <strong>START BACKUP</strong>. The log
shows every stage in real time. Each downloaded file's SHA-256 is verified against the
manifest — a mismatch triggers an automatic retry.</p>

<h4>Crash Recovery</h4>
<p>If a backup is interrupted mid-run (power cut, Windows Update reboot), SUYB writes
a checkpoint file after every downloaded file using an atomic rename so even a power
cut during the write itself can't corrupt it. The next time you click Start Backup,
SUYB detects the checkpoint and offers to resume from where it stopped — no need to
re-download files already verified.</p>

<h4>Cloud Upload</h4>
<p>SUYB supports Google Drive (service account key or OAuth) and OneDrive (MSAL). Configure
your cloud credentials in Settings → Global Cloud Config. After a successful backup the ZIP
is uploaded automatically and the cloud state index is updated so SUYB can browse and restore
from cloud directly.</p>

<h4>Scheduled Backups</h4>
<p>Each profile can have its own backup schedule — daily or weekly at a configured time.
Enable "Minimize to system tray instead of closing" and "Launch SUYB when Windows starts"
in Settings so it runs in the background without any manual intervention.</p>

<h4>Oh Snap! API Keys</h4>
<p>SUYB uses the standard SnapSmack admin login for authentication — no API key is needed.
The <strong>Oh Snap! API Keys</strong> page (Boring Ass Stuff → Oh Snap! API Keys) is for
the Oh Snap skin designer desktop app, not for SUYB.</p>

<h4>Restoring From Backup</h4>
<p>Open the Restore tab in SUYB, select your backup ZIP (local or from cloud), and click
Restore. Before uploading each file SUYB verifies its checksum against the manifest — a
corrupt local file is rejected rather than uploaded to overwrite a good server copy.</p>

<h4>Audit Mode</h4>
<p>The Audit tab compares the manifest against your live server filesystem via FTP,
identifying missing files, orphaned files not tracked by the CMS, size mismatches, and
files found in the wrong location. Save the report as HTML for your records.</p>
HTML
];

// ── FLKR FCKR ────────────────────────────────────────────────────────────

$help_topics['flkr-fckr'] = [
    'section'  => 'Desktop Tools',
    'title'    => 'FLKR FCKR — Flickr Import',
    'icon'     => '&#x1F4F8;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>FLKR FCKR — Flickr Import Tool</h3>
<p>FLKR FCKR is a standalone Windows desktop tool that migrates your Flickr photo archive
into SnapSmack. It runs entirely on your computer — not on your server — because server-side
import of a large Flickr archive is not practical. A collection of a few thousand photos
involves gigabytes of image data, hours of processing time, and hundreds of individual API
calls. Running that inside a PHP request on a shared host would time out, exhaust memory,
and leave your database in a half-imported state with no way to resume. FLKR FCKR handles
all the heavy work locally: image resizing and thumbnail generation happen on your machine,
files are sent to your server via FTP one at a time at a throttled rate you control, and
database records are created via API only after each file is safely on the server.
A crash-recovery checkpoint means an interrupted import can be resumed exactly where it
stopped — no re-importing, no duplicates.</p>

<h4>What It Does</h4>
<ul>
    <li>Parses your Flickr data export (albums.json + per-photo JSON sidecars)</li>
    <li>Imports your albums, tags, titles, descriptions, dates, and GPS coordinates</li>
    <li>Resizes images to SnapSmack's web-optimised dimensions, generates square and
    aspect-ratio thumbnails</li>
    <li>Uploads all three versions (main image, square thumb, aspect thumb) via FTP to
    your server's media directory</li>
    <li>Creates the image record and album mappings in your database via the FLKR FCKR API</li>
    <li>Maps Flickr's privacy settings — private photos import as drafts by default
    (configurable)</li>
    <li>Detects duplicates via the Flickr photo ID so re-running the tool is always safe</li>
</ul>

<h4>Getting Started</h4>
<ol>
    <li><strong>Download your Flickr export</strong> — in Flickr, go to Account Settings →
    Your Flickr Data → Request your archive. You will receive a download link by email.
    Unzip the archive to a folder on your computer before running FLKR FCKR.</li>
    <li><strong>Generate an API key</strong> — go to
    <strong>Admin &rarr; Boring Ass Stuff &rarr; API Keys</strong> and generate a new key
    with type <em>FLKR FCKR Import</em>. Copy it — it is shown only once. You can revoke
    it when the import is complete.</li>
    <li><strong>Enter your credentials in FLKR FCKR</strong> — paste your site URL and API
    key, then enter your FTP credentials and the remote base path (the same path your images
    normally live under on the server).</li>
    <li><strong>Select the export folder</strong> — point the tool at the folder you unzipped
    from Flickr. Click <strong>Load Export</strong> to parse the metadata.</li>
    <li><strong>Review the photo grid</strong> — every photo appears as a thumbnail tile.
    Click any tile to exclude it from the import. The album sidebar lets you filter by
    album or see unalbumed photos separately.</li>
    <li><strong>Set your throttle</strong> — the default 1.5 seconds between posts is
    conservative and safe for shared hosting. Increase it if your host is slow; decrease
    it carefully if you are on a fast VPS.</li>
    <li><strong>Start Import</strong> — click the button and let it run. You can pause and
    resume at any time. If it is interrupted, FLKR FCKR offers to resume on the next
    launch.</li>
</ol>

<h4>Privacy Handling</h4>
<p>Photos marked as private or friends-only in Flickr import as <strong>drafts</strong> by
default — they land in your database but are not publicly visible. Change the
<em>Private →</em> dropdown to <strong>published</strong> if you want everything to come in
live regardless of its Flickr privacy setting.</p>

<h4>After the Import</h4>
<p>Once the import is complete, revoke the FLKR FCKR API key from the API Keys page — you
will not need it again. If any photos failed (usually due to a dropped FTP connection), you
can re-run FLKR FCKR against the same export folder with the same settings. Duplicate
detection via Flickr photo ID means already-imported photos are skipped instantly.</p>

<h4>Why Not Just Upload a ZIP?</h4>
<p>A ZIP upload might work for 50 photos. For 500 it will probably time out. For 5,000 it
is not a realistic option on any normal hosting setup. FLKR FCKR was built specifically
because there is no server-side solution that scales to a real Flickr archive. The desktop
tool approach also gives you live progress, pause/resume control, and the ability to exclude
specific photos or albums before the import starts.</p>
HTML
];

// ── MULTISITE MANAGEMENT ─────────────────────────────────────────────────

$help_topics['multisite'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Multisite Management',
    'icon'     => '&#x29BF;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Multisite Management</h3>
<p>Multisite lets you run a fleet of SnapSmack installations from a single hub. One site
acts as the hub; every other site connects as a spoke. Once connected, the hub can
monitor all spokes, push content to them, and moderate their comments — all from
one admin panel.</p>

<h4>Getting Started</h4>
<p>Open <strong>Multisite Management</strong> in the sidebar. Choose whether this install
is the <strong>Hub</strong> (the command centre) or a <strong>Spoke</strong> (a spoke
site that reports back to the hub).</p>

<h4>Connecting a Spoke</h4>
<ol>
    <li>On the <strong>spoke</strong>, click <em>Enable as Spoke</em>, then
    <em>Generate Registration Token</em>. A 32-character token appears, valid for
    15 minutes.</li>
    <li>On the <strong>hub</strong>, click <em>Register Spoke</em>. Paste the
    spoke's URL, its registration token, and give it a friendly name.</li>
    <li>The hub performs an API handshake — if successful the spoke appears in
    your fleet list with an active status.</li>
</ol>

<h4>Hub Features</h4>
<ul>
    <li><strong>Spoke Signals</strong> — unified moderation queue for pending comments
    across every spoke. Approve, trash, or reply without leaving the hub.</li>
    <li><strong>Spoke Posts</strong> — aggregated post feed from all spokes so you
    can see what's been published across the fleet.</li>
    <li><strong>Cross-Post</strong> — push an image from the hub to one or more spokes
    in a single action.</li>
    <li><strong>Fleet Stats</strong> — traffic statistics rolled up from every spoke:
    fleet-wide totals, bot traffic visibility, daily sparkline, cross-fleet most-viewed
    images, per-site breakdown with top image thumbnails, and top referrers. Selectable
    time windows from 7 days to all time.</li>
    <li><strong>Backup Dock</strong> — backup health overview for every site in the fleet.
    See last backup date, size, and any sites that are overdue.</li>
    <li><strong>Blogroll Sync</strong> — keep blogrolls in sync across the fleet with push
    or pull modes.</li>
    <li><strong>SSO Drill-Through</strong> — log in to the hub once, then click through to
    any spoke without re-authenticating. The <em>Remote Login</em> link next to each spoke
    in the fleet list generates a single-use session token and redirects your browser
    directly to that spoke's admin panel.</li>
    <li><strong>Hub Update Push</strong> — each out-of-date spoke shows an UPDATE button
    in the fleet list. The hub instructs the spoke to pull the latest release package,
    verify its Ed25519 signature and SHA-256 checksum, apply pending migrations, and
    report back. <em>UPDATE ALL BEHIND</em> does the whole fleet in sequence with
    one click. When all spokes are current the button becomes a greyed <em>ALL UP
    TO DATE</em> indicator.</li>
    <li><strong>Push It (Push It Real Good)</strong> — fleet settings control lives on its own
    page (Multisite → Push It). For each setting group — timezone, Akismet, AI provider and
    keys, SMACKBACK, global comments, contact email — you can toggle <em>Hub Controls This
    Setting</em>. When on, the hub owns that setting fleet-wide: spokes receive the value and
    their corresponding settings UI locks to read-only with a "Managed by Network Hub" notice.
    When off, spokes manage their own value. Push any group on demand with its own button, or
    push everything hub-controlled at once with <em>PUSH IT ALL</em>. Download settings remain
    on the Settings page and can be targeted to a custom subset of spokes.</li>
</ul>

<h4>Heartbeat Monitoring</h4>
<p>The hub pings each spoke on a regular schedule. If a spoke goes unresponsive, its
status changes to <em>unreachable</em> so you can investigate. Heartbeat data includes
software version, PHP version, disk usage, maintenance mode state, and SMACKBACK status
— giving you a quick health check for the entire fleet.</p>
<p>The fleet table also shows a <strong>TRACK</strong> badge per spoke: <strong>BORING</strong>
(grey, stable-track) or <strong>BITCHIN'</strong> (amber, dev-track). This is reported
by the spoke at heartbeat time and reflects the spoke's own update track setting. The hub
does not control which track its spokes run on; each spoke sets its own track in its own
Configuration page. The hub roster shows it read-only for visibility.</p>

<h4>API Communication</h4>
<p>Hub and spoke communicate through a JSON API at <code>api.php?route=multisite/*</code>.
All requests are authenticated with per-node API keys exchanged during the initial handshake.
Communication uses HTTPS and keys are stored server-side — nothing is exposed to visitors.</p>
HTML
];

$help_topics['fleet-stats'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Fleet Stats Rollup',
    'icon'     => '&#x1F4CA;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Fleet Stats Rollup</h3>
<p>Fleet Stats is the multisite hub's traffic intelligence page. It aggregates statistics
from every connected spoke and the hub itself into a single view — so you can see how
your entire network is performing without logging into each site individually.</p>

<h4>Time Windows</h4>
<p>Seven time windows are available via the nav tabs at the top of the page:
<strong>7D</strong>, <strong>30D</strong> (default), <strong>90D</strong>,
<strong>6M</strong>, <strong>1YR</strong>, and <strong>ALL</strong> (all-time, no
date filter). Each spoke is queried with the same window, and the hub's own stats
are pulled locally using the same range. The current window is shown as a badge
in the top right corner of the page.</p>

<h4>Fleet Totals</h4>
<p>Six summary tiles at the top give you the network at a glance for the selected period:</p>
<ul>
    <li><strong>Total Views</strong> — every page view recorded across the fleet, excluding bots.</li>
    <li><strong>Unique Visitors</strong> — distinct visitor count, summed across all sites.
    Visitors who visit multiple sites in your fleet count once per site.</li>
    <li><strong>Sites Reporting</strong> — how many sites returned data vs. total sites. A site
    that is offline or unreachable shows in the error notice and counts as not reporting.</li>
    <li><strong>Bot Views</strong> — views flagged as automated (crawlers, scanners, monitoring
    pings). The percentage shows bots as a proportion of total traffic including bots.</li>
    <li><strong>Avg Views / Day</strong> — total fleet views divided by the number of non-zero
    days in the period. Zero-traffic days (e.g. the site was offline) are excluded from the
    denominator so an outage doesn't drag the average down.</li>
    <li><strong>Peak Day</strong> — the single calendar date with the highest combined fleet
    traffic in the selected window, and the view count for that day.</li>
</ul>

<h4>Daily Traffic Sparkline</h4>
<p>The bar chart below the tiles shows fleet-wide traffic by day across the selected period.
Hover over any bar for the exact date, view count, and unique visitor count. The chart
uses all sites' daily aggregates merged by date — spokes that do not have stats for a given
date simply contribute zero for that day.</p>

<h4>Fleet Top Images</h4>
<p>The <strong>Most Viewed — Fleet Wide</strong> panel shows the top twelve most-viewed
images across your entire network for the selected period, sorted by view count. Each card
shows the thumbnail (linked to the live image page), the image title, which site it belongs
to, and its view count. Bot traffic is excluded. Only images that have been directly viewed
on their own page are counted — archive page views do not attribute to individual images.</p>
<p>This data comes from the raw per-hit stats table on each spoke, not the daily pre-aggregated
table, so it reflects actual views rather than rolled-up estimates. If a spoke has not yet
accumulated any single-image traffic, it will not contribute to this panel.</p>

<h4>Network Breakdown</h4>
<p>The per-site table ranks every site in the fleet by total views. Each row shows:</p>
<ul>
    <li><strong>Views</strong> and <strong>Unique Visitors</strong> for the period.</li>
    <li><strong>Avg/Day</strong> — views divided by the period length in days.</li>
    <li><strong>Bots</strong> — bot traffic as a percentage of that site's total traffic
    (human + bot). A high bot percentage may indicate a crawler or scan event.</li>
    <li><strong>Top Image</strong> — the single most-viewed image on that site for the
    period, with a small thumbnail and title linking to the live page.</li>
    <li><strong>Share</strong> — that site's proportion of total fleet traffic, shown as
    a bar and percentage.</li>
</ul>
<p>The hub's own stats appear in the table labelled <em>(Hub)</em> with a LOCAL badge,
since the hub's data is queried directly from its own database rather than via the API.</p>

<h4>Top Referrers</h4>
<p>The referrers panel aggregates referring domain names across all sites for the selected
period. The count reflects the number of days on which each referrer appeared as the top
referrer for any site — it is a relative rank, not an absolute click count. Direct and
unknown traffic appears as <em>Direct / Unknown</em>.</p>

<h4>How Data Is Collected</h4>
<p>The hub makes one API call per active spoke when the page loads, passing the selected
time window. Each spoke queries its own <code>snap_stats_daily</code> table (for the
sparkline and totals) and its raw <code>snap_stats</code> hit log (for top images and bot
counts), then returns the results to the hub. The hub merges all responses and renders the
page. Offline or slow-to-respond spokes time out after 10 seconds and are listed in the
error notice at the top of the page — their data is absent from fleet totals for that visit.</p>
<p>Fleet stats data is not cached between page loads. Each visit fetches fresh data from
every spoke. On large fleets or slow connections this page may take a few seconds to load.</p>
HTML
];

$help_topics['hub-update-push'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Hub Update Push',
    'icon'     => '&#x2B06;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Hub Update Push</h3>
<p>When the hub is running a newer version of SnapSmack than one or more spokes, an
<strong>UPDATE</strong> button appears next to each out-of-date spoke in the fleet list.
The <strong>UPDATE ALL BEHIND</strong> button at the top of the list handles the whole
fleet in sequence with one click. When every spoke is current, the button becomes a greyed
<em>ALL UP TO DATE</em> indicator.</p>

<h4>What Happens When You Click UPDATE</h4>
<p>The hub sends an authenticated instruction to the spoke's API. The spoke then:</p>
<ol>
    <li>Downloads the latest release package from the SnapSmack release server.</li>
    <li>Verifies the Ed25519 cryptographic signature and SHA-256 checksum before extracting
    a single file. If either check fails the package is discarded and the update aborts.</li>
    <li>Acquires a maintenance lock so visitors see a brief maintenance notice rather than
    a broken page during extraction.</li>
    <li>Extracts the package to the web root, overwriting only tracked files.</li>
    <li>Applies any pending database migrations in order. Each migration is idempotent —
    running it twice is safe.</li>
    <li>Releases the maintenance lock and reports the result back to the hub.</li>
</ol>
<p>The row in the fleet list updates in real time: a spinner while the update runs, a green
tick and the new version number on success, a red cross with the error message on failure.
No SSH, no FTP, no logging into each site individually.</p>

<h4>Version Comparison</h4>
<p>The hub uses semantic version comparison (not string equality) when deciding which spokes
are "behind." A spoke that has been manually updated to a version <em>ahead</em> of the hub
does not show as behind — only spokes running an older version are flagged. UPDATE ALL BEHIND
targets only those older spokes.</p>

<h4>If an Update Fails</h4>
<p>The error message returned by the spoke is shown in the fleet row. Common causes:</p>
<ul>
    <li>The spoke cannot reach the release server (network/firewall issue).</li>
    <li>Signature or checksum mismatch — the downloaded file is corrupt or was tampered with.
    This should never happen with a legitimate release and is a serious warning sign if it does.</li>
    <li>Disk space exhausted on the spoke's server.</li>
    <li>Insufficient file permissions on the spoke's web root.</li>
</ul>
<p>After resolving the underlying issue, click UPDATE again for the affected spoke. You can
also log into that spoke's admin directly and run the update there via the standard in-admin
updater (Admin → Updates).</p>
HTML
];

$help_topics['remote-login-sso'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Remote Login (Hub SSO)',
    'icon'     => '&#x1F511;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>Remote Login — Single Sign-On to Spokes</h3>
<p>The <strong>Remote Login</strong> link in the fleet list lets you drill through from
the hub to any spoke's admin panel without entering credentials on the spoke. You log in
once to the hub; the hub handles authentication to the spoke on your behalf.</p>

<h4>How It Works</h4>
<p>When you click Remote Login:</p>
<ol>
    <li>The hub generates a short-lived single-use session token and sends it to the spoke
    via an authenticated API call.</li>
    <li>The hub redirects your browser to the spoke's login handler, passing the token as
    a URL parameter.</li>
    <li>The spoke validates the token — it must exist in the spoke's database, must not
    have been used before, and must not have expired.</li>
    <li>If valid, the spoke creates an authenticated admin session for your browser and
    lands you on the spoke's dashboard.</li>
</ol>
<p>The token is single-use: once your browser presents it to the spoke, it is immediately
invalidated. It cannot be reused even if someone intercepts the redirect URL. Tokens expire
after a short period (regardless of whether they are used) so an unclicked link does not
remain valid indefinitely.</p>

<h4>What You Can Do Once Logged In</h4>
<p>Remote Login gives you a full admin session on the spoke — the same as logging in
directly. You can post, manage images, change settings, run updates, review comments, and
do everything else a spoke admin can do. The session is tied to your browser and persists
until you log out or the session expires normally.</p>

<h4>Requirements</h4>
<ul>
    <li>The hub and spoke must be connected (active status in the fleet list).</li>
    <li>The spoke must be reachable — if it is offline, the token delivery will fail and
    you will see an error.</li>
    <li>You must be an admin on the hub. Editors cannot use Remote Login.</li>
</ul>

<h4>Security Note</h4>
<p>Remote Login relies on the same API key authentication used for all hub-to-spoke
communication. The token is transmitted over HTTPS. If your spoke is not running HTTPS,
Remote Login will still function but the redirect URL containing the token will be
transmitted in plaintext — which is a good reason to make sure all sites in your fleet
have valid SSL certificates.</p>
HTML
];

$help_topics['server-files'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Server Directory Structure',
    'icon'     => '&#x1F4C2;',
    'role'     => 'admin',
    'content'  => <<<'HTML'
<h3>What's on Your Server</h3>
<p>If you connect to your server via FTP or SFTP, here is what every directory contains and whether you should ever touch it.</p>

<h4>assets/</h4>
<p>Global CSS, JavaScript, and font files that ship with SnapSmack. Also contains <code>site-images/</code> for the default avatar and a few interface graphics. Don't edit files here directly — they are overwritten on every update. Custom CSS belongs in your skin's style settings.</p>

<h4>core/</h4>
<p>The engine. Authentication, database connection, skin rendering, API handlers, the updater — everything that makes SnapSmack run. You should never need to edit anything here. These files are overwritten on every update. If you find yourself wanting to change something in core, open a support request instead.</p>

<h4>data/</h4>
<p>Runtime data created by SnapSmack as it runs. Not part of the release package — SnapSmack creates this directory on first use. Contains:</p>
<ul>
<li><code>data/sessions/</code> — PHP login sessions, stored here instead of <code>/tmp</code> so your host's cron job can't log you out every 24 minutes. Protected by a deny-all <code>.htaccess</code> — not web-accessible.</li>
<li><code>data/custom-head.html</code> — any custom HTML you've added via Admin → Settings → Head Scripts. Edit through the admin, not directly.</li>
</ul>
<p>Do not delete the <code>data/</code> directory. You will be logged out immediately and lose any custom head scripts.</p>

<h4>img_uploads/</h4>
<p>Every photo you have ever posted. Organised as <code>img_uploads/YYYY/MM/</code> by upload date. Thumbnails live alongside originals. Back this directory up regularly — it is the only directory that cannot be restored from a package. Everything else can be reinstalled. Your photos cannot.</p>

<h4>licenses/</h4>
<p>Open source licenses for the fonts and JavaScript libraries bundled with SnapSmack. Required for legal compliance with the fonts you use. Do not delete.</p>

<h4>skins/</h4>
<p>Installed themes. Each subdirectory is one skin. The base release ships with 50 Shades of Noah Grey, New Horizon, Galleria, Rational Geo, and Photogram (mobile). Additional skins can be installed from Smack Central → Skin Packager. To remove a skin, uninstall it through the admin — do not delete the directory manually while it is the active skin or you will get a 500 error.</p>

<h4>Files in the Root</h4>
<p>The PHP files in the root directory are SnapSmack's public-facing pages and admin screens — <code>index.php</code>, <code>smack-admin.php</code>, and so on. These are overwritten on every update. <code>install.php</code> self-deletes after a successful install; if it is still present, delete it manually. <code>.htaccess</code> handles URL rewriting and HTTPS redirection — do not delete it or your site will break.</p>
HTML
];

// =========================================================================
//  SKIN HELP HOOK
// =========================================================================

// Active skin can provide a help.php file that returns an array of additional topics
// in the same format as above, with section set to the skin name.
if (!empty($settings['active_skin'])) {
    $_help_skin_slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $settings['active_skin']);
    $_help_skin_path = "skins/{$_help_skin_slug}/help.php";
    if (file_exists($_help_skin_path)) {
        $skin_help = include $_help_skin_path;
        if (is_array($skin_help)) {
            $help_topics = array_merge($help_topics, $skin_help);
        }
    }
}

// =========================================================================
//  ROLE-BASED FILTERING
// =========================================================================

// Editors only see topics without a role restriction (or role=editor).
// Admins see everything.
if ($_help_user_role !== 'admin') {
    $help_topics = array_filter($help_topics, function ($t) {
        return empty($t['role']) || $t['role'] === 'editor';
    });
}

// =========================================================================
//  DETERMINE ACTIVE TOPIC
// =========================================================================

$active_topic = $_GET['topic'] ?? '_toc';
$show_toc = ($active_topic === '_toc' || !isset($help_topics[$active_topic]));
if ($show_toc) {
    $active_topic = '_toc';
}

// Group topics by section for sidebar navigation
$sections = [];
foreach ($help_topics as $slug => $topic) {
    $sections[$topic['section']][$slug] = $topic;
}

// Build search index — JSON blob of slug → title + section + plain text snippet
$_search_index = [];
foreach ($help_topics as $slug => $ht) {
    $_search_index[$slug] = [
        'title'   => $ht['title'],
        'section' => $ht['section'],
        'icon'    => $ht['icon'] ?? '',
        'text'    => strtolower(strip_tags($ht['content'])),
    ];
}
?>

<div class="help-layout">

    <!-- Help Navigation Sidebar -->
    <div class="help-nav">
        <div class="help-nav-header">
            <h2 class="help-nav-title">USER MANUAL</h2>
            <p class="help-nav-subtitle">SnapSmack Documentation</p>
        </div>

        <!-- Search -->
        <div class="help-search">
            <input type="text" id="help-search-input" placeholder="Search docs..." autocomplete="off">
        </div>

        <!-- Search results (shown when typing) -->
        <div class="help-search-results" id="help-search-results"></div>

        <!-- Normal nav (hidden when searching) -->
        <div id="help-nav-sections">
            <a href="smack-help.php" class="help-nav-link<?php echo $show_toc ? ' active' : ''; ?>" style="padding-left: 16px; font-weight: bold;">&#x2630;&ensp;Table of Contents</a>

            <?php foreach ($sections as $section_name => $topics): ?>
                <div class="help-nav-section" data-section="<?php echo htmlspecialchars($section_name); ?>">
                    <div class="help-nav-section-label"><?php echo htmlspecialchars($section_name); ?></div>

                    <?php foreach ($topics as $slug => $topic): ?>
                        <a href="smack-help.php?topic=<?php echo urlencode($slug); ?>"
                           class="help-nav-link<?php echo ($slug === $active_topic) ? ' active' : ''; ?>"
                           data-slug="<?php echo htmlspecialchars($slug); ?>"
                           data-title="<?php echo htmlspecialchars(strtolower($topic['title'])); ?>"><?php echo htmlspecialchars($topic['icon'] ?? ''); ?>&ensp;<?php echo htmlspecialchars($topic['title']); ?></a>
                    <?php endforeac