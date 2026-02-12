<?php
/**
 * Smack-EXIF Helper
 * Formats raw rational EXIF data into human-readable photography terms.
 * Version: 2.4 - Precision Rounding & Shutter Fix
 */

function get_smack_exif($data) {
    if (!$data || !is_array($data)) return null;

    $formatted = [];

    // 1. Camera Model
    $val_model = ($data['Model'] ?? 'Unknown Camera');
    $formatted['camera'] = ($val_model === 'N/A' || empty($val_model)) ? 'Unknown Camera' : strtoupper($val_model);

    // 2. Aperture (FNumber)
    $formatted['aperture'] = 'f/0'; 
    if (isset($data['FNumber']) && $data['FNumber'] !== 'N/A') {
        $val = smack_evaluate_fraction($data['FNumber']);
        if ($val > 0) {
            $formatted['aperture'] = 'f/' . round($val, 1);
        }
    }

    // 3. Shutter Speed (ExposureTime) - THE S23 FIX
    $formatted['shutter'] = 'N/A';
    // Check ExposureTime first, then ShutterSpeedValue
    $raw_shutter = $data['ExposureTime'] ?? ($data['ShutterSpeedValue'] ?? 'N/A');

    if ($raw_shutter !== 'N/A') {
        $val = smack_evaluate_fraction($raw_shutter);
        if ($val > 0) {
            if ($val < 1) {
                // Round to the nearest whole denominator for clean fractions
                $formatted['shutter'] = '1/' . round(1 / $val);
            } else {
                // For long exposures, show the decimal seconds (e.g. 1.5s)
                $formatted['shutter'] = round($val, 1) . 's';
            }
        }
    }

    // 4. ISO
    $formatted['iso'] = (isset($data['ISOSpeedRatings']) && $data['ISOSpeedRatings'] !== 'N/A') 
                        ? $data['ISOSpeedRatings'] 
                        : 'N/A';

    // 5. Focal Length
    $formatted['focal'] = '0mm'; 
    if (isset($data['FocalLength']) && $data['FocalLength'] !== 'N/A') {
        $val = smack_evaluate_fraction($data['FocalLength']);
        if ($val > 0) {
            $formatted['focal'] = round($val, 1) . 'mm';
        }
    }

    // 6. Flash
    $raw_flash = $data['Flash'] ?? ($data['flash_fire'] ?? 'No');
    if (is_numeric($raw_flash)) {
        $formatted['flash'] = ($raw_flash % 2 !== 0) ? 'Yes' : 'No';
    } else {
        $formatted['flash'] = (strtoupper($raw_flash) === 'YES') ? 'Yes' : 'No';
    }

    // 7. Manual Overrides (Film/Lens)
    $formatted['lens'] = $data['lens'] ?? '';
    $formatted['film'] = $data['film'] ?? '';

    return $formatted;
}

/**
 * Safely converts string fractions or decimals into floats.
 */
function smack_evaluate_fraction($fraction) {
    if (is_numeric($fraction)) return (float)$fraction;
    if (!$fraction || $fraction === 'N/A') return 0.0;

    // Handle strings like "1/125" or "58333333/1000000000"
    if (strpos($fraction, '/') !== false) {
        $parts = explode('/', $fraction);
        if (count($parts) == 2) {
            $num = (float)trim($parts[0]);
            $den = (float)trim($parts[1]);
            if ($den != 0) {
                return $num / $den;
            }
        }
    }
    
    // Fallback for decimals stored as strings
    return is_numeric($fraction) ? (float)$fraction : 0.0;
}