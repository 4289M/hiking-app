<?php
/** logout.php — ออกจากระบบ (รับเฉพาะ POST + CSRF เพื่อกันการ logout ผ่านลิงก์ปลอม) */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(current_user_id() !== null ? 'dashboard.php' : 'login.php');
}
csrf_verify_form();
logout_user();
redirect('login.php');
