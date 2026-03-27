=== WP Mosaic ===
Contributors: seanmorris
Tags: gallery, mosaic, tiled, justified, photo grid
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later

Justified image mosaic galleries for WordPress. Build tiled photo grids from the Media Library — no cropping, clean rows, responsive layout.

== Description ==

WP Mosaic creates Jetpack-style tiled image galleries using a simple shortcode. Select images from your WordPress Media Library, drag to reorder, set the gap between images, and embed anywhere with `[mosaic id="X"]`.

The layout engine is row-based: images are packed into rows so their combined width fills the container, scaling each image to a common height per row. Aspect ratios are preserved — nothing gets cropped. The layout is fully responsive and recalculates on resize.

**Features:**

* Pick images from the standard WordPress Media Library
* Drag-to-reorder in the admin editor
* Live preview while editing
* Configurable gap between images (0–20px)
* Responsive — recalculates layout on window resize
* Lazy loading on all mosaic images
* Lightweight — no jQuery on the front end, no dependencies

Originally the Mosaic engine from [SnapSmack](https://github.com/seanmorris/snapsmack), extracted and adapted for WordPress.

== Installation ==

1. Upload the `wp-mosaic` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Go to **Mosaics** in the admin sidebar to create your first mosaic
4. Copy the shortcode and paste it into any post or page

== Changelog ==

= 1.0.0 =
* Initial release — ported from SnapSmack mosaic engine
