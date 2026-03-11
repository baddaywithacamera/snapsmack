<?php
/**
 * SNAPSMACK - EXIF Data Formatter
 * Alpha v0.7.1
 *
 * Converts raw EXIF data (stored as JSON) into human-readable photography
 * metadata. Handles rational number conversion for aperture, shutter speed,
 * ISO, and focal length. Supports manual overrides for lens and film type.
 */

function get_smack_exif($data) {
    if (!$data || !is_array($data)) return null;

    $formatted = [];

    // --- CAMERA MODEL ---
    $val_model = ($data['Model'] ?? 'Unknown Camera');
    $formatted['camera'] = ($val_model === 'N/A' || empty($val_model)) ? 'Unknown Camera' : strtoupper($val_model);

    // --- APERTURE (F-NUMBER) ---
    $formatted['aperture'] = 'f/0';
    if (isset($data['FNumber']) && $data['FNumber'] !== 'N/A') {
        $val = smack_evaluate_fraction($data['FNumber']);
        if ($val > 0) {
            $formatted['aperture'] = 'f/' . round($val, 1);
        }
    }

    // --- SHUTTER SPEED (EXPOSURE TIME) ---
    // Check ExposureTime first, then fall back to ShutterSpeedValue
    $formatted['shutter'] = 'N/A';
    $raw_shutter = $data['ExposureTime'] ?? ($data['ShutterSpeedValue'] ?? 'N/A');

    if ($raw_shutter !== 'N/A') {
        $val = smack_evaluate_fraction($raw_shutter);
        if ($val > 0) {
            if ($val < 1) {
                // For fast shutter speeds, display as fractions (e.g. 1/125)
                $formatted['shutter'] = '1/' . round(1 / $val);
            } else {
                // For long exposures, show decimal seconds (e.g. 1.5s)
                $formatted['shutter'] = round($val, 1) . 's';
            }
        }
    }

    // --- ISO ---
    $formatted['iso'] = (isset($data['ISOSpeedRatings']) && $data['ISOSpeedRatings'] !== 'N/A')
                        ? $data['ISOSpeedRatings']
                        : 'N/A';

    // --- FOCAL LENGTH ---
    $formatted['focal'] = '0mm';
    if (isset($data['FocalLength']) && $data['FocalLength'] !== 'N/A') {
        $val = smack_evaluate_fraction($data['FocalLength']);
        if ($val > 0) {
            $formatted['focal'] = round($val, 1) . 'mm';
        }
    }

    // --- FLASH ---
    $raw_flash = $data['Flash'] ?? ($data['flash_fire'] ?? 'No');
    if (is_numeric($raw_flash)) {
        // EXIF standard: even numbers = no flash, odd numbers = flash fired
        $formatted['flash'] = ($raw_flash % 2 !== 0) ? 'Yes' : 'No';
    } else {
        $formatted['flash'] = (strtoupper($raw_flash) === 'YES') ? 'Yes' : 'No';
    }

    // --- MANUAL OVERRIDES ---
    // Lens and film type are not in standard EXIF, so allow manual entry
    $formatted['lens'] = $data['lens'] ?? '';
    $formatted['film'] = $data['film'] ?? '';

    return $formatted;
}

/**
 * Converts string fractions or decimals to floats.
 * Handles formats like "1/125", "58333333/1000000000", or "0.5"
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
