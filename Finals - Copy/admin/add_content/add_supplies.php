<?php
// admin/add_content/add_supplies.php
?>

<link rel="stylesheet" href="admin/assets/add_supplies.css">

<!-- Add/Edit Supplies Modal -->
<div class="modal fade" id="supplyModal" tabindex="-1"
    aria-labelledby="supplyFormTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Supplies</span>
                    <h5 class="modal-title" id="supplyFormTitle">
                        Add Supplies
                    </h5>
                </div>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body">
                <form id="supplyForm">
                    <div class="ingredient-panel">

                        <div class="ingredient-layout">

                            <!-- Photo Upload -->
                            <div class="photo-column">

                                <span class="form-label">Photo</span>

                                <div class="photo-preview"
                                    id="supplyPhotoPreview">
                                    <i class="bi bi-image"
                                        style="font-size: 30px;"></i>
                                </div>

                                <input type="file"
                                    id="supplyPhoto"
                                    class="photo-input"
                                    accept="image/jpeg,image/png,image/webp">

                                <button type="button" class="upload-photo"
                                    onclick="document.getElementById('supplyPhoto').click()">
                                    <i class="bi bi-cloud-upload"></i>
                                    Upload Photo
                                </button>

                            </div>

                            <!-- Supplies Information -->
                            <div>
                                <div class="ingredient-fields">

                                    <!-- Name -->
                                    <div class="field-group full-width">
                                        <label for="supplyName"
                                            class="form-label">
                                            Name
                                        </label>

                                        <input type="text"
                                            id="supplyName"
                                            name="name"
                                            class="form-control"
                                            placeholder="Supply name"
                                            maxlength="120"
                                            required>
                                    </div>

                                    <!-- Unit -->
                                    <div class="field-group">
                                        <label for="supplyUnit"
                                            class="form-label">
                                            Unit
                                        </label>

                                        <input type="text"
                                            id="supplyUnit"
                                            name="unit"
                                            class="form-control"
                                            placeholder="e.g. pcs, boxes"
                                            maxlength="40"
                                            required>
                                    </div>

                                    <!-- Date Added (automatic, read-only) -->
                                    <div class="field-group">
                                        <label for="supplyDateAdded"
                                            class="form-label">
                                            Date Added
                                            <span class="auto-badge">Auto</span>
                                        </label>

                                        <div class="date-input">
                                            <i class="bi bi-calendar3"></i>

                                            <input type="text"
                                                id="supplyDateAdded"
                                                class="form-control"
                                                readonly
                                                tabindex="-1">
                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Save Supplies Button Only -->
                        <div class="ingredient-actions">
                            <button type="submit"
                                class="save-btn"
                                id="saveSupplyBtn">
                                Add Supplies
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
    const modalElement = document.getElementById("supplyModal");
    const form = document.getElementById("supplyForm");
    const photoInput = document.getElementById("supplyPhoto");
    const photoPreview = document.getElementById("supplyPhotoPreview");
    const saveButton = document.getElementById("saveSupplyBtn");
    const dateField = document.getElementById("supplyDateAdded");

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
            "Bootstrap JavaScript is required for the supplies modal."
        );
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    let editSupplyId = null;
    let supplyPhotoData = "";

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
    function getSupplyItems() {
        return typeof items !== "undefined" && Array.isArray(items)
            ? items
            : null;
    }

    // Display the selected image or default image icon.
    function displaySupplyPhoto(src) {
        photoPreview.replaceChildren();

        if (src) {
            const img = document.createElement("img");
            img.src = src;
            img.alt = "Supply photo";
            photoPreview.appendChild(img);
        } else {
            const icon = document.createElement("i");
            icon.className = "bi bi-image";
            icon.style.fontSize = "30px";
            photoPreview.appendChild(icon);
        }
    }

    // Clear the photo.
    function clearSupplyPhoto() {
        supplyPhotoData = "";
        photoInput.value = "";
        displaySupplyPhoto("");
    }

    // Reset the form.
    window.resetSupplyForm = function () {
        editSupplyId = null;
        form.reset();
        clearSupplyPhoto();
        showDateAdded(todayISO());

        document.getElementById("supplyFormTitle").textContent =
            "Add Supplies";

        saveButton.textContent = "Add Supplies";
    };

    // Clear all fields without closing the modal.
    window.clearSupplyFields = function () {
        form.querySelectorAll('input:not([type="file"]):not([readonly])')
            .forEach(function (input) {
                input.value = "";
            });

        clearSupplyPhoto();
    };

    // Preview the uploaded photo.
    photoInput.addEventListener("change", function () {
        const file = this.files[0];

        if (!file) {
            return;
        }

        if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
            alert("Please select a JPG, PNG or WEBP image.");
            clearSupplyPhoto();
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert("The photo is too large. Please choose one under 5 MB.");
            clearSupplyPhoto();
            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {
            supplyPhotoData = event.target.result;
            displaySupplyPhoto(supplyPhotoData);
        };

        reader.onerror = function () {
            alert("Unable to read the selected image.");
            clearSupplyPhoto();
        };

        reader.readAsDataURL(file);
    });

    // Open the modal for adding or editing a supply.
    window.openSuppliesForm = function (id = null) {
        editSupplyId = id;
        form.reset();
        clearSupplyPhoto();

        const supplyItems = getSupplyItems();

        const supply = id === null || !supplyItems
            ? null
            : supplyItems.find(function (entry) {
                return String(entry.id) === String(id);
            });

        if (id !== null && !supply) {
            alert("The selected supply could not be found.");
            return;
        }

        document.getElementById("supplyFormTitle").textContent =
            supply ? "Edit Supplies" : "Add Supplies";

        saveButton.textContent =
            supply ? "Update Supplies" : "Add Supplies";

        // New supplies show today's date; existing ones keep their own.
        showDateAdded(supply ? supply.dateAdded : todayISO());

        if (supply) {
            document.getElementById("supplyName").value =
                supply.name ?? "";

            document.getElementById("supplyUnit").value =
                supply.unit ?? "";

            supplyPhotoData = supply.image ?? "";

            if (supplyPhotoData) {
                displaySupplyPhoto(supplyPhotoData);
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
            type: "Supplies",
            name: document.getElementById("supplyName").value.trim(),
            unit: document.getElementById("supplyUnit").value.trim(),
            image: supplyPhotoData
        };

        // Validate supply information.
        if (
            !data.name ||
            !data.unit
        ) {
            alert("Please enter valid supply information.");
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

        // editSupplyId is null for a new supply (the server adds Date Added itself).
        saveInventoryItem(data, editSupplyId)
            .then(function () {
                modal.hide();

                // Show the Supplies panel so the saved supply is visible.
                if (typeof goToCategory === "function") {
                    goToCategory("Supplies");
                }

                form.reset();
                clearSupplyPhoto();
                showDateAdded(todayISO());
                editSupplyId = null;
            })
            .catch(function (error) {
                alert(error.message || "The supply could not be saved.");
            })
            .finally(function () {
                saveButton.disabled = false;
            });
    });

    // Show today's date as soon as the page loads.
    showDateAdded(todayISO());

})();
</script>