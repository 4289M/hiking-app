<?php
/**
 * api/_bootstrap.php
 * โค้ดร่วมของทุก API: ตอบ JSON, ตรวจ session, ตรวจ CSRF, อ่าน body แบบจำกัดขนาด
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** ส่ง JSON แล้วจบการทำงาน */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $message] + $extra, $status);
}

/** แปลง exception ที่ไม่คาดคิดเป็น JSON 500 โดยไม่เปิดเผยรายละเอียด */
set_exception_handler(static function (Throwable $ex): void {
    error_log('[hiking-app] API error: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    $debug = app_config('app')['debug'] ?? false;
    http_response_code(500);
    echo json_encode(
        ['ok' => false, 'error' => 'เกิดข้อผิดพลาดที่เซิร์ฟเวอร์' . ($debug ? ': ' . $ex->getMessage() : '')],
        JSON_UNESCAPED_UNICODE
    );
});

/** ต้อง login — คืน user_id */
function api_require_login(): int
{
    $uid = current_user_id();
    if ($uid === null) {
        json_error('กรุณาเข้าสู่ระบบใหม่', 401, ['code' => 'unauthenticated']);
    }
    return $uid;
}

function api_require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_error('Method not allowed', 405);
    }
}

/** ตรวจ CSRF จาก header X-CSRF-Token */
function api_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!csrf_check($token)) {
        json_error('CSRF token ไม่ถูกต้อง กรุณารีเฟรชหน้า', 419, ['code' => 'csrf']);
    }
}

/** อ่าน JSON body พร้อมจำกัดขนาด */
function api_json_body(): array
{
    $max = (int) app_config('gps')['max_body_bytes'];
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) {
        json_error('ข้อมูลมีขนาดใหญ่เกินไป', 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) {
        json_error('ข้อมูลมีขนาดใหญ่เกินไป', 413);
    }
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('รูปแบบข้อมูล JSON ไม่ถูกต้อง', 400);
    }
    return $data;
}

/** POST API มาตรฐาน: method + login + CSRF + body */
function api_post_init(): array
{
    api_require_method('POST');
    $uid = api_require_login();
    api_require_csrf();
    return [$uid, api_json_body()];
}

function int_param(mixed $v): int
{
    return filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
}

/**
 * อ่านเวลา "at" จาก client แล้วตรวจว่าอยู่ในช่วงที่สมเหตุสมผล
 * (ไม่ก่อนเวลาเริ่มกิจกรรม และไม่เกินเวลาปัจจุบันของ server + 2 นาที)
 * ถ้าไม่ส่งมาหรือไม่ถูกต้อง ใช้เวลาปัจจุบันของ server
 */
function event_time(mixed $value, ?string $notBeforeDb = null): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $dt = parse_client_time($value) ?? $now;
    if ($dt > $now->modify('+2 minutes')) {
        $dt = $now;
    }
    if ($notBeforeDb !== null) {
        $min = db_time_to_dt($notBeforeDb);
        if ($dt < $min) {
            $dt = $min;
        }
    }
    return $dt;
}

/** วินาทีระหว่างเวลา 2 ค่า (ไม่ติดลบ) */
function seconds_between(DateTimeImmutable $a, DateTimeImmutable $b): int
{
    $ms = ((int) $b->format('U') * 1000 + (int) $b->format('v')) - ((int) $a->format('U') * 1000 + (int) $a->format('v'));
    return max(0, (int) round($ms / 1000));
}
