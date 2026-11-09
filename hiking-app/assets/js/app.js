/* =====================================================================
 * assets/js/app.js — ฟังก์ชันร่วมทุกหน้า
 *  - App.api()   เรียก API แบบ JSON พร้อม CSRF token
 *  - App.toast() แจ้งเตือนแบบ toast
 *  - ตัวช่วยจัดรูปแบบตัวเลข/เวลา
 *  - Validation ของฟอร์ม Bootstrap และสถานะ Loading ของปุ่ม submit
 * ===================================================================== */
(function () {
    'use strict';

    class ApiError extends Error {
        constructor(message, { status = 0, code = null, network = false, data = null } = {}) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.code = code;
            this.network = network; // true = ติดต่อ server ไม่ได้ (ออฟไลน์/timeout)
            this.data = data;
        }
    }

    function csrf() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    /**
     * เรียก API
     * @param {string} url
     * @param {{method?:string, body?:any, timeout?:number}} opts
     * @returns {Promise<any>} JSON ที่ ok === true
     * @throws {ApiError}
     */
    async function api(url, { method = 'GET', body = undefined, timeout = 20000 } = {}) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeout);
        let res;
        try {
            res = await fetch(url, {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
                    ...(method !== 'GET' ? { 'X-CSRF-Token': csrf() } : {}),
                },
                body: body !== undefined ? JSON.stringify(body) : undefined,
                signal: ctrl.signal,
            });
        } catch (err) {
            throw new ApiError('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', { network: true });
        } finally {
            clearTimeout(timer);
        }

        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            // ไม่ใช่ JSON (เช่น proxy/captive portal ตอบกลับเป็น HTML)
            throw new ApiError('เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + res.status + ')', { status: res.status, network: res.status === 0 || res.status >= 500 });
        }
        if (!res.ok || !data || data.ok !== true) {
            throw new ApiError((data && data.error) || ('เกิดข้อผิดพลาด (' + res.status + ')'), {
                status: res.status,
                code: data && data.code,
                data,
                network: res.status >= 500 || res.status === 408,
            });
        }
        return data;
    }

    function toast(message, type = 'success', delay = 3500) {
        const area = document.getElementById('toastArea');
        if (!area || !window.bootstrap) {
            console.log('[toast]', message);
            return;
        }
        const colors = { success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-dark' };
        const el = document.createElement('div');
        el.className = 'toast align-items-center border-0 ' + (colors[type] || colors.info);
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        const wrap = document.createElement('div');
        wrap.className = 'd-flex';
        const bodyEl = document.createElement('div');
        bodyEl.className = 'toast-body';
        bodyEl.textContent = message; // textContent กัน XSS
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close me-2 m-auto' + (type === 'warning' ? '' : ' btn-close-white');
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', 'ปิด');
        wrap.append(bodyEl, close);
        el.append(wrap);
        area.append(el);
        const t = new bootstrap.Toast(el, { delay });
        el.addEventListener('hidden.bs.toast', () => el.remove());
        t.show();
    }

    /* ---------- รูปแบบตัวเลข ---------- */
    function fmtDuration(totalSeconds) {
        const s = Math.max(0, Math.floor(totalSeconds));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        return h + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    }
    const fmtKm = (meters, d = 2) => (meters / 1000).toFixed(d);
    const fmtKmh = (mps, d = 1) => (mps * 3.6).toFixed(d);

    function setLoading(btn, loading, text) {
        if (!btn) return;
        if (loading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ';
            btn.append(document.createTextNode(text || btn.dataset.loadingText || 'กำลังดำเนินการ...'));
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
        }
    }

    /* ---------- Validation ฟอร์ม (ฝั่ง client — server ตรวจซ้ำเสมอ) ---------- */
    document.querySelectorAll('form.needs-validation').forEach((form) => {
        form.addEventListener('submit', (ev) => {
            form.querySelectorAll('[data-match]').forEach((input) => {
                const other = form.querySelector('#' + input.dataset.match);
                input.setCustomValidity(other && other.value !== input.value ? 'mismatch' : '');
            });
            if (!form.checkValidity()) {
                ev.preventDefault();
                ev.stopPropagation();
            } else {
                setLoading(form.querySelector('button[type="submit"]'), true);
            }
            form.classList.add('was-validated');
        });
    });

    window.App = { api, ApiError, toast, csrf, fmtDuration, fmtKm, fmtKmh, setLoading };
})();
