<?php
/**
 * includes/functions.php
 * ฟังก์ชันช่วยทั่วไป: escape, เวลา, รูปแบบตัวเลข, Haversine และการคำนวณสถิติเส้นทาง
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('UTC');

if (app_config('app')['debug'] ?? false) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

/** Escape สำหรับแสดงใน HTML (กัน XSS) */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** ข้อความแจ้งเตือนครั้งเดียว (flash) ผ่าน session */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------
 * เวลา
 * ------------------------------------------------------------------ */

/** แปลงสตริงเวลาจาก client (ISO 8601) เป็น DateTimeImmutable UTC, คืน null ถ้าไม่ถูกต้อง */
function parse_client_time(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || strlen($value) > 40) {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'));
}

/** รูปแบบสำหรับบันทึกลง DATETIME(3) */
function to_db_time(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

function db_time_to_dt(string $dbTime): DateTimeImmutable
{
    return new DateTimeImmutable($dbTime, new DateTimeZone('UTC'));
}

/** มิลลิวินาทีแบบ epoch จากค่าเวลาใน DB */
function db_time_ms(string $dbTime): int
{
    $dt = db_time_to_dt($dbTime);
    return (int) $dt->format('U') * 1000 + (int) $dt->format('v');
}

/** ISO 8601 (UTC, มี Z) สำหรับส่งให้ JavaScript */
function db_time_iso(?string $dbTime): ?string
{
    if ($dbTime === null) {
        return null;
    }
    return db_time_to_dt($dbTime)->format('Y-m-d\TH:i:s.v\Z');
}

/** แสดงวันเวลาแบบไทย เช่น "14 ต.ค. 2569 · 07:12 น." */
function thai_datetime(?string $dbTime, bool $withTime = true): string
{
    if ($dbTime === null || $dbTime === '') {
        return '-';
    }
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $dt = db_time_to_dt($dbTime)->setTimezone(new DateTimeZone(app_config('app')['display_timezone']));
    $s = (int) $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . ((int) $dt->format('Y') + 543);
    if ($withTime) {
        $s .= ' · ' . $dt->format('H:i') . ' น.';
    }
    return $s;
}

function thai_time(?string $dbTime, string $format = 'H:i:s'): string
{
    if (!$dbTime) {
        return '-';
    }
    return db_time_to_dt($dbTime)
        ->setTimezone(new DateTimeZone(app_config('app')['display_timezone']))
        ->format($format);
}

/** วินาที → H:MM:SS */
function format_duration(int|float $seconds): string
{
    $seconds = max(0, (int) round($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}

function format_km(float|string $meters, int $decimals = 2): string
{
    return number_format(((float) $meters) / 1000, $decimals);
}

/** m/s → km/h */
function format_kmh(float|string $mps, int $decimals = 1): string
{
    return number_format(((float) $mps) * 3.6, $decimals);
}

/* ------------------------------------------------------------------
 * GPS
 * ------------------------------------------------------------------ */

/** ระยะทางระหว่าง 2 พิกัด (เมตร) ด้วยสูตร Haversine */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371008.8; // รัศมีโลกเฉลี่ย (เมตร)
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

/**
 * คำนวณสถิติเส้นทางจากรายการจุด (เรียงตาม sequence แล้ว)
 * ใช้อัลกอริทึมเดียวกับฝั่ง client (tracking.js → TrackCalculator):
 *  - ข้ามจุดที่ accuracy > max_accuracy_m
 *  - ไม่นับระยะทางข้าม segment (ช่วง Pause)
 *  - นับระยะจาก "จุดยึด" (anchor) เมื่อขยับเกิน threshold เท่านั้น เพื่อกรอง GPS drift
 *  - ทิ้งจุดที่ความเร็วโดยนัยเกิน max_speed_mps (พิกัดกระโดด)
 *
 * @param array<int,array{latitude:mixed,longitude:mixed,accuracy:mixed,altitude:mixed,recorded_at:string,segment:mixed}> $points
 */
function compute_track_stats(array $points): array
{
    $cfg = app_config('gps');
    $distance = 0.0;
    $maxSpeed = 0.0;
    $minAlt = null;
    $maxAlt = null;
    $anchor = null;
    $anchorSegment = null;

    foreach ($points as $p) {
        $acc = (float) $p['accuracy'];
        if ($acc > $cfg['max_accuracy_m']) {
            continue;
        }
        $lat = (float) $p['latitude'];
        $lon = (float) $p['longitude'];
        $t = db_time_ms($p['recorded_at']);
        $seg = (int) $p['segment'];

        if ($p['altitude'] !== null && $p['altitude'] !== '') {
            $alt = (float) $p['altitude'];
            $minAlt = $minAlt === null ? $alt : min($minAlt, $alt);
            $maxAlt = $maxAlt === null ? $alt : max($maxAlt, $alt);
        }

        if ($anchor === null || $anchorSegment !== $seg) {
            $anchor = ['lat' => $lat, 'lon' => $lon, 't' => $t, 'acc' => $acc];
            $anchorSegment = $seg;
            continue;
        }

        $d = haversine($anchor['lat'], $anchor['lon'], $lat, $lon);
        $dt = ($t - $anchor['t']) / 1000;
        if ($dt <= 0) {
            continue;
        }
        $threshold = max($cfg['min_move_m'], min($acc, $anchor['acc']) * 0.5);
        if ($d < $threshold) {
            continue; // ยังไม่ขยับพอ — น่าจะเป็น drift
        }
        $speed = $d / $dt;
        if ($speed > $cfg['max_speed_mps']) {
            continue; // พิกัดกระโดดผิดปกติ
        }
        $distance += $d;
        $maxSpeed = max($maxSpeed, $speed);
        $anchor = ['lat' => $lat, 'lon' => $lon, 't' => $t, 'acc' => $acc];
    }

    return [
        'distance_meters' => round($distance, 2),
        'max_speed_mps'   => round($maxSpeed, 2),
        'min_altitude'    => $minAlt,
        'max_altitude'    => $maxAlt,
    ];
}

/** โหลดจุดทั้งหมดของกิจกรรม แล้วคำนวณและบันทึกสถิติลง hikes */
function recalc_hike_stats(PDO $pdo, int $hikeId): array
{
    $stmt = $pdo->prepare(
        'SELECT latitude, longitude, accuracy, altitude, recorded_at, segment
           FROM hike_points WHERE hike_id = ? ORDER BY sequence_number'
    );
    $stmt->execute([$hikeId]);
    $points = $stmt->fetchAll();
    $stats = compute_track_stats($points);
    $stats['point_count'] = count($points);

    $upd = $pdo->prepare(
        'UPDATE hikes SET distance_meters = ?, max_speed_mps = ?, min_altitude = ?, max_altitude = ?, point_count = ?
          WHERE id = ?'
    );
    $upd->execute([
        $stats['distance_meters'],
        $stats['max_speed_mps'],
        $stats['min_altitude'],
        $stats['max_altitude'],
        $stats['point_count'],
        $hikeId,
    ]);
    return $stats;
}

/** ดึงกิจกรรมที่เป็นของผู้ใช้คนนี้เท่านั้น (กัน IDOR) */
function find_user_hike(PDO $pdo, int $hikeId, int $userId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM hikes WHERE id = ? AND user_id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hikeId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** ข้อมูลกิจกรรมสำหรับส่งเป็น JSON */
function hike_to_array(array $h): array
{
    return [
        'id'               => (int) $h['id'],
        'hike_name'        => $h['hike_name'],
        'status'           => $h['status'],
        'started_at'       => db_time_iso($h['started_at']),
        'ended_at'         => db_time_iso($h['ended_at']),
        'paused_at'        => db_time_iso($h['paused_at']),
        'duration_seconds' => (int) $h['duration_seconds'],
        'paused_seconds'   => (int) $h['paused_seconds'],
        'distance_meters'  => (float) $h['distance_meters'],
        'max_speed_mps'    => (float) $h['max_speed_mps'],
        'min_altitude'     => $h['min_altitude'] !== null ? (float) $h['min_altitude'] : null,
        'max_altitude'     => $h['max_altitude'] !== null ? (float) $h['max_altitude'] : null,
        'point_count'      => (int) $h['point_count'],
        'current_segment'  => (int) $h['current_segment'],
    ];
}

/**
 * แปลงช่วงวันที่ (YYYY-MM-DD ตามเวลาท้องถิ่น) เป็นเงื่อนไข SQL ที่เทียบกับเวลา UTC
 * ใช้ร่วมกันระหว่าง history.php, api/hike-history.php และ api/export-csv.php
 */
function history_filter(int $uid, ?string $from, ?string $to): array
{
    $tz = new DateTimeZone(app_config('app')['display_timezone']);
    $where = ['user_id = ?'];
    $params = [$uid];
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        if ($d) {
            $where[] = 'started_at >= ?';
            $params[] = to_db_time($d);
        }
    }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if ($d) {
            $where[] = 'started_at < ?';
            $params[] = to_db_time($d->modify('+1 day'));
        }
    }
    return [implode(' AND ', $where), $params];
}

/** ความเร็วเฉลี่ย (m/s) จากระยะทางและเวลาเดินจริง */
function avg_speed_mps(array $h): float
{
    $d = (int) $h['duration_seconds'];
    return $d > 0 ? ((float) $h['distance_meters']) / $d : 0.0;
}
