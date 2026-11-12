<?php
// admin/add_content/add_cookies.php
?>

<link rel="stylesheet" href="admin/assets/add_cookies.css">

<!-- Add/Edit Cookie Modal -->
<div class="modal fade" id="cookieModal" tabindex="-1"
    aria-labelledby="cookieFormTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Cookies</span>
                    <h5 class="modal-title" id="cookieFormTitle">
                        Add Cookies
                    </h5>
                </div>

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

                                <span class="form-label">Photo</span>

                                <div class="photo-preview"
                                    id="cookiePhotoPreview">
                                    <i class="bi bi-image"
                                        style="font-size: 30px;"></i>
                                </div>

                                <input type="file" id="cookiePhoto"
                                    class="photo-input"
                                    accept="image/jpeg,image/png,image/webp">

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
                                            maxlength="120"
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
                                            maxlength="40"
                                            required>
                                    </div>

                                    <!-- Price (Philippine Peso) -->
                                    <div class="field-group">
                                        <label for="cookiePrice"
                                            class="form-label">
                                            Price (₱)
                                        </label>

                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>

                                            <input type="number"
                                                id="cookiePrice"
                                                name="price"
                                                min="0"
                                                step="0.01"
                                                class="form-control"
                                                placeholder="0.00"
                                                required>
                                        </div>
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
                                            maxlength="80"
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
                                            placeholder="e.g. Chocolate, Vanilla"
                                            maxlength="80">
                                    </div>

                                    <!-- Texture -->
                                    <div class="field-group full-width">
                                        <label for="cookieTexture"
                                            class="form-label">
                                            Texture
                                        </label>

                                        <input type="text"
                                            id="cookieTexture"
                                            name="texture"
                                            class="form-control"
                                            placeholder="e.g. Chewy, Crunchy"
                                            maxlength="80">
                                    </div>

                                    <!-- Date Added (automatic, read-only) -->
                                    <div class="field-group full-width">
                                        <label for="cookieDateAdded"
                                            class="form-label">
                                            Date Added
                                            <span class="auto-badge">Auto</span>
                                        </label>

                                        <div class="date-input">
                                            <i class="bi bi-calendar3"></i>

                                            <input type="text"
                                                id="cookieDateAdded"
                                                class="form-control"
                                                readonly
                                                tabindex="-1">
                                        </div>
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
    const dateField = document.getElementById("cookieDateAdded");

    if (
        !modalElement ||
        !form ||
        !photoInput ||
        !photoPreview ||
        !saveButton ||
        !dateField
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

    // Today's date as YYYY-MM-DD (uses the computer's local time).
    function todayISO() {
        const now = new Date();
        const month = String(now.getMonth() + 1).padStart(2, "0");
        const day = String(now.getDate()).padStart(2, "0");

        return now.getFullYear() + "-" + month + "-" + day;
    }

    // Turn YYYY-MM-DD into a readable date, e.g. "November 9, 2026".
    function formatDateAdded(iso) {
        if (!iso) {
            return "Not recorded";
        }

        const parts = String(iso).slice(0, 10).split("-").map(Number);

        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return String(iso);
        }

        return new Date(parts[0], parts[1] - 1, parts[2])
            .toLocaleDateString("en-PH", {
                year: "numeric",
                month: "long",
                day: "numeric"
            });
    }

    // Show the date in the read-only field.
    function showDateAdded(iso) {
        dateField.value = formatDateAdded(iso);
    }

    // Get the inventory list loaded from the database.
    function getCookieItems() {
        return typeof items !== "undefined" && Array.isArray(items)
            ? items
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
        showDateAdded(todayISO());

        document.getElementById("cookieFormTitle").textContent =
            "Add Cookies";

        saveButton.textContent = "Add Cookie";
    };

    // Clear all cookie fields without closing the modal.
    window.clearCookieFields = function () {
        form.querySelectorAll('input:not([type="file"]):not([readonly])')
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

        if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
            alert("Please select a JPG, PNG or WEBP image.");
            clearCookiePhoto();
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert("The photo is too large. Please choose one under 5 MB.");
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

        // New cookies show today's date; existing ones keep their own.
        showDateAdded(cookie ? cookie.dateAdded : todayISO());

        if (cookie) {
            document.getElementById("cookieName").value =
                cookie.name ?? "";

            document.getElementById("cookieUnit").value =
                cookie.unit ?? "";

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

    // Validate, then save to the database through the inventory page.
    form.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return;
        }

        const data = {
            type: "Products",
            name: document.getElementById("cookieName").value.trim(),
            unit: document.getElementById("cookieUnit").value.trim(),
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
            !Number.isFinite(data.price) ||
            data.price < 0
        ) {
            alert("Please enter valid cookie information.");
            return;
        }

        if (typeof saveInventoryItem !== "function") {
            alert(
                "The inventory page is not ready yet. " +
                "Please refresh the page and try again."
            );
            return;
        }

        saveButton.disabled = true;

        // editCookieId is null for a new cookie (the server adds Date Added itself).
        saveInventoryItem(data, editCookieId)
            .then(function () {
                modal.hide();

                form.reset();
                clearCookiePhoto();
                showDateAdded(todayISO());
                editCookieId = null;
            })
            .catch(function (error) {
                alert(error.message || "The cookie could not be saved.");
            })
            .finally(function () {
                saveButton.disabled = false;
            });
    });

    // Show today's date as soon as the page loads.
    showDateAdded(todayISO());

})();
</script>