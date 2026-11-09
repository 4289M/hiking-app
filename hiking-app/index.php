<?php
/** index.php — เข้าหน้าแรก: ไป dashboard ถ้า login แล้ว ไม่งั้นไปหน้า login */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

redirect(current_user_id() !== null ? 'dashboard.php' : 'login.php');
