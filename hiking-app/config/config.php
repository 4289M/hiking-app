<?php
/**
 * config/config.php
 * ค่าตั้งค่าหลักของแอป — แก้ค่าฐานข้อมูลที่นี่
 * ไฟล์ในโฟลเดอร์ config/ ถูกป้องกันไม่ให้เปิดผ่านเว็บด้วย config/.htaccess
 *
 * แนะนำสำหรับ Production: ตั้งค่าผ่าน Environment Variables (HIKING_DB_HOST ฯลฯ)
 * แทนการเขียนรหัสผ่านไว้ในไฟล์
 */
declare(strict_types=1);

return [
    'db' => [
        'host'     => getenv('HIKING_DB_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('HIKING_DB_PORT') ?: 3306),
        'name'     => getenv('HIKING_DB_NAME') ?: 'hiking_app',
        'user'     => getenv('HIKING_DB_USER') ?: 'root',
        'password' => getenv('HIKING_DB_PASS') !== false ? (string) getenv('HIKING_DB_PASS') : '',
        'charset'  => 'utf8mb4',
    ],

    'app' => [
        'name'            => 'เดินป่า Tracker',
        // เขตเวลาสำหรับ "แสดงผล" เท่านั้น — ฐานข้อมูลเก็บ UTC เสมอ
        'display_timezone' => 'Asia/Bangkok',
        // true = แสดง error ละเอียด (ใช้ตอนพัฒนาเท่านั้น)
        'debug'           => false,
    ],

    // ค่าการกรอง GPS — ต้องตรงกับ GPS_CONFIG ใน assets/js/tracking.js
    'gps' => [
        'max_accuracy_m'     => 35.0,   // ทิ้งจุดที่ความคลาดเคลื่อนมากกว่านี้
        'min_move_m'         => 5.0,    // ระยะขยับขั้นต่ำถึงจะนับระยะทาง (กัน GPS drift)
        'max_speed_mps'      => 12.0,   // ~43 กม./ชม. เกินนี้ถือว่าพิกัดกระโดด
        'max_points_per_req' => 500,
        'max_body_bytes'     => 262144, // 256 KB
    ],
];
