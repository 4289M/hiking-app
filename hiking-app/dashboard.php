<?php
/** dashboard.php — หน้าหลัก: สถิติรวม + กิจกรรมล่าสุด */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$uid = require_login();
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_hikes,
            COALESCE(SUM(distance_meters), 0) AS total_distance,
            COALESCE(SUM(duration_seconds), 0) AS total_duration
       FROM hikes WHERE user_id = ? AND status = 'finished'"
);
$stmt->execute([$uid]);
$totals = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM hikes WHERE user_id = ? AND status IN ('recording','paused') ORDER BY id DESC LIMIT 1");
$stmt->execute([$uid]);
$active = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM hikes WHERE user_id = ? AND status = 'finished' ORDER BY started_at DESC LIMIT 5");
$stmt->execute([$uid]);
$recent = $stmt->fetchAll();

$totalHours = intdiv((int) $totals['total_duration'], 3600);
$totalMins = intdiv((int) $totals['total_duration'] % 3600, 60);

$pageTitle = 'หน้าหลัก';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<header class="dash-hero">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <div class="small opacity-75">สวัสดี</div>
            <h1 class="h4 fw-bold mb-0"><?= e(current_user_name()) ?></h1>
        </div>
        <form method="post" action="logout.php" class="d-md-none m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-icon btn-outline-light" aria-label="ออกจากระบบ"><i class="bi bi-box-arrow-right"></i></button>
        </form>
    </div>
</header>

<main class="container dash-main pb-4">
    <section class="card stat-card shadow-sm border-0 mb-3">
        <div class="card-body row text-center text-md-start g-2">
            <div class="col-4">
                <div class="stat-label">กิจกรรม</div>
                <div class="stat-value"><?= number_format((int) $totals['total_hikes']) ?></div>
                <div class="stat-unit">ครั้ง</div>
            </div>
            <div class="col-4">
                <div class="stat-label">ระยะทางรวม</div>
                <div class="stat-value"><?= format_km($totals['total_distance'], 1) ?></div>
                <div class="stat-unit">กิโลเมตร</div>
            </div>
            <div class="col-4">
                <div class="stat-label">เวลาเดินรวม</div>
                <div class="stat-value"><?= $totalHours ?>:<?= sprintf('%02d', $totalMins) ?></div>
                <div class="stat-unit">ชั่วโมง</div>
            </div>
        </div>
    </section>

    <?php if ($active): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 mb-3" role="status">
        <i class="bi bi-record-circle fs-4"></i>
        <div class="flex-grow-1">
            <div class="fw-semibold">มีกิจกรรมที่ยังไม่จบ</div>
            <div class="small"><?= e($active['hike_name']) ?> · <?= $active['status'] === 'paused' ? 'หยุดพักอยู่' : 'กำลังบันทึก' ?></div>
        </div>
        <a href="tracking.php" class="btn btn-forest">ไปต่อ</a>
    </div>
    <?php else: ?>
    <a href="tracking.php" class="btn btn-forest btn-xl w-100 mb-4"><i class="bi bi-play-fill"></i> เริ่มเดินป่าใหม่</a>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-baseline mb-2">
        <h2 class="h5 fw-bold mb-0">กิจกรรมล่าสุด</h2>
        <a href="history.php" class="fw-semibold small">ดูทั้งหมด</a>
    </div>

    <?php if (!$recent): ?>
    <div class="empty-state">
        <i class="bi bi-signpost-split"></i>
        <p class="mb-0">ยังไม่มีกิจกรรม กด "เริ่มเดินป่าใหม่" เพื่อบันทึกเส้นทางแรกของคุณ</p>
    </div>
    <?php else: ?>
    <div class="row g-2">
        <?php foreach ($recent as $h): ?>
        <div class="col-12 col-md-6">
            <a href="hike-detail.php?id=<?= (int) $h['id'] ?>" class="hike-item">
                <span class="hike-item-icon"><i class="bi bi-bezier2"></i></span>
                <span class="flex-grow-1 min-w-0">
                    <span class="d-block fw-semibold text-truncate"><?= e($h['hike_name']) ?></span>
                    <span class="d-block small text-secondary"><?= e(thai_datetime($h['started_at'])) ?></span>
                    <span class="d-flex gap-3 small mt-1">
                        <span class="fw-semibold"><?= format_km($h['distance_meters']) ?> กม.</span>
                        <span><?= format_duration((int) $h['duration_seconds']) ?></span>
                    </span>
                </span>
                <i class="bi bi-chevron-right text-secondary"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
