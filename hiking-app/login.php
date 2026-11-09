<?php
/** login.php — เข้าสู่ระบบ */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

redirect_if_logged_in();

$errors = [];
$email = '';

// จำกัดการลองรหัสผ่านผิด: 5 ครั้ง ล็อก 5 นาที (ต่อ session)
$lockUntil = (int) ($_SESSION['login_lock_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_form();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($lockUntil > time()) {
        $errors[] = 'ลองเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณารอ ' . ceil(($lockUntil - time()) / 60) . ' นาทีแล้วลองใหม่';
    } elseif ($email === '' || $password === '') {
        $errors[] = 'กรุณากรอกอีเมลและรหัสผ่าน';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, name, email, password FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([mb_strtolower($email)]);
            $user = $stmt->fetch();

            // ใช้ hash หลอกเมื่อไม่พบผู้ใช้ เพื่อให้เวลาตอบสนองใกล้เคียงกัน
            $hash = $user['password'] ?? '$2y$10$usesomesillystringforsaltandhashtomakeittakeequaltimeXXXXXXXX';
            if (password_verify($password, $hash) && $user) {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $upd = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                unset($_SESSION['login_fail'], $_SESSION['login_lock_until']);
                login_user($user);
                redirect('dashboard.php');
            }

            $_SESSION['login_fail'] = (int) ($_SESSION['login_fail'] ?? 0) + 1;
            if ($_SESSION['login_fail'] >= 5) {
                $_SESSION['login_lock_until'] = time() + 300;
                $_SESSION['login_fail'] = 0;
            }
            $errors[] = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง กรุณาลองใหม่';
        } catch (Throwable $ex) {
            error_log('[hiking-app] login: ' . $ex->getMessage());
            $errors[] = 'ระบบขัดข้อง ไม่สามารถเข้าสู่ระบบได้ในขณะนี้';
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
$showNav = false;
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
    <section class="auth-hero">
        <svg class="auth-mountains" viewBox="0 0 390 300" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
            <path d="M0 230 L70 150 L120 190 L200 90 L270 170 L320 130 L390 200 L390 300 L0 300Z" fill="#245A42"/>
            <path d="M0 270 L90 210 L160 250 L240 180 L310 240 L390 220 L390 300 L0 300Z" fill="#2C6A4E"/>
        </svg>
        <div class="auth-brand">
            <h1><i class="bi bi-triangle-half"></i> <?= e(app_config('app')['name']) ?></h1>
            <p>บันทึกเส้นทาง ระยะทาง และเวลาเดินป่าด้วย GPS</p>
        </div>
    </section>

    <section class="auth-card">
        <h2 class="h4 fw-bold mb-3">เข้าสู่ระบบ</h2>

        <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-circle"></i><div><?= e($err) ?></div></div>
        <?php endforeach; ?>

        <form method="post" action="login.php" novalidate class="needs-validation">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label">อีเมล</label>
                <input type="email" class="form-control form-control-lg" id="email" name="email"
                       value="<?= e($email) ?>" required maxlength="190" autocomplete="email" inputmode="email">
                <div class="invalid-feedback">กรุณากรอกอีเมลให้ถูกต้อง</div>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">รหัสผ่าน</label>
                <input type="password" class="form-control form-control-lg" id="password" name="password"
                       required autocomplete="current-password">
                <div class="invalid-feedback">กรุณากรอกรหัสผ่าน</div>
            </div>
            <button type="submit" class="btn btn-forest btn-lg w-100" data-loading-text="กำลังเข้าสู่ระบบ...">เข้าสู่ระบบ</button>
        </form>

        <p class="text-center mt-3 mb-0">ยังไม่มีบัญชี? <a href="register.php" class="fw-semibold">สมัครสมาชิก</a></p>
        <p class="text-center small text-secondary mt-4 mb-0"><i class="bi bi-lock"></i> ต้องเปิดผ่าน HTTPS เพื่อใช้งาน GPS บนมือถือ</p>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
