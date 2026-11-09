<?php
/**
 * POST api/hike-update.php
 * body: { "hike_id": int, "hike_name": string }  — แก้ไขชื่อกิจกรรม
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

[$uid, $body] = api_post_init();
$hikeId = int_param($body['hike_id'] ?? null);
$name = trim((string) ($body['hike_name'] ?? ''));

if (!$hikeId) {
    json_error('ไม่พบรหัสกิจกรรม');
}
if ($name === '' || mb_strlen($name) > 150) {
    json_error('ชื่อกิจกรรมต้องมี 1–150 ตัวอักษร', 422);
}

$pdo = db();
$hike = find_user_hike($pdo, $hikeId, $uid);
if (!$hike) {
    json_error('ไม่พบกิจกรรม', 404);
}

$stmt = $pdo->prepare('UPDATE hikes SET hike_name = ? WHERE id = ? AND user_id = ?');
$stmt->execute([$name, $hikeId, $uid]);

json_out(['ok' => true, 'hike' => hike_to_array(find_user_hike($pdo, $hikeId, $uid))]);
