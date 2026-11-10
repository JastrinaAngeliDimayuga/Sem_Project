<?php
// admin/add_content/add_ingredients.php
?>

<link rel="stylesheet" href="admin/assets/add_ingredients.css">

<!-- Add/Edit Ingredient Modal -->
<div class="modal fade" id="itemModal" tabindex="-1"
    aria-labelledby="formTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Ingredients</span>
                    <h5 class="modal-title" id="formTitle">
                        Add Ingredients
                    </h5>
                </div>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body">
                <form id="itemForm">
                    <div class="ingredient-panel">

                        <div class="ingredient-layout">

                            <!-- Photo Upload -->
                            <div class="photo-column">

                                <span class="form-label">Photo</span>

                                <div class="photo-preview" id="photoPreview">
                                    <i class="bi bi-image"
                                        style="font-size: 30px;"></i>
                                </div>

                                <input type="file"
                                    id="ingredientPhoto"
                                    class="photo-input"
                                    accept="image/*">

                                <button type="button" class="upload-photo"
                                    onclick="document.getElementById('ingredientPhoto').click()">
                                    <i class="bi bi-cloud-upload"></i>
                                    Upload Photo
                                </button>

                            </div>

                            <!-- Ingredient Information -->
                            <div>
                                <div class="ingredient-fields">

                                    <!-- Name -->
                                    <div class="field-group full-width">
                                        <label for="name" class="form-label">
                                            Name
                                        </label>

                                        <input type="text"
                                            id="name"
                                            name="name"
                                            class="form-control"
                                            placeholder="Ingredient name"
                                            required>
                                    </div>

                                    <!-- Unit -->
                                    <div class="field-group">
                                        <label for="unit" class="form-label">
                                            Unit
                                        </label>

                                        <input type="text"
                                            id="unit"
                                            name="unit"
                                            class="form-control"
                                            placeholder="e.g. kg, packs"
                                            required>
                                    </div>

                                    <!-- Price (Philippine Peso) -->
                                    <div class="field-group">
                                        <label for="price" class="form-label">
                                            Price (₱)
                                        </label>

                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>

                                            <input type="number"
                                                id="price"
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
                                        <label for="ingredientCategory"
                                            class="form-label">
                                            Category
                                        </label>

                                        <input type="text"
                                            id="ingredientCategory"
                                            name="ingredientCategory"
                                            class="form-control"
                                            placeholder="e.g. Baking"
                                            required>
                                    </div>

                                    <!-- Expiration -->
                                    <div class="field-group">
                                        <label for="expiration"
                                            class="form-label">
                                            Expiration
                                        </label>

                                        <input type="date"
                                            id="expiration"
                                            name="expiration"
                                            class="form-control">
                                    </div>

                                    <!-- Texture -->
                                    <div class="field-group">
                                        <label for="texture"
                                            class="form-label">
                                            Texture
                                        </label>

                                        <input type="text"
                                            id="texture"
                                            name="texture"
                                            class="form-control"
                                            placeholder="e.g. Powder">
                                    </div>

                                    <!-- Date Added (automatic, read-only) -->
                                    <div class="field-group">
                                        <label for="ingredientDateAdded"
                                            class="form-label">
                                            Date Added
                                            <span class="auto-badge">Auto</span>
                                        </label>

                                        <div class="date-input">
                                            <i class="bi bi-calendar3"></i>

                                            <input type="text"
                                                id="ingredientDateAdded"
                                                class="form-control"
                                                readonly
                                                tabindex="-1">
                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Save Ingredient Button Only -->
                        <div class="ingredient-actions">
                            <button type="submit"
                                class="save-btn"
                                id="saveIngredientBtn">
                                Add Product
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
    const modalElement = document.getElementById("itemModal");
    const form = document.getElementById("itemForm");
    const photoInput = document.getElementById("ingredientPhoto");
    const photoPreview = document.getElementById("photoPreview");
    const saveButton = document.getElementById("saveIngredientBtn");
    const dateField = document.getElementById("ingredientDateAdded");

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
            "Bootstrap JavaScript is required for the ingredient modal."
        );
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    let editId = null;
    let photoData = "";

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

    // Get the existing inventory array.
    function getInventoryItems() {
        return typeof items !== "undefined" && Array.isArray(items)
            ? items
            : null;
    }

    // Display the selected image or default image icon.
    function displayPhoto(src) {
        photoPreview.replaceChildren();

        if (src) {
            const img = document.createElement("img");
            img.src = src;
            img.alt = "Ingredient photo";
            photoPreview.appendChild(img);
        } else {
            const icon = document.createElement("i");
            icon.className = "bi bi-image";
            icon.style.fontSize = "30px";
            photoPreview.appendChild(icon);
        }
    }

    // Clear the photo.
    function clearPhoto() {
        photoData = "";
        photoInput.value = "";
        displayPhoto("");
    }

    window.clearIngredientPhoto = function () {
        clearPhoto();
    };

    // Reset the form.
    window.resetIngredientForm = function () {
        editId = null;
        form.reset();
        clearPhoto();
        showDateAdded(todayISO());

        document.getElementById("formTitle").textContent =
            "Add Ingredients";

        saveButton.textContent = "Add Product";
    };

    // Clear all fields without closing the modal.
    window.clearIngredientFields = function () {
        form.querySelectorAll('input:not([type="file"]):not([readonly])')
            .forEach(function (input) {
                input.value = "";
            });

        clearPhoto();
    };

    // Preview the uploaded photo.
    photoInput.addEventListener("change", function () {
        const file = this.files[0];

        if (!file) {
            return;
        }

        if (!file.type.startsWith("image/")) {
            alert("Please select a valid image.");
            clearPhoto();
            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {
            photoData = event.target.result;
            displayPhoto(photoData);
        };

        reader.onerror = function () {
            alert("Unable to read the selected image.");
            clearPhoto();
        };

        reader.readAsDataURL(file);
    });

    // Open the modal for adding or editing an ingredient.
    window.openForm = function (id = null) {
        editId = id;
        form.reset();
        clearPhoto();

        const inventoryItems = getInventoryItems();

        const item = id === null || !inventoryItems
            ? null
            : inventoryItems.find(function (entry) {
                return String(entry.id) === String(id);
            });

        if (id !== null && !item) {
            alert("The selected ingredient could not be found.");
            return;
        }

        document.getElementById("formTitle").textContent =
            item ? "Edit Ingredients" : "Add Ingredients";

        saveButton.textContent =
            item ? "Update Product" : "Add Product";

        // New items show today's date; existing items keep their own.
        showDateAdded(item ? item.dateAdded : todayISO());

        if (item) {
            document.getElementById("name").value =
                item.name ?? "";

            document.getElementById("unit").value =
                item.unit ?? "";

            document.getElementById("price").value =
                item.price ?? "";

            document.getElementById("ingredientCategory").value =
                item.category ?? "";

            document.getElementById("expiration").value =
                item.expiration ?? "";

            document.getElementById("texture").value =
                item.texture ?? item.spec ?? "";

            photoData = item.image ?? "";

            if (photoData) {
                displayPhoto(photoData);
            }
        }

        modal.show();
    };

    // Validate and save to the existing in-memory inventory.
    form.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return;
        }

        const data = {
            type: "Ingredients",
            name: document.getElementById("name").value.trim(),
            unit: document.getElementById("unit").value.trim(),
            price: Number(
                document.getElementById("price").value
            ),
            category: document.getElementById("ingredientCategory")
                .value.trim(),
            expiration: document.getElementById("expiration").value,
            texture: document.getElementById("texture").value.trim(),
            image: photoData
        };

        // Validate ingredient information.
        if (
            !data.name ||
            !data.unit ||
            !data.category ||
            !Number.isFinite(data.price) ||
            data.price < 0
        ) {
            alert("Please enter valid ingredient information.");
            return;
        }

        const inventoryItems = getInventoryItems();

        if (!inventoryItems) {
            alert(
                "The inventory list is not initialized. " +
                "Please check your main inventory JavaScript."
            );
            return;
        }

        // Update an existing ingredient (its dateAdded is never changed).
        if (editId !== null) {
            const existingItem = inventoryItems.find(function (entry) {
                return String(entry.id) === String(editId);
            });

            if (!existingItem) {
                alert("The ingredient could not be found.");
                return;
            }

            Object.assign(existingItem, data);

        } else {
            // Add a new ingredient with the automatic date added.
            inventoryItems.push({
                id: Date.now(),
                dateAdded: todayISO(),
                ...data
            });
        }

        // Close the modal.
        modal.hide();

        // Refresh the inventory display.
        if (typeof render === "function") {
            render();
        }

        // Reset the form after saving.
        form.reset();
        clearPhoto();
        showDateAdded(todayISO());
        editId = null;
    });

    // Show today's date as soon as the page loads.
    showDateAdded(todayISO());

})();
</script>