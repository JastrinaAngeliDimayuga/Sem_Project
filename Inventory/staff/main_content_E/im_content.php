<?php
if (!function_exists('webPathOf')) {
    /* Turns a file on disk into a web path, so links work from any page that includes this one */
    function webPathOf(string $fsPath): string
    {
        $real = realpath($fsPath);
        $doc  = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if (!$real || !$doc) { return ''; }
        $real = str_replace('\\', '/', $real);
        $doc  = rtrim(str_replace('\\', '/', $doc), '/');
        return stripos($real, $doc) === 0 ? substr($real, strlen($doc)) : '';
    }
}

/* notify.js sits in the project root (SEMPROJECT/notify.js) */
$notifyJs = webPathOf(__DIR__ . '/../notify.js') ?: 'notify.js';
?>
<!DOCTYPE html>
<html lang="en">

<body>

    <div class="app">

        <!-- Bootstrap loads BEFORE any include that may use it -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <!-- NOTIFICATION HELPER (browser storage, no database) -->
        <script src="<?= htmlspecialchars($notifyJs, ENT_QUOTES, 'UTF-8') ?>"></script>

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

                <?php include 'staff/main_content_E/im_content/add_button.php'; ?>

            </div>

            <!-- VIEW MODAL -->

            <?php include 'staff/main_content_E/im_content/view_im.php'; ?>


            <!-- INVENTORY PANEL -->
            <div class="panel">
                <div id="inventory"></div>
            </div>

        </main>
    </div>

    <!-- Add modals moved outside .app so they are not trapped by its CSS -->
    <!-- MODAL FILES -->
    <?php include 'staff/add_content/add_ingredients.php'; ?>
    <?php include 'staff/add_content/add_cookies.php'; ?>
    <?php include 'staff/add_content/add_supplies.php'; ?>

    <script>
        // Shared storage key: the dashboard and Stocking Record read the same key
        const STORAGE_KEY = "inventoryItems";

        const FALLBACK_IMAGE =
            "https://images.unsplash.com/photo-1582176604856-e824b4736522?auto=format&fit=crop&w=300&q=80";

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

        // Tell the admin when an item is added or removed (browser storage only)
        function syncNotifications() {
            if (window.Notify) {
                try {
                    Notify.syncItems(items);
                } catch (e) {
                    console.error("Could not update notifications.", e);
                }
            }
        }

        let items = loadItems();

        let category = "Ingredients";

        function render() {
            saveItems();
            syncNotifications();

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
                img.src = item.image || FALLBACK_IMAGE;
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
                    if (item.type === "Products") openStaffForm(item.id);
                    else if (item.type === "Supplies") openSuppliesForm(item.id);
                    else openForm(item.id);
                };

                const view = document.createElement("button");
                view.type = "button";
                view.className = "view";
                view.innerHTML =
                    '<i class="bi bi-eye-fill"></i> View';

                view.onclick = () => openViewModal(item);

                actions.append(edit, view);
                top.append(name, actions);

                const details = document.createElement("div");
                details.className = "details";

                [
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

        // View modal: read-only details of one item
        function getViewModal() {
            return bootstrap.Modal.getOrCreateInstance(
                document.getElementById("viewModal")
            );
        }

        function openViewModal(item) {
            document.getElementById("viewItemImage").src =
                item.image || FALLBACK_IMAGE;
            document.getElementById("viewItemName").textContent = item.name;
            document.getElementById("viewItemType").textContent = item.type;

            const price = Number(item.price);

            const fields = [
                ["Unit", item.unit],
                ["Price", Number.isFinite(price) && item.price !== "" &&
                    item.price != null ? "₱" + price.toFixed(2) : ""],
                ["Category", item.category],
                ["Flavour", item.flavour],
                ["Texture / Specification", item.texture ?? item.spec],
                ["Orders", item.orders]
            ];

            const grid = document.getElementById("viewItemDetails");
            grid.innerHTML = "";

            fields.forEach(([label, value]) => {
                if (value === undefined || value === null || value === "") {
                    return;
                }

                const div = document.createElement("div");

                const labelElement = document.createElement("div");
                labelElement.className = "view-detail-label";
                labelElement.textContent = label;

                const valueElement = document.createElement("div");
                valueElement.className = "view-detail-value";
                valueElement.textContent = value;

                div.append(labelElement, valueElement);
                grid.appendChild(div);
            });

            getViewModal().show();
        }

        render();
    </script>

</body>

</html>