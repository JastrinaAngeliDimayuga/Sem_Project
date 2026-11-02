<!DOCTYPE html>
<html lang="en">

<body>

 <div class="app">

        <header class="header">
            Inventory Management
        </header>

        <main class="inventory-section">

            <!-- TOOLBAR -->
            <div class="toolbar">

                <!-- CATEGORY TABS -->
                <div class="category-tabs">

                    <button type="button" class="tab active" data-category="Ingredients"
                        onclick="showCategory('Ingredients', this)" aria-label="Ingredients" title="Ingredients">
                        <i class="bi bi-clipboard2-data-fill"></i>
                    </button>

                    <button type="button" class="tab" data-category="Products" onclick="showCategory('Products', this)"
                        aria-label="Products" title="Products">
                        <i class="bi bi-cookie"></i>
                    </button>

                    <button type="button" class="tab" data-category="Supplies" onclick="showCategory('Supplies', this)"
                        aria-label="Supplies" title="Supplies">
                        <i class="bi bi-box-seam-fill"></i>
                    </button>

                </div>

            <!-- ADD BUTTONS: ALL IN ONE ROW -->

            <?php include 'admin/main_content_A/im_content/add_button.php'; ?>

            </div>

            <!-- MODAL FILES -->
            <?php include 'admin/add_content/add_ingredients.php'; ?>
            <?php include 'admin/add_content/add_cookies.php'; ?>
            <?php include 'admin/add_content/add_supplies.php'; ?>

            <!-- DELETE CONFIRMATION MODAL -->

            <?php include 'admin/main_content_A/im_content/delete_im.php'; ?>


            <!-- INVENTORY PANEL -->
            <div class="panel">
                <div id="inventory"></div>
            </div>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Shared storage key: the dashboard reads the same key
        const STORAGE_KEY = "inventoryItems";

        function loadItems() {
            try {
                const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
                return Array.isArray(saved) ? saved : [];
            } catch (e) {
                return [];
            }
        }

        function saveItems() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
            } catch (e) {
                // Storage full (large photos): save again without the photos
                try {
                    localStorage.setItem(
                        STORAGE_KEY,
                        JSON.stringify(items.map(i => ({...i, image: ""})))
                    );
                } catch (e2) {
                    console.error("Could not save inventory.", e2);
                }
            }
        }

        let items = loadItems();

        let category = "Ingredients";

        function render() {
            saveItems();

            const container = document.getElementById("inventory");
            container.innerHTML = "";

            const filteredItems = items.filter(
                item => item.type === category
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
                img.src = item.image ||
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
                edit.onclick = () => {
                    if (item.type === "Products") openCookieForm(item.id);
                    else if (item.type === "Supplies") openSuppliesForm(item.id);
                    else openForm(item.id);
                };

                const del = document.createElement("button");
                del.type = "button";
                del.className = "delete";
                del.innerHTML =
                    '<i class="bi bi-trash3-fill"></i> Delete';

                del.onclick = () => openDeleteModal(item);

                actions.append(edit, del);
                top.append(name, actions);

                const details = document.createElement("div");
                details.className = "details";

                [
                    ["Quantity", item.quantity],
                    ["Unit", item.unit],
                    ["Specification", item.texture ?? item.spec ?? ""]
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

        // Switch to a tab by name (used after saving so the new item is visible)
        function goToCategory(name) {
            const button = document.querySelector(
                '.tab[data-category="' + name + '"]'
            );

            if (button) {
                showCategory(name, button);
            } else {
                category = name;
                render();
            }
        }

        // Custom delete confirmation (replaces the browser's confirm popup)
        let pendingDeleteId = null;

        function getDeleteModal() {
            return bootstrap.Modal.getOrCreateInstance(
                document.getElementById("deleteModal")
            );
        }

        function openDeleteModal(item) {
            pendingDeleteId = item.id;
            document.getElementById("deleteItemName").textContent = item.name;
            getDeleteModal().show();
        }

        document.getElementById("confirmDeleteBtn")
            .addEventListener("click", () => {
                if (pendingDeleteId !== null) {
                    items = items.filter(i => i.id !== pendingDeleteId);
                    pendingDeleteId = null;
                    render();
                }

                getDeleteModal().hide();
            });

        render();
    </script>

</body>

</html>