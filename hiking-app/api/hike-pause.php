<?php
/**
 * POST api/hike-pause.php
 * body: { "hike_id": int, "at": ISO8601 (เวลาที่กดพักบนอุปกรณ์) }
 * เรียกซ้ำได้ (idempotent): ถ้าพักอยู่แล้วจะไม่เปลี่ยนแปลง
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

if ($hike['status'] === 'recording') {
    $at = event_time($body['at'] ?? null, $hike['started_at']);
    $upd = $pdo->prepare("UPDATE hikes SET status = 'paused', paused_at = ? WHERE id = ?");
    $upd->execute([to_db_time($at), $hikeId]);
}
$pdo->commit();

json_out(['ok' => true, 'hike' => hike_to_array(find_user_hike($pdo, $hikeId, $uid))]);
