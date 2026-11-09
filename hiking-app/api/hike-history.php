<?php
/**
 * GET api/hike-history.php?from=YYYY-MM-DD&to=YYYY-MM-DD&page=1&per_page=20
 * วันที่เป็นเขตเวลาที่แสดงผล (Asia/Bangkok) จะแปลงเป็น UTC ก่อนค้นหา
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

api_require_method('GET');
$uid = api_require_login();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
[$where, $params] = history_filter($uid, $_GET['from'] ?? null, $_GET['to'] ?? null);

$pdo = db();
$cnt = $pdo->prepare("SELECT COUNT(*) FROM hikes WHERE $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM hikes WHERE $where ORDER BY started_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);

$items = array_map(static function (array $h): array {
    $a = hike_to_array($h);
    $a['avg_speed_mps'] = round(avg_speed_mps($h), 3);
    return $a;
}, $stmt->fetchAll());

json_out([
    'ok'       => true,
    'page'     => $page,
    'per_page' => $perPage,
    'total'    => $total,
    'items'    => $items,
]);
