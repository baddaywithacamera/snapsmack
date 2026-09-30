<?php
declare(strict_types=1);

/** CMS-owned bounded models for the quieter public skins. */
function snapsmack_public_skin_presentation(string $skin, array $options): array
{
    $on = static fn(string $key, bool $default=true): bool => array_key_exists($key, $options)
        ? in_array((string)$options[$key], ['1','true','on','yes'], true) : $default;
    $media = static fn(string $key): string => snapsmack_declared_media_url($options, $key);
    $out = [];
    if (in_array($skin, ['alfred','telegram','tilez','stanley','writing-with-impact'], true)) {
        $out['header_media'] = [
            'image'=>$media('header_image'), 'logo'=>$media('header_logo'),
            'retina'=>$on('retina_logo', false), 'show_tagline'=>$on('show_tagline'),
        ];
    }
    if ($skin === 'photogram') $out['discover'] = ['visible'=>$on('pg_show_discover')];
    if ($skin === 'rational-geo') {
        $colours=['yellow'=>'#ffcc00','white'=>'#ffffff','black'=>'#000000','grey'=>'#808080','none'=>'transparent'];
        $choice=(string)($options['image_border_color'] ?? 'yellow');
        $out['rational']=[
            'border_color'=>$colours[$choice] ?? $colours['yellow'],
            'border_width'=>snapsmack_declared_option_int($options,'hero_border_width',0,80),
            'show_map'=>$on('show_map_background'), 'show_description'=>$on('single_show_description'),
            'show_signals'=>$on('single_show_signals'),
        ];
    }
    if ($skin === 'scroll') $out['scroll']=[
        'logo'=>$media('masthead_logo'),
        'mosaic_emphasis'=>in_array((string)($options['scroll_mosaic_emphasis'] ?? ''),['natural','balanced','landscape','portrait'],true)
            ? (string)$options['scroll_mosaic_emphasis'] : 'landscape',
    ];
    if ($skin === 'slickr') $out['slickr']=[
        'show_description'=>$on('single_show_description'), 'show_exif'=>$on('show_exif_panel'),
        'show_geo'=>$on('show_geo_link'), 'show_provenance'=>$on('show_provenance_footer'),
    ];
    if ($skin === 'stanley') $out['stanley']=[
        'reality_test'=>$on('other_side_test_mode', false), 'hero'=>$media('stanley_2024_hero'),
    ];
    if ($skin === 'writing-with-impact') $out['writing']=[
        'paper'=>in_array((string)($options['paper_style'] ?? ''),['plain','greenbar'],true)
            ? (string)$options['paper_style'] : 'plain',
    ];
    return $out;
}
