<?php
/**
 * SNAPSMACK.CA - SNAP SLAPPER (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'SNAP SLAPPER - the SnapSmack photo editor';
$page_description = 'SNAP SLAPPER is a free, private, non-destructive desktop photo editor and library built beside SnapSmack. Normal and Advanced modes, layers, masks, curves, LEWKS, and LEWK AGAIN.';
$page_og_url      = 'https://snapsmack.ca/tool-snap-slapper.php';
$page_social_image = 'https://snapsmack.ca/img/snapslapper-editor-adv.png';
$nav_active       = 'tool-snap-slapper';

$tool_name     = 'SNAP SLAPPER';
$tool_short    = 'A private photo library and real non-destructive editor built beside SnapSmack, not rented from somebody else&rsquo;s cloud.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Closed beta';
$tool_group    = 'make';
$tool_news     = 'snap-slapper-editor';
$tool_facts    = [
    ['What it is', 'Photo library + non-destructive editor'],
    ['Originals', 'Never touched. Exports are new files.'],
    ['Formats out', 'JPEG, PNG, TIFF, PSD, OpenRaster'],
    ['AI', 'LEWK AGAIN, five providers, your key'],
];
$tool_body = <<<'HTML'
            <p>SNAP SLAPPER began as the missing space between a folder full of photographs and the finished work on a SnapSmack site. It has grown into the place where the whole local workflow can happen: browse a real archive, rate and tag it, organize files and folders, develop one photograph carefully, move through a shoot quickly, save the work as an open project, and hand the finished result to the web without surrendering the originals.</p>
            <p>It is deliberately two editors in one. <strong>Normal</strong> keeps the everyday photographic controls visible and the machinery out of the way. <strong>Advanced</strong> opens the layers, masks, curves, colour tools, geometry, retouching, filters, textures, recipes, and export controls when the photograph actually needs them. You choose the depth; the software does not punish you for knowing less or hold you back for knowing more.</p>
            <h2>The two editors in one</h2>
            <p>Every photograph Sean publishes now goes through SNAP SLAPPER first. That is the test it is being held to: not a demo file, but a working photographer&rsquo;s daily output, with the awkward controls found by hitting them.</p>
            <h2>Your archive is not an import hostage.</h2>
            <p>Point SNAP SLAPPER at the folders you already use. It reads the photographs where they live instead of demanding that thousands of originals be swallowed by a proprietary catalogue before you can see them. Include subfolders when the hierarchy matters, resize both the thumbnails and folder text, search filenames, sort the shoot, and filter what is on screen.</p>
            <p>Ratings, favourites, tags, and albums sit beside the photograph rather than in another disconnected application. The organizer can create and rename folders, move photographs, batch-rename safely, and show the important metadata without turning file management into a scavenger hunt.</p>
            <p>The original file remains the original file. Editing is non-destructive, exports are new files, and the library is a view onto your photography rather than a deed transferring ownership to the program.</p>
            <h2>Normal means focused, not crippled.</h2>
            <p>Normal mode puts brightness, contrast, highlights, shadows, temperature, saturation, vibrance, black and white, geometry, and vignette where a photographer can find them. Crop, red-eye correction, Auto, LEWKS, export, and blog-copy tools remain one click away. It is enough room to finish most photographs without staring into an aircraft cockpit.</p>
            <p>The filmstrip follows the folder under the open photograph, loads as you scroll, and can be folded away when the image needs every available pixel. Fit and 100% views are explicit: one is for composition, the other is a real focus check at native resolution.</p>
            <p>Normal mode intentionally leaves out layers, masks, paint machinery, and specialist controls. Simplicity here is a designed workspace, not an arbitrary set of disabled features.</p>
            <h2>When the photograph needs the whole bench.</h2>
            <p>Advanced mode exposes the complete non-destructive stack. Add adjustment, image, text, and filter layers; change opacity and blend mode; reorder or isolate the work; and apply radial, linear, luminosity, colour-range, or painted masks. The live histogram can show luminance or RGB while the photograph changes underneath it.</p>
            <p>Light, colour, presence, effects, levels, master and per-channel curves, geometry, retouching, black-and-white colour mixing, colour mixing, split toning, glow, sharpening, filters, and textures are editable instructions rather than damage baked into the source. Perspective can be corrected vertically, horizontally, or by pulling individual corners while straight lines remain straight.</p>
            <p>Recipes capture a sequence for reuse and batch work. Projects preserve the stack for later. PSD, TIFF, PNG, and JPEG exports provide practical exits instead of pretending one application should own the rest of your working life.</p>
            <h2>The interface gets out of the photograph&rsquo;s way.</h2>
            <p>A serious editor needs density without becoming an obstacle course. The right rail collapses complex sections into named groups. The filmstrip opens when you are comparing a run and closes when you are concentrating on one frame. Normal and Advanced are visible modes, not secret preferences buried three dialogs deep.</p>
            <p>Before/After makes the original available without destroying the current state. Undo, redo, reset, crop, heal, red-eye work, and keyboard shortcuts support the repetitive rhythm of actual editing. Window changes re-render a correctly sized preview so maximizing the workspace does not leave a fuzzy proxy stretched across the screen.</p>
            <p>The editor is being dogfooded against real folders and real photographs. That matters. The awkward controls, unloaded thumbnails, duplicate windows, and assumptions that only appear after the twentieth image are being found by using the program as the primary editor—not by admiring a demo file.</p>
            <h2 id="lewks">Looks you can see, change, save, and leave with.</h2>
            <p>LEWKS are reusable appearance recipes, previewed against the photograph that is actually open rather than a vendor&rsquo;s perfectly lit sample. Black-and-white treatments, corrective starting points, film and print character, landscape colour, portrait handling, and deliberately strange experiments can all be auditioned at adjustable strength.</p>
            <p>Applying one does not flatten the photograph into a dead end. The underlying controls remain controls. Change the contrast, pull back a colour channel, alter the curve, stack another idea, or save the result as your own recipe. A useful preset should accelerate a decision, not conceal how the decision was made.</p>
            <p>SNAP SLAPPER runs locally, arrives through SNAP HQ, and works beside a SnapSmack installation rather than inventing another subscription account or cloud library. The desktop application does the heavy image work on your computer. Your site, your archive, your edits, and your exit remain yours.</p>
HTML;
$tool_shots = [
    ['snapslapper-library.png', 'SNAP SLAPPER photo library showing folders, thumbnails, ratings, tags, and photograph information', 'The library: folders remain folders, with ratings, tags, albums, search, sorting, and readable thumbnails.'],
    ['snapslapper-editor-norm.png', 'SNAP SLAPPER Normal editor with a photograph, simple controls, and a folder filmstrip', 'Normal mode: the controls used on most photographs, plus a filmstrip for moving through the folder.'],
    ['snapslapper-editor-adv.png', 'SNAP SLAPPER Advanced editor with layers, histogram, detailed adjustment controls, and filmstrip', 'Advanced mode: layers, live histogram, detailed tonal controls, masks, geometry, retouching, and colour work.'],
    ['snapslapper-editor-adv-nofilm.png', 'SNAP SLAPPER Advanced editor with the filmstrip hidden for a larger canvas', 'The same Advanced workspace with the filmstrip closed: more canvas when browsing is finished.'],
    ['snapslapper-editor-lewks.png', 'SNAP SLAPPER LEWKS browser previewing reusable looks on the photographer&rsquo;s own image', 'LEWKS preview on your photograph, with adjustable strength before anything is applied.']
];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
