<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        /* ==============================
   VIEW INVENTORY
============================== */

        #viewModal .modal-dialog {
            max-width: 480px;
        }

        #viewModal .modal-content {
            border: none;
            border-radius: 16px;
            padding: 10px;
            background: #fff;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        #viewModal .modal-header {
            padding: 8px 10px 12px;
            margin: 0 0 10px;
            border-bottom: 1px solid #a98977;
        }

        #viewModal .modal-title {
            color: #704b38;
            font-size: 20px;
            font-weight: 800;
            text-transform: uppercase;
        }

        #viewModal .modal-body {
            padding: 10px 10px 4px;
            text-align: center;
        }

        #viewModal .view-image {
            width: 140px;
            height: 140px;
            margin: 0 auto 12px;
            border-radius: 10px;
            object-fit: cover;
            background: #dedede;
            display: block;
        }

        #viewModal .view-name {
            margin: 0 0 6px;
            color: #704b38;
            font-size: 18px;
            font-weight: 800;
            word-break: break-word;
        }

        #viewModal .view-type {
            display: inline-block;
            margin-bottom: 14px;
            padding: 3px 12px;
            border-radius: 20px;
            background: #f3e7e0;
            color: #a85e39;
            font-size: 12px;
            font-weight: 700;
        }

        #viewModal .view-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 16px;
            text-align: left;
            border: 1.5px solid #a85e39;
            border-radius: 10px;
            padding: 14px;
        }

        #viewModal .view-detail-label {
            color: #8b8b8b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        #viewModal .view-detail-value {
            color: #704b38;
            font-size: 14px;
            font-weight: 600;
            word-break: break-word;
        }

        #viewModal .modal-footer {
            border: none;
            justify-content: center;
            gap: 10px;
            padding: 14px 10px 8px;
        }

        #viewModal .close-view-btn {
            min-height: 34px;
            min-width: 100px;
            padding: 7px 18px;
            border: none;
            border-radius: 3px;
            background: #a36c4c;
            color: #fff;
            font-size: 12px;
            box-shadow: 3px 3px 0 #70452e;
            cursor: pointer;
        }

        #viewModal .close-view-btn:hover {
            background: #805037;
        }

    </style>
</head>


<body>

    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalTitle"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="viewModalTitle">
                        Item Details
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>

                <div class="modal-body">
                    <img id="viewItemImage" class="view-image" src="" alt="Item photo">

                    <p class="view-name" id="viewItemName"></p>

                    <span class="view-type" id="viewItemType"></span>

                    <div class="view-details" id="viewItemDetails"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="close-view-btn" data-bs-dismiss="modal">
                        Close
                    </button>
                </div>

            </div>
        </div>
    </div>
</body>

</html>