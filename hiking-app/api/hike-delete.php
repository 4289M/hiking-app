<?php
/**
 * POST api/hike-delete.php
 * body: { "hike_id": int }  — ลบกิจกรรมและพิกัดทั้งหมด (FK ON DELETE CASCADE)
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
$del = $pdo->prepare('DELETE FROM hikes WHERE id = ? AND user_id = ?');
$del->execute([$hikeId, $uid]);
$pdo->commit();

json_out(['ok' => true, 'deleted_id' => $hikeId]);
