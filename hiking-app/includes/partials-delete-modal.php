<?php
/** includes/partials-delete-modal.php — Dialog ยืนยันการลบกิจกรรม (ใช้ใน history.php และ hike-detail.php) */
declare(strict_types=1);
?>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="deleteModalTitle">ลบกิจกรรม?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">ต้องการลบ "<strong id="deleteHikeName"></strong>" และพิกัด GPS ทั้งหมดของกิจกรรมนี้ใช่หรือไม่? การลบไม่สามารถกู้คืนได้</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light btn-lg flex-fill" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" id="btnDeleteConfirm" class="btn btn-danger btn-lg flex-fill">ลบ</button>
            </div>
        </div>
    </div>
</div>
