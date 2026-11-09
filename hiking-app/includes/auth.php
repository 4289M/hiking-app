<?php
/**
 * includes/auth.php
 * จัดการ Session และการตรวจสอบสิทธิ์
 * ทุกหน้า/ทุก API ต้อง require ไฟล์นี้ก่อน
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('HIKINGSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),   // ส่ง cookie ผ่าน HTTPS เท่านั้นเมื่อใช้ HTTPS
        'httponly' => true,         // JavaScript อ่าน cookie ไม่ได้
        'samesite' => 'Lax',
    ]);
    session_start();

    // หมดอายุเมื่อไม่ได้ใช้งาน 12 ชั่วโมง (เดินป่านานได้)
    $now = time();
    if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > 43200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
}

start_secure_session();

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_user_name(): string
{
    return (string) ($_SESSION['user_name'] ?? '');
}

function login_user(array $user): void
{
    session_regenerate_id(true); // กัน session fixation
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    csrf_regenerate();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/** สำหรับหน้าเว็บ: ถ้ายังไม่ login ให้ไปหน้า login */
function require_login(): int
{
    $uid = current_user_id();
    if ($uid === null) {
        redirect('login.php');
    }
    return $uid;
}

/** สำหรับหน้า login/register: ถ้า login แล้วให้ไป dashboard */
function redirect_if_logged_in(): void
{
    if (current_user_id() !== null) {
        redirect('dashboard.php');
    }
}
