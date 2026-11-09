/* =====================================================================
 * assets/js/history.js — ลบกิจกรรม (พร้อม Dialog ยืนยัน)
 * ใช้กับปุ่มที่มี data-delete-hike="id" data-hike-name="..." [data-redirect="url"]
 * ===================================================================== */
(function () {
    'use strict';

    const modalEl = document.getElementById('deleteModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    const nameEl = document.getElementById('deleteHikeName');
    const btnConfirm = document.getElementById('btnDeleteConfirm');
    let target = null;

    document.querySelectorAll('[data-delete-hike]').forEach((btn) => {
        btn.addEventListener('click', () => {
            target = btn;
            nameEl.textContent = btn.dataset.hikeName || '';
            modal.show();
        });
    });

    btnConfirm.addEventListener('click', async () => {
        if (!target) return;
        const id = Number(target.dataset.deleteHike);
        App.setLoading(btnConfirm, true, 'กำลังลบ...');
        try {
            await App.api('api/hike-delete.php', { method: 'POST', body: { hike_id: id } });
            // ลบข้อมูลที่ค้างในเครื่องของกิจกรรมนี้ด้วย (ถ้ามี)
            if (window.HikeStore) {
                try { await HikeStore.removeHike(id); } catch (e) { /* ignore */ }
            }
            modal.hide();
            App.toast('ลบกิจกรรมแล้ว');
            if (target.dataset.redirect) {
                setTimeout(() => { window.location.href = target.dataset.redirect; }, 600);
            } else {
                const card = document.getElementById('hike-' + id);
                if (card) card.remove();
            }
        } catch (err) {
            App.toast(err.message, 'danger');
        } finally {
            App.setLoading(btnConfirm, false);
        }
    });
})();
