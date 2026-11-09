<?php
/**
 * POST api/hike-start.php
 * body: { "hike_name"?: string, "started_at"?: ISO8601 }
 * ถ้ามีกิจกรรมที่ยังไม่จบอยู่แล้ว จะคืนกิจกรรมนั้น (code: active_exists) แทนการสร้างใหม่
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

[$uid, $body] = api_post_init();
$pdo = db();

$pdo->beginTransaction();
// ล็อกแถวกิจกรรมที่ยังไม่จบของผู้ใช้ กันการกด Start ซ้ำพร้อมกัน
$stmt = $pdo->prepare("SELECT * FROM hikes WHERE user_id = ? AND status IN ('recording','paused') ORDER BY id DESC LIMIT 1 FOR UPDATE");
$stmt->execute([$uid]);
$active = $stmt->fetch();
if ($active) {
    $pdo->commit();
    json_out(['ok' => true, 'code' => 'active_exists', 'hike' => hike_to_array($active)]);
}

$startedAt = event_time($body['started_at'] ?? null);
$name = trim((string) ($body['hike_name'] ?? ''));
if ($name === '') {
    $local = $startedAt->setTimezone(new DateTimeZone(app_config('app')['display_timezone']));
    $name = 'เดินป่า ' . $local->format('d/m/') . ((int) $local->format('Y') + 543) . ' ' . $local->format('H:i');
}
$name = mb_substr($name, 0, 150);

$ins = $pdo->prepare("INSERT INTO hikes (user_id, hike_name, started_at, status) VALUES (?, ?, ?, 'recording')");
$ins->execute([$uid, $name, to_db_time($startedAt)]);
$id = (int) $pdo->lastInsertId();
$pdo->commit();

$hike = find_user_hike($pdo, $id, $uid);
json_out(['ok' => true, 'code' => 'created', 'hike' => hike_to_array($hike)], 201);
