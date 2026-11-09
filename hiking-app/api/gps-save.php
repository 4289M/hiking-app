<?php
/**
 * POST api/gps-save.php
 * body: {
 *   "hike_id": int,
 *   "points": [
 *     { "id": "client_point_id", "seq": int, "segment": int,
 *       "lat": float, "lon": float, "alt": float|null, "acc": float,
 *       "speed": float|null, "t": ISO8601 }
 *   ]
 * }
 * - บันทึกเป็น batch ใน Transaction เดียว
 * - จุดที่เคยบันทึกแล้ว (seq หรือ id ซ้ำ) จะถูกข้าม แต่ถือว่า "ยืนยันแล้ว" เพื่อให้ client ลบออกจากคิว
 * response: { ok, saved: [ids], rejected: [{id, reason}], point_count }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

[$uid, $body] = api_post_init();
$cfg = app_config('gps');

$hikeId = int_param($body['hike_id'] ?? null);
$points = $body['points'] ?? null;
if (!$hikeId || !is_array($points)) {
    json_error('ข้อมูลไม่ครบ');
}
if (count($points) > $cfg['max_points_per_req']) {
    json_error('ส่งพิกัดได้ไม่เกิน ' . $cfg['max_points_per_req'] . ' จุดต่อครั้ง', 413);
}

$pdo = db();
$hike = find_user_hike($pdo, $hikeId, $uid); // ตรวจเจ้าของกิจกรรมก่อนเสมอ (กัน IDOR)
if (!$hike) {
    json_error('ไม่พบกิจกรรม', 404);
}
if (count($points) === 0) {
    json_out(['ok' => true, 'saved' => [], 'rejected' => [], 'point_count' => (int) $hike['point_count']]);
}

$minTime = db_time_to_dt($hike['started_at'])->modify('-60 seconds');
$maxTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 minutes');
if ($hike['ended_at'] !== null) {
    $maxTime = min($maxTime, db_time_to_dt($hike['ended_at'])->modify('+60 seconds'));
}

$valid = [];
$rejected = [];
$seenSeq = [];
foreach ($points as $p) {
    $cid = is_array($p) && isset($p['id']) && is_string($p['id']) ? $p['id'] : null;
    if ($cid === null || !preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $cid)) {
        $rejected[] = ['id' => is_string($cid) ? mb_substr($cid, 0, 64) : null, 'reason' => 'invalid_id'];
        continue;
    }
    $seq = filter_var($p['seq'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4000000000]]);
    $seg = filter_var($p['segment'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    $lat = filter_var($p['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($p['lon'] ?? null, FILTER_VALIDATE_FLOAT);
    $acc = filter_var($p['acc'] ?? null, FILTER_VALIDATE_FLOAT);
    $alt = isset($p['alt']) && $p['alt'] !== null ? filter_var($p['alt'], FILTER_VALIDATE_FLOAT) : null;
    $spd = isset($p['speed']) && $p['speed'] !== null ? filter_var($p['speed'], FILTER_VALIDATE_FLOAT) : null;
    $t = parse_client_time($p['t'] ?? null);

    $reason = null;
    if ($seq === false || $seg === false) {
        $reason = 'invalid_seq';
    } elseif ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        $reason = 'invalid_coord';
    } elseif ($acc === false || $acc < 0 || $acc > 100000) {
        $reason = 'invalid_accuracy';
    } elseif ($alt === false || ($alt !== null && ($alt < -1000 || $alt > 10000))) {
        $reason = 'invalid_altitude';
    } elseif ($t === null || $t < $minTime || $t > $maxTime) {
        $reason = 'invalid_time';
    } elseif (isset($seenSeq[$seq])) {
        $reason = 'duplicate_in_request';
    }
    if ($reason !== null) {
        $rejected[] = ['id' => $cid, 'reason' => $reason];
        continue;
    }
    $seenSeq[$seq] = true;
    if ($spd === false || ($spd !== null && ($spd < 0 || $spd > 999))) {
        $spd = null;
    }
    $valid[] = [
        $hikeId, $seq, $seg,
        round($lat, 7), round($lon, 7),
        $alt !== null ? round($alt, 2) : null,
        round($acc, 2),
        $spd !== null ? round($spd, 2) : null,
        to_db_time($t), $cid,
    ];
}

$saved = [];
if ($valid) {
    $pdo->beginTransaction();
    try {
        foreach (array_chunk($valid, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
            // ON DUPLICATE KEY: ข้ามจุดที่มีอยู่แล้ว (กันข้อมูลซ้ำเมื่อ sync ซ้ำ)
            $sql = "INSERT INTO hike_points
                      (hike_id, sequence_number, segment, latitude, longitude, altitude, accuracy, speed, recorded_at, client_point_id)
                    VALUES $placeholders
                    ON DUPLICATE KEY UPDATE id = id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge(...$chunk));
            foreach ($chunk as $row) {
                $saved[] = $row[9];
            }
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM hike_points WHERE hike_id = ?');
        $cnt->execute([$hikeId]);
        $pointCount = (int) $cnt->fetchColumn();
        $upd = $pdo->prepare('UPDATE hikes SET point_count = ? WHERE id = ?');
        $upd->execute([$pointCount, $hikeId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
} else {
    $pointCount = (int) $hike['point_count'];
}

json_out(['ok' => true, 'saved' => $saved, 'rejected' => $rejected, 'point_count' => $pointCount]);
