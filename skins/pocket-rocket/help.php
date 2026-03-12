<?php
/**
 * SNAPSMACK - Skin Help Topics: Pocket Rocket
 * Alpha v0.7.3
 */

return [

    'skin-overview-po' => [
        'section'  => 'Active Skin: Pocket Rocket',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25A3;',
        'content'  => <<<'HTML'
<h3>Pocket Rocket — v1.0</h3>
<p>A mobile-first skin for phone screens. Everything scrolls vertically, everything
taps, nothing hovers. Medium grey palette with 1px borders. No floating gallery, no
justified grid, no cropped thumbnails — aspect-preserved images only.</p>

<h4>Key Features</h4>
<ul>
    <li><strong>Hamburger navigation</strong> — fixed 48px header bar with slide-down
    nav drawer. HOME, ARCHIVE, BLOGROLL, plus any dynamic pages.</li>
    <li><strong>Doomscroll feed</strong> — homepage is a vertical wall of full-width
    images from newest to oldest. Tap any image to open its transmission page.</li>
    <li><strong>Archive</strong> — square thumbnail grid with category and album filters.
    Simple, scrollable, filterable.</li>
    <li><strong>Info drawer</strong> — tap INFO to slide down the description and EXIF
    specs. Tap again to close.</li>
    <li><strong>Signals drawer</strong> — tap SIGNALS to slide up the comments and reply
    form. Tap again to close.</li>
</ul>

<h4>Design Philosophy</h4>
<p>This skin does one thing: gets out of the way. No animations beyond drawer
transitions, no parallax, no lightbox, no hotkeys. The photo fills the screen.
Everything else is one tap away.</p>
HTML
    ],

    'skin-navigation-po' => [
        'section'  => 'Active Skin: Pocket Rocket',
        'title'    => 'Navigation & Drawers',
        'icon'     => '&#x2630;',
        'content'  => <<<'HTML'
<h3>Navigation</h3>
<p>The hamburger menu in the top-right corner opens a nav drawer below the header bar.
Links include HOME, ARCHIVE, BLOGROLL (if enabled), and any custom pages you have
created. Tapping any link navigates immediately and closes the drawer.</p>

<h3>Prev / Next</h3>
<p>On individual transmission pages, PREV and NEXT buttons appear below the title bar.
These navigate chronologically through your published transmissions.</p>

<h3>Info Drawer</h3>
<p>Tap the INFO button to reveal the image description and EXIF specifications. The
drawer slides down with a smooth transition. Only EXIF fields that contain data are
shown — empty fields are hidden automatically.</p>

<h3>Signals Drawer</h3>
<p>Tap SIGNALS to reveal comments and the reply form. The count next to SIGNALS shows
how many approved comments exist. The drawer slides up from the bottom. Only one
drawer can be open at a time — opening one closes the other.</p>
HTML
    ],

    'skin-community-po' => [
        'section'  => 'Active Skin: Pocket Rocket',
        'title'    => 'Community Accounts & Settings',
        'icon'     => '&#x2665;',
        'content'  => <<<'HTML'
<h3>Community Features</h3>
<p>The likes button and reaction picker appear on every page via the community dock. Visitors can react without creating an account. Comments (shown in the Signals drawer) require a free community account created directly on your site.</p>

<h4>Community Accounts</h4>
<p>Visitors register and log in directly on your site — no email confirmation required, no third-party auth. Once logged in, their display name appears on all their comments. Manage community accounts in Admin > Community Users.</p>

<h4>Admin Controls</h4>
<p>Go to Admin > Community Settings to toggle likes, reactions, and comments independently; choose the active reaction set (up to 6 emoji); enable or disable the thumbs-down reaction; set rate limits; and configure email notifications for new comments.</p>
HTML
    ],

];
