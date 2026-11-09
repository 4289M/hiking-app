<?php
/**
 * GET api/hike-detail.php?id=123[&points=0]
 * คืนข้อมูลกิจกรรม + พิกัดทั้งหมด (เฉพาะเจ้าของเท่านั้น)
 * พิกัดส่งแบบ array กะทัดรัด: [seq, segment, lat, lon, alt, acc, t_ms]
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

api_require_method('GET');
$uid = api_require_login();
$hikeId = int_param($_GET['id'] ?? null);
if (!$hikeId) {
    json_error('ไม่พบรหัสกิจกรรม');
}

$pdo = db();
$hike = find_user_hike($pdo, $hikeId, $uid);
if (!$hike) {
    json_error('ไม่พบกิจกรรม', 404);
}

$out = ['ok' => true, 'hike' => hike_to_array($hike) + ['avg_speed_mps' => round(avg_speed_mps($hike), 3)]];

if (($_GET['points'] ?? '1') !== '0') {
    $stmt = $pdo->prepare(
        'SELECT sequence_number, segment, latitude, longitude, altitude, accuracy, recorded_at
           FROM hike_points WHERE hike_id = ? ORDER BY sequence_number'
    );
    $stmt->execute([$hikeId]);
    $pts = [];
    while ($r = $stmt->fetch()) {
        $pts[] = [
            (int) $r['sequence_number'],
            (int) $r['segment'],
            (float) $r['latitude'],
            (float) $r['longitude'],
            $r['altitude'] !== null ? (float) $r['altitude'] : null,
            (float) $r['accuracy'],
            db_time_ms($r['recorded_at']),
        ];
    }
    $out['points'] = $pts;
}

json_out($out);
