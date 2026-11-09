<?php
/**
 * includes/csrf.php
 * CSRF Token ต่อ session
 * - ฟอร์ม HTML: ใส่ csrf_field() แล้วเรียก csrf_verify_form()
 * - fetch/API: ส่ง header "X-CSRF-Token" (อ่านค่าจาก <meta name="csrf-token">)
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_regenerate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** ตรวจ token จากฟอร์ม POST — ถ้าไม่ผ่านให้หยุดทันที */
function csrf_verify_form(): void
{
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}
