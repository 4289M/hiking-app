<?php
/** register.php — สมัครสมาชิก */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

redirect_if_logged_in();

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_form();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'กรุณากรอกชื่อ (ไม่เกิน 100 ตัวอักษร)';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif (strlen($password) > 72) {
        $errors[] = 'รหัสผ่านยาวเกินไป (ไม่เกิน 72 ไบต์)';
    }
    if ($password !== $confirm) {
        $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';
    }

    if (!$errors) {
        try {
            $pdo = db();
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $check->execute([$email]);
            if ($check->fetch()) {
                $errors[] = 'อีเมลนี้ถูกใช้สมัครแล้ว';
            } else {
                $ins = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
                $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                login_user(['id' => (int) $pdo->lastInsertId(), 'name' => $name]);
                flash('success', 'สมัครสมาชิกสำเร็จ ยินดีต้อนรับ!');
                redirect('dashboard.php');
            }
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'อีเมลนี้ถูกใช้สมัครแล้ว';
            } else {
                error_log('[hiking-app] register: ' . $ex->getMessage());
                $errors[] = 'ระบบขัดข้อง ไม่สามารถสมัครสมาชิกได้ในขณะนี้';
            }
        } catch (Throwable $ex) {
            error_log('[hiking-app] register: ' . $ex->getMessage());
            $errors[] = 'ระบบขัดข้อง ไม่สามารถสมัครสมาชิกได้ในขณะนี้';
        }
    }
}

$pageTitle = 'สมัครสมาชิก';
$showNav = false;
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
    <section class="auth-hero auth-hero-sm">
        <svg class="auth-mountains" viewBox="0 0 390 300" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
            <path d="M0 230 L70 150 L120 190 L200 90 L270 170 L320 130 L390 200 L390 300 L0 300Z" fill="#245A42"/>
            <path d="M0 270 L90 210 L160 250 L240 180 L310 240 L390 220 L390 300 L0 300Z" fill="#2C6A4E"/>
        </svg>
        <div class="auth-brand">
            <h1><i class="bi bi-triangle-half"></i> <?= e(app_config('app')['name']) ?></h1>
            <p>สร้างบัญชีเพื่อเริ่มบันทึกเส้นทางเดินป่า</p>
        </div>
    </section>

    <section class="auth-card">
        <h2 class="h4 fw-bold mb-3">สมัครสมาชิก</h2>

        <?php if ($errors): ?>
        <div class="alert alert-danger py-2" role="alert">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate class="needs-validation">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="name" class="form-label">ชื่อที่แสดง</label>
                <input type="text" class="form-control form-control-lg" id="name" name="name"
                       value="<?= e($name) ?>" required maxlength="100" autocomplete="name">
                <div class="invalid-feedback">กรุณากรอกชื่อ</div>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">อีเมล</label>
                <input type="email" class="form-control form-control-lg" id="email" name="email"
                       value="<?= e($email) ?>" required maxlength="190" autocomplete="email" inputmode="email">
                <div class="invalid-feedback">กรุณากรอกอีเมลให้ถูกต้อง</div>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">รหัสผ่าน</label>
                <input type="password" class="form-control form-control-lg" id="password" name="password"
                       required minlength="8" maxlength="72" autocomplete="new-password">
                <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
                <div class="invalid-feedback">รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร</div>
            </div>
            <div class="mb-3">
                <label for="password_confirm" class="form-label">ยืนยันรหัสผ่าน</label>
                <input type="password" class="form-control form-control-lg" id="password_confirm" name="password_confirm"
                       required minlength="8" maxlength="72" autocomplete="new-password" data-match="password">
                <div class="invalid-feedback">ยืนยันรหัสผ่านไม่ตรงกัน</div>
            </div>
            <button type="submit" class="btn btn-forest btn-lg w-100" data-loading-text="กำลังสมัคร...">สมัครสมาชิก</button>
        </form>

        <p class="text-center mt-3 mb-0">มีบัญชีแล้ว? <a href="login.php" class="fw-semibold">เข้าสู่ระบบ</a></p>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
