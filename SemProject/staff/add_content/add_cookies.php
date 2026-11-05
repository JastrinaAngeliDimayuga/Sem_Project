<?php
// admin/add_content/add_cookies.php
?>

<head>
    <link rel="stylesheet" href="staff/assets/add_cookies.css">
</head>

<!-- Add/Edit Cookie Modal -->
<div class="modal fade" id="staffModal" tabindex="-1" aria-labelledby="staffFormTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <h5 class="modal-title" id="staffFormTitle">
                    Add Cookies
                </h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body">
                <form id="staffForm">
                    <div class="ingredient-panel">

                        <div class="ingredient-layout">

                            <!-- Photo Upload -->
                            <div class="photo-column">

                                <div class="photo-preview" id="staffPhotoPreview">
                                    <i class="bi bi-image" style="font-size: 30px;"></i>
                                </div>

                                <input type="file" id="staffPhoto" class="photo-input" accept="image/*">

                                <button type="button" class="upload-photo"
                                    onclick="document.getElementById('staffPhoto').click()">
                                    <i class="bi bi-cloud-upload"></i>
                                    Upload Photo
                                </button>

                            </div>

                            <!-- Cookie Information -->
                            <div>
                                <div class="ingredient-fields">

                                    <!-- Name -->
                                    <div class="field-group full-width">
                                        <label for="staffName" class="form-label">
                                            Name
                                        </label>

                                        <input type="text" id="staffName" name="name" class="form-control"
                                            placeholder="Cookie name" required>
                                    </div>

                                    <!-- Unit -->
                                    <div class="field-group">
                                        <label for="staffUnit" class="form-label">
                                            Unit
                                        </label>

                                        <input type="text" id="staffUnit" name="unit" class="form-control"
                                            placeholder="e.g. pcs, packs, boxes" required>
                                    </div>

                                    <!-- Quantity -->
                                    <div class="field-group">
                                        <label for="staffQuantity" class="form-label">
                                            Quantity
                                        </label>

                                        <input type="number" id="staffQuantity" name="quantity" min="0" step="any"
                                            class="form-control" placeholder="0" required>
                                    </div>

                                    <!-- Price (Philippine Peso) -->
                                    <div class="field-group">
                                        <label for="staffPrice" class="form-label">
                                            Price (₱)
                                        </label>

                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>

                                            <input type="number" id="staffPrice" name="price" min="0" step="0.01"
                                                class="form-control" placeholder="0.00" required>
                                        </div>
                                    </div>

                                    <!-- Orders -->
                                    <div class="field-group">
                                        <label for="staffOrders" class="form-label">
                                            Orders
                                        </label>

                                        <input type="number" id="staffOrders" name="orders" min="0" step="1"
                                            class="form-control" placeholder="Number of orders" value="0">
                                    </div>

                                    <!-- Category -->
                                    <div class="field-group">
                                        <label for="staffCategory" class="form-label">
                                            Category
                                        </label>

                                        <input type="text" id="staffCategory" name="category" class="form-control"
                                            placeholder="e.g. Chocolate Cookies" required>
                                    </div>

                                    <!-- Flavour -->
                                    <div class="field-group">
                                        <label for="staffFlavour" class="form-label">
                                            Flavour
                                        </label>

                                        <input type="text" id="staffFlavour" name="flavour" class="form-control"
                                            placeholder="e.g. Chocolate, Vanilla">
                                    </div>

                                    <!-- Texture -->
                                    <div class="field-group">
                                        <label for="staffTexture" class="form-label">
                                            Texture
                                        </label>

                                        <input type="text" id="staffTexture" name="texture" class="form-control"
                                            placeholder="e.g. Chewy, Crunchy">
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Save Cookie Button Only -->
                        <div class="ingredient-actions">
                            <button type="submit" class="save-btn" id="saveStaffBtn">
                                Add Cookie
                            </button>
                        </div>

                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
    (function () {
        const modalElement = document.getElementById("staffModal");
        const form = document.getElementById("staffForm");
        const photoInput = document.getElementById("staffPhoto");
        const photoPreview = document.getElementById("staffPhotoPreview");
        const saveButton = document.getElementById("saveStaffBtn");

        if (
            !modalElement ||
            !form ||
            !photoInput ||
            !photoPreview ||
            !saveButton
        ) {
            return;
        }

        if (typeof bootstrap === "undefined") {
            console.error(
                "Bootstrap JavaScript is required for the cookie modal."
            );
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

        let editStaffId = null;
        let staffPhotoData = "";

        // Get the shared inventory array.
        function getStaffItems() {
            return typeof items !== "undefined" && Array.isArray(items)
                ? items
                : null;
        }

        // Display the selected cookie photo.
        function displayStaffPhoto(src) {
            photoPreview.replaceChildren();

            if (src) {
                const img = document.createElement("img");
                img.src = src;
                img.alt = "Cookie photo";
                photoPreview.appendChild(img);
            } else {
                const icon = document.createElement("i");
                icon.className = "bi bi-image";
                icon.style.fontSize = "30px";
                photoPreview.appendChild(icon);
            }
        }

        // Clear the cookie photo.
        function clearStaffPhoto() {
            staffPhotoData = "";
            photoInput.value = "";
            displayStaffPhoto("");
        }

        // Reset the cookie form.
        window.resetStaffForm = function () {
            editStaffId = null;
            form.reset();
            clearStaffPhoto();

            document.getElementById("staffFormTitle").textContent =
                "Add Cookies";

            saveButton.textContent = "Add Cookie";
        };

        // Clear all cookie fields without closing the modal.
        window.clearStaffFields = function () {
            form.querySelectorAll('input:not([type="file"])')
                .forEach(function (input) {
                    input.value = "";
                });

            clearStaffPhoto();
        };

        // Preview the uploaded cookie photo.
        photoInput.addEventListener("change", function () {
            const file = this.files[0];

            if (!file) {
                return;
            }

            if (!file.type.startsWith("image/")) {
                alert("Please select a valid image.");
                clearStaffPhoto();
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                staffPhotoData = event.target.result;
                displayStaffPhoto(staffPhotoData);
            };

            reader.onerror = function () {
                alert("Unable to read the selected image.");
                clearStaffPhoto();
            };

            reader.readAsDataURL(file);
        });

        // Open the modal for adding or editing a cookie.
        window.openStaffForm = function (id = null) {
            editStaffId = id;
            form.reset();
            clearStaffPhoto();

            const staffItems = getStaffItems();

            const staff = id === null || !staffItems
                ? null
                : staffItems.find(function (entry) {
                    return String(entry.id) === String(id);
                });

            if (id !== null && !staff) {
                alert("The selected cookie could not be found.");
                return;
            }

            document.getElementById("staffFormTitle").textContent =
                staff ? "Edit Cookies" : "Add Cookies";

            saveButton.textContent =
                staff ? "Update Cookie" : "Add Cookie";

            if (staff) {
                document.getElementById("staffName").value =
                    staff.name ?? "";

                document.getElementById("staffUnit").value =
                    staff.unit ?? "";

                document.getElementById("staffQuantity").value =
                    staff.quantity ?? "";

                document.getElementById("staffPrice").value =
                    staff.price ?? "";

                document.getElementById("staffOrders").value =
                    staff.orders ?? 0;

                document.getElementById("staffCategory").value =
                    staff.category ?? "";

                document.getElementById("staffFlavour").value =
                    staff.flavour ?? "";

                document.getElementById("staffTexture").value =
                    staff.texture ?? staff.spec ?? "";

                staffPhotoData = staff.image ?? "";

                if (staffPhotoData) {
                    displayStaffPhoto(staffPhotoData);
                }
            }

            modal.show();
        };

        // Validate and save to the shared in-memory inventory.
        form.addEventListener("submit", function (event) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            const data = {
                type: "Products",
                name: document.getElementById("staffName").value.trim(),
                unit: document.getElementById("staffUnit").value.trim(),
                quantity: Number(
                    document.getElementById("staffQuantity").value
                ),
                price: Number(
                    document.getElementById("staffPrice").value
                ),
                orders: Math.floor(Number(
                    document.getElementById("staffOrders").value
                )) || 0,
                category: document.getElementById("staffCategory").value.trim(),
                flavour: document.getElementById("staffFlavour").value.trim(),
                texture: document.getElementById("staffTexture").value.trim(),
                image: staffPhotoData
            };

            // Validate cookie information.
            if (
                !data.name ||
                !data.unit ||
                !data.category ||
                !Number.isFinite(data.quantity) ||
                data.quantity < 0 ||
                !Number.isFinite(data.price) ||
                data.price < 0 ||
                data.orders < 0
            ) {
                alert("Please enter valid cookie information.");
                return;
            }

            const staffItems = getStaffItems();

            if (!staffItems) {
                alert(
                    "The cookie inventory list is not initialized. " +
                    "Please check your main inventory JavaScript."
                );
                return;
            }

            // Update an existing cookie.
            if (editStaffId !== null) {
                const existingStaff = staffItems.find(function (entry) {
                    return String(entry.id) === String(editStaffId);
                });

                if (!existingStaff) {
                    alert("The cookie could not be found.");
                    return;
                }

                Object.assign(existingStaff, data);

            } else {
                // Add a new cookie.
                staffItems.push({
                    id: Date.now(),
                    ...data
                });
            }

            // Close the modal.
            modal.hide();

            // Refresh the inventory display.
            if (typeof renderStaff === "function") {
                renderStaff();
            } else if (typeof render === "function") {
                render();
            }

            // Reset the form after saving.
            form.reset();
            clearStaffPhoto();
            editStaffId = null;
        });

    })();
</script>