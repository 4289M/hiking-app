/* =====================================================================
 * assets/js/offline.js
 * 1) HikeStore  — เก็บข้อมูลในเครื่องด้วย IndexedDB
 *      - outbox : คิวงานที่ยังไม่ได้รับการยืนยันจาก Server (พิกัด, pause, resume, finish)
 *                 key เพิ่มขึ้นอัตโนมัติ = ลำดับที่ต้องส่ง
 *      - state  : สถานะกิจกรรมที่กำลังบันทึก (ใช้กู้คืนเมื่อเปิดหน้าใหม่)
 * 2) SyncManager — ส่งคิวขึ้น Server ตามลำดับ ลบออกจากคิว "เฉพาะ" เมื่อ Server ยืนยันแล้ว
 * ===================================================================== */
(function () {
    'use strict';

    const DB_NAME = 'hiking-tracker';
    const DB_VERSION = 1;

    /* ------------------------------------------------------------------
     * HikeStore
     * ------------------------------------------------------------------ */
    const HikeStore = {
        _db: null,
        _memory: null, // fallback เมื่อ IndexedDB ใช้ไม่ได้ (เช่น private mode บางเบราว์เซอร์)
        persistent: true,

        async open() {
            if (this._db || this._memory) return;
            if (!('indexedDB' in window)) {
                this._useMemory();
                return;
            }
            try {
                this._db = await new Promise((resolve, reject) => {
                    const req = indexedDB.open(DB_NAME, DB_VERSION);
                    req.onupgradeneeded = () => {
                        const db = req.result;
                        if (!db.objectStoreNames.contains('outbox')) {
                            const os = db.createObjectStore('outbox', { keyPath: 'key', autoIncrement: true });
                            os.createIndex('hikeId', 'hikeId', { unique: false });
                        }
                        if (!db.objectStoreNames.contains('state')) {
                            db.createObjectStore('state', { keyPath: 'k' });
                        }
                    };
                    req.onsuccess = () => resolve(req.result);
                    req.onerror = () => reject(req.error);
                    req.onblocked = () => reject(new Error('IndexedDB blocked'));
                });
            } catch (e) {
                console.warn('IndexedDB ใช้งานไม่ได้ จะเก็บข้อมูลไว้ในหน่วยความจำแทน', e);
                this._useMemory();
            }
        },

        _useMemory() {
            this.persistent = false;
            this._memory = { outbox: [], seq: 0, state: null };
        },

        _tx(store, mode, fn) {
            return new Promise((resolve, reject) => {
                const tx = this._db.transaction(store, mode);
                const os = tx.objectStore(store);
                let result;
                Promise.resolve(fn(os)).then((r) => { result = r; });
                tx.oncomplete = () => resolve(result);
                tx.onerror = () => reject(tx.error);
                tx.onabort = () => reject(tx.error || new Error('transaction aborted'));
            });
        },

        _req(r) {
            return new Promise((resolve, reject) => {
                r.onsuccess = () => resolve(r.result);
                r.onerror = () => reject(r.error);
            });
        },

        /** เพิ่มงานลงคิว (หลายรายการใน transaction เดียว) */
        async enqueue(items) {
            await this.open();
            const list = Array.isArray(items) ? items : [items];
            if (this._memory) {
                list.forEach((it) => this._memory.outbox.push({ ...it, key: ++this._memory.seq }));
                return;
            }
            await this._tx('outbox', 'readwrite', (os) => { list.forEach((it) => os.add(it)); });
        },

        /** อ่านคิวตามลำดับ */
        async peek(limit = 500) {
            await this.open();
            if (this._memory) return this._memory.outbox.slice(0, limit);
            return this._tx('outbox', 'readonly', (os) => new Promise((resolve, reject) => {
                const out = [];
                const cur = os.openCursor();
                cur.onsuccess = () => {
                    const c = cur.result;
                    if (c && out.length < limit) {
                        out.push(c.value);
                        c.continue();
                    } else {
                        resolve(out);
                    }
                };
                cur.onerror = () => reject(cur.error);
            }));
        },

        async remove(keys) {
            await this.open();
            if (!keys.length) return;
            if (this._memory) {
                const set = new Set(keys);
                this._memory.outbox = this._memory.outbox.filter((it) => !set.has(it.key));
                return;
            }
            await this._tx('outbox', 'readwrite', (os) => { keys.forEach((k) => os.delete(k)); });
        },

        async count(hikeId = null) {
            await this.open();
            if (this._memory) {
                return this._memory.outbox.filter((it) => hikeId === null || it.hikeId === hikeId).length;
            }
            return this._tx('outbox', 'readonly', (os) =>
                this._req(hikeId === null ? os.count() : os.index('hikeId').count(hikeId)));
        },

        async countPoints(hikeId) {
            const items = await this.peek(100000);
            return items.filter((it) => it.type === 'point' && it.hikeId === hikeId).length;
        },

        async hasPending(hikeId, type) {
            const items = await this.peek(100000);
            return items.some((it) => it.hikeId === hikeId && (!type || it.type === type));
        },

        async removeHike(hikeId) {
            const items = await this.peek(100000);
            await this.remove(items.filter((it) => it.hikeId === hikeId).map((it) => it.key));
        },

        async saveState(state) {
            await this.open();
            if (this._memory) { this._memory.state = state; return; }
            await this._tx('state', 'readwrite', (os) => { os.put({ k: 'active', v: state, savedAt: Date.now() }); });
        },

        async loadState() {
            await this.open();
            if (this._memory) return this._memory.state;
            const row = await this._tx('state', 'readonly', (os) => this._req(os.get('active')));
            return row ? row.v : null;
        },

        async clearState() {
            await this.open();
            if (this._memory) { this._memory.state = null; return; }
            await this._tx('state', 'readwrite', (os) => { os.delete('active'); });
        },
    };

    /* ------------------------------------------------------------------
     * SyncManager
     * events (addEventListener):
     *   'status'   detail: { pending, online, syncing, lastOkAt, error }
     *   'finished' detail: { hikeId, hike }        เมื่อ Server ยืนยันการจบกิจกรรม
     *   'gone'     detail: { hikeId }              Server ไม่พบกิจกรรม (ถูกลบ)
     *   'auth'                                     session หมดอายุ ต้อง login ใหม่
     * ------------------------------------------------------------------ */
    class SyncManager extends EventTarget {
        constructor({ batchMax = 200, intervalMs = 15000 } = {}) {
            super();
            this.batchMax = Math.min(batchMax, 500);
            this.intervalMs = intervalMs;
            this.syncing = false;
            this.online = navigator.onLine;
            this.reachable = null; // ผลการติดต่อ server ล่าสุด
            this.lastOkAt = null;
            this.lastError = null;
            this.backoff = 0;
            this._again = false;
            this._debounce = null;
            this._authStopped = false;

            window.addEventListener('online', () => { this.online = true; this.backoff = 0; this.run(); });
            window.addEventListener('offline', () => { this.online = false; this.emitStatus(); });
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') this.run(); });
            setInterval(() => this.run(), this.intervalMs);
        }

        /** เรียกหลังเพิ่มพิกัด — รวมส่งเป็น batch ทุก ~5 วินาที */
        schedule(delay = 5000) {
            clearTimeout(this._debounce);
            this._debounce = setTimeout(() => this.run(), delay);
        }

        async emitStatus() {
            let pending = 0;
            try { pending = await HikeStore.count(); } catch (e) { /* ignore */ }
            this.dispatchEvent(new CustomEvent('status', {
                detail: {
                    pending,
                    online: this.online && this.reachable !== false,
                    browserOnline: this.online,
                    syncing: this.syncing,
                    lastOkAt: this.lastOkAt,
                    error: this.lastError,
                },
            }));
        }

        async run() {
            if (this._authStopped) return;
            if (this.syncing) { this._again = true; return; }
            this.syncing = true;
            this.emitStatus();
            try {
                // ทำงานจนคิวหมด หรือเจอข้อผิดพลาด
                for (let guard = 0; guard < 200; guard++) {
                    const items = await HikeStore.peek(this.batchMax);
                    if (!items.length) break;
                    const progressed = await this._sendNext(items);
                    if (!progressed) break;
                }
            } finally {
                this.syncing = false;
                await this.emitStatus();
                if (this._again) {
                    this._again = false;
                    setTimeout(() => this.run(), 500);
                }
            }
        }

        /** ส่งงานถัดไปในคิว คืน true ถ้าสำเร็จ (คิวขยับ) */
        async _sendNext(items) {
            const first = items[0];
            try {
                if (first.type === 'point') {
                    // รวมพิกัดที่ต่อเนื่องกันของกิจกรรมเดียวกันเป็น batch
                    const batch = [];
                    for (const it of items) {
                        if (it.type !== 'point' || it.hikeId !== first.hikeId) break;
                        batch.push(it);
                    }
                    const res = await App.api('api/gps-save.php', {
                        method: 'POST',
                        body: { hike_id: first.hikeId, points: batch.map((it) => it.point) },
                    });
                    // ลบเฉพาะจุดที่ server ยืนยันว่าบันทึกแล้ว หรือปฏิเสธเพราะข้อมูลผิด (ส่งซ้ำก็ไม่ผ่าน)
                    const done = new Set([...(res.saved || []), ...((res.rejected || []).map((r) => r.id))]);
                    if (res.rejected && res.rejected.length) {
                        console.warn('Server ปฏิเสธพิกัดบางจุด', res.rejected);
                    }
                    const keys = batch.filter((it) => done.has(it.point.id)).map((it) => it.key);
                    await HikeStore.remove(keys);
                    this._ok();
                    return keys.length > 0;
                }

                const endpoint = { pause: 'api/hike-pause.php', resume: 'api/hike-resume.php', finish: 'api/hike-finish.php' }[first.type];
                if (!endpoint) {
                    await HikeStore.remove([first.key]); // งานที่ไม่รู้จัก
                    return true;
                }
                const body = { hike_id: first.hikeId, at: first.at };
                if (first.type === 'finish' && first.name) body.hike_name = first.name;
                const res = await App.api(endpoint, { method: 'POST', body });
                await HikeStore.remove([first.key]);
                this._ok();
                if (first.type === 'finish') {
                    this.dispatchEvent(new CustomEvent('finished', { detail: { hikeId: first.hikeId, hike: res.hike } }));
                }
                return true;
            } catch (err) {
                return this._handleError(err, first);
            }
        }

        async _handleError(err, item) {
            if (err.network) {
                this.reachable = false;
                this.lastError = 'ออฟไลน์ — จะส่งข้อมูลอัตโนมัติเมื่อเชื่อมต่อได้';
                this.backoff = Math.min((this.backoff || 2000) * 2, 60000);
                setTimeout(() => this.run(), this.backoff);
                return false;
            }
            if (err.status === 401) {
                this._authStopped = true;
                this.lastError = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่ (ข้อมูลยังเก็บอยู่ในเครื่อง)';
                this.dispatchEvent(new CustomEvent('auth'));
                return false;
            }
            if (err.status === 419) {
                this.lastError = 'CSRF token หมดอายุ กรุณารีเฟรชหน้า (ข้อมูลยังเก็บอยู่ในเครื่อง)';
                return false;
            }
            if (err.status === 404) {
                // กิจกรรมถูกลบแล้ว — ทิ้งงานทั้งหมดของกิจกรรมนี้
                await HikeStore.removeHike(item.hikeId);
                this.dispatchEvent(new CustomEvent('gone', { detail: { hikeId: item.hikeId } }));
                return true;
            }
            if (err.status === 409 && err.code === 'finished') {
                // pause/resume หลังกิจกรรมจบแล้ว — ไม่มีผล ทิ้งได้
                await HikeStore.remove([item.key]);
                return true;
            }
            if (err.status === 413 || err.status === 400 || err.status === 422) {
                // ข้อมูลผิดรูปแบบ ส่งซ้ำก็ไม่ผ่าน — ทิ้งรายการนี้เพื่อไม่ให้คิวค้าง
                console.error('ทิ้งรายการที่ server ไม่รับ', item, err);
                await HikeStore.remove([item.key]);
                return true;
            }
            this.lastError = err.message;
            return false;
        }

        _ok() {
            this.reachable = true;
            this.lastOkAt = Date.now();
            this.lastError = null;
            this.backoff = 0;
        }
    }

    window.HikeStore = HikeStore;
    window.SyncManager = SyncManager;
})();
