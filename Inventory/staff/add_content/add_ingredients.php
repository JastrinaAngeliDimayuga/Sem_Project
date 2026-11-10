<?php
// staff/add_content/add_ingredients.php
?>

<head>
    <link rel="stylesheet" href="staff/assets/add_ingredients.css?v=<?php echo @filemtime('staff/assets/add_ingredients.css'); ?>">
</head>


<!-- Add/Edit Ingredient Modal -->
<div class="modal fade" id="ingredientModal" tabindex="-1"
    aria-labelledby="ingredientTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <div class="modal-heading">
                    <p class="modal-eyebrow">Ingredients</p>
                    <h5 class="modal-title" id="ingredientTitle">
                        Add Ingredients
                    </h5>
                </div>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body">
                <form id="ingredientForm">
                    <div class="ing-panel">

                        <div class="ing-layout">

                            <!-- Photo Upload -->
                            <div class="ing-photo-col">

                                <span class="photo-label">Photo</span>

                                <div class="ing-preview" id="ingredientPreview">
                                    <i class="bi bi-image"
                                        style="font-size: 30px;"></i>
                                </div>

                                <input type="file"
                                    id="ingredientPhoto"
                                    class="ing-file"
                                    accept="image/*">

                                <button type="button" class="ing-upload"
                                    onclick="document.getElementById('ingredientPhoto').click()">
                                    <i class="bi bi-cloud-upload"></i>
                                    Upload Photo
                                </button>

                            </div>

                            <!-- Ingredient Information -->
                            <div>
                                <div class="ing-fields">

                                    <!-- Name -->
                                    <div class="ing-field ing-full">
                                        <label for="ingredientName" class="form-label">
                                            Name
                                        </label>

                                        <input type="text"
                                            id="ingredientName"
                                            name="name"
                                            class="form-control"
                                            placeholder="Ingredient name"
                                            required>
                                    </div>

                                    <!-- Unit -->
                                    <div class="ing-field">
                                        <label for="ingredientUnit" class="form-label">
                                            Unit
                                        </label>

                                        <input type="text"
                                            id="ingredientUnit"
                                            name="unit"
                                            class="form-control"
                                            placeholder="e.g. kg, packs"
                                            required>
                                    </div>

                                    <!-- Price (Philippine Peso) -->
                                    <div class="ing-field">
                                        <label for="ingredientPrice" class="form-label">
                                            Price (₱)
                                        </label>

                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>

                                            <input type="number"
                                                id="ingredientPrice"
                                                name="price"
                                                min="0"
                                                step="0.01"
                                                class="form-control"
                                                placeholder="0.00"
                                                required>
                                        </div>
                                    </div>

                                    <!-- Category -->
                                    <div class="ing-field">
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
                                    <div class="ing-field">
                                        <label for="ingredientExpiration"
                                            class="form-label">
                                            Expiration
                                        </label>

                                        <input type="date"
                                            id="ingredientExpiration"
                                            name="expiration"
                                            class="form-control">
                                    </div>

                                    <!-- Texture -->
                                    <div class="ing-field">
                                        <label for="ingredientTexture"
                                            class="form-label">
                                            Texture
                                        </label>

                                        <input type="text"
                                            id="ingredientTexture"
                                            name="texture"
                                            class="form-control"
                                            placeholder="e.g. Powder">
                                    </div>

                                    <!-- Date Added (automatic, read-only) -->
                                    <div class="ing-field">
                                        <div class="date-label-row">
                                            <span class="form-label">Date Added</span>
                                            <span class="date-badge">Auto</span>
                                        </div>

                                        <div class="date-display" id="ingredientDateAdded">
                                            <i class="bi bi-calendar3"></i>
                                            <span class="date-text"></span>
                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Save Ingredient Button Only -->
                        <div class="ing-actions">
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
    const modalElement = document.getElementById("ingredientModal");
    const form = document.getElementById("ingredientForm");
    const photoInput = document.getElementById("ingredientPhoto");
    const photoPreview = document.getElementById("ingredientPreview");
    const saveButton = document.getElementById("saveIngredientBtn");
    const dateText = document.querySelector("#ingredientDateAdded .date-text");

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

    // Today's date as YYYY-MM-DD (local time).
    function todayISO() {
        const d = new Date();
        const pad = function (n) { return String(n).padStart(2, "0"); };
        return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
    }

    // YYYY-MM-DD -> "November 9, 2026".
    function formatDate(iso) {
        if (!iso) {
            return "Not recorded";
        }

        const parts = String(iso).split("-").map(Number);

        if (parts.length !== 3 || parts.some(isNaN)) {
            return "Not recorded";
        }

        return new Date(parts[0], parts[1] - 1, parts[2])
            .toLocaleDateString("en-US", {
                year: "numeric",
                month: "long",
                day: "numeric"
            });
    }

    // Show the automatic Date Added value.
    function showDateAdded(iso) {
        if (dateText) {
            dateText.textContent = formatDate(iso);
        }
    }

    showDateAdded(todayISO());

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

        document.getElementById("ingredientTitle").textContent =
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
    // Called by add_button.php: onclick="openForm()"
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

        document.getElementById("ingredientTitle").textContent =
            item ? "Edit Ingredients" : "Add Ingredients";

        saveButton.textContent =
            item ? "Update Product" : "Add Product";

        // New items get today's date; existing items keep their own.
        showDateAdded(item ? item.dateAdded : todayISO());

        if (item) {
            document.getElementById("ingredientName").value =
                item.name ?? "";

            document.getElementById("ingredientUnit").value =
                item.unit ?? "";

            document.getElementById("ingredientPrice").value =
                item.price ?? "";

            document.getElementById("ingredientCategory").value =
                item.category ?? "";

            document.getElementById("ingredientExpiration").value =
                item.expiration ?? "";

            document.getElementById("ingredientTexture").value =
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
            name: document.getElementById("ingredientName").value.trim(),
            unit: document.getElementById("ingredientUnit").value.trim(),
            price: Number(
                document.getElementById("ingredientPrice").value
            ),
            category: document.getElementById("ingredientCategory")
                .value.trim(),
            expiration: document.getElementById("ingredientExpiration").value,
            texture: document.getElementById("ingredientTexture").value.trim(),
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

        // Update an existing ingredient (Date Added is never changed).
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
            // Add a new ingredient with today's date.
            inventoryItems.push({
                id: Date.now(),
                ...data,
                dateAdded: todayISO()
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
        showDateAdded(todayISO());
    });

})();
</script>