<?php
/**
 * GET api/export-gpx.php?id=123
 * ดาวน์โหลดเส้นทางเป็นไฟล์ GPX 1.1 (เปิดได้ใน Google Earth, Strava, Garmin ฯลฯ)
 * แต่ละ segment (ช่วงระหว่าง Pause) เป็น <trkseg> แยกกัน
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$uid = current_user_id();
if ($uid === null) {
    http_response_code(401);
    exit('กรุณาเข้าสู่ระบบ');
}
$hikeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pdo = db();
$hike = $hikeId ? find_user_hike($pdo, (int) $hikeId, $uid) : null;
if (!$hike) {
    http_response_code(404);
    exit('ไม่พบกิจกรรม');
}

$x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$filename = 'hike-' . $hike['id'] . '-' . db_time_to_dt($hike['started_at'])->format('Ymd') . '.gpx';

header('Content-Type: application/gpx+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<gpx version="1.1" creator="Hiking GPS Tracker" xmlns="http://www.topografix.com/GPX/1/1"'
    . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    . ' xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd">' . "\n";
echo '  <metadata><name>' . $x($hike['hike_name']) . '</name><time>' . db_time_iso($hike['started_at']) . "</time></metadata>\n";
echo '  <trk><name>' . $x($hike['hike_name']) . "</name>\n";

$stmt = $pdo->prepare(
    'SELECT segment, latitude, longitude, altitude, accuracy, recorded_at
       FROM hike_points WHERE hike_id = ? ORDER BY sequence_number'
);
$stmt->execute([$hike['id']]);

$currentSeg = null;
while ($p = $stmt->fetch()) {
    if ($currentSeg !== (int) $p['segment']) {
        if ($currentSeg !== null) {
            echo "    </trkseg>\n";
        }
        echo "    <trkseg>\n";
        $currentSeg = (int) $p['segment'];
    }
    echo '      <trkpt lat="' . $p['latitude'] . '" lon="' . $p['longitude'] . '">';
    if ($p['altitude'] !== null) {
        echo '<ele>' . $p['altitude'] . '</ele>';
    }
    echo '<time>' . db_time_iso($p['recorded_at']) . '</time>';
    echo '<hdop>' . round(((float) $p['accuracy']) / 5, 1) . '</hdop>';
    echo "</trkpt>\n";
}
if ($currentSeg !== null) {
    echo "    </trkseg>\n";
}
echo "  </trk>\n</gpx>\n";
