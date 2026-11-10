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

        <div class="page">

            <!-- HERO BANNER -->
            <header class="hero">
                <p class="hero-eyebrow">Stock management</p>
                <h1 class="hero-title">Inventory Management</h1>
                <p class="hero-text">
                    Add, edit and organize your ingredients, products and supplies in one place.
                </p>
            </header>

            <!-- SUMMARY CARDS -->
            <section class="stats" aria-label="Inventory summary">

                <div class="stat-card">
                    <div class="stat-info">
                        <span class="stat-label">Total Items</span>
                        <strong class="stat-value" id="statTotal">0</strong>
                        <span class="stat-note">Across all categories</span>
                    </div>
                    <div class="stat-icon stat-icon-total"><i class="bi bi-boxes"></i></div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <span class="stat-label">Ingredients</span>
                        <strong class="stat-value" id="statIngredients">0</strong>
                        <span class="stat-note">Raw materials</span>
                    </div>
                    <div class="stat-icon stat-icon-ingredients"><i class="bi bi-clipboard2-data-fill"></i></div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <span class="stat-label">Products</span>
                        <strong class="stat-value" id="statProducts">0</strong>
                        <span class="stat-note">Cookies for sale</span>
                    </div>
                    <div class="stat-icon stat-icon-products"><i class="bi bi-cookie"></i></div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <span class="stat-label">Supplies</span>
                        <strong class="stat-value" id="statSupplies">0</strong>
                        <span class="stat-note">Packaging and tools</span>
                    </div>
                    <div class="stat-icon stat-icon-supplies"><i class="bi bi-box-seam"></i></div>
                </div>

            </section>

            <!-- INVENTORY PANEL -->
            <main class="panel">

                <!-- TOOLBAR -->
                <div class="toolbar">

                    <!-- CATEGORY TABS -->
                    <div class="category-tabs">

                        <button type="button" class="tab active" data-category="Ingredients"
                            onclick="showCategory('Ingredients', this)" aria-label="Ingredients" title="Ingredients">
                            <i class="bi bi-clipboard2-data-fill"></i>
                            <span>Ingredients</span>
                        </button>

                        <button type="button" class="tab" data-category="Products"
                            onclick="showCategory('Products', this)" aria-label="Products" title="Products">
                            <i class="bi bi-cookie"></i>
                            <span>Products</span>
                        </button>

                        <button type="button" class="tab" data-category="Supplies"
                            onclick="showCategory('Supplies', this)" aria-label="Supplies" title="Supplies">
                            <i class="bi bi-box-seam-fill"></i>
                            <span>Supplies</span>
                        </button>

                    </div>

                    <!-- ADD BUTTONS: ALL IN ONE ROW -->
                    <?php include 'staff/main_content_E/im_content/add_button.php'; ?>

                </div>

                <!-- SECTION HEADING + SEARCH -->
                <div class="section-head">
                    <div>
                        <h2 class="section-title" id="sectionTitle">Ingredients</h2>
                        <p class="section-count" id="sectionCount">0 items</p>
                    </div>

                    <label class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="search" id="searchInput" placeholder="Search items" autocomplete="off"
                            aria-label="Search items" oninput="draw()">
                    </label>
                </div>

                <!-- INVENTORY LIST -->
                <div id="inventory"></div>

            </main>

        </div>
    </div>

    <!-- VIEW MODAL -->
    <?php include 'staff/main_content_E/im_content/view_im.php'; ?>

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

        // Updates the four summary cards at the top
        function updateStats() {
            const count = type => items.filter(i => i.type === type).length;

            document.getElementById("statTotal").textContent = items.length;
            document.getElementById("statIngredients").textContent = count("Ingredients");
            document.getElementById("statProducts").textContent = count("Products");
            document.getElementById("statSupplies").textContent = count("Supplies");
        }

        // Full refresh: save, notify, then redraw (other files call render())
        function render() {
            saveItems();
            syncNotifications();
            draw();
        }

        // Redraws the stats, heading and list only (no saving, used by search)
        function draw() {
            updateStats();

            const search = (document.getElementById("searchInput").value || "")
                .trim()
                .toLowerCase();

            const categoryItems = items.filter(item => item.type === category);

            const filteredItems = categoryItems.filter(item =>
                !search || String(item.name || "").toLowerCase().includes(search)
            );

            document.getElementById("sectionTitle").textContent = category;
            document.getElementById("sectionCount").textContent =
                filteredItems.length + (filteredItems.length === 1 ? " item" : " items");

            const container = document.getElementById("inventory");
            container.innerHTML = "";

            if (filteredItems.length === 0) {
                const searching = categoryItems.length > 0 && search;

                container.innerHTML = `
                <div class="empty-state">
                    <i class="bi ${searching ? "bi-search" : "bi-inbox"}"></i>
                    <h5>${searching ? "No matching items" : "No inventory items yet"}</h5>
                    <p>${searching ? "Try a different search." : "Add an item to get started."}</p>
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

            // Start each tab with a clean search
            document.getElementById("searchInput").value = "";

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

        // YYYY-MM-DD -> "November 9, 2026"
        function formatDateAdded(iso) {
            if (!iso) return "";
            const p = String(iso).split("-").map(Number);
            if (p.length !== 3 || p.some(isNaN)) return "";
            return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString("en-US", {
                year: "numeric", month: "long", day: "numeric"
            });
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
                ["Orders", item.orders],
                ["Date Added", formatDateAdded(item.dateAdded)]
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