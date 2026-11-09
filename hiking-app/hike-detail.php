<?php
/** hike-detail.php?id=123 — รายละเอียดกิจกรรม แผนที่ย้อนหลัง และรายการจุด GPS */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$uid = require_login();
$pdo = db();

$hikeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hike = $hikeId ? find_user_hike($pdo, (int) $hikeId, $uid) : null;
if (!$hike) {
    flash('warning', 'ไม่พบกิจกรรม หรือคุณไม่มีสิทธิ์ดูกิจกรรมนี้');
    redirect('history.php');
}

// รายการจุด GPS แบบแบ่งหน้า
$perPage = 50;
$pages = max(1, (int) ceil(((int) $hike['point_count']) / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare(
    "SELECT sequence_number, segment, latitude, longitude, altitude, accuracy, recorded_at
       FROM hike_points WHERE hike_id = ? ORDER BY sequence_number LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$hike['id']]);
$points = $stmt->fetchAll();

$totalSeconds = (int) $hike['duration_seconds'] + (int) $hike['paused_seconds'];

$pageTitle = $hike['hike_name'];
$activeNav = 'history';
$useMap = true;
$pageScripts = ['map.js', 'detail.js', 'history.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="detail-layout" data-hike-id="<?= (int) $hike['id'] ?>">
    <div class="detail-map-wrap">
        <div id="detailMap" class="detail-map" role="region" aria-label="แผนที่เส้นทางย้อนหลัง"></div>
        <a href="history.php" class="btn-map detail-back" aria-label="กลับไปประวัติ"><i class="bi bi-chevron-left"></i></a>
        <div id="mapLoading" class="map-loading"><div class="spinner-border text-success" role="status"><span class="visually-hidden">กำลังโหลด...</span></div></div>
    </div>

    <div class="container detail-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="min-w-0">
                <h1 class="h4 fw-bold mb-0 text-break" id="hikeTitle"><?= e($hike['hike_name']) ?></h1>
                <div class="small text-secondary">
                    <?= e(thai_datetime($hike['started_at'])) ?>
                    <?php if ($hike['ended_at']): ?> – <?= e(thai_time($hike['ended_at'], 'H:i')) ?> น.<?php endif; ?>
                </div>
                <?php if ($hike['status'] !== 'finished'): ?>
                <a href="tracking.php" class="badge text-bg-warning text-decoration-none mt-1">ยังไม่จบ — ไปบันทึกต่อ</a>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
                <button type="button" class="btn btn-outline-secondary btn-icon" data-bs-toggle="modal" data-bs-target="#renameModal" aria-label="แก้ไขชื่อกิจกรรม"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-outline-danger btn-icon" data-delete-hike="<?= (int) $hike['id'] ?>"
                        data-hike-name="<?= e($hike['hike_name']) ?>" data-redirect="history.php" aria-label="ลบกิจกรรม"><i class="bi bi-trash3"></i></button>
            </div>
        </div>

        <div class="stat-grid stat-grid-lg mb-3">
            <div class="stat-tile"><div class="stat-label">ระยะทาง</div><div class="stat-tile-value text-forest"><?= format_km($hike['distance_meters']) ?> <small>กม.</small></div></div>
            <div class="stat-tile"><div class="stat-label">เวลาเดินจริง</div><div class="stat-tile-value"><?= format_duration((int) $hike['duration_seconds']) ?></div></div>
            <div class="stat-tile"><div class="stat-label">เวลาพัก</div><div class="stat-tile-value"><?= format_duration((int) $hike['paused_seconds']) ?></div></div>
            <div class="stat-tile"><div class="stat-label">เวลารวม</div><div class="stat-tile-value"><?= format_duration($totalSeconds) ?></div></div>
            <div class="stat-tile"><div class="stat-label">ความเร็วเฉลี่ย</div><div class="stat-tile-value"><?= format_kmh(avg_speed_mps($hike)) ?> <small>กม./ชม.</small></div></div>
            <div class="stat-tile"><div class="stat-label">ความเร็วสูงสุด</div><div class="stat-tile-value"><?= format_kmh($hike['max_speed_mps']) ?> <small>กม./ชม.</small></div></div>
            <div class="stat-tile"><div class="stat-label">ความสูง</div><div class="stat-tile-value">
                <?php if ($hike['min_altitude'] !== null): ?>
                <?= number_format((float) $hike['min_altitude']) ?>–<?= number_format((float) $hike['max_altitude']) ?> <small>ม.</small>
                <?php else: ?>-<?php endif; ?>
            </div></div>
            <div class="stat-tile"><div class="stat-label">จุด GPS</div><div class="stat-tile-value"><?= number_format((int) $hike['point_count']) ?></div></div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <a href="api/export-gpx.php?id=<?= (int) $hike['id'] ?>" class="btn btn-forest btn-lg flex-fill"><i class="bi bi-download"></i> Export GPX</a>
            <a href="api/export-csv.php?id=<?= (int) $hike['id'] ?>" class="btn btn-outline-forest btn-lg flex-fill"><i class="bi bi-filetype-csv"></i> Export CSV</a>
        </div>

        <h2 class="h6 fw-bold">จุด GPS ตามลำดับเวลา</h2>
        <?php if (!$points): ?>
        <p class="text-secondary">ไม่มีพิกัดที่บันทึกไว้</p>
        <?php else: ?>
        <div class="table-responsive border rounded-3 bg-white">
            <table class="table table-sm table-hover mb-0 points-table">
                <thead class="table-light">
                    <tr><th scope="col">#</th><th scope="col">เวลา</th><th scope="col">ละติจูด, ลองจิจูด</th><th scope="col" class="text-end">ความสูง</th><th scope="col" class="text-end">±ม.</th></tr>
                </thead>
                <tbody>
                <?php foreach ($points as $p): ?>
                    <tr data-seq="<?= (int) $p['sequence_number'] ?>" class="point-row" tabindex="0">
                        <td><?= (int) $p['sequence_number'] ?></td>
                        <td><?= e(thai_time($p['recorded_at'])) ?></td>
                        <td><?= e(number_format((float) $p['latitude'], 6)) ?>, <?= e(number_format((float) $p['longitude'], 6)) ?></td>
                        <td class="text-end"><?= $p['altitude'] !== null ? e(number_format((float) $p['altitude'])) : '-' ?></td>
                        <td class="text-end"><?= e(number_format((float) $p['accuracy'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="หน้าของจุด GPS">
            <ul class="pagination pagination-sm justify-content-center">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="?id=<?= (int) $hike['id'] ?>&amp;page=<?= $page - 1 ?>">ก่อนหน้า</a></li>
                <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="?id=<?= (int) $hike['id'] ?>&amp;page=<?= $page + 1 ?>">ถัดไป</a></li>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Dialog แก้ไขชื่อ -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="renameForm" novalidate>
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="renameModalTitle">แก้ไขชื่อกิจกรรม</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <label for="renameInput" class="form-label small">ชื่อกิจกรรม</label>
                <input type="text" id="renameInput" class="form-control form-control-lg" maxlength="150" required value="<?= e($hike['hike_name']) ?>">
                <div class="invalid-feedback">กรุณากรอกชื่อกิจกรรม</div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light btn-lg flex-fill" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-forest btn-lg flex-fill">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/partials-delete-modal.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
