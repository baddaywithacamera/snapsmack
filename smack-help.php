<?php
/**
 * SNAPSMACK - Man Pages (Help System)
 * Alpha v0.7
 *
 * In-admin documentation system covering every feature of the CMS.
 * Topics are organised into sections. Active skins can inject their own
 * help topics via a help.php file in the skin directory.
 */

$page_title = 'Man Pages';
require_once 'core/auth.php';
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

<h4>Editing &amp; Deleting</h4>
<p>Click Edit to rename a category. Deleting a category removes the association with all
transmissions but does not delete the transmissions themselves.</p>
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

<h4>Deleting Assets</h4>
<p>Deleting an asset removes the file from disk and the database record. Any shortcodes
referencing the deleted asset will render as empty space on the public site.</p>
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
and feature set. Some skins support the gallery wall, others don't. Some offer multiple
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
Presentation, Typography, Gallery Wall, and Content. Changes are applied immediately and
generate CSS that is injected into the public site.</p>
<p>Option types include colour pickers, range sliders, dropdowns, and number fields. Each
option targets a specific CSS selector and property, so you can see exactly what it affects.</p>

<h4>Gallery Tab</h4>
<p>Browse the skin registry for new skins and updates. Install, update, or remove skins.
All downloads are cryptographically verified. You cannot remove the currently active skin.</p>
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
HTML
];

$help_topics['user-manager'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'User Manager',
    'icon'     => '&#x263A;',
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

$help_topics['maintenance'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Maintenance',
    'icon'     => '&#x2692;',
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

<h4>Delete Orphaned Files</h4>
<p>Removes physical image files that have no corresponding database record. These can
appear after failed uploads or manual file deletions.</p>
HTML
];

$help_topics['backup'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'Backup & Recovery',
    'icon'     => '&#x2B07;',
    'content'  => <<<'HTML'
<h3>Backup System</h3>
<p>SnapSmack can export your data in several formats for safekeeping.</p>

<h4>Database Export</h4>
<ul>
    <li><strong>Full SQL Dump</strong> — complete export of all tables with data.
    This is the most important backup to have. You can restore from this using
    phpMyAdmin or the MySQL command line.</li>
    <li><strong>Schema Only</strong> — table structure without data. Useful for
    creating a blank installation with the same schema.</li>
    <li><strong>User Keys</strong> — exports the users table separately for safekeeping.</li>
</ul>

<h4>Media Manifest</h4>
<p>Generates a listing of all uploaded files with SHA-256 checksums. This lets you
verify file integrity after restoring from a backup or migrating to a new server.</p>

<h4>Source Code Archive</h4>
<p>Creates a compressed archive (tar.gz) of the SnapSmack codebase. This does not
include uploaded images — back those up separately via FTP or your hosting panel's
file manager.</p>

<h4>Recommended Backup Strategy</h4>
<p>Run a full SQL dump weekly (or before any update). Download it along with a copy of
your <code>img_uploads/</code> directory. Store both off-server (Google Drive, Dropbox,
external hard drive). The source code can always be re-downloaded from the SnapSmack
repository.</p>
HTML
];

$help_topics['updates'] = [
    'section'  => 'Boring Ass Stuff',
    'title'    => 'System Updates',
    'icon'     => '&#x21BB;',
    'content'  => <<<'HTML'
<h3>Update System</h3>
<p>SnapSmack can update itself from the official update server. All update packages are
cryptographically signed with Ed25519 to prevent tampering.</p>

<h4>Checking for Updates</h4>
<p>Click "Check Now" to query the update server. If a cron job is registered, this happens
automatically every 6 hours. A 24-hour fallback check runs on dashboard load even without
cron.</p>

<h4>Applying an Update</h4>
<p>The update process:</p>
<ol>
    <li>A full backup is created automatically (mandatory — you cannot skip this).</li>
    <li>The update package is downloaded and verified (SHA-256 checksum + Ed25519 signature).</li>
    <li>Files are extracted, skipping protected paths (db.php, constants.php, uploads, etc.).</li>
    <li>If the update includes database changes, migration scripts run automatically.</li>
    <li>The version number is updated in the database and constants file.</li>
</ol>
<p>If any step fails, the system automatically rolls back to the pre-update backup.</p>

<h4>Protected Paths</h4>
<p>The updater never overwrites your site-specific files: database configuration, upload
directories, .htaccess, robots.txt, and the signing public key. These are listed in
<code>protected_paths.json</code>.</p>

<h4>Skin Updates</h4>
<p>The update page also shows notifications about new or updated skins available in the
registry. These are installed separately from core updates via the Skin Gallery.</p>
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
This uses the media library asset system.</p>

<h4>Navigation</h4>
<p>Pages can be assigned to header navigation slots in the Configuration page. The
number of available slots depends on the active skin.</p>
HTML
];

$help_topics['gallery-wall'] = [
    'section'  => 'Public Features',
    'title'    => 'Gallery Wall',
    'icon'     => '&#x25A6;',
    'content'  => <<<'HTML'
<h3>Interactive Gallery Wall</h3>
<p>The gallery wall is a 3D interactive experience that displays your photographs as
draggable tiles on a virtual wall. It is desktop-only — mobile visitors are automatically
redirected to the standard archive view.</p>

<h4>Requirements</h4>
<p>The gallery wall only appears when the active skin declares <code>supports_wall</code>
in its manifest. Not all skins include wall support.</p>

<h4>Physics</h4>
<p>The wall uses simulated physics for dragging and momentum. You can tune:</p>
<ul>
    <li><strong>Friction</strong> — how quickly the wall decelerates after a drag (0.1 = ice, 0.99 = molasses).</li>
    <li><strong>Drag Weight</strong> — resistance when dragging.</li>
    <li><strong>Pinch Sensitivity</strong> — zoom responsiveness on trackpads.</li>
</ul>

<h4>Visual Settings</h4>
<p>Configure in Smooth Your Skin: wall background colour, tile gap, shadow style,
title font, number of rows (1–4), and maximum tile count.</p>
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
    Flickr or 500px.</li>
</ul>

<h4>Thumbnail Size</h4>
<p>Choose from five sizes: XS, S, M, L, XL. This affects how many images appear per row
at any given viewport width.</p>

<h4>Filtering</h4>
<p>Visitors can filter by category or album using the navigation on your public site. The
archive loads additional images via AJAX as the visitor scrolls (infinite scroll).</p>
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

// ── INSTALLER ────────────────────────────────────────────────────────────

$help_topics['installer'] = [
    'section'  => 'System',
    'title'    => 'Installer',
    'icon'     => '&#x2316;',
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
//  DETERMINE ACTIVE TOPIC
// =========================================================================

$active_topic = $_GET['topic'] ?? 'dashboard';
if (!isset($help_topics[$active_topic])) {
    $active_topic = 'dashboard';
}

// Group topics by section for sidebar navigation
$sections = [];
foreach ($help_topics as $slug => $topic) {
    $sections[$topic['section']][$slug] = $topic;
}
?>

<div class="help-layout">

    <!-- Help Navigation Sidebar -->
    <div class="help-nav">
        <div class="help-nav-header">
            <h2 class="help-nav-title">MAN PAGES</h2>
            <p class="help-nav-subtitle">SnapSmack Documentation</p>
        </div>

        <?php foreach ($sections as $section_name => $topics): ?>
            <div class="help-nav-section">
                <div class="help-nav-section-label"><?php echo htmlspecialchars($section_name); ?></div>

                <?php foreach ($topics as $slug => $topic): ?>
                    <a href="smack-help.php?topic=<?php echo urlencode($slug); ?>" class="help-nav-link<?php echo ($slug === $active_topic) ? ' active' : ''; ?>"><?php echo htmlspecialchars($topic['icon'] ?? ''); ?>&ensp;<?php echo htmlspecialchars($topic['title']); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Help Content Area -->
    <div class="help-content">
        <?php
        $topic = $help_topics[$active_topic];
        ?>

        <div class="help-topic-header">
            <div class="help-topic-section">
                <?php echo htmlspecialchars($topic['section']); ?>
            </div>
            <h1 class="help-topic-title">
                <?php echo $topic['icon'] ?? ''; ?>&ensp;<?php echo htmlspecialchars($topic['title']); ?>
            </h1>
        </div>

        <div class="help-body">
            <style>
                .help-body h3 {
                    font-size: 1.15rem;
                    letter-spacing: 1px;
                    margin: 0 0 12px 0;
                    padding: 0;
                }
                .help-body h4 {
                    font-size: 0.85rem;
                    letter-spacing: 1.5px;
                    text-transform: uppercase;
                    color: var(--text-secondary, #aaa);
                    margin: 24px 0 8px 0;
                    padding: 0;
                }
                .help-body p {
                    margin: 0 0 12px 0;
                }
                .help-body ul, .help-body ol {
                    margin: 0 0 16px 0;
                    padding-left: 24px;
                }
                .help-body li {
                    margin-bottom: 6px;
                }
                .help-body pre {
                    background: var(--bg-secondary, rgba(0,0,0,0.3));
                    border: 1px solid var(--lens-border, #333);
                    padding: 12px 16px;
                    font-family: 'Consolas', 'Monaco', monospace;
                    font-size: 0.82rem;
                    overflow-x: auto;
                    margin: 8px 0 16px 0;
                    letter-spacing: 0.5px;
                }
                .help-body code {
                    background: var(--bg-secondary, rgba(0,0,0,0.2));
                    padding: 2px 6px;
                    font-family: 'Consolas', 'Monaco', monospace;
                    font-size: 0.82rem;
                    border-radius: 2px;
                }
                .help-body strong {
                    color: var(--text-main, #eee);
                }
                .help-body em {
                    color: var(--text-secondary, #aaa);
                }
            </style>

            <?php echo $topic['content']; ?>
        </div>

        <!-- Topic Navigation -->
        <div class="help-topic-nav">
            <?php
            $slugs = array_keys($help_topics);
            $current_index = array_search($active_topic, $slugs);
            $prev = ($current_index > 0) ? $slugs[$current_index - 1] : null;
            $next = ($current_index < count($slugs) - 1) ? $slugs[$current_index + 1] : null;
            ?>
            <div>
                <?php if ($prev): ?>
                    <a href="smack-help.php?topic=<?php echo urlencode($prev); ?>">
                        &larr; <?php echo htmlspecialchars($help_topics[$prev]['title']); ?>
                    </a>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($next): ?>
                    <a href="smack-help.php?topic=<?php echo urlencode($next); ?>">
                        <?php echo htmlspecialchars($help_topics[$next]['title']); ?> &rarr;
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
