# เดินป่า Tracker — Hiking GPS Tracker (PHP + MySQL)

Web App บันทึกการเดินป่าด้วย GPS ใช้งานได้ทั้งมือถือและคอมพิวเตอร์
PHP 8.2+ (PDO) · MySQL 8+ หรือ MariaDB 10.4+ (ที่มากับ XAMPP) · Bootstrap 5 · Leaflet + OpenStreetMap · IndexedDB (Offline)

---

## 1. Architecture และ Flow การทำงาน

```
┌────────────── Browser (มือถือ) ───────────────┐          ┌──────────── Apache + PHP ────────────┐
│ tracking.js                                    │          │ api/*.php  (JSON, Session, CSRF)      │
│  ├ Geolocation.watchPosition()                 │          │  ├ hike-start / pause / resume        │
│  ├ กรองจุด (accuracy, drift, jump) + Haversine │          │  ├ gps-save  (batch + Transaction)    │
│  ├ map.js  → Leaflet วาดเส้นทาง                │  HTTPS   │  ├ hike-finish (คำนวณสถิติซ้ำฝั่ง server)│
│  └ offline.js                                  │ ───────▶ │  ├ hike-history / hike-detail         │
│     ├ IndexedDB.outbox  (คิวรอส่ง)             │  JSON    │  ├ hike-update / hike-delete          │
│     ├ IndexedDB.state   (กู้คืนกิจกรรม)        │ ◀─────── │  └ export-gpx / export-csv            │
│     └ SyncManager (ส่งตามลำดับ, ลบเมื่อยืนยัน) │          │           │ PDO prepared statements   │
└────────────────────────────────────────────────┘          │           ▼                           │
                                                            │   MySQL: users / hikes / hike_points  │
                                                            └───────────────────────────────────────┘
```

**Flow การบันทึก**

1. **Start** → `api/hike-start.php` สร้างแถวใน `hikes` (status = `recording`) ต้องออนไลน์
2. GPS ส่งตำแหน่งเข้ามา → `tracking.js` กรองจุด:
   - accuracy เกิน 35 ม. → ทิ้ง
   - timestamp ซ้ำหรือย้อนหลัง → ทิ้ง (กันจุดซ้ำ)
   - ขยับจาก "จุดยึด" น้อยกว่า `max(5 ม., accuracy × 0.5)` → ไม่นับ (กัน GPS drift)
   - ความเร็วโดยนัยเกิน 12 m/s (~43 กม./ชม.) → ทิ้ง (พิกัดกระโดด)
   - จุดที่ผ่าน → บวกระยะ Haversine, เพิ่ม `seq`, เข้าคิว IndexedDB
3. `SyncManager` ส่งพิกัดเป็น batch ทุก ~5 วินาทีไป `api/gps-save.php` → server ตอบรายการ `saved` → ลบออกจากคิว **เฉพาะที่ server ยืนยัน**
4. **Pause** → หยุดนับระยะและเวลาเดิน, ส่ง event `pause` (มีเวลาที่กดจริง)
5. **Resume** → บวกเวลาพักเข้า `paused_seconds`, เริ่ม `segment` ใหม่ (ไม่นับระยะทางระหว่างพัก)
6. **Finish** → ส่ง event `finish` → server คำนวณระยะทาง/ความเร็วสูงสุด/ความสูง ใหม่จากพิกัดทั้งหมดในฐานข้อมูล (อัลกอริทึมเดียวกับฝั่ง client) แล้วบันทึกเวลาจริง
7. ถ้าออฟไลน์ ทุกอย่างอยู่ในคิว IndexedDB ตามลำดับ และจะส่งเมื่อออนไลน์ (ระบบจะ **ไม่** แจ้งว่าบันทึกสำเร็จจนกว่า server ยืนยัน)

**เวลา**: ฐานข้อมูลเก็บ UTC ทั้งหมด (`DATETIME(3)`), แสดงผลเป็น Asia/Bangkok, เวลาเดินคำนวณจาก timestamp จริง (`เวลารวม − เวลาพัก`) ไม่ได้นับจาก `setInterval` และชดเชยนาฬิกามือถือที่ไม่ตรงด้วยเวลาของ server

---

## 2. โครงสร้างไฟล์

```
hiking-app/
├── index.php                 → redirect ไป dashboard / login
├── login.php  register.php  logout.php
├── dashboard.php             → สถิติรวม + กิจกรรมล่าสุด
├── tracking.php              → หน้าบันทึก GPS
├── history.php               → ประวัติ + กรองตามวันที่ + ลบ + Export
├── hike-detail.php           → แผนที่ย้อนหลัง + สถิติ + ตารางจุด GPS
├── .htaccess                 → ปิด directory listing, กันไฟล์ .md/.sql
├── config/
│   ├── config.php            → ★ ตั้งค่าฐานข้อมูล / ค่ากรอง GPS
│   ├── database.php          → PDO (utf8mb4, UTC, prepared statements)
│   └── .htaccess             → Deny all
├── includes/
│   ├── auth.php              → Session ปลอดภัย, require_login()
│   ├── csrf.php              → CSRF token
│   ├── functions.php         → Haversine, compute_track_stats(), รูปแบบเวลาไทย
│   ├── header.php  footer.php
│   ├── partials-delete-modal.php
│   └── .htaccess             → Deny all
├── api/
│   ├── _bootstrap.php        → JSON response, ตรวจ login/CSRF/ขนาด body
│   ├── hike-start.php  hike-pause.php  hike-resume.php  hike-finish.php
│   ├── gps-save.php
│   ├── hike-history.php  hike-detail.php  hike-update.php  hike-delete.php
│   ├── export-gpx.php  export-csv.php
│   └── .htaccess
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── app.js            → App.api(), toast, validation
│       ├── offline.js        → HikeStore (IndexedDB) + SyncManager
│       ├── map.js            → HikeMap (Leaflet)
│       ├── tracking.js       → GPS, สถิติ, Start/Pause/Resume/Finish, กู้คืน
│       ├── detail.js         → แผนที่ย้อนหลัง, แก้ชื่อ
│       └── history.js        → ลบกิจกรรมพร้อมยืนยัน
└── sql/hiking_app.sql
```

---

## 3. ติดตั้งบน XAMPP ทีละขั้น (Windows)

1. ติดตั้ง **XAMPP ที่มี PHP 8.2 ขึ้นไป** จาก https://www.apachefriends.org
2. เปิด **XAMPP Control Panel** → กด **Start** ที่ **Apache** และ **MySQL**
3. คัดลอกโฟลเดอร์ `hiking-app` ไปไว้ที่ `C:\xampp\htdocs\hiking-app`
4. สร้างฐานข้อมูล (เลือกวิธีใดวิธีหนึ่ง)
   - **phpMyAdmin**: เปิด http://localhost/phpmyadmin → แท็บ **Import** → เลือกไฟล์ `sql/hiking_app.sql` → **Go**
   - **Command line**:
     ```
     C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\hiking-app\sql\hiking_app.sql
     ```
   สคริปต์จะสร้างฐานข้อมูล `hiking_app` และตาราง `users`, `hikes`, `hike_points` ให้อัตโนมัติ
5. ตรวจว่าเปิด extension `pdo_mysql` แล้ว (XAMPP เปิดไว้เป็นค่าเริ่มต้น — ดูได้ใน `C:\xampp\php\php.ini` บรรทัด `extension=pdo_mysql`)

## 4. ตั้งค่า Database Connection

แก้ไฟล์ `config/config.php`

```php
'db' => [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'name'     => 'hiking_app',
    'user'     => 'root',
    'password' => '',          // XAMPP ค่าเริ่มต้นไม่มีรหัสผ่าน
    'charset'  => 'utf8mb4',
],
```

หรือกำหนดผ่าน Environment Variables: `HIKING_DB_HOST`, `HIKING_DB_PORT`, `HIKING_DB_NAME`, `HIKING_DB_USER`, `HIKING_DB_PASS` (แนะนำสำหรับ Production)

> แนะนำให้สร้าง user ของ MySQL แยกสำหรับแอปนี้ แทนการใช้ root:
> ```sql
> CREATE USER 'hiking'@'localhost' IDENTIFIED BY 'รหัสผ่านที่แข็งแรง';
> GRANT SELECT, INSERT, UPDATE, DELETE ON hiking_app.* TO 'hiking'@'localhost';
> ```

ถ้าต้องการดู error ละเอียดตอนพัฒนา ตั้ง `'debug' => true` (ปิดก่อนขึ้น Production)

## 5. เปิดใช้งานผ่าน localhost

1. เปิด http://localhost/hiking-app/
2. กด **สมัครสมาชิก** → กรอกชื่อ อีเมล รหัสผ่าน (≥ 8 ตัว) → เข้าสู่ Dashboard อัตโนมัติ
3. บนคอมพิวเตอร์ `http://localhost` ถือเป็น secure context ใช้ Geolocation ได้ (ตำแหน่งจะมาจาก Wi-Fi/IP จึงไม่แม่นเท่า GPS มือถือ)

## 6. ทดสอบ GPS บนโทรศัพท์มือถือผ่าน HTTPS

เบราว์เซอร์มือถือ **บังคับใช้ HTTPS** สำหรับ Geolocation — เปิดผ่าน `http://192.168.x.x` จะใช้ GPS ไม่ได้ เลือกวิธีใดวิธีหนึ่ง:

### วิธี A: Tunnel (ง่ายที่สุด)
ใช้ **ngrok** หรือ **Cloudflare Tunnel** เปิด HTTPS ชั่วคราวให้ localhost
```
ngrok http 80
```
จะได้ URL เช่น `https://abcd-1234.ngrok-free.app` → เปิดบนมือถือที่ `https://abcd-1234.ngrok-free.app/hiking-app/`
(แบบ Cloudflare: `cloudflared tunnel --url http://localhost:80`)

### วิธี B: HTTPS ใน XAMPP ด้วย mkcert (ใช้ในวง LAN)
1. ติดตั้ง mkcert (https://github.com/FiloSottile/mkcert) แล้วรัน
   ```
   mkcert -install
   mkcert 192.168.1.50 localhost
   ```
   (เปลี่ยน `192.168.1.50` เป็น IP ของคอมพิวเตอร์ — ดูด้วย `ipconfig`)
2. คัดลอกไฟล์ `.pem` ที่ได้ไปที่ `C:\xampp\apache\conf\ssl\`
3. แก้ `C:\xampp\apache\conf\extra\httpd-ssl.conf`
   ```
   SSLCertificateFile    "conf/ssl/192.168.1.50+1.pem"
   SSLCertificateKeyFile "conf/ssl/192.168.1.50+1-key.pem"
   ```
4. Restart Apache, เปิด Firewall พอร์ต 443
5. ติดตั้ง root CA ของ mkcert บนมือถือ (ไฟล์ `rootCA.pem` จาก `mkcert -CAROOT`)
   - Android: Settings → Security → Encryption & credentials → Install a certificate → CA certificate
   - iOS: ส่งไฟล์ไปที่เครื่อง → Install Profile → Settings → General → About → Certificate Trust Settings → เปิด trust
6. เปิด `https://192.168.1.50/hiking-app/` บนมือถือ

### สิ่งที่ต้องอนุญาตบนมือถือ
- เปิด Location/GPS ของเครื่อง และเลือก "ความแม่นยำสูง"
- อนุญาตตำแหน่งให้เบราว์เซอร์ (Chrome/Safari) และกด **อนุญาต** เมื่อเว็บถาม
- iOS: Settings → Privacy & Security → Location Services → Safari Websites → While Using + Precise Location

## 7. ทดสอบ Start, Pause, Resume และ Finish

1. เปิดหน้า **เดินป่า** → รอให้ชิป GPS เป็นสีเขียว (±ไม่เกิน 15 ม.)
2. กด **เริ่มเดินป่า** → ป้าย "กำลังบันทึก" ขึ้น, เวลาเดินเริ่มนับ
3. เดินจริง 100–200 ม. → ระยะทางและเส้นทางสีเขียวเพิ่มขึ้น, มี marker จุดเริ่มต้นสีเขียว
4. กด **พัก** → ป้ายเป็น "หยุดพักชั่วคราว", เวลาเดินหยุด, เวลาพักเพิ่ม, เดินต่อไประยะทางต้องไม่เพิ่ม
5. กด **เดินต่อ** → เวลาเดินนับต่อจากเดิม, เส้นทางเริ่มเส้นใหม่ (ไม่ลากเส้นข้ามช่วงพัก)
6. กด **จบกิจกรรม** → Dialog ยืนยัน → แก้ชื่อได้ → **ยืนยันจบ**
7. ระบบพาไปหน้า **รายละเอียด** แสดงเส้นทาง จุดเริ่มต้น (เขียว) จุดสิ้นสุด (ส้ม) และสถิติ

**ทดสอบบนคอมพิวเตอร์โดยไม่ต้องเดินจริง**: Chrome DevTools → ⋮ → More tools → **Sensors** → Location → ใส่ lat/lon แล้วเปลี่ยนค่าทีละนิด (เช่นเพิ่ม latitude ครั้งละ 0.0001 ≈ 11 ม.)

## 8. ทดสอบการบันทึกข้อมูลลง MySQL

ระหว่างบันทึก รันใน phpMyAdmin (แท็บ SQL):
```sql
-- กิจกรรมล่าสุด
SELECT id, hike_name, status, started_at, ended_at, duration_seconds, paused_seconds,
       distance_meters, point_count, current_segment
  FROM hikes ORDER BY id DESC LIMIT 5;

-- พิกัดของกิจกรรม (เปลี่ยน 1 เป็น id ที่ต้องการ)
SELECT sequence_number, segment, latitude, longitude, accuracy, recorded_at
  FROM hike_points WHERE hike_id = 1 ORDER BY sequence_number;

-- ตรวจว่าไม่มีจุดซ้ำ (ต้องได้ 0 แถว)
SELECT hike_id, sequence_number, COUNT(*) c FROM hike_points
 GROUP BY hike_id, sequence_number HAVING c > 1;
```
เวลาในฐานข้อมูลเป็น **UTC** (ช้ากว่าเวลาไทย 7 ชั่วโมง) — เป็นเรื่องปกติ

## 9. ทดสอบโหมด Offline และการ Sync

1. เริ่มกิจกรรมและเดินจนมีจุด GPS
2. ตัดอินเทอร์เน็ต (เปิด Airplane mode แต่ **เปิด GPS ไว้** หรือ DevTools → Network → **Offline**)
3. ชิปมุมขวาบนเปลี่ยนเป็น **ออฟไลน์**, ข้อความด้านล่างเป็น "รอส่ง N รายการ" — เส้นทางยังวาดต่อ
4. ลองกด **พัก/เดินต่อ** ระหว่างออฟไลน์ได้
5. เปิดอินเทอร์เน็ตกลับ → ภายในไม่กี่วินาทีข้อความเป็น "ซิงก์แล้ว" และจำนวนแถวใน `hike_points` เพิ่มขึ้น
6. **กด Finish ขณะออฟไลน์** → ระบบจะแจ้งว่า "จบกิจกรรมแล้วในเครื่อง แต่ยังไม่ได้บันทึกลง Server" (ไม่แจ้งว่าสำเร็จ) → เมื่อออนไลน์จะส่งให้อัตโนมัติแล้วพาไปหน้ารายละเอียด
7. **ทดสอบกู้คืน**: ระหว่างบันทึก ปิดแท็บ/รีเฟรชหน้า → เปิดหน้า เดินป่า ใหม่ → ระยะทาง เวลา และเส้นทางกลับมาครบ บันทึกต่อได้ทันที
8. ดูคิวในเครื่องได้ที่ DevTools → Application → IndexedDB → `hiking-tracker` → `outbox`

## 10. Test Cases

| # | กรณีทดสอบ | ขั้นตอน | ผลที่คาดหวัง |
|---|-----------|---------|--------------|
| 1 | สมัครสมาชิก | กรอกข้อมูลถูกต้อง | เข้าสู่ Dashboard, รหัสผ่านใน DB เป็น hash `$2y$...` |
| 2 | อีเมลซ้ำ | สมัครด้วยอีเมลเดิม | "อีเมลนี้ถูกใช้สมัครแล้ว" |
| 3 | รหัสผ่านสั้น / ยืนยันไม่ตรง | กรอก < 8 ตัว / ไม่ตรงกัน | แจ้งเตือนภาษาไทย ไม่บันทึก |
| 4 | Login ผิด 5 ครั้ง | ใส่รหัสผิดติดกัน | ถูกล็อก 5 นาที |
| 5 | เข้าหน้าโดยไม่ login | เปิด `dashboard.php` ตรง ๆ | redirect ไป `login.php` |
| 6 | API ไม่ login | เรียก `api/hike-history.php` | 401 JSON |
| 7 | CSRF | POST API โดยไม่มี `X-CSRF-Token` | 419 "CSRF token ไม่ถูกต้อง" |
| 8 | IDOR | user B เปิด `hike-detail.php?id=<ของ A>` / `api/export-gpx.php?id=` / ลบ | ไม่พบกิจกรรม (404) ข้อมูลไม่ถูกแก้ |
| 9 | XSS | ตั้งชื่อกิจกรรม `<script>alert(1)</script>` | แสดงเป็นข้อความ ไม่รันสคริปต์ |
| 10 | Start ซ้ำ | กด Start ในอีกแท็บขณะมีกิจกรรมค้าง | ได้กิจกรรมเดิมคืน (ไม่สร้างซ้ำ) |
| 11 | Pause ไม่นับระยะ | Pause แล้วเดิน 50 ม. | ระยะทางไม่เพิ่ม, เวลาเดินหยุด |
| 12 | Resume | กดเดินต่อ | ใช้กิจกรรมเดิม, `current_segment` +1, `paused_seconds` เพิ่ม |
| 13 | GPS drift | วางมือถือนิ่ง 5 นาที | ระยะทางเพิ่มไม่เกินไม่กี่เมตร |
| 14 | accuracy ต่ำ | ตำแหน่ง ±80 ม. | ชิปแดง "แม่นยำต่ำ", ไม่บันทึกจุด |
| 15 | พิกัดกระโดด | ตำแหน่งกระโดด 1 กม. ใน 1 วินาที | ไม่บันทึก, ระยะทางไม่เพิ่ม |
| 16 | ส่งพิกัดซ้ำ | POST `gps-save` ชุดเดิม 2 ครั้ง | จำนวนแถวไม่เพิ่ม, ตอบ `saved` ครบ |
| 17 | พิกัดผิดรูปแบบ | lat = 95 | อยู่ใน `rejected` reason `invalid_coord` |
| 18 | Request ใหญ่เกิน | body > 256 KB / > 500 จุด | 413 |
| 19 | Offline sync | ตัดเน็ต → เดิน → ต่อเน็ต | จุดครบ, ไม่ซ้ำ, ลำดับถูกต้อง |
| 20 | Finish ขณะ offline | กดจบตอนออฟไลน์ | แจ้งว่ายังไม่บันทึกลง Server จนกว่าจะ sync สำเร็จ |
| 21 | กู้คืน | รีเฟรชหน้าระหว่างบันทึก | สถานะ/ระยะ/เส้นทางกลับมา บันทึกต่อได้ |
| 22 | Finish | กดจบ | `status=finished`, `ended_at`, `duration_seconds = เวลารวม − เวลาพัก` |
| 23 | ระยะทาง client = server | เทียบตัวเลขหน้า Tracking กับหน้ารายละเอียด | ตรงกัน (อัลกอริทึมเดียวกัน) |
| 24 | ค้นหาตามวันที่ | เลือกช่วงวันที่ในประวัติ | แสดงเฉพาะกิจกรรมในช่วง (ตามเวลาไทย) |
| 25 | แก้ชื่อ / ลบ | แก้ชื่อในหน้ารายละเอียด / ลบพร้อมยืนยัน | ชื่ออัปเดต / กิจกรรมและพิกัดถูกลบ (CASCADE) |
| 26 | Export GPX | กด Export GPX | ไฟล์ .gpx เปิดใน Google Earth/Strava ได้ แยก `<trkseg>` ตามช่วงพัก |
| 27 | Export CSV | กด Export CSV | เปิดใน Excel ภาษาไทยไม่เพี้ยน (UTF-8 BOM) |
| 28 | Logout | กดออกจากระบบ | session ถูกลบ เข้าหน้าอื่นไม่ได้ |

## 11. ข้อจำกัดและแนวทางแก้ไขก่อนขึ้น Production

| ข้อจำกัด | ผลกระทบ | แนวทางแก้ไข |
|----------|---------|-------------|
| **เบราว์เซอร์หยุด GPS เมื่อปิดจอ/สลับแอป** (โดยเฉพาะ iOS Safari) | เส้นทางช่วงนั้นหายไป จะต่อเป็นเส้นตรงเมื่อกลับมา | ใช้ Wake Lock (ทำไว้แล้วถ้าเบราว์เซอร์รองรับ) และแนะนำผู้ใช้เปิดหน้าจอค้าง; ถ้าต้องบันทึกเบื้องหลังจริง ต้องทำเป็นแอป Native/Capacitor ที่ใช้ background location |
| Start ต้องออนไลน์ | เริ่มกิจกรรมในที่ไม่มีสัญญาณไม่ได้ | เพิ่ม `client_uuid` ใน `hikes` ให้สร้างกิจกรรมแบบ offline แล้ว sync ภายหลัง |
| ไม่มี Service Worker | เปิดหน้าเว็บใหม่ขณะออฟไลน์ไม่ได้ (หน้าที่เปิดอยู่แล้วยังทำงานต่อได้) | ทำเป็น PWA: Service Worker cache หน้า/asset และ map tiles บางส่วน |
| แผนที่ OSM ต้องใช้อินเทอร์เน็ต | ออฟไลน์จะไม่เห็นพื้นหลังแผนที่ (เส้นทางยังวาดได้) | cache tiles ล่วงหน้า หรือใช้ tile server ของตัวเอง; ปฏิบัติตาม [OSM Tile Usage Policy](https://operations.osmfoundation.org/policies/tiles/) — ปริมาณมากต้องใช้ผู้ให้บริการเชิงพาณิชย์ |
| ไลบรารีโหลดจาก CDN | ถ้า CDN ล่ม หน้าเว็บทำงานไม่ครบ | ดาวน์โหลด Bootstrap/Leaflet มาไว้ใน `assets/vendor` หรือใส่ SRI hash |
| จำกัด login ผิดต่อ session | ผู้โจมตีล้าง cookie แล้วลองใหม่ได้ | เก็บสถิติ login ผิดในตารางตาม IP/อีเมล หรือใช้ fail2ban / reCAPTCHA |
| ความสูงจาก GPS คลาดเคลื่อน ±10–30 ม. | ความสูงต่ำสุด/สูงสุดไม่แม่นยำ | ใช้ DEM (SRTM) หรือ barometer จากแอป native |
| ใช้ข้อมูลในเครื่องได้คนเดียว | ถ้าล้างข้อมูลเบราว์เซอร์ก่อน sync ข้อมูลที่ยังไม่ส่งจะหาย | แจ้งผู้ใช้ (ทำไว้แล้ว) และ sync บ่อย ๆ |
| ระยะทางคำนวณซ้ำทั้งหมดตอน Finish | กิจกรรมยาวมาก (หลายหมื่นจุด) ใช้เวลาประมวลผลมากขึ้น | คำนวณแบบ incremental หรือทำเป็น background job |
| ยังไม่มีลืมรหัสผ่าน / ยืนยันอีเมล | — | เพิ่มระบบ reset password ผ่านอีเมล (token หมดอายุ) |

**Checklist ก่อนขึ้น Production**
- [ ] ใช้ HTTPS จริง (Let's Encrypt) และเปิดบรรทัด HSTS / redirect HTTPS ใน `.htaccess`
- [ ] ตั้ง `'debug' => false` และ `display_errors = Off` ใน php.ini
- [ ] ใช้ MySQL user แยกสิทธิ์เฉพาะ `hiking_app` และตั้งรหัสผ่านแข็งแรง ผ่าน Environment Variables
- [ ] ตรวจว่า `config/`, `includes/`, `sql/` เปิดผ่านเว็บไม่ได้ (Apache ต้องเปิด `AllowOverride All`) หรือย้ายไว้นอก document root
- [ ] ตั้ง backup ฐานข้อมูลอัตโนมัติ
- [ ] เพิ่ม Content-Security-Policy ที่อนุญาตเฉพาะ CDN/tile ที่ใช้

---

## API สรุป

| Method | Endpoint | Body / Query | หมายเหตุ |
|--------|----------|--------------|----------|
| POST | `api/hike-start.php` | `{started_at?, hike_name?}` | คืนกิจกรรมเดิมถ้ายังมีที่ไม่จบ |
| POST | `api/hike-pause.php` | `{hike_id, at}` | idempotent |
| POST | `api/hike-resume.php` | `{hike_id, at}` | idempotent, segment +1 |
| POST | `api/gps-save.php` | `{hike_id, points:[{id, seq, segment, lat, lon, alt, acc, speed, t}]}` | ≤ 500 จุด, ≤ 256 KB |
| POST | `api/hike-finish.php` | `{hike_id, at, hike_name?}` | คำนวณสถิติใหม่ฝั่ง server |
| GET | `api/hike-history.php` | `?from=&to=&page=&per_page=` | |
| GET | `api/hike-detail.php` | `?id=&points=1` | points = `[seq, segment, lat, lon, alt, acc, t_ms]` |
| POST | `api/hike-update.php` | `{hike_id, hike_name}` | |
| POST | `api/hike-delete.php` | `{hike_id}` | |
| GET | `api/export-gpx.php` | `?id=` | GPX 1.1 |
| GET | `api/export-csv.php` | `?id=` หรือ `?all=1&from=&to=` | UTF-8 BOM |

ทุก POST ต้องมี header `X-CSRF-Token` (ค่าจาก `<meta name="csrf-token">`) และทุก API ตรวจ session + เจ้าของกิจกรรมทุกครั้ง
