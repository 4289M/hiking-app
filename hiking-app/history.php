<?php
/** history.php — ประวัติกิจกรรม พร้อมค้นหาตามช่วงวันที่ */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$uid = require_login();
$pdo = db();

$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';

$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
[$where, $params] = history_filter($uid, $from ?: null, $to ?: null);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM hikes WHERE $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM hikes WHERE $where ORDER BY started_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$hikes = $stmt->fetchAll();

$qs = static fn (array $extra): string => http_build_query(array_filter(['from' => $from, 'to' => $to] + $extra));

$pageTitle = 'ประวัติกิจกรรม';
$activeNav = 'history';
$pageScripts = ['history.js'];
require __DIR__ . '/includes/header.php';
?>
<header class="page-head">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <h1 class="h4 fw-bold mb-0">ประวัติกิจกรรม</h1>
            <a href="api/export-csv.php?<?= e($qs(['all' => 1])) ?>" class="btn btn-outline-forest btn-sm"><i class="bi bi-filetype-csv"></i> Export CSV</a>
        </div>
        <form method="get" action="history.php" class="row g-2 align-items-end">
            <div class="col">
                <label for="from" class="form-label small text-secondary mb-1">ตั้งแต่วันที่</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>" class="form-control">
            </div>
            <div class="col">
                <label for="to" class="form-label small text-secondary mb-1">ถึงวันที่</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>" class="form-control">
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-forest btn-icon" aria-label="ค้นหา"><i class="bi bi-search"></i></button>
                <?php if ($from || $to): ?>
                <a href="history.php" class="btn btn-light btn-icon" aria-label="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</header>

<main class="container py-3">
    <p class="small text-secondary mb-2">ทั้งหมด <?= number_format($total) ?> กิจกรรม</p>

    <?php if (!$hikes): ?>
    <div class="empty-state">
        <i class="bi bi-search"></i>
        <p class="mb-0"><?= ($from || $to) ? 'ไม่พบกิจกรรมในช่วงวันที่ที่เลือก' : 'ยังไม่มีกิจกรรม' ?></p>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($hikes as $h): ?>
        <div class="col-12 col-md-6 col-xl-4" id="hike-<?= (int) $h['id'] ?>">
            <article class="card hike-card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <h2 class="h6 fw-semibold mb-0 text-truncate hike-name"><?= e($h['hike_name']) ?></h2>
                            <div class="small text-secondary"><?= e(thai_datetime($h['started_at'])) ?></div>
                            <?php if ($h['status'] !== 'finished'): ?>
                            <span class="badge text-bg-warning mt-1"><?= $h['status'] === 'paused' ? 'หยุดพัก' : 'กำลังบันทึก' ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold fs-5 text-forest"><?= format_km($h['distance_meters']) ?> กม.</div>
                            <div class="small text-secondary"><?= format_duration((int) $h['duration_seconds']) ?></div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-auto">
                        <?php if ($h['status'] === 'finished'): ?>
                        <a href="hike-detail.php?id=<?= (int) $h['id'] ?>" class="btn btn-forest flex-fill">ดูรายละเอียด</a>
                        <?php else: ?>
                        <a href="tracking.php" class="btn btn-forest flex-fill">ไปบันทึกต่อ</a>
                        <?php endif; ?>
                        <a href="api/export-gpx.php?id=<?= (int) $h['id'] ?>" class="btn btn-outline-secondary">GPX</a>
                        <button type="button" class="btn btn-outline-danger btn-icon"
                                data-delete-hike="<?= (int) $h['id'] ?>" data-hike-name="<?= e($h['hike_name']) ?>"
                                aria-label="ลบกิจกรรม <?= e($h['hike_name']) ?>"><i class="bi bi-trash3"></i></button>
                    </div>
                </div>
            </article>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <nav class="mt-4" aria-label="หน้า">
        <ul class="pagination justify-content-center">
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="?<?= e($qs(['page' => $page - 1])) ?>">ก่อนหน้า</a></li>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
            <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="?<?= e($qs(['page' => $page + 1])) ?>">ถัดไป</a></li>
        </ul>
    </nav>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/partials-delete-modal.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
