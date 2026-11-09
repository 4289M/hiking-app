<?php
/**
 * POST api/hike-resume.php
 * body: { "hike_id": int, "at": ISO8601 (เวลาที่กดเดินต่อบนอุปกรณ์) }
 * เพิ่มเวลาพักเข้า paused_seconds และเริ่ม segment ใหม่ (ไม่นับระยะข้ามช่วงพัก)
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
$hike = find_user_hike($pdo, $hikeId, $uid, true);
if (!$hike) {
    $pdo->rollBack();
    json_error('ไม่พบกิจกรรม', 404);
}
if ($hike['status'] === 'finished') {
    $pdo->rollBack();
    json_error('กิจกรรมนี้จบไปแล้ว', 409, ['code' => 'finished']);
}

if ($hike['status'] === 'paused') {
    $pausedAt = db_time_to_dt($hike['paused_at'] ?? $hike['started_at']);
    $at = event_time($body['at'] ?? null, $hike['paused_at'] ?? $hike['started_at']);
    $add = seconds_between($pausedAt, $at);
    $upd = $pdo->prepare(
        "UPDATE hikes SET status = 'recording', paused_at = NULL,
                paused_seconds = paused_seconds + ?, current_segment = current_segment + 1
          WHERE id = ?"
    );
    $upd->execute([$add, $hikeId]);
}
$pdo->commit();

json_out(['ok' => true, 'hike' => hike_to_array(find_user_hike($pdo, $hikeId, $uid))]);
