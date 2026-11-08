<?php
// admin/add_content/add_supplies.php
?>

<head>
    <link rel="stylesheet" href="admin/assets/add_supplies.css">
</head>


<!-- Add/Edit Supplies Modal -->
<div class="modal fade" id="supplyModal" tabindex="-1"
    aria-labelledby="supplyFormTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <h5 class="modal-title" id="supplyFormTitle">
                    Add Supplies
                </h5>

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

                                <div class="photo-preview"
                                    id="supplyPhotoPreview">
                                    <i class="bi bi-image"
                                        style="font-size: 30px;"></i>
                                </div>

                                <input type="file"
                                    id="supplyPhoto"
                                    class="photo-input"
                                    accept="image/*">

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
                                            required>
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
            "Bootstrap JavaScript is required for the supplies modal."
        );
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    let editSupplyId = null;
    let supplyPhotoData = "";

    // Get the shared inventory array.
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

        document.getElementById("supplyFormTitle").textContent =
            "Add Supplies";

        saveButton.textContent = "Add Supplies";
    };

    // Clear all fields without closing the modal.
    window.clearSupplyFields = function () {
        form.querySelectorAll('input:not([type="file"])')
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

        if (!file.type.startsWith("image/")) {
            alert("Please select a valid image.");
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

    // Validate and save to the shared in-memory inventory.
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

        const supplyItems = getSupplyItems();

        if (!supplyItems) {
            alert(
                "The inventory list is not initialized. " +
                "Please check your main inventory JavaScript."
            );
            return;
        }

        // Update an existing supply.
        if (editSupplyId !== null) {
            const existingSupply = supplyItems.find(function (entry) {
                return String(entry.id) === String(editSupplyId);
            });

            if (!existingSupply) {
                alert("The supply could not be found.");
                return;
            }

            Object.assign(existingSupply, data);

        } else {
            // Add a new supply.
            supplyItems.push({
                id: Date.now(),
                ...data
            });
        }

        // Close the modal.
        modal.hide();

        // Show the Supplies panel and refresh the inventory display.
        if (typeof goToCategory === "function") {
            goToCategory("Supplies");
        } else if (typeof render === "function") {
            render();
        }

        // Reset the form after saving.
        form.reset();
        clearSupplyPhoto();
        editSupplyId = null;
    });

})();
</script>