<?php
/**
 * includes/footer.php
 * ตัวแปรที่ใช้ได้:
 *   $pageScripts (array) รายชื่อไฟล์ JS ใน assets/js ที่จะโหลดเพิ่ม
 *   $useMap, $showNav, $activeNav (จาก header.php)
 */
declare(strict_types=1);
$pageScripts = $pageScripts ?? [];
?>

<?php if ($showNav): ?>
<nav class="bottom-nav d-md-none" aria-label="เมนูหลัก">
    <?php foreach ($navItems as $key => $item): ?>
    <a href="<?= e($item['href']) ?>" class="<?= $activeNav === $key ? 'active' : '' ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
        <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($useMap): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<script src="assets/js/app.js?v=1"></script>
<?php foreach ($pageScripts as $js): ?>
<script src="assets/js/<?= e($js) ?>?v=1"></script>
<?php endforeach; ?>
</body>
</html>
