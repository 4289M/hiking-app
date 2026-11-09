<?php
/** tracking.php — หน้าบันทึกการเดินป่า (GPS + แผนที่) */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$uid = require_login();
$pdo = db();

// กิจกรรมที่ยังไม่จบ (ถ้ามี) — ใช้กู้คืนเมื่อเปิดหน้าใหม่
$stmt = $pdo->prepare("SELECT * FROM hikes WHERE user_id = ? AND status IN ('recording','paused') ORDER BY id DESC LIMIT 1");
$stmt->execute([$uid]);
$active = $stmt->fetch();

$bootstrap = [
    'userId'     => $uid,
    // เวลาของ server (ms) — ใช้ชดเชยกรณีนาฬิกามือถือไม่ตรง
    'serverNow'  => (int) round(microtime(true) * 1000),
    'activeHike' => $active ? hike_to_array($active) : null,
    'gps'        => [
        'maxAccuracy' => app_config('gps')['max_accuracy_m'],
        'minMove'     => app_config('gps')['min_move_m'],
        'maxSpeed'    => app_config('gps')['max_speed_mps'],
        'batchMax'    => app_config('gps')['max_points_per_req'],
    ],
];

$pageTitle = 'บันทึกการเดินป่า';
$activeNav = 'tracking';
$useMap = true;
$bodyClass = 'tracking-page';
$pageScripts = ['offline.js', 'map.js', 'tracking.js'];
require __DIR__ . '/includes/header.php';
?>
<script id="trackingBootstrap" type="application/json"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<main class="tracking-layout">
    <div id="map" class="tracking-map" role="region" aria-label="แผนที่เส้นทาง"></div>

    <div class="map-topbar">
        <a href="dashboard.php" class="btn-map d-md-none" aria-label="กลับหน้าหลัก"><i class="bi bi-chevron-left"></i></a>
        <div class="d-flex gap-2 ms-auto flex-wrap justify-content-end">
            <span id="gpsStatus" class="chip"><span class="dot dot-gray"></span><span class="chip-text">GPS: รอ</span></span>
            <span id="netStatus" class="chip"><span class="dot dot-gray"></span><span class="chip-text">ตรวจสอบ...</span></span>
        </div>
    </div>
    <div id="recStatus" class="rec-badge d-none" role="status"><span class="dot dot-white"></span><span class="chip-text">กำลังบันทึก</span></div>

    <div class="map-controls">
        <button type="button" id="btnZoomIn" class="btn-map" aria-label="ซูมเข้า"><i class="bi bi-plus-lg"></i></button>
        <button type="button" id="btnZoomOut" class="btn-map" aria-label="ซูมออก"><i class="bi bi-dash-lg"></i></button>
        <button type="button" id="btnFollow" class="btn-map active" aria-pressed="true" aria-label="เลื่อนแผนที่ตามตำแหน่ง" title="เลื่อนแผนที่ตามตำแหน่ง"><i class="bi bi-cursor-fill"></i></button>
        <button type="button" id="btnLocate" class="btn-map" aria-label="กลับไปตำแหน่งปัจจุบัน" title="กลับไปตำแหน่งปัจจุบัน"><i class="bi bi-crosshair"></i></button>
    </div>

    <section class="tracking-sheet" aria-label="สถิติการเดินป่า">
        <div class="sheet-handle" aria-hidden="true"></div>

        <div id="gpsAlert" class="alert alert-warning py-2 small mb-2 d-none" role="alert"></div>

        <div class="d-flex justify-content-between align-items-end mb-2">
            <div>
                <div class="stat-label">ระยะทาง</div>
                <div class="big-distance"><span id="statDistance">0.00</span> <small>กม.</small></div>
            </div>
            <div class="text-end">
                <div class="stat-label">เวลาเดินจริง</div>
                <div class="big-time" id="statMoving">0:00:00</div>
            </div>
        </div>

        <div class="stat-grid mb-2">
            <div class="stat-tile"><div class="stat-label">ความเร็ว</div><div class="stat-tile-value"><span id="statSpeed">0.0</span> <small>กม./ชม.</small></div></div>
            <div class="stat-tile"><div class="stat-label">เฉลี่ย</div><div class="stat-tile-value"><span id="statAvg">0.0</span> <small>กม./ชม.</small></div></div>
            <div class="stat-tile"><div class="stat-label">สูงสุด</div><div class="stat-tile-value"><span id="statMax">0.0</span> <small>กม./ชม.</small></div></div>
            <div class="stat-tile"><div class="stat-label">เวลาพัก</div><div class="stat-tile-value" id="statPaused">0:00:00</div></div>
            <div class="stat-tile"><div class="stat-label">เวลารวม</div><div class="stat-tile-value" id="statTotal">0:00:00</div></div>
            <div class="stat-tile"><div class="stat-label">ความสูง</div><div class="stat-tile-value"><span id="statAlt">-</span> <small>ม.</small></div></div>
        </div>

        <div class="d-flex justify-content-between small text-secondary mb-3 gap-2 flex-wrap">
            <span><i class="bi bi-geo"></i> <span id="coordText">-</span> <span id="accText"></span></span>
            <span><i class="bi bi-pin-map"></i> <span id="statPoints">0</span> จุด · <span id="syncText">-</span></span>
        </div>

        <div class="d-flex gap-2" id="controls">
            <button type="button" id="btnStart" class="btn btn-forest btn-xl flex-fill"><i class="bi bi-play-fill"></i> เริ่มเดินป่า</button>
            <button type="button" id="btnPause" class="btn btn-outline-forest btn-xl flex-fill d-none"><i class="bi bi-pause-fill"></i> พัก</button>
            <button type="button" id="btnResume" class="btn btn-forest btn-xl flex-fill d-none"><i class="bi bi-play-fill"></i> เดินต่อ</button>
            <button type="button" id="btnFinish" class="btn btn-finish btn-xl flex-fill d-none"><i class="bi bi-stop-fill"></i> จบกิจกรรม</button>
        </div>

        <p class="small text-secondary mt-2 mb-0" id="wakeNote">
            <i class="bi bi-phone"></i> เปิดหน้าจอค้างไว้ระหว่างบันทึก — เบราว์เซอร์อาจหยุดรับ GPS เมื่อปิดจอหรือสลับแอป
        </p>
    </section>
</main>

<!-- Dialog ยืนยันจบกิจกรรม -->
<div class="modal fade" id="finishModal" tabindex="-1" aria-labelledby="finishModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="finishModalTitle">จบกิจกรรมนี้?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <p id="finishSummary" class="text-secondary"></p>
                <label for="finishName" class="form-label small">ชื่อกิจกรรม</label>
                <input type="text" id="finishName" class="form-control form-control-lg" maxlength="150">
                <div id="finishPending" class="alert alert-info small mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light btn-lg flex-fill" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" id="btnFinishConfirm" class="btn btn-finish btn-lg flex-fill">ยืนยันจบ</button>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
