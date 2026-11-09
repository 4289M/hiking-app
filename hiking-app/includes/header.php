<?php
/**
 * includes/header.php
 * ตัวแปรที่ใช้ได้ก่อน include:
 *   $pageTitle  (string)  ชื่อหน้า
 *   $activeNav  (string)  dashboard | tracking | history
 *   $useMap     (bool)    โหลด Leaflet
 *   $bodyClass  (string)  class เพิ่มเติมของ <body>
 *   $showNav    (bool)    แสดงเมนู (default true เมื่อ login แล้ว)
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? app_config('app')['name'];
$activeNav = $activeNav ?? '';
$useMap    = $useMap ?? false;
$bodyClass = $bodyClass ?? '';
$showNav   = $showNav ?? (current_user_id() !== null);

// Security headers พื้นฐาน
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');

$navItems = [
    'dashboard' => ['href' => 'dashboard.php', 'icon' => 'bi-house-door', 'label' => 'หน้าหลัก'],
    'tracking'  => ['href' => 'tracking.php',  'icon' => 'bi-geo-alt',    'label' => 'เดินป่า'],
    'history'   => ['href' => 'history.php',   'icon' => 'bi-clock-history', 'label' => 'ประวัติ'],
];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1E4634">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(app_config('app')['name']) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php if ($useMap): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=1">
</head>
<body class="<?= e($bodyClass) ?><?= $showNav ? ' has-nav' : '' ?>">

<?php if ($showNav): ?>
<nav class="navbar navbar-expand-md app-topbar d-none d-md-flex">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <i class="bi bi-triangle-half"></i> <?= e(app_config('app')['name']) ?>
        </a>
        <ul class="navbar-nav me-auto gap-1">
            <?php foreach ($navItems as $key => $item): ?>
            <li class="nav-item">
                <a class="nav-link<?= $activeNav === $key ? ' active' : '' ?>" href="<?= e($item['href']) ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
                    <i class="bi <?= e($item['icon']) ?>"></i> <?= e($item['label']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <span class="navbar-text me-3 text-white-50"><i class="bi bi-person-circle"></i> <?= e(current_user_name()) ?></span>
        <form method="post" action="logout.php" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</button>
        </form>
    </div>
</nav>
<?php endif; ?>

<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" id="toastArea" aria-live="polite"></div>

<?php foreach (take_flashes() as $f): ?>
<div class="container pt-3">
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show mb-0" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
    </div>
</div>
<?php endforeach; ?>
