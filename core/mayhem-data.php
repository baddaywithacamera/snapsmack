<?php
/**
 * SNAPSMACK - Organized Mayhem data source (shared)
 *
 * Single, reusable data endpoint for the Organized Mayhem tabletop engine
 * (assets/js/ss-engine-organized-mayhem.js). Any skin that runs the tabletop
 * — 52 PICKUP, INSTANT CAMERA, etc. — opts in with ONE line near the top of
 * its landing/archive template:
 *
 *     require_once dirname(__DIR__, 2) . '/core/mayhem-data.php';
 *
 * When the request carries ?ajax=mayhem this emits
 *     { "images":[{id,title,src,url}, …], "vitals":{load,ncpu,mem_used_pct} }
 * and exits. Otherwise it only defines helpers (safe to include on render).
 *
 * Sampling is deliberately CHEAP — a PK-range walk, never ORDER BY RAND() —
 * because the 64MB "shared-host defensible" budget is the SERVER's; the browser
 * does the scatter/render work. GramOfSmack fragments (trigram slices, panorama
 * rows, carousel members) are excluded so multi-part assets never scatter out
 * of context. Vitals mirror smack-admin.php (sys_getloadavg + memory).
 *
 * Expects $pdo and the BASE_URL constant from the index.php include context.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('mayhem_mem_limit_bytes')) {
    function mayhem_mem_limit_bytes(): int {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return 0;
        $u = strtolower(substr($v, -1));
        $n = (int) $v;
        return match ($u) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => (int) $v,
        };
    }
}

if (!function_exists('mayhem_vitals')) {
    /**
     * Server vitals for the engine's watchdog. Same source the dashboard reads.
     */
    function mayhem_vitals(): array {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $ncpu = 1;
        if (@is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $ncpu = max(1, preg_match_all('/^processor\s*:/mi', $cpuinfo));
            }
        }
        $limit = mayhem_mem_limit_bytes();
        $mem_pct = $limit > 0 ? (int) round(memory_get_usage(true) / $limit * 100) : 0;
        return [
            'load'         => round((float) ($load[0] ?? 0), 2),
            'ncpu'         => $ncpu,
            'mem_used_pct' => $mem_pct,
        ];
    }
}

if (!function_exists('mayhem_image_pool')) {
    /**
     * A random-ish sample of standalone published images, sampled cheaply via a
     * PK range walk (no filesort). GramOfSmack fragments excluded.
     *
     * @return array<int,array{id:int,title:string,src:string,url:string}>
     */
    function mayhem_image_pool(PDO $pdo, int $count): array {
        $count = max(20, min(600, $count));
        $now   = date('Y-m-d H:i:s');

        $b = $pdo->prepare(
            "SELECT MIN(id) AS lo, MAX(id) AS hi
             FROM snap_images WHERE img_status='published' AND img_date <= ?"
        );
        $b->execute([$now]);
        $bounds = $b->fetch(PDO::FETCH_ASSOC) ?: [];
        $lo = (int) ($bounds['lo'] ?? 0);
        $hi = (int) ($bounds['hi'] ?? 0);
        if ($hi <= 0 || $lo <= 0) return [];

        $sql = "SELECT i.id, i.img_title, i.img_slug, i.img_file, i.img_thumb_aspect
                FROM snap_images i
                LEFT JOIN snap_post_images pi ON pi.image_id = i.id
                LEFT JOIN snap_posts p        ON p.id = pi.post_id
                WHERE i.img_status='published' AND i.img_date <= :now
                  AND i.id >= :floor
                  AND ( pi.image_id IS NULL
                     OR (p.post_type='single' AND p.trigram_id IS NULL) )
                ORDER BY i.id
                LIMIT :lim";
        $stmt = $pdo->prepare($sql);

        $rows = [];
        $seen = [];
        $attempts = 0;
        // Each pass picks a random PK floor and walks forward; dedupe by id.
        // Cap attempts so a sparse/low-id table can't spin.
        while (count($rows) < $count && $attempts < 14) {
            $attempts++;
            $floor = ($attempts === 1) ? $lo : random_int($lo, $hi); // first pass from the bottom guarantees coverage on small sets
            $stmt->bindValue(':now', $now);
            $stmt->bindValue(':floor', $floor, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $count, PDO::PARAM_INT);
            $stmt->execute();
            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$batch) continue;
            foreach ($batch as $r) {
                if (isset($seen[$r['id']])) continue;
                $seen[$r['id']] = true;
                $rows[] = $r;
                if (count($rows) >= $count) break;
            }
        }

        shuffle($rows); // mix the floor-walk batches into a scattered order
        $images = [];
        foreach ($rows as $r) {
            $src = !empty($r['img_thumb_aspect'])
                ? BASE_URL . ltrim($r['img_thumb_aspect'], '/')
                : BASE_URL . ltrim($r['img_file'], '/');
            $images[] = [
                'id'    => (int) $r['id'],
                'title' => $r['img_title'],
                'src'   => $src,
                'url'   => BASE_URL . htmlspecialchars($r['img_slug']),
            ];
        }
        return $images;
    }
}

if (!function_exists('mayhem_build_payload')) {
    function mayhem_build_payload(PDO $pdo, int $count): array {
        return [
            'images' => mayhem_image_pool($pdo, $count),
            'vitals' => mayhem_vitals(),
        ];
    }
}

// ── AJAX endpoint ────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mayhem' && isset($pdo) && $pdo instanceof PDO) {
    header('Content-Type: application/json');
    $count = (int) ($_GET['count'] ?? 120);
    echo json_encode(mayhem_build_payload($pdo, $count));
    exit;
}
// ===== SNAPSMACK EOF =====
