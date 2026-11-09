/* =====================================================================
 * assets/js/tracking.js — หน้าบันทึกการเดินป่า
 *
 * หลักการสำคัญ
 *  - เวลา: คำนวณจาก timestamp จริงเสมอ (ไม่ได้นับจำนวนครั้งของ setInterval)
 *          และชดเชยนาฬิกามือถือด้วยเวลาของ server (clockOffset)
 *  - ระยะทาง: Haversine ระหว่าง "จุดยึด" (anchor) กับตำแหน่งใหม่
 *          นับเมื่อขยับเกิน threshold เท่านั้น เพื่อกรอง GPS drift
 *          ทิ้งจุดที่ accuracy แย่ หรือความเร็วโดยนัยสูงผิดปกติ
 *          ไม่นับระยะข้ามช่วงพัก (segment ใหม่ทุกครั้งที่ Resume)
 *          อัลกอริทึมเดียวกับ compute_track_stats() ใน includes/functions.php
 *  - ข้อมูล: ทุกจุดเข้าคิว IndexedDB ก่อน แล้ว SyncManager ส่งขึ้น server
 *          ลบออกจากคิวเมื่อ server ยืนยันเท่านั้น
 *  - กู้คืน: สถานะถูกเก็บใน IndexedDB — เปิดหน้าใหม่แล้วบันทึกต่อได้
 * ===================================================================== */
(function () {
    'use strict';

    const boot = JSON.parse(document.getElementById('trackingBootstrap').textContent);
    const CFG = {
        maxAccuracy: Number(boot.gps.maxAccuracy),
        minMove: Number(boot.gps.minMove),
        maxSpeed: Number(boot.gps.maxSpeed),
        batchMax: Number(boot.gps.batchMax),
        speedWindowMs: 15000,
        staleFixMs: 30000,
    };
    const clockOffset = Number(boot.serverNow) - Date.now();
    const nowMs = () => Date.now() + clockOffset;
    const iso = (ms) => new Date(ms).toISOString();

    const $ = (id) => document.getElementById(id);
    const el = {
        gpsStatus: $('gpsStatus'), netStatus: $('netStatus'), recStatus: $('recStatus'), gpsAlert: $('gpsAlert'),
        distance: $('statDistance'), moving: $('statMoving'), paused: $('statPaused'), total: $('statTotal'),
        speed: $('statSpeed'), avg: $('statAvg'), max: $('statMax'), alt: $('statAlt'), points: $('statPoints'),
        coord: $('coordText'), acc: $('accText'), sync: $('syncText'), wakeNote: $('wakeNote'),
        btnStart: $('btnStart'), btnPause: $('btnPause'), btnResume: $('btnResume'), btnFinish: $('btnFinish'),
        btnLocate: $('btnLocate'), btnFollow: $('btnFollow'), btnZoomIn: $('btnZoomIn'), btnZoomOut: $('btnZoomOut'),
        finishName: $('finishName'), finishSummary: $('finishSummary'), finishPending: $('finishPending'),
        btnFinishConfirm: $('btnFinishConfirm'),
    };

    let S = null;          // สถานะกิจกรรมปัจจุบัน (null = ยังไม่เริ่ม)
    let lastFix = null;    // ตำแหน่งล่าสุดจาก GPS
    let recentMoves = [];  // [{t, d}] สำหรับคำนวณความเร็วปัจจุบัน
    let watchId = null;
    let wakeLock = null;
    let syncStatus = { pending: 0, online: navigator.onLine, syncing: false, lastOkAt: null, error: null };
    let persistTimer = null;
    let busy = false;

    const sheet = document.querySelector('.tracking-sheet');
    const map = HikeMap.create($('map'), {
        zoomControl: false,
        // บนมือถือแผงสถิติบังครึ่งล่างของแผนที่
        bottomInset: () => (window.innerWidth < 768 && sheet ? sheet.offsetHeight : 0),
    });
    const sync = new SyncManager({ batchMax: CFG.batchMax });
    const finishModal = new bootstrap.Modal($('finishModal'));

    /* ------------------------------------------------------------------
     * สถานะกิจกรรม
     * ------------------------------------------------------------------ */
    function newState(h) {
        return {
            v: 1,
            userId: boot.userId,
            hikeId: h.id,
            hikeName: h.hike_name,
            status: h.status,                       // recording | paused | finishing
            startedAt: Date.parse(h.started_at),
            pausedTotal: (h.paused_seconds || 0) * 1000,
            pausedAt: h.paused_at ? Date.parse(h.paused_at) : null,
            finishAt: null,
            segment: h.current_segment || 1,
            seq: 0,
            points: 0,
            distance: 0,
            maxSpeed: 0,
            anchor: null,
            lastT: 0,
            track: {},                               // { segment: [[lat, lon], ...] }
            start: null,
        };
    }

    /**
     * พิจารณาตำแหน่งใหม่ คืน { ok, d } — ok = ควรบันทึกจุดนี้
     * (ต้องตรงกับ compute_track_stats() ฝั่ง PHP)
     */
    function advance(st, f) {
        if (f.acc > CFG.maxAccuracy) return { ok: false, reason: 'accuracy' };
        if (st.lastT && f.t <= st.lastT) return { ok: false, reason: 'duplicate' };

        let d = 0;
        if (st.anchor && st.anchor.seg === st.segment) {
            d = GeoUtil.haversine(st.anchor.lat, st.anchor.lon, f.lat, f.lon);
            const dt = (f.t - st.anchor.t) / 1000;
            if (dt <= 0) return { ok: false, reason: 'time' };
            const threshold = Math.max(CFG.minMove, Math.min(f.acc, st.anchor.acc) * 0.5);
            if (d < threshold) return { ok: false, reason: 'drift' };
            const speed = d / dt;
            if (speed > CFG.maxSpeed) return { ok: false, reason: 'jump' };
            st.distance += d;
            st.maxSpeed = Math.max(st.maxSpeed, speed);
        }
        st.anchor = { lat: f.lat, lon: f.lon, t: f.t, acc: f.acc, seg: st.segment };
        st.lastT = f.t;
        (st.track[st.segment] = st.track[st.segment] || []).push([f.lat, f.lon]);
        if (!st.start) st.start = [f.lat, f.lon];
        return { ok: true, d };
    }

    function randomHex(bytes) {
        const a = new Uint8Array(bytes);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
    }

    async function record(f) {
        const r = advance(S, f);
        if (!r.ok) return;
        S.seq += 1;
        S.points += 1;
        if (r.d > 0) recentMoves.push({ t: f.t, d: r.d });

        map.addTrackPoint(S.segment, f.lat, f.lon);
        if (S.points === 1 || !map.startMarker) map.setStart(S.start[0], S.start[1]);

        const point = {
            id: 'h' + S.hikeId + '-' + S.seq + '-' + randomHex(4),
            seq: S.seq,
            segment: S.segment,
            lat: Math.round(f.lat * 1e7) / 1e7,
            lon: Math.round(f.lon * 1e7) / 1e7,
            alt: f.alt !== null ? Math.round(f.alt * 100) / 100 : null,
            acc: Math.round(f.acc * 100) / 100,
            speed: f.speed !== null && f.speed >= 0 ? Math.round(f.speed * 100) / 100 : null,
            t: iso(f.t),
        };
        try {
            await HikeStore.enqueue({ type: 'point', hikeId: S.hikeId, point });
            sync.schedule();
        } catch (e) {
            showAlert('บันทึกพิกัดลงเครื่องไม่สำเร็จ: ' + e.message, 'danger');
        }
        persist();
    }

    function persist(immediate = false) {
        if (!S) return;
        clearTimeout(persistTimer);
        const doSave = () => HikeStore.saveState(S).catch((e) => console.error('saveState', e));
        if (immediate) return doSave();
        persistTimer = setTimeout(doSave, 2000);
    }

    /** สร้างสถานะใหม่จากข้อมูลใน server (ใช้เมื่อเปิดจากเครื่องที่ไม่มีข้อมูลในเครื่อง) */
    async function rebuildFromServer(h) {
        const res = await App.api('api/hike-detail.php?id=' + encodeURIComponent(h.id));
        const st = newState(res.hike);
        let maxSeq = 0;
        for (const p of res.points) {
            // p = [seq, segment, lat, lon, alt, acc, t_ms]
            st.segment = p[1];
            advance(st, { lat: p[2], lon: p[3], alt: p[4], acc: p[5], t: p[6] });
            maxSeq = Math.max(maxSeq, p[0]);
        }
        st.seq = maxSeq;
        st.points = res.points.length;
        st.segment = res.hike.current_segment;
        // ใช้ระยะทางจากการคำนวณ (server จะคำนวณซ้ำอีกครั้งตอนจบ)
        return st;
    }

    /* ------------------------------------------------------------------
     * เวลาและสถิติ
     * ------------------------------------------------------------------ */
    function timing() {
        if (!S) return { total: 0, paused: 0, moving: 0 };
        const end = S.status === 'finishing' && S.finishAt ? S.finishAt : nowMs();
        const total = Math.max(0, end - S.startedAt);
        const paused = S.pausedTotal + (S.status === 'paused' && S.pausedAt ? Math.max(0, end - S.pausedAt) : 0);
        return { total: total / 1000, paused: paused / 1000, moving: Math.max(0, total - paused) / 1000 };
    }

    function currentSpeed() {
        if (!S || S.status !== 'recording' || !lastFix) return 0;
        const now = nowMs();
        if (now - lastFix.t > CFG.staleFixMs) return 0;
        if (lastFix.speed !== null && lastFix.speed >= 0 && lastFix.acc <= CFG.maxAccuracy) {
            return lastFix.speed < 0.3 ? 0 : lastFix.speed;
        }
        recentMoves = recentMoves.filter((m) => now - m.t <= CFG.speedWindowMs);
        const d = recentMoves.reduce((sum, m) => sum + m.d, 0);
        return d / (CFG.speedWindowMs / 1000);
    }

    /* ------------------------------------------------------------------
     * แสดงผล
     * ------------------------------------------------------------------ */
    function setChip(chip, color, text) {
        chip.querySelector('.dot').className = 'dot dot-' + color;
        chip.querySelector('.chip-text').textContent = text;
    }

    function showAlert(msg, type = 'warning') {
        el.gpsAlert.className = 'alert alert-' + type + ' py-2 small mb-2';
        el.gpsAlert.textContent = msg;
    }
    function hideAlert() { el.gpsAlert.classList.add('d-none'); }

    function render() {
        const status = S ? S.status : 'idle';
        el.btnStart.classList.toggle('d-none', status !== 'idle');
        el.btnPause.classList.toggle('d-none', status !== 'recording');
        el.btnResume.classList.toggle('d-none', status !== 'paused');
        el.btnFinish.classList.toggle('d-none', status !== 'recording' && status !== 'paused');

        const badge = el.recStatus;
        badge.classList.toggle('d-none', status === 'idle');
        badge.classList.toggle('paused', status !== 'recording');
        badge.querySelector('.chip-text').textContent =
            status === 'recording' ? 'กำลังบันทึก' : status === 'paused' ? 'หยุดพักชั่วคราว' : status === 'finishing' ? 'จบแล้ว · รอส่งข้อมูล' : '';

        const tm = timing();
        el.distance.textContent = App.fmtKm(S ? S.distance : 0);
        el.moving.textContent = App.fmtDuration(tm.moving);
        el.paused.textContent = App.fmtDuration(tm.paused);
        el.total.textContent = App.fmtDuration(tm.total);
        el.speed.textContent = App.fmtKmh(currentSpeed());
        el.avg.textContent = App.fmtKmh(S && tm.moving > 0 ? S.distance / tm.moving : 0);
        el.max.textContent = App.fmtKmh(S ? S.maxSpeed : 0);
        el.points.textContent = (S ? S.points : 0).toLocaleString('th-TH');

        if (lastFix) {
            el.coord.textContent = lastFix.lat.toFixed(5) + ', ' + lastFix.lon.toFixed(5);
            el.acc.textContent = '(±' + Math.round(lastFix.acc) + ' ม.)';
            el.alt.textContent = lastFix.alt !== null ? Math.round(lastFix.alt).toLocaleString('th-TH') : '-';
            const age = nowMs() - lastFix.t;
            if (age > CFG.staleFixMs) setChip(el.gpsStatus, 'amber', 'GPS ขาดหาย');
            else if (lastFix.acc <= 15) setChip(el.gpsStatus, 'green', 'GPS ±' + Math.round(lastFix.acc) + ' ม.');
            else if (lastFix.acc <= CFG.maxAccuracy) setChip(el.gpsStatus, 'amber', 'GPS ±' + Math.round(lastFix.acc) + ' ม.');
            else setChip(el.gpsStatus, 'red', 'แม่นยำต่ำ ±' + Math.round(lastFix.acc) + ' ม.');
        }

        // สถานะเครือข่ายและการซิงก์
        if (!syncStatus.browserOnline && syncStatus.browserOnline !== undefined) setChip(el.netStatus, 'red', 'ออฟไลน์');
        else if (!syncStatus.online) setChip(el.netStatus, 'red', 'เชื่อมต่อ server ไม่ได้');
        else setChip(el.netStatus, 'green', 'ออนไลน์');

        if (syncStatus.syncing) el.sync.textContent = 'กำลังส่ง...';
        else if (syncStatus.pending > 0) el.sync.textContent = 'รอส่ง ' + syncStatus.pending.toLocaleString('th-TH') + ' รายการ';
        else if (syncStatus.lastOkAt) el.sync.textContent = 'ซิงก์แล้ว';
        else el.sync.textContent = S ? 'ไม่มีข้อมูลค้างส่ง' : '-';
    }

    /* ------------------------------------------------------------------
     * GPS
     * ------------------------------------------------------------------ */
    function onPosition(pos) {
        const c = pos.coords;
        const f = {
            lat: c.latitude,
            lon: c.longitude,
            acc: c.accuracy,
            alt: typeof c.altitude === 'number' ? c.altitude : null,
            speed: typeof c.speed === 'number' && !Number.isNaN(c.speed) ? c.speed : null,
            t: (pos.timestamp || Date.now()) + clockOffset,
        };
        lastFix = f;
        if (el.gpsAlert.dataset.kind === 'gps') { hideAlert(); el.gpsAlert.dataset.kind = ''; }
        map.setPosition(f.lat, f.lon, f.acc);
        if (S && S.status === 'recording') record(f);
        render();
    }

    function onPositionError(err) {
        const msgs = {
            1: 'ไม่ได้รับอนุญาตให้เข้าถึงตำแหน่ง — เปิดสิทธิ์ Location ของเบราว์เซอร์สำหรับเว็บนี้ แล้วรีเฟรชหน้า',
            2: 'ไม่สามารถหาตำแหน่งได้ — ตรวจสอบว่าเปิด GPS/Location ของเครื่อง และอยู่ในที่โล่ง',
            3: 'รอสัญญาณ GPS นานเกินไป กำลังลองใหม่...',
        };
        el.gpsAlert.dataset.kind = 'gps';
        showAlert(msgs[err.code] || ('GPS ผิดพลาด: ' + err.message), err.code === 3 ? 'warning' : 'danger');
        setChip(el.gpsStatus, err.code === 3 ? 'amber' : 'red', err.code === 1 ? 'GPS ถูกปฏิเสธ' : 'GPS ไม่พร้อม');
    }

    function startWatch() {
        if (!window.isSecureContext) {
            el.gpsAlert.dataset.kind = 'gps';
            showAlert('ต้องเปิดเว็บผ่าน HTTPS (หรือ localhost) จึงจะใช้ GPS ได้', 'danger');
            setChip(el.gpsStatus, 'red', 'ต้องใช้ HTTPS');
            return;
        }
        if (!('geolocation' in navigator)) {
            showAlert('เบราว์เซอร์นี้ไม่รองรับ GPS (Geolocation API)', 'danger');
            setChip(el.gpsStatus, 'red', 'ไม่รองรับ GPS');
            return;
        }
        setChip(el.gpsStatus, 'gray', 'กำลังหาตำแหน่ง...');
        watchId = navigator.geolocation.watchPosition(onPosition, onPositionError, {
            enableHighAccuracy: true,
            maximumAge: 0,
            timeout: 30000,
        });
    }

    /* ------------------------------------------------------------------
     * Wake Lock (กันหน้าจอดับ — ถ้าเบราว์เซอร์รองรับ)
     * ------------------------------------------------------------------ */
    async function requestWakeLock() {
        if (!('wakeLock' in navigator) || document.visibilityState !== 'visible' || wakeLock) return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => { wakeLock = null; });
            el.wakeNote.textContent = 'หน้าจอจะเปิดค้างไว้ระหว่างบันทึก — อย่าสลับไปแอปอื่นนานเพราะเบราว์เซอร์อาจหยุดรับ GPS';
        } catch (e) { /* ผู้ใช้/ระบบไม่อนุญาต */ }
    }
    function releaseWakeLock() {
        if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
    }

    /* ------------------------------------------------------------------
     * ปุ่ม Start / Pause / Resume / Finish
     * ------------------------------------------------------------------ */
    async function startHike() {
        if (busy) return;
        busy = true;
        App.setLoading(el.btnStart, true, 'กำลังเริ่ม...');
        try {
            const res = await App.api('api/hike-start.php', { method: 'POST', body: { started_at: iso(nowMs()) } });
            if (res.code === 'active_exists') {
                S = await rebuildFromServer(res.hike);
                redrawTrack();
                App.toast('มีกิจกรรมที่ยังไม่จบ — กู้คืนให้แล้ว', 'info');
            } else {
                S = newState(res.hike);
                map.clearTrack();
                App.toast('เริ่มบันทึกการเดินป่าแล้ว');
                // ใช้ตำแหน่งล่าสุดเป็นจุดแรก ถ้ายังสดอยู่
                if (lastFix && nowMs() - lastFix.t < 10000) {
                    const first = { ...lastFix, t: Math.max(lastFix.t, S.startedAt) };
                    await record(first);
                }
            }
            await persist(true);
            requestWakeLock();
            if (!lastFix) showAlert('เริ่มแล้ว แต่ยังไม่ได้รับสัญญาณ GPS — ระบบจะบันทึกเมื่อได้ตำแหน่ง');
        } catch (err) {
            App.toast(err.network ? 'ต้องเชื่อมต่ออินเทอร์เน็ตเพื่อเริ่มกิจกรรม' : err.message, 'danger', 5000);
        } finally {
            busy = false;
            App.setLoading(el.btnStart, false);
            render();
        }
    }

    async function pauseHike() {
        if (!S || S.status !== 'recording') return;
        const at = nowMs();
        S.status = 'paused';
        S.pausedAt = at;
        recentMoves = [];
        await HikeStore.enqueue({ type: 'pause', hikeId: S.hikeId, at: iso(at) });
        await persist(true);
        sync.run();
        render();
    }

    async function resumeHike() {
        if (!S || S.status !== 'paused') return;
        const at = nowMs();
        S.pausedTotal += Math.max(0, at - (S.pausedAt || at));
        S.pausedAt = null;
        S.status = 'recording';
        S.segment += 1;      // segment ใหม่ — ไม่นับระยะทางระหว่างพัก
        S.anchor = null;
        await HikeStore.enqueue({ type: 'resume', hikeId: S.hikeId, at: iso(at) });
        await persist(true);
        requestWakeLock();
        sync.run();
        render();
    }

    function openFinish() {
        if (!S || (S.status !== 'recording' && S.status !== 'paused')) return;
        const tm = timing();
        el.finishSummary.textContent = 'ระบบจะบันทึกระยะทาง ' + App.fmtKm(S.distance) + ' กม. และเวลาเดิน '
            + App.fmtDuration(tm.moving) + ' ลงฐานข้อมูล หลังจบแล้วจะบันทึกต่อไม่ได้';
        el.finishName.value = S.hikeName || '';
        el.finishPending.classList.add('d-none');
        finishModal.show();
    }

    async function confirmFinish() {
        if (!S || (S.status !== 'recording' && S.status !== 'paused')) return;
        const name = el.finishName.value.trim().slice(0, 150);
        const at = nowMs();
        if (S.status === 'paused' && S.pausedAt) {
            S.pausedTotal += Math.max(0, at - S.pausedAt);
            S.pausedAt = null;
        }
        S.status = 'finishing';
        S.finishAt = at;
        if (name) S.hikeName = name;
        App.setLoading(el.btnFinishConfirm, true, 'กำลังบันทึก...');
        try {
            await HikeStore.enqueue({ type: 'finish', hikeId: S.hikeId, at: iso(at), name: name || null });
            await persist(true);
        } catch (e) {
            App.setLoading(el.btnFinishConfirm, false);
            App.toast('บันทึกลงเครื่องไม่สำเร็จ: ' + e.message, 'danger');
            return;
        }
        releaseWakeLock();
        render();
        await sync.run();
        App.setLoading(el.btnFinishConfirm, false);
        // ถ้ายังไม่ได้รับการยืนยันจาก server — แจ้งตามจริง ห้ามบอกว่าสำเร็จ
        if (S && S.status === 'finishing') {
            finishModal.hide();
            showFinishingNotice();
        }
    }

    function showFinishingNotice() {
        showAlert('จบกิจกรรมแล้วในเครื่อง แต่ยังไม่ได้บันทึกลง Server — ระบบจะส่งอัตโนมัติเมื่อออนไลน์ '
            + 'กรุณาเปิดหน้านี้ไว้หรือกลับมาเปิดอีกครั้ง และอย่าล้างข้อมูลเบราว์เซอร์', 'info');
    }

    function redrawTrack() {
        if (!S) return;
        map.setTrack(S.track);
        if (S.start) map.setStart(S.start[0], S.start[1]);
    }

    /* ------------------------------------------------------------------
     * Sync events
     * ------------------------------------------------------------------ */
    sync.addEventListener('status', (ev) => { syncStatus = ev.detail; render(); });

    sync.addEventListener('finished', async (ev) => {
        if (!S || ev.detail.hikeId !== S.hikeId) return;
        const id = S.hikeId;
        S = null;
        await HikeStore.clearState();
        finishModal.hide();
        App.toast('บันทึกกิจกรรมลงฐานข้อมูลเรียบร้อย');
        setTimeout(() => { window.location.href = 'hike-detail.php?id=' + encodeURIComponent(id); }, 900);
    });

    sync.addEventListener('gone', async (ev) => {
        if (!S || ev.detail.hikeId !== S.hikeId) return;
        S = null;
        await HikeStore.clearState();
        map.clearTrack();
        showAlert('กิจกรรมนี้ถูกลบออกจาก Server แล้ว', 'warning');
        render();
    });

    sync.addEventListener('auth', () => {
        el.gpsAlert.className = 'alert alert-danger py-2 small mb-2';
        el.gpsAlert.textContent = 'Session หมดอายุ — ข้อมูลยังเก็บอยู่ในเครื่อง ';
        const a = document.createElement('a');
        a.href = 'login.php';
        a.textContent = 'เข้าสู่ระบบใหม่';
        el.gpsAlert.append(a);
    });

    /* ------------------------------------------------------------------
     * กู้คืนกิจกรรมเมื่อเปิดหน้าใหม่
     * ------------------------------------------------------------------ */
    async function restore() {
        let local = null;
        try { local = await HikeStore.loadState(); } catch (e) { console.error(e); }
        if (local && local.userId !== boot.userId) {
            local = null; // ข้อมูลของผู้ใช้อื่นในเครื่องเดียวกัน — ไม่ใช้
        }
        const server = boot.activeHike;

        if (server) {
            if (local && local.hikeId === server.id) {
                S = local; // ข้อมูลในเครื่องใหม่กว่าเสมอ (server ได้ข้อมูลจากเครื่องนี้)
            } else {
                try {
                    S = await rebuildFromServer(server);
                    await persist(true);
                } catch (err) {
                    showAlert('โหลดกิจกรรมที่ค้างอยู่ไม่สำเร็จ กรุณารีเฟรชหน้า (' + err.message + ')', 'danger');
                    el.btnStart.disabled = true;
                    return;
                }
            }
            App.toast('กู้คืนกิจกรรม "' + S.hikeName + '" แล้ว', 'info');
        } else if (local) {
            const finishPending = local.status === 'finishing' || await HikeStore.hasPending(local.hikeId, 'finish');
            if (finishPending) {
                S = local;
                S.status = 'finishing';
                showFinishingNotice();
            } else {
                // กิจกรรมถูกจบ/ลบจากที่อื่นแล้ว — จุดที่ค้างในคิวยังถูกส่งต่อโดย SyncManager
                await HikeStore.clearState();
            }
        }
        if (S) {
            redrawTrack();
            if (S.status === 'recording' || S.status === 'paused') requestWakeLock();
        }
    }

    /* ------------------------------------------------------------------
     * เริ่มต้น
     * ------------------------------------------------------------------ */
    el.btnStart.addEventListener('click', startHike);
    el.btnPause.addEventListener('click', pauseHike);
    el.btnResume.addEventListener('click', resumeHike);
    el.btnFinish.addEventListener('click', openFinish);
    el.btnFinishConfirm.addEventListener('click', confirmFinish);
    el.btnZoomIn.addEventListener('click', () => map.zoomIn());
    el.btnZoomOut.addEventListener('click', () => map.zoomOut());
    el.btnLocate.addEventListener('click', () => {
        if (!map.locate()) App.toast('ยังไม่ได้รับตำแหน่ง GPS', 'warning');
    });
    el.btnFollow.addEventListener('click', () => map.setFollow(!map.follow));
    map.map.on('followchange', (ev) => {
        el.btnFollow.classList.toggle('active', ev.follow);
        el.btnFollow.setAttribute('aria-pressed', String(ev.follow));
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (S && (S.status === 'recording' || S.status === 'paused')) requestWakeLock();
            render();
        } else {
            persist(true);
        }
    });
    window.addEventListener('pagehide', () => persist(true));
    window.addEventListener('beforeunload', (ev) => {
        if (S && S.status === 'recording') {
            persist(true);
            ev.preventDefault();
            ev.returnValue = ''; // เตือนก่อนปิดหน้า — การปิดหน้าจะหยุดรับ GPS
        }
    });

    (async function init() {
        await HikeStore.open();
        if (!HikeStore.persistent) {
            showAlert('เบราว์เซอร์นี้ไม่รองรับ IndexedDB (เช่นโหมดไม่ระบุตัวตน) — ข้อมูลที่ยังไม่ได้ส่งจะหายถ้าปิดหน้า', 'warning');
        }
        startWatch();
        await restore();
        render();
        setInterval(render, 1000); // อัปเดตหน้าจอ — ค่าเวลาคำนวณจาก timestamp ทุกครั้ง
        sync.run();
    })();
})();
