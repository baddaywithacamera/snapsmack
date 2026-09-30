<?php

/** Single source of truth for Menu Manager appearance defaults. */
if (!function_exists('snapsmack_menu_appearance_defaults')) {
    function snapsmack_menu_appearance_defaults(): array
    {
        return [
            'nav_dropdown_bg' => '#000000',
            'nav_dropdown_opacity' => '88',
            'nav_dropdown_text' => '#ffffff',
        ];
    }
}

/** Return bounded presentation values; saved settings always win. */
if (!function_exists('snapsmack_menu_appearance')) {
    function snapsmack_menu_appearance(array $settings): array
    {
        $values = array_replace(snapsmack_menu_appearance_defaults(), array_intersect_key($settings, snapsmack_menu_appearance_defaults()));
        foreach (['nav_dropdown_bg', 'nav_dropdown_text'] as $key) {
            $candidate = trim((string)$values[$key]);
            $values[$key] = preg_match('/^#[0-9a-f]{6}$/i', $candidate) ? strtolower($candidate) : snapsmack_menu_appearance_defaults()[$key];
        }
        $opacity = filter_var($values['nav_dropdown_opacity'], FILTER_VALIDATE_INT);
        $values['nav_dropdown_opacity'] = max(0, min(100, $opacity === false ? (int)snapsmack_menu_appearance_defaults()['nav_dropdown_opacity'] : $opacity));
        return $values;
    }
}

// ===== SNAPSMACK EOF =====
