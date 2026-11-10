<style>
    /* ==============================
       VIEW INVENTORY MODAL
    ============================== */

    #viewModal .modal-dialog {
        max-width: 480px;
    }

    #viewModal .modal-content {
        border: 1px solid #E4D6C8;
        border-radius: 20px;
        padding: 0;
        background: #FCF9F5;
        box-shadow: 0 18px 45px rgba(53, 40, 32, 0.22);
    }

    #viewModal .modal-header {
        padding: 18px 24px;
        margin: 0;
        background: #F6EEE6;
        border-bottom: 1px solid #E4D6C8;
    }

    #viewModal .modal-title {
        color: #2A1C13;
        font-size: 20px;
        font-weight: 700;
    }

    #viewModal .modal-body {
        padding: 24px 24px 8px;
        text-align: center;
    }

    #viewModal .view-image {
        display: block;
        width: 140px;
        height: 140px;
        margin: 0 auto 14px;
        border-radius: 14px;
        object-fit: cover;
        background: #F6EEE6;
    }

    #viewModal .view-name {
        margin: 0 0 8px;
        color: #2A1C13;
        font-size: 20px;
        font-weight: 800;
        word-break: break-word;
    }

    #viewModal .view-type {
        display: inline-block;
        margin-bottom: 16px;
        padding: 4px 14px;
        border-radius: 20px;
        background: #EFE3D8;
        color: #6B4631;
        font-size: 12px;
        font-weight: 700;
    }

    #viewModal .view-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px 16px;
        padding: 16px;
        text-align: left;
        background: #FFFFFF;
        border: 1px solid #E4D6C8;
        border-radius: 14px;
    }

    #viewModal .view-detail-label {
        margin-bottom: 2px;
        color: #85766A;
        font-size: 11px;
        font-weight: 600;
    }

    #viewModal .view-detail-value {
        color: #2A1C13;
        font-size: 14px;
        font-weight: 600;
        word-break: break-word;
    }

    #viewModal .modal-footer {
        justify-content: center;
        gap: 10px;
        padding: 16px 24px 22px;
        border: none;
        background: transparent;
    }

    #viewModal .close-view-btn {
        min-width: 120px;
        min-height: 42px;
        padding: 10px 22px;
        border: none;
        border-radius: 12px;
        background: linear-gradient(135deg, #6B4631, #4F3324);
        color: #FFFFFF;
        font-size: 14px;
        font-weight: 600;
        box-shadow: 0 4px 10px rgba(60, 38, 24, 0.28);
        cursor: pointer;
        transition: filter 0.2s ease;
    }

    #viewModal .close-view-btn:hover,
    #viewModal .close-view-btn:focus-visible {
        filter: brightness(1.12);
    }
</style>

<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="viewModalTitle">Item Details</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <img id="viewItemImage" class="view-image" src="" alt="Item photo">

                <p class="view-name" id="viewItemName"></p>

                <span class="view-type" id="viewItemType"></span>

                <div class="view-details" id="viewItemDetails"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="close-view-btn" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>