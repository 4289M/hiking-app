<?php
/**
 * GET api/export-csv.php?id=123              สถิติ + พิกัดของกิจกรรมเดียว
 * GET api/export-csv.php?all=1&from=&to=     สถิติของทุกกิจกรรม (ตามช่วงวันที่)
 * ไฟล์มี UTF-8 BOM เพื่อให้ Excel แสดงภาษาไทยถูกต้อง
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$uid = current_user_id();
if ($uid === null) {
    http_response_code(401);
    exit('กรุณาเข้าสู่ระบบ');
}
$pdo = db();

/** กัน CSV/Formula injection เมื่อเปิดใน Excel */
function csv_safe(mixed $v): string
{
    $s = (string) $v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
        $s = "'" . $s;
    }
    return $s;
}

function csv_row($out, array $row): void
{
    fputcsv($out, array_map('csv_safe', $row), ',', '"', '\\');
}

function hike_stat_row(array $h): array
{
    return [
        $h['id'],
        $h['hike_name'],
        thai_time($h['started_at'], 'Y-m-d H:i:s'),
        $h['ended_at'] ? thai_time($h['ended_at'], 'Y-m-d H:i:s') : '',
        $h['status'],
        number_format(((float) $h['distance_meters']) / 1000, 3, '.', ''),
        format_duration((int) $h['duration_seconds']),
        format_duration((int) $h['paused_seconds']),
        format_duration((int) $h['duration_seconds'] + (int) $h['paused_seconds']),
        number_format(avg_speed_mps($h) * 3.6, 2, '.', ''),
        number_format(((float) $h['max_speed_mps']) * 3.6, 2, '.', ''),
        $h['min_altitude'] ?? '',
        $h['max_altitude'] ?? '',
        $h['point_count'],
    ];
}

$statHeader = ['id', 'ชื่อกิจกรรม', 'เริ่ม (เวลาไทย)', 'จบ (เวลาไทย)', 'สถานะ', 'ระยะทาง (กม.)',
    'เวลาเดินจริง', 'เวลาพัก', 'เวลารวม', 'ความเร็วเฉลี่ย (กม./ชม.)', 'ความเร็วสูงสุด (กม./ชม.)',
    'ความสูงต่ำสุด (ม.)', 'ความสูงสูงสุด (ม.)', 'จำนวนจุด GPS'];

if (!empty($_GET['all'])) {
    [$where, $params] = history_filter($uid, $_GET['from'] ?? null, $_GET['to'] ?? null);
    $stmt = $pdo->prepare("SELECT * FROM hikes WHERE $where ORDER BY started_at DESC");
    $stmt->execute($params);
    $filename = 'hikes-' . date('Ymd') . '.csv';
    $hikes = $stmt->fetchAll();
    $single = null;
} else {
    $hikeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $single = $hikeId ? find_user_hike($pdo, (int) $hikeId, $uid) : null;
    if (!$single) {
        http_response_code(404);
        exit('ไม่พบกิจกรรม');
    }
    $hikes = [$single];
    $filename = 'hike-' . $single['id'] . '-' . db_time_to_dt($single['started_at'])->format('Ymd') . '.csv';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
csv_row($out, $statHeader);
foreach ($hikes as $h) {
    csv_row($out, hike_stat_row($h));
}

if ($single) {
    csv_row($out, []);
    csv_row($out, ['ลำดับ', 'ช่วง (segment)', 'เวลา (เวลาไทย)', 'latitude', 'longitude', 'altitude (ม.)', 'accuracy (ม.)', 'speed (กม./ชม.)']);
    $stmt = $pdo->prepare(
        'SELECT sequence_number, segment, recorded_at, latitude, longitude, altitude, accuracy, speed
           FROM hike_points WHERE hike_id = ? ORDER BY sequence_number'
    );
    $stmt->execute([$single['id']]);
    while ($p = $stmt->fetch()) {
        csv_row($out, [
            $p['sequence_number'],
            $p['segment'],
            thai_time($p['recorded_at'], 'Y-m-d H:i:s.v'),
            $p['latitude'],
            $p['longitude'],
            $p['altitude'] ?? '',
            $p['accuracy'],
            $p['speed'] !== null ? number_format(((float) $p['speed']) * 3.6, 2, '.', '') : '',
        ]);
    }
}
fclose($out);
