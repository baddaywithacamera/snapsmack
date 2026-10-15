<?php
declare(strict_types=1);

/** CMS-owned bounded models for the quieter public skins. */
function snapsmack_public_skin_presentation(string $skin, array $options): array
{
    $on = static fn(string $key): bool => array_key_exists($key, $options)
        && in_array((string)$options[$key], ['1','true','on','yes'], true);
    $media = static fn(string $key): string => snapsmack_declared_media_url($options, $key);
    $out = [];
    if (in_array($skin, ['alfred','telegram','tilez','stanley','writing-with-impact'], true)) {
        $out['header_media'] = [
            'image'=>$media('header_image'), 'logo'=>$media('header_logo'),
            'retina'=>$on('retina_logo'), 'show_tagline'=>$on('show_tagline'),
        ];
    }
    if ($skin === 'photogram') $out['discover'] = ['visible'=>$on('pg_show_discover')];
    if ($skin === 'rational-geo') {
        // No colours and no CSS are decided here. The owner's choice travels
        // as the bare word they picked; the stylesheet turns it into a colour,
        // because colour is the skin's business. This used to assemble a CSS
        // declaration string that the template printed as an inline style.
        // The manifest owns the default. Restating it here is how a control's
        // default drifts out of step with its declaration, so an unrecognised
        // value emits nothing and the stylesheet's own fallback applies.
        $allowed=['yellow','white','black','grey','none'];
        $choice=(string)($options['image_border_color'] ?? '');
        if (!in_array($choice, $allowed, true)) $choice='';
        $out['rational']=[
            'infobox_height'=>snapsmack_declared_option_int($options,'infobox_height',30,100),
            'frame'=>$choice,
            'border_width'=>snapsmack_declared_option_int($options,'hero_border_width',0,80),
            // No CSS is assembled here any more. This used to build a
            // declaration string that the skin printed as an inline style on
            // .rg-photo-wrap, which drew the hero frame a second time around
            // the wrapper -- the double yellow outline. The colour now travels
            // as a bounded value and reaches the stylesheet as a custom
            // property, like every other control in this skin.

            'show_map'=>$on('show_map_background'), 'show_description'=>$on('single_show_description'),
            'show_signals'=>$on('single_show_signals'),
        ];
    }
    if ($skin === 'scroll') $out['scroll']=[
        'logo'=>$media('masthead_logo'),
        // The Landing Masthead control documents '|' as a line break. The
        // strict template cannot split a string, so the split happens here
        // and the skin only iterates. Empty segments are dropped so a
        // trailing or doubled bar cannot emit a blank line.
        'masthead_lines'=>array_values(array_filter(array_map('trim',
            explode('|', (string)($options['scroll_masthead_lines'] ?? ''))),
            static fn($line) => $line !== '')),
        'mosaic_emphasis'=>in_array((string)($options['scroll_mosaic_emphasis'] ?? ''),['natural','balanced','landscape','portrait'],true)
            ? (string)$options['scroll_mosaic_emphasis'] : '',
    ];
    if ($skin === 'slickr') $out['slickr']=[
        'show_description'=>$on('single_show_description'), 'show_exif'=>$on('show_exif_panel'),
        'show_geo'=>$on('show_geo_link'), 'show_provenance'=>$on('show_provenance_footer'),
    ];
    if ($skin === 'stanley') $out['stanley']=[
        'reality_test'=>$on('other_side_test_mode'), 'hero'=>$media('stanley_2024_hero'),
    ];
    if ($skin === 'writing-with-impact') $out['writing']=[
        'paper'=>in_array((string)($options['paper_style'] ?? ''),['plain','greenbar'],true)
            ? (string)$options['paper_style'] : '',
    ];
    return $out;
}
