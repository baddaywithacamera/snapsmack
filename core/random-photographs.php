<?php
// SNAPSMACK_EOF_HEADER: keep canonical marker last.
declare(strict_types=1);

/** Small PK-range samples, as in mayhem-data; never a full random sort.
 * Sparse IDs can bias floor sampling. This is a scattered display pool, not
 * a statistically uniform sampling API. Only the bounded result is shuffled.
 */
function snap_random_public_images(PDO $pdo, int $limit, array $columns): array {
    $allowed = ['id','img_title','img_slug','img_alt','img_file','img_width',
        'img_height','img_thumb_square','img_thumb_aspect','allow_comments'];
    if (!in_array('id', $columns, true) || array_diff($columns, $allowed)) {
        throw new InvalidArgumentException('Unsupported random photograph projection');
    }
    $limit = max(1, min(400, $limit));
    $bounds = $pdo->query("SELECT MIN(id) AS lo, MAX(id) AS hi FROM snap_images
        WHERE img_status='published' AND img_date <= NOW()")->fetch(PDO::FETCH_ASSOC);
    if ($bounds['lo'] === null) return [];
    $lo = (int)$bounds['lo']; $hi = (int)$bounds['hi'];
    $projection = implode(',', $columns);
    $statement = $pdo->prepare("SELECT $projection FROM snap_images
        WHERE id >= :floor AND img_status='published' AND img_date <= NOW()
        ORDER BY id LIMIT :count");
    $rows = [];
    $slice = max(1, (int)ceil($limit / 8));
    for ($attempt = 0; count($rows) < $limit && $attempt < 24; $attempt++) {
        $statement->bindValue(':floor', random_int($lo, $hi), PDO::PARAM_INT);
        $statement->bindValue(':count', $slice, PDO::PARAM_INT);
        $statement->execute();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = $row;
            if (count($rows) >= $limit) break;
        }
    }
    if (count($rows) < $limit) {
        // A bounded prefix fills small/sparse pools without duplicate results.
        $statement->bindValue(':floor', $lo, PDO::PARAM_INT);
        $statement->bindValue(':count', $limit, PDO::PARAM_INT);
        $statement->execute();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = $row;
            if (count($rows) >= $limit) break;
        }
    }
    $rows = array_values($rows);
    shuffle($rows);
    return $rows;
}
// ===== SNAPSMACK EOF =====
