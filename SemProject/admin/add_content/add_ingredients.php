<?php
// admin/add_content/add_ingredients.php
?>

<head>
    <link rel="stylesheet" href="admin/assets/add_ingredients.css">
</head>


<!-- Add/Edit Ingredient Modal -->
<div class="modal fade" id="itemModal" tabindex="-1"
    aria-labelledby="formTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <h5 class="modal-title" id="formTitle">
                    Add Ingredients
                </h5>

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

                                    <!-- Quantity -->
                                    <div class="field-group">
                                        <label for="quantity"
                                            class="form-label">
                                            Quantity
                                        </label>

                                        <input type="number"
                                            id="quantity"
                                            name="quantity"
                                            min="0"
                                            step="any"
                                            class="form-control"
                                            placeholder="0"
                                            required>
                                    </div>

                                    <!-- Price -->
                                    <div class="field-group">
                                        <label for="price" class="form-label">
                                            Price
                                        </label>

                                        <input type="number"
                                            id="price"
                                            name="price"
                                            min="0"
                                            step="0.01"
                                            class="form-control"
                                            placeholder="0.00"
                                            required>
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
            "Bootstrap JavaScript is required for the ingredient modal."
        );
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    let editId = null;
    let photoData = "";

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

        document.getElementById("formTitle").textContent =
            "Add Ingredients";

        saveButton.textContent = "Add Product";
    };

    // Clear all fields without closing the modal.
    window.clearIngredientFields = function () {
        form.querySelectorAll('input:not([type="file"])')
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

        if (item) {
            document.getElementById("name").value =
                item.name ?? "";

            document.getElementById("unit").value =
                item.unit ?? "";

            document.getElementById("quantity").value =
                item.quantity ?? "";

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
            name: document.getElementById("name").value.trim(),
            unit: document.getElementById("unit").value.trim(),
            quantity: Number(
                document.getElementById("quantity").value
            ),
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
            !Number.isFinite(data.quantity) ||
            data.quantity < 0 ||
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

        // Update an existing ingredient.
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
            // Add a new ingredient.
            inventoryItems.push({
                id: Date.now(),
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
        editId = null;
    });

})();
</script>