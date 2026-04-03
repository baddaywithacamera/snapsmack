<?php
/**
 * SNAPSMACK - Skin Help Topics: Photogram
 * Alpha v0.7.8
 */

return [

    'skin-overview-photogram' => [
        'section'  => 'Active Skin: Photogram',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25A3;',
        'content'  => <<<'HTML'
<h3>Photogram</h3>
<p>A phone-native photo blog that looks and feels like a classic photo-sharing app.
Works on every device — desktop shows a centred phone column, mobile fills the screen.</p>

<h4>Using Photogram</h4>
<ul>
    <li>The <strong>home screen</strong> shows your profile and a 3-column grid of all your photos.</li>
    <li>Tap any photo to open the full post view.</li>
    <li><strong>Double-tap the photo</strong> to like it.</li>
    <li>Tap the <strong>speech bubble</strong> to open comments.</li>
    <li>The <strong>Discover tab</strong> shows your local tags and recent photos.</li>
</ul>

<h4>Skin Options</h4>
<ul>
    <li><strong>Grid Gap</strong> — controls the space between grid cells (none, hairline, or small).</li>
    <li><strong>Avatar Shape</strong> — circle or rounded square.</li>
    <li><strong>Accent Colour</strong> — used for links, active tab indicator, and interactive elements.</li>
    <li><strong>Sheet Speed</strong> — how fast the comment sheet slides in.</li>
    <li><strong>Show Discover Tab</strong> — toggle the Discover tab on or off from Skin Admin.</li>
</ul>
HTML
    ],

    'skin-hashtags-photogram' => [
        'section'  => 'Active Skin: Photogram',
        'title'    => 'Hashtags & Search',
        'icon'     => '&#x0023;',
        'content'  => <<<'HTML'
<h3>Hashtags</h3>
<p>Add <strong>#hashtags</strong> to any image description or use the Tags field on the
post/edit page. They become tappable archive links. Tapping a tag opens a filtered grid
of all photos sharing that tag. Tags are extracted automatically when you save or post.</p>

<h3>Search</h3>
<p>Site-wide search is off by default. Enable it in <strong>Admin &gt; Configuration &gt;
Architecture &amp; Interaction &rarr; Site-Wide Search</strong>. Once on, a search tab
appears in the bottom nav. Search looks through photo titles, descriptions, and hashtags.
Queries starting with <code>#</code> redirect to the tag archive. Matching tags appear as
tappable pill chips above the image results.</p>
HTML
    ],

    'skin-community-photogram' => [
        'section'  => 'Active Skin: Photogram',
        'title'    => 'Community Accounts',
        'icon'     => '&#x2665;',
        'content'  => <<<'HTML'
<h3>Community Features</h3>
<p>Likes and reactions work without an account. To leave a comment, visitors create a free
community account directly on your site — no third-party login required. Manage accounts
in Admin &gt; Community Users.</p>
<p>Configure community features globally in <strong>Admin &gt; Community Settings</strong>:
toggle likes, reactions, and comments on or off; choose the active reaction set; set rate
limits; and configure email notifications for new comments.</p>
HTML
    ],

];
