/* =====================================================================
 * assets/js/detail.js — หน้ารายละเอียดกิจกรรม
 *  - โหลดพิกัดจาก API แล้ววาดเส้นทางย้อนหลัง
 *  - คลิกแถวในตาราง → แสดงจุดนั้นบนแผนที่
 *  - แก้ไขชื่อกิจกรรม
 * ===================================================================== */
(function () {
    'use strict';

    const root = document.querySelector('.detail-layout');
    if (!root) return;
    const hikeId = Number(root.dataset.hikeId);
    const loading = document.getElementById('mapLoading');
    const map = HikeMap.create(document.getElementById('detailMap'));

    App.api('api/hike-detail.php?id=' + encodeURIComponent(hikeId))
        .then((res) => {
            if (!map.renderHistory(res.points)) {
                loading.innerHTML = '';
                const p = document.createElement('p');
                p.className = 'bg-white rounded-3 px-3 py-2 mb-0';
                p.textContent = 'กิจกรรมนี้ไม่มีพิกัด GPS';
                loading.append(p);
                return;
            }
            loading.remove();
        })
        .catch((err) => {
            loading.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'bg-white rounded-3 px-3 py-2 mb-0 text-danger';
            p.textContent = 'โหลดเส้นทางไม่สำเร็จ: ' + err.message;
            loading.append(p);
        });

    // คลิก/กด Enter ที่แถวในตาราง → ไปยังจุดบนแผนที่
    document.querySelectorAll('.point-row').forEach((row) => {
        const go = () => {
            document.querySelectorAll('.point-row.table-active').forEach((r) => r.classList.remove('table-active'));
            row.classList.add('table-active');
            if (map.showPointBySeq(Number(row.dataset.seq))) {
                document.getElementById('detailMap').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };
        row.addEventListener('click', go);
        row.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') go(); });
    });

    // แก้ไขชื่อ
    const form = document.getElementById('renameForm');
    const input = document.getElementById('renameInput');
    const modalEl = document.getElementById('renameModal');
    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const name = input.value.trim();
        if (!name) {
            input.classList.add('is-invalid');
            return;
        }
        input.classList.remove('is-invalid');
        const btn = form.querySelector('button[type="submit"]');
        App.setLoading(btn, true, 'กำลังบันทึก...');
        try {
            const res = await App.api('api/hike-update.php', { method: 'POST', body: { hike_id: hikeId, hike_name: name } });
            document.getElementById('hikeTitle').textContent = res.hike.hike_name;
            document.title = res.hike.hike_name;
            document.querySelectorAll('[data-delete-hike]').forEach((b) => { b.dataset.hikeName = res.hike.hike_name; });
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            App.toast('บันทึกชื่อกิจกรรมแล้ว');
        } catch (err) {
            App.toast(err.message, 'danger');
        } finally {
            App.setLoading(btn, false);
        }
    });
})();
