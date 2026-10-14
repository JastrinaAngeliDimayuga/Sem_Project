<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Inventory Management</title>

    <link rel="stylesheet" href="admin/assets/im.css">
</head>

<body>

    <div class="app">

        <!-- Header -->
        <header class="header">
            Inventory Management
        </header>

        <!-- Inventory Section -->
        <main class="inventory-section">

            <!-- Tabs and Add Button -->
            <div class="toolbar">

                <div class="category-tabs">

                    <button
                        type="button"
                        class="tab active"
                        onclick="showCategory('Ingredients', this)"
                        aria-label="Ingredients"
                        title="Ingredients"
                    >
                        <i class="bi bi-clipboard2-data-fill"></i>
                    </button>

                    <button
                        type="button"
                        class="tab"
                        onclick="showCategory('Products', this)"
                        aria-label="Products"
                        title="Products"
                    >
                        <i class="bi bi-cookie"></i>
                    </button>

                </div>

                <button
                    type="button"
                    class="add-btn"
                    onclick="openForm()"
                >
                    <i class="bi bi-plus-lg"></i>
                    Add
                </button>

            </div>

            <!-- Inventory Table/Content -->
            <div class="panel">
                <div id="inventory"></div>
            </div>

        </main>

    </div>

    <!-- Add/Edit Modal -->
    <div
        class="modal fade"
        id="itemModal"
        tabindex="-1"
        aria-labelledby="formTitle"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="formTitle">
                        Add Item
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">

                    <form id="itemForm">

                        <label for="name" class="form-label">
                            Item Name
                        </label>

                        <input
                            id="name"
                            name="name"
                            class="form-control mb-3"
                            placeholder="Chocolate Chips"
                            required
                        >

                        <label for="quantity" class="form-label">
                            Quantity
                        </label>

                        <input
                            id="quantity"
                            name="quantity"
                            type="number"
                            min="0"
                            step="any"
                            class="form-control mb-3"
                            placeholder="12"
                            required
                        >

                        <label for="unit" class="form-label">
                            Unit
                        </label>

                        <input
                            id="unit"
                            name="unit"
                            class="form-control mb-3"
                            placeholder="Packs"
                            required
                        >

                        <label for="spec" class="form-label">
                            Specification
                        </label>

                        <input
                            id="spec"
                            name="spec"
                            class="form-control mb-4"
                            placeholder="Sugar"
                            required
                        >

                        <button type="submit" class="save-btn">
                            Save Item
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let items = [
            {
                id: 1,
                name: "Chocolate Chips",
                quantity: 12,
                unit: "Packs",
                spec: "Sugar",
                category: "Ingredients"
            }
        ];

        let category = "Ingredients";
        let editId = null;

        const modal = new bootstrap.Modal(
            document.getElementById("itemModal")
        );

        function render() {
            const container = document.getElementById("inventory");
            container.innerHTML = "";

            const filteredItems = items.filter(
                item => item.category === category
            );

            if (filteredItems.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h5>No inventory items yet</h5>
                        <p>Add an item to get started.</p>
                    </div>
                `;
                return;
            }

            filteredItems.forEach(item => {
                const card = document.createElement("div");
                card.className = "item";

                const img = document.createElement("img");

                img.src =
                    "https://images.unsplash.com/photo-1582176604856-e824b4736522?auto=format&fit=crop&w=300&q=80";

                img.alt = item.name;
                img.className = "product-image";

                const content = document.createElement("div");
                content.className = "item-content";

                const top = document.createElement("div");
                top.className = "item-top";

                const name = document.createElement("strong");
                name.className = "item-name";
                name.textContent = item.name;

                const actions = document.createElement("div");
                actions.className = "actions";

                const edit = document.createElement("button");
                edit.type = "button";
                edit.className = "edit";
                edit.innerHTML =
                    '<i class="bi bi-pencil-square"></i> Edit';

                edit.onclick = () => openForm(item.id);

                const del = document.createElement("button");
                del.type = "button";
                del.className = "delete";
                del.innerHTML =
                    '<i class="bi bi-trash3-fill"></i> Delete';

                del.onclick = () => {
                    if (confirm("Delete " + item.name + "?")) {
                        items = items.filter(
                            i => i.id !== item.id
                        );
                        render();
                    }
                };

                actions.append(edit, del);
                top.append(name, actions);

                const details = document.createElement("div");
                details.className = "details";

                [
                    ["Quantity", item.quantity],
                    ["Unit", item.unit],
                    ["Specification", item.spec]
                ].forEach(([label, value]) => {
                    const div = document.createElement("div");
                    div.className = "detail";

                    const labelElement = document.createElement("div");
                    labelElement.className = "detail-label";
                    labelElement.textContent = label;

                    const valueElement = document.createElement("div");
                    valueElement.className = "detail-value";
                    valueElement.textContent = value;

                    div.append(labelElement, valueElement);
                    details.appendChild(div);
                });

                content.append(top, details);
                card.append(img, content);
                container.appendChild(card);
            });
        }

        function showCategory(name, button) {
            category = name;

            document.querySelectorAll(".tab").forEach(tab => {
                tab.classList.remove("active");
            });

            button.classList.add("active");

            render();
        }

        function openForm(id = null) {
            editId = id;

            document.getElementById("itemForm").reset();

            const item = items.find(i => i.id === id);

            document.getElementById("formTitle").textContent =
                item ? "Edit Item" : "Add Item";

            if (item) {
                document.getElementById("name").value = item.name;
                document.getElementById("quantity").value = item.quantity;
                document.getElementById("unit").value = item.unit;
                document.getElementById("spec").value = item.spec;
            }

            modal.show();
        }

        document.getElementById("itemForm").addEventListener(
            "submit",
            function (event) {
                event.preventDefault();

                const data = {
                    name: document.getElementById("name").value.trim(),
                    quantity: Number(
                        document.getElementById("quantity").value
                    ),
                    unit: document.getElementById("unit").value.trim(),
                    spec: document.getElementById("spec").value.trim()
                };

                if (
                    !data.name ||
                    !data.unit ||
                    !data.spec ||
                    !Number.isFinite(data.quantity) ||
                    data.quantity < 0
                ) {
                    return;
                }

                if (editId !== null) {
                    const item = items.find(i => i.id === editId);

                    if (item) {
                        Object.assign(item, data);
                    }
                } else {
                    items.push({
                        id: Date.now(),
                        ...data,
                        category
                    });
                }

                modal.hide();
                render();
            }
        );

        render();
    </script>

</body>
</html>
