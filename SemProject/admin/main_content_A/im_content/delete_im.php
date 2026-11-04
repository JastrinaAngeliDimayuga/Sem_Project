<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        /* ==============================
   DELETE INVENTORY
============================== */

        #deleteModal .modal-dialog {
            max-width: 420px;
        }

        #deleteModal .modal-content {
            border: none;
            border-radius: 16px;
            padding: 10px;
            background: #fff;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        #deleteModal .modal-header {
            padding: 8px 10px 12px;
            margin: 0 0 10px;
            border-bottom: 1px solid #a98977;
        }

        #deleteModal .modal-title {
            color: #704b38;
            font-size: 20px;
            font-weight: 800;
            text-transform: uppercase;
        }

        #deleteModal .modal-body {
            padding: 10px 10px 4px;
            text-align: center;
        }

        #deleteModal .delete-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            border-radius: 50%;
            background: #fbe9e7;
            color: #c0392b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        #deleteModal .delete-message {
            margin: 0 0 4px;
            color: #704b38;
            font-size: 15px;
            font-weight: 700;
        }

        #deleteModal .delete-name {
            color: #a85e39;
            word-break: break-word;
        }

        #deleteModal .delete-note {
            margin: 0;
            color: #8b8b8b;
            font-size: 12px;
        }

        #deleteModal .modal-footer {
            border: none;
            justify-content: center;
            gap: 10px;
            padding: 14px 10px 8px;
        }

        #deleteModal .cancel-btn,
        #deleteModal .confirm-delete-btn {
            min-height: 34px;
            min-width: 100px;
            padding: 7px 18px;
            border: none;
            border-radius: 3px;
            color: #fff;
            font-size: 12px;
            cursor: pointer;
        }

        #deleteModal .cancel-btn {
            background: #a36c4c;
            box-shadow: 3px 3px 0 #70452e;
        }

        #deleteModal .cancel-btn:hover {
            background: #805037;
        }

        #deleteModal .confirm-delete-btn {
            background: #c0392b;
            box-shadow: 3px 3px 0 #8e2a20;
        }

        #deleteModal .confirm-delete-btn:hover {
            background: #a53125;
        }

    </style>
</head>


<body>
   
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalTitle"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">

                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteModalTitle">
                                Delete Item
                            </h5>

                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                            </button>
                        </div>

                        <div class="modal-body">
                            <div class="delete-icon">
                                <i class="bi bi-trash3-fill"></i>
                            </div>

                            <p class="delete-message">
                                Delete <span class="delete-name" id="deleteItemName"></span>?
                            </p>

                            <p class="delete-note">
                                This action cannot be undone.
                            </p>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="cancel-btn" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="button" class="confirm-delete-btn" id="confirmDeleteBtn">
                                Delete
                            </button>
                        </div>

                    </div>
                </div>
            </div>
</body>

</html>