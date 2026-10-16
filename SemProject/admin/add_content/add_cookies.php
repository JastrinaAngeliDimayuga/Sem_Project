<?php
// admin/add_content/add_cookies.php
?>

<head>
    <link rel="stylesheet" href="admin/assets/add_cookies.css">
</head>

<!-- Add/Edit Cookie Modal -->
<div class="modal fade" id="cookieModal" tabindex="-1"
    aria-labelledby="cookieFormTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <h5 class="modal-title" id="cookieFormTitle">
                    Add Cookies
                </h5>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body">
                <form id="cookieForm">
                    <div class="ingredient-panel">

                        <div class="ingredient-layout">

                            <!-- Photo Upload -->
                            <div class="photo-column">

                                <div class="photo-preview"
                                    id="cookiePhotoPreview">
                                    <i class="bi bi-image"
                                        style="font-size: 30px;"></i>
                                </div>

                                <input type="file" id="cookiePhoto"
                                    class="photo-input" accept="image/*">

                                <button type="button" class="upload-photo"
                                    onclick="document.getElementById('cookiePhoto').click()">
                                    <i class="bi bi-cloud-upload"></i>
                                    Upload Photo
                                </button>

                            </div>

                            <!-- Cookie Information -->
                            <div>
                                <div class="ingredient-fields">

                                    <!-- Name -->
                                    <div class="field-group full-width">
                                        <label for="cookieName"
                                            class="form-label">
                                            Name
                                        </label>

                                        <input type="text"
                                            id="cookieName"
                                            name="name"
                                            class="form-control"
                                            placeholder="Cookie name"
                                            required>
                                    </div>

                                    <!-- Unit -->
                                    <div class="field-group">
                                        <label for="cookieUnit"
                                            class="form-label">
                                            Unit
                                        </label>

                                        <input type="text"
                                            id="cookieUnit"
                                            name="unit"
                                            class="form-control"
                                            placeholder="e.g. pcs, packs, boxes"
                                            required>
                                    </div>

                                    <!-- Quantity -->
                                    <div class="field-group">
                                        <label for="cookieQuantity"
                                            class="form-label">
                                            Quantity
                                        </label>

                                        <input type="number"
                                            id="cookieQuantity"
                                            name="quantity"
                                            min="0"
                                            step="any"
                                            class="form-control"
                                            placeholder="0"
                                            required>
                                    </div>

                                    <!-- Price -->
                                    <div class="field-group">
                                        <label for="cookiePrice"
                                            class="form-label">
                                            Price
                                        </label>

                                        <input type="number"
                                            id="cookiePrice"
                                            name="price"
                                            min="0"
                                            step="0.01"
                                            class="form-control"
                                            placeholder="0.00"
                                            required>
                                    </div>

                                    <!-- Category -->
                                    <div class="field-group">
                                        <label for="cookieCategory"
                                            class="form-label">
                                            Category
                                        </label>

                                        <input type="text"
                                            id="cookieCategory"
                                            name="category"
                                            class="form-control"
                                            placeholder="e.g. Chocolate Cookies"
                                            required>
                                    </div>

                                    <!-- Flavour -->
                                    <div class="field-group">
                                        <label for="cookieFlavour"
                                            class="form-label">
                                            Flavour
                                        </label>

                                        <input type="text"
                                            id="cookieFlavour"
                                            name="flavour"
                                            class="form-control"
                                            placeholder="e.g. Chocolate, Vanilla">
                                    </div>

                                    <!-- Texture -->
                                    <div class="field-group">
                                        <label for="cookieTexture"
                                            class="form-label">
                                            Texture
                                        </label>

                                        <input type="text"
                                            id="cookieTexture"
                                            name="texture"
                                            class="form-control"
                                            placeholder="e.g. Chewy, Crunchy">
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Save Cookie Button Only -->
                        <div class="ingredient-actions">
                            <button type="submit"
                                class="save-btn"
                                id="saveCookieBtn">
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
    const modalElement = document.getElementById("cookieModal");
    const form = document.getElementById("cookieForm");
    const photoInput = document.getElementById("cookiePhoto");
    const photoPreview = document.getElementById("cookiePhotoPreview");
    const saveButton = document.getElementById("saveCookieBtn");

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

    let editCookieId = null;
    let cookiePhotoData = "";

    // Get the existing cookie inventory array.
    function getCookieItems() {
        return typeof cookies !== "undefined" && Array.isArray(cookies)
            ? cookies
            : null;
    }

    // Display the selected cookie photo.
    function displayCookiePhoto(src) {
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
    function clearCookiePhoto() {
        cookiePhotoData = "";
        photoInput.value = "";
        displayCookiePhoto("");
    }

    // Reset the cookie form.
    window.resetCookieForm = function () {
        editCookieId = null;
        form.reset();
        clearCookiePhoto();

        document.getElementById("cookieFormTitle").textContent =
            "Add Cookies";

        saveButton.textContent = "Add Cookie";
    };

    // Clear all cookie fields without closing the modal.
    window.clearCookieFields = function () {
        form.querySelectorAll('input:not([type="file"])')
            .forEach(function (input) {
                input.value = "";
            });

        clearCookiePhoto();
    };

    // Preview the uploaded cookie photo.
    photoInput.addEventListener("change", function () {
        const file = this.files[0];

        if (!file) {
            return;
        }

        if (!file.type.startsWith("image/")) {
            alert("Please select a valid image.");
            clearCookiePhoto();
            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {
            cookiePhotoData = event.target.result;
            displayCookiePhoto(cookiePhotoData);
        };

        reader.onerror = function () {
            alert("Unable to read the selected image.");
            clearCookiePhoto();
        };

        reader.readAsDataURL(file);
    });

    // Open the modal for adding or editing a cookie.
    window.openCookieForm = function (id = null) {
        editCookieId = id;
        form.reset();
        clearCookiePhoto();

        const cookieItems = getCookieItems();

        const cookie = id === null || !cookieItems
            ? null
            : cookieItems.find(function (entry) {
                return String(entry.id) === String(id);
            });

        if (id !== null && !cookie) {
            alert("The selected cookie could not be found.");
            return;
        }

        document.getElementById("cookieFormTitle").textContent =
            cookie ? "Edit Cookies" : "Add Cookies";

        saveButton.textContent =
            cookie ? "Update Cookie" : "Add Cookie";

        if (cookie) {
            document.getElementById("cookieName").value =
                cookie.name ?? "";

            document.getElementById("cookieUnit").value =
                cookie.unit ?? "";

            document.getElementById("cookieQuantity").value =
                cookie.quantity ?? "";

            document.getElementById("cookiePrice").value =
                cookie.price ?? "";

            document.getElementById("cookieCategory").value =
                cookie.category ?? "";

            document.getElementById("cookieFlavour").value =
                cookie.flavour ?? "";

            document.getElementById("cookieTexture").value =
                cookie.texture ?? cookie.spec ?? "";

            cookiePhotoData = cookie.image ?? "";

            if (cookiePhotoData) {
                displayCookiePhoto(cookiePhotoData);
            }
        }

        modal.show();
    };

    // Validate and save to the in-memory cookie inventory.
    form.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return;
        }

        const data = {
            name: document.getElementById("cookieName").value.trim(),
            unit: document.getElementById("cookieUnit").value.trim(),
            quantity: Number(
                document.getElementById("cookieQuantity").value
            ),
            price: Number(
                document.getElementById("cookiePrice").value
            ),
            category: document.getElementById("cookieCategory").value.trim(),
            flavour: document.getElementById("cookieFlavour").value.trim(),
            texture: document.getElementById("cookieTexture").value.trim(),
            image: cookiePhotoData
        };

        // Validate cookie information.
        if (
            !data.name ||
            !data.unit ||
            !data.category ||
            !Number.isFinite(data.quantity) ||
            data.quantity < 0 ||
            !Number.isFinite(data.price) ||
            data.price < 0
        ) {
            alert("Please enter valid cookie information.");
            return;
        }

        const cookieItems = getCookieItems();

        if (!cookieItems) {
            alert(
                "The cookie inventory list is not initialized. " +
                "Please check your main inventory JavaScript."
            );
            return;
        }

        // Update an existing cookie.
        if (editCookieId !== null) {
            const existingCookie = cookieItems.find(function (entry) {
                return String(entry.id) === String(editCookieId);
            });

            if (!existingCookie) {
                alert("The cookie could not be found.");
                return;
            }

            Object.assign(existingCookie, data);

        } else {
            // Add a new cookie.
            cookieItems.push({
                id: Date.now(),
                ...data
            });
        }

        // Close the modal.
        modal.hide();

        // Refresh the inventory display.
        if (typeof renderCookies === "function") {
            renderCookies();
        } else if (typeof render === "function") {
            render();
        }

        // Reset the form after saving.
        form.reset();
        clearCookiePhoto();
        editCookieId = null;
    });

})();
</script>