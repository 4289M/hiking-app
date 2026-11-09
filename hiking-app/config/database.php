<?php
/**
 * config/database.php
 * สร้างการเชื่อมต่อ PDO แบบ singleton
 */
declare(strict_types=1);

function app_config(?string $section = null): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $section === null ? $config : ($config[$section] ?? []);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = app_config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        $c['port'],
        $c['name'],
        $c['charset']
    );

    try {
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // เก็บและอ่านเวลาเป็น UTC เสมอ
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        // ไม่เปิดเผยรายละเอียดการเชื่อมต่อ/รหัสผ่านให้ผู้ใช้เห็น
        error_log('[hiking-app] DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
    }

    return $pdo;
}
