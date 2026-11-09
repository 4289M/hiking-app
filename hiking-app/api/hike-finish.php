<?php
/**
 * POST api/hike-finish.php
 * body: { "hike_id": int, "at": ISO8601, "hike_name"?: string }
 * - ถ้ากำลังพักอยู่ จะนับเวลาพักถึงเวลาที่กดจบ
 * - คำนวณระยะทาง/สถิติใหม่จากพิกัดทั้งหมดในฐานข้อมูล (server เป็นตัวตัดสินสุดท้าย)
 * เรียกซ้ำได้: ถ้าจบไปแล้ว จะคืนข้อมูลเดิม
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

[$uid, $body] = api_post_init();
$hikeId = int_param($body['hike_id'] ?? null);
if (!$hikeId) {
    json_error('ไม่พบรหัสกิจกรรม');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $hike = find_user_hike($pdo, $hikeId, $uid, true);
    if (!$hike) {
        $pdo->rollBack();
        json_error('ไม่พบกิจกรรม', 404);
    }

    if ($hike['status'] !== 'finished') {
        $started = db_time_to_dt($hike['started_at']);
        $endedAt = event_time($body['at'] ?? null, $hike['paused_at'] ?? $hike['started_at']);
        $pausedSeconds = (int) $hike['paused_seconds'];
        if ($hike['status'] === 'paused' && $hike['paused_at'] !== null) {
            $pausedSeconds += seconds_between(db_time_to_dt($hike['paused_at']), $endedAt);
        }
        $total = seconds_between($started, $endedAt);
        $pausedSeconds = min($pausedSeconds, $total);
        $duration = $total - $pausedSeconds;

        $name = trim((string) ($body['hike_name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 150) : $hike['hike_name'];

        $upd = $pdo->prepare(
            "UPDATE hikes SET status = 'finished', ended_at = ?, paused_at = NULL,
                    paused_seconds = ?, duration_seconds = ?, hike_name = ?
              WHERE id = ?"
        );
        $upd->execute([to_db_time($endedAt), $pausedSeconds, $duration, $name, $hikeId]);
    }

    recalc_hike_stats($pdo, $hikeId);
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $ex;
}

$hike = find_user_hike($pdo, $hikeId, $uid);
json_out(['ok' => true, 'hike' => hike_to_array($hike)]);
