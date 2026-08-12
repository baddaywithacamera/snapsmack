<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->
# SNAPSMACK DESKTOP IMAGE EDITOR

## Initial product specification

Status: post-SNAPSMACK 1.0 concept  
Working title: TBD  
Target platforms: Windows and Linux  
License: GPL-3.0-or-later proposed  
Last updated: 2026-08-12

## 1. Product definition

The SNAPSMACK desktop image editor is a free, local-first photography editor intended as a post-1.0 surprise for SNAPSMACK users.

It is a light-lifting editor in the spirit of classic Paint Shop Pro: fast to open, understandable without training, useful for ordinary photographic work, and equipped with a real layered document model. It is not intended to compete feature-for-feature with Photoshop, GIMP, darktable, Affinity Photo, or full digital-asset-management suites.

The application combines three practical workflows:

- Develop camera RAW files before editing.
- Make ordinary layered photographic edits.
- Merge overlapping photographs into panoramas.

All work happens locally. No account, cloud service, subscription, telemetry requirement, or network connection is needed to edit photographs.

## 2. Release timing

Development is explicitly deferred until after SNAPSMACK 1.0 ships.

Before SNAPSMACK 1.0, this document may collect product decisions and technical research only. The editor must not divert engineering, testing, release, documentation, or support effort from the SNAPSMACK 1.0 milestone.

## 3. Product goals

- Give SNAPSMACK users a capable free editor for routine photographic work.
- Preserve the speed, clarity, and directness associated with classic Paint Shop Pro.
- Support layers without turning the interface into a professional compositing cockpit.
- Support camera RAW formats out of the box on Windows and Linux.
- Provide a dedicated RAW development workflow before layered editing.
- Preserve RAW development settings non-destructively when requested.
- Merge handheld and tripod panorama sequences locally.
- Work well on ordinary contemporary computers without requiring an online service.
- Use an open layered project format and avoid proprietary lock-in.
- Never overwrite the photographer's only source file by default.

## 4. Non-goals

- No macOS, iPhone, iPad, iCloud, HEIC, or Apple-specific integration in the initial product.
- No attempt to clone every Photoshop or GIMP feature.
- No digital-asset-management catalogue in the initial release.
- No cloud storage, cloud processing, team collaboration, or account system.
- No AI image generation requirement.
- No video editing, animation suite, desktop publishing, or illustration-first workflow.
- No promise of perfect PSD round-tripping.
- No advanced prepress or CMYK production suite in the initial release.
- No destructive editing of original RAW files.

## 5. Platforms and desktop architecture

The application targets Windows and Linux and must render its interface through Chromium/Blink on both platforms, consistent with the other SNAPSMACK desktop applications.

Proposed architecture:

- Electron provides the Chromium desktop shell, windows, menus, input, and packaging.
- TypeScript implements the visible application interface and command layer.
- WebGPU provides interactive canvas rendering, transforms, masks, blend previews, and GPU compositing where available.
- A Rust imaging engine owns documents, layers, tiles, undo history, RAW development state, panorama processing, color management, serialization, and export.
- Worker processes or threads perform decoding, thumbnails, histograms, panorama analysis, previews, and export without blocking the interface.
- Typed IPC connects Chromium to the Rust engine.
- Large pixel buffers must not be repeatedly serialized through JSON. Tiled files, shared memory, or another bounded binary transfer mechanism must be used.

The Rust engine, not the Chromium renderer, is the canonical owner of the open document. A renderer crash must not invalidate the document or its recovery journal.

## 6. Licensing direction

GPL-3.0-or-later is the proposed application license. This allows the project to reuse and contribute to mature free-software imaging work where license-compatible, while guaranteeing that distributed modifications remain available to users.

Every dependency must receive a recorded license and redistribution review before inclusion. Codec patents and platform redistribution terms must be evaluated independently from source-code licenses.

Likely foundational components include:

- LibRaw for camera RAW decoding.
- Little CMS or an equivalent mature engine for ICC color management.
- Selected GPL-compatible demosaicing, lens-correction, panorama, and image-processing components where their quality and integration cost justify reuse.

No dependency choice in this early specification is final.

## 7. RAW workflow

RAW support is mandatory in the first public release, not a later plugin.

Opening a RAW file enters a dedicated Develop workspace before the ordinary layered editor. The initial controls should include:

- Camera white balance and manual temperature/tint.
- Exposure and black level.
- Highlight recovery.
- Shadows and contrast.
- White and black points.
- Demosaicing quality selection where useful.
- Lens distortion, chromatic-aberration, and vignetting correction when profiles exist.
- Noise reduction.
- Capture sharpening.
- Crop, straighten, rotate, and orientation correction.
- Histogram and clipping warnings.
- Output color space and bit-depth selection.

The Develop action creates a 16-bit working layer. By default, the application should retain the original RAW reference or embedded source plus its development parameters so the layer can be reopened in Develop and rendered again non-destructively.

A RAW-backed layer is not directly paintable. When a paint operation targets it, the application offers to:

- Create a raster paint layer above it; or
- Rasterize a copy while retaining the original RAW-backed layer.

The initial supported family should include common CR2/CR3, NEF/NRW, ARW, RAF, ORF/ORI, RW2, DNG, PEF, and medium-format RAW files supported reliably by the selected decoder. The final compatibility list must be generated from tested cameras and decoder versions rather than marketing claims.

## 8. Layered editor

The editor is optimized for photographic correction and modest compositing.

Required layer model:

- Raster layers.
- RAW-backed developed layers.
- Visibility, opacity, naming, locking, and reordering.
- Layer groups.
- Common photographic blend modes.
- Raster masks.
- Non-destructive position, scale, rotation, and flip transforms where practical.
- Merge down, flatten copy, and flatten document.

Required editing tools:

- Move, crop, resize, rotate, straighten, and canvas size.
- Rectangular, elliptical, freehand/lasso, and contiguous-color selections.
- Add, subtract, intersect, feather, grow, shrink, invert, and clear selection.
- Brush, eraser, fill, gradient, clone, and basic healing.
- Eyedropper and foreground/background color controls.
- Text and simple vector shapes, if they can be delivered without destabilizing the photographic core.

Required adjustments:

- Brightness and contrast.
- Levels and curves.
- White balance and color balance.
- Hue, saturation, and lightness.
- Vibrance.
- Black and white conversion.
- Sharpen and blur.
- Basic noise reduction.
- Resize and resampling controls.

Adjustments may initially apply destructively to a selected raster layer if undo is reliable. Adjustment layers are desirable after the core editor is stable, but are not required to block the first useful release.

## 9. Panorama merge

Panorama merge is a first-class workflow, not an external command hidden in a menu.

The user selects two or more overlapping photographs and opens the Panorama workspace. The application then:

1. Reads orientation, focal length, and relevant metadata.
2. Detects and matches image features.
3. Estimates camera positions and image transforms.
4. Corrects lens distortion and exposure differences where possible.
5. Projects the images onto a selectable panorama surface.
6. Finds seams and blends overlaps.
7. Presents an interactive preview before rendering the final result.

Initial projection options:

- Perspective/rectilinear.
- Cylindrical.
- Spherical.
- Automatic recommendation.

Initial user controls:

- Reorder or exclude source frames.
- Choose projection.
- Set horizon/straighten.
- Adjust crop boundaries.
- Enable automatic crop.
- Choose exposure matching strength.
- Choose blend quality.
- Review alignment failures and unmatched images.

The rendered panorama should enter the layered editor as either:

- A single 16-bit raster layer for the simplest workflow; or
- A panorama group retaining aligned source layers and masks when the selected engine can provide this reliably.

The second result is preferable because the photographer can repair ghosts, seams, moving people, waves, foliage, or exposure mismatches manually. It must not block delivery of a trustworthy flattened panorama workflow.

HDR panorama merging, focus stacking, gigapixel/multi-row optimization, and moving-subject deghosting beyond basic seam selection are later features unless an adopted engine supplies them reliably at low integration cost.

## 10. Document and file handling

The application requires an open native layered project format.

The format should be a documented container containing:

- Versioned document metadata.
- Canvas properties and working color profile.
- Layer tree and properties.
- Tile or image payloads.
- Masks.
- RAW source references or embedded RAW data.
- RAW development parameters.
- Panorama source relationships and alignment parameters where retained.
- Non-destructive transforms.
- Recovery and compatibility metadata.

The format must support atomic saves: write a new valid file, verify it, then replace the previous version. The application should keep an optional rotating backup and recovery journal.

Initial import/export priorities:

- Import: common camera RAW, JPEG, PNG, TIFF, WebP, and the native project format.
- Export: JPEG, PNG, TIFF, WebP, and flattened copies suitable for SNAPSMACK posting.
- Preserve EXIF, IPTC, XMP, orientation, capture date, copyright, and color-profile data where the target format supports them and the user has not requested stripping.
- PSD import may be investigated later. Perfect PSD export is not an initial goal.

## 11. Precision, color, and performance

- RAW development and serious adjustment paths should use at least 16-bit integer or half/32-bit floating-point precision internally.
- The document has an explicit working color space and embedded ICC profile.
- Display conversion respects the monitor profile where the operating system makes it available.
- Compositing and adjustments must define whether operations occur in encoded or linear light; photographic operations should use the correct space rather than whichever implementation is easiest.
- Large images are stored and processed in tiles.
- Undo history stores commands, parameters, changed tiles, or bounded snapshots rather than duplicating the complete document after every action.
- Expensive adjustments render responsive proxy previews and refine at rest or during export.
- GPU acceleration is used where available, with a correct CPU fallback.
- The application must remain usable when one decoded image is much larger than available GPU memory.

## 12. Interface direction

The interface should evoke the speed and comprehensibility of classic Paint Shop Pro without copying its appearance.

Principles:

- The photograph receives most of the window.
- Familiar desktop menus and keyboard shortcuts remain available.
- Tool options appear close to the active tool.
- Layers, history, histogram, and color panels are dockable and may be hidden.
- Controls are compact enough for productive desktop use without becoming cryptic.
- Develop, Edit, and Panorama are clearly separated workspaces.
- Common actions require few clicks and do not open unnecessary modal dialogs.
- The interface remains recognizably part of the SNAPSMACK family without importing the CMS admin interface wholesale.

## 13. SNAPSMACK relationship

The editor is useful without SNAPSMACK and must never require a SNAPSMACK installation.

Optional integration may include:

- Export presets matching SNAPSMACK's preferred dimensions and quality.
- Preserve or prepare title, caption, ALT text, tags, copyright, and capture metadata.
- Open an exported image's folder for manual posting.
- A later explicit handoff to an installed SNAPSMACK posting tool.

The image editor must not receive broad CMS credentials merely for convenience. Any future direct-posting integration requires narrow authorization and separate security review.

## 14. First useful release

The first useful public release should include:

- Windows and Linux installers with the same Chromium/Blink interface.
- Camera RAW opening and a dedicated Develop workspace.
- 16-bit layered documents.
- Raster and RAW-backed layers.
- Reliable selection, crop, transform, brush, eraser, clone, and basic healing.
- Levels, curves, white balance, color, sharpening, blur, and resize operations.
- Masks, opacity, groups, and common blend modes.
- Undo/redo and crash recovery.
- Native layered save/open.
- JPEG, PNG, TIFF, and WebP export.
- Panorama alignment, projection, preview, crop, blending, and 16-bit output.
- Metadata and ICC-profile preservation.
- Offline operation with no account requirement.

## 15. Deferred features

- Adjustment layers and live filters.
- Full PSD compatibility.
- HDR merge and HDR panorama.
- Focus stacking.
- Content-aware fill and advanced object removal.
- Advanced frequency separation and retouching.
- Sophisticated vector illustration.
- Plugin SDK.
- Scripting and batch processing.
- Digital-asset-management catalogue.
- AI-assisted masking, denoising, enlargement, or generative tools.
- Direct publishing to SNAPSMACK.

## 16. Major engineering risks

- Correct, high-quality RAW development across diverse cameras.
- Camera and lens profile acquisition and redistribution.
- GPU/CPU consistency across Windows and Linux.
- Large-image memory pressure and tile-cache design.
- Undo history that is both trustworthy and bounded.
- Color-management correctness.
- Panorama alignment and blending failures on low-detail or moving scenes.
- Atomic layered saves and recovery after power loss or renderer failure.
- Maintaining Electron, native Rust components, and bundled imaging libraries securely.
- GPL and third-party license compliance across binary distributions.

## 17. Open decisions

- Final product name and visual identity.
- Whether the RAW source is embedded by default or referenced beside the project.
- Which demosaicing and lens-correction engines to adopt.
- Whether GEGL/babl, darktable modules, or a custom Rust tile graph should form the processing core.
- Which panorama engine provides the best balance of licensing, quality, maintainability, and layered output.
- Whether the first release includes adjustment layers.
- Native project extension and public schema.
- Minimum supported GPU and fallback-performance expectations.
- Exact boundary between a light editor and advanced retouching.

## 18. Gate before implementation

Implementation begins only after:

- SNAPSMACK 1.0 has shipped and its immediate stabilization work is complete.
- The project owner explicitly activates this project.
- A dependency and license matrix is approved.
- RAW development and panorama proof-of-concept results meet an agreed quality bar.
- A representative Windows/Linux hardware test matrix exists.
- The native project format and crash-recovery strategy have received design review.

Until then, this specification is a parking place for the idea—not an active SNAPSMACK milestone.

<!-- ===== SNAPSMACK EOF ===== -->
