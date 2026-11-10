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

/* notify.js sits in the project root (INVENTORY/notify.js) */
$notifyJs = webPathOf(__DIR__ . '/../notify.js') ?: 'notify.js';

/* im.css lives in admin/assets/ - works wherever this file is placed */
$imCss = '';
foreach ([
    __DIR__ . '/assets/im.css',
    __DIR__ . '/../assets/im.css',
    __DIR__ . '/admin/assets/im.css',
] as $candidate) {
    $path = webPathOf($candidate);
    if ($path !== '') {
        $imCss = $path . '?v=' . filemtime(realpath($candidate));
        break;
    }
}
if ($imCss === '') { $imCss = 'admin/assets/im.css'; }
?>
<link rel="stylesheet" href="<?= htmlspecialchars($imCss, ENT_QUOTES, 'UTF-8') ?>">

<style>
    /* Items without a photo: no picture, the text uses the full card width */
    .im-wrap .item.no-photo {
        grid-template-columns: 1fr;
    }

    .im-wrap .item.no-photo .item-content {
        flex: 1;
        width: 100%;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- NOTIFICATION HELPER (browser storage, no database) -->
<script src="<?= htmlspecialchars($notifyJs, ENT_QUOTES, 'UTF-8') ?>"></script>

<div class="im-wrap">

    <!-- ============ HERO ============ -->
    <section class="im-hero">
        <div>
            <span class="im-eyebrow">Stock Management</span>
            <h1 class="im-hero-title">Inventory Management</h1>
            <p class="im-hero-text">Add, edit and organize your ingredients, products and supplies in one place.</p>
        </div>
    </section>

    <!-- ============ STAT CARDS ============ -->
    <section class="im-stats">
        <div class="im-stat">
            <div>
                <span class="im-stat-label">Total Items</span>
                <span class="im-stat-value" id="imCountTotal">0</span>
                <span class="im-stat-sub">Across all categories</span>
            </div>
            <span class="im-stat-icon im-ic-brown"><i class="bi bi-boxes"></i></span>
        </div>

        <div class="im-stat">
            <div>
                <span class="im-stat-label">Ingredients</span>
                <span class="im-stat-value" id="imCountIngredients">0</span>
                <span class="im-stat-sub">Raw materials</span>
            </div>
            <span class="im-stat-icon im-ic-green"><i class="bi bi-clipboard2-data"></i></span>
        </div>

        <div class="im-stat">
            <div>
                <span class="im-stat-label">Products</span>
                <span class="im-stat-value" id="imCountProducts">0</span>
                <span class="im-stat-sub">Cookies for sale</span>
            </div>
            <span class="im-stat-icon im-ic-amber"><i class="bi bi-cookie"></i></span>
        </div>

        <div class="im-stat">
            <div>
                <span class="im-stat-label">Supplies</span>
                <span class="im-stat-value" id="imCountSupplies">0</span>
                <span class="im-stat-sub">Packaging and tools</span>
            </div>
            <span class="im-stat-icon im-ic-red"><i class="bi bi-box-seam"></i></span>
        </div>
    </section>

    <!-- ============ MAIN CARD ============ -->
    <main class="inventory-section im-card">

        <!-- TOOLBAR: tabs on the left, add buttons on the right -->
        <div class="toolbar">

            <!-- CATEGORY TABS -->
            <div class="category-tabs">

                <button type="button" class="tab active" data-category="Ingredients"
                    onclick="showCategory('Ingredients', this)" aria-label="Ingredients" title="Ingredients">
                    <i class="bi bi-clipboard2-data-fill"></i><span>Ingredients</span>
                </button>

                <button type="button" class="tab" data-category="Products" onclick="showCategory('Products', this)"
                    aria-label="Products" title="Products">
                    <i class="bi bi-cookie"></i><span>Products</span>
                </button>

                <button type="button" class="tab" data-category="Supplies" onclick="showCategory('Supplies', this)"
                    aria-label="Supplies" title="Supplies">
                    <i class="bi bi-box-seam-fill"></i><span>Supplies</span>
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

        <!-- PANEL HEADER: category title + search -->
        <div class="im-panel-head">
            <div>
                <h2 class="im-panel-title" id="imCategoryTitle">Ingredients</h2>
                <p class="im-panel-sub" id="imCategoryCount">0 items</p>
            </div>

            <div class="im-search">
                <i class="bi bi-search"></i>
                <input type="text" id="im-search" placeholder="Search items" autocomplete="off">
            </div>
        </div>

        <!-- INVENTORY PANEL -->
        <div class="panel">
            <div id="inventory"></div>
        </div>

    </main>
</div>

<script>
    // Shared storage key: the dashboard and Stocking Record read the same key
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

    // Create a notification when an item is added or removed (browser storage only)
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
    let searchTerm = "";

    // Update the four stat cards
    function updateCounts() {
        const counts = { Ingredients: 0, Products: 0, Supplies: 0 };

        items.forEach(item => {
            if (counts[item.type] !== undefined) { counts[item.type]++; }
        });

        document.getElementById("imCountTotal").textContent = items.length;
        document.getElementById("imCountIngredients").textContent = counts.Ingredients;
        document.getElementById("imCountProducts").textContent = counts.Products;
        document.getElementById("imCountSupplies").textContent = counts.Supplies;
    }

    // Draw the list only (no saving) - used by tabs and search
    function renderList() {
        updateCounts();

        const container = document.getElementById("inventory");
        container.innerHTML = "";

        const inCategory = items.filter(item => item.type === category);
        const term = searchTerm.trim().toLowerCase();

        const filteredItems = term
            ? inCategory.filter(item =>
                [item.name, item.unit, item.texture, item.spec].some(
                    v => String(v ?? "").toLowerCase().includes(term)
                ))
            : inCategory;

        document.getElementById("imCategoryTitle").textContent = category;
        document.getElementById("imCategoryCount").textContent =
            filteredItems.length + (filteredItems.length === 1 ? " item" : " items") +
            (term ? " found" : "");

        if (filteredItems.length === 0) {
            const hasItems = inCategory.length > 0;
            container.innerHTML = `
                <div class="empty-state">
                    <i class="bi ${hasItems ? "bi-search" : "bi-inbox"}"></i>
                    <h5>${hasItems ? "No matching items" : "No inventory items yet"}</h5>
                    <p>${hasItems ? "Try a different search." : "Add an item to get started."}</p>
                </div>
            `;
            return;
        }

        filteredItems.forEach(item => {
            const card = document.createElement("div");
            card.className = "item";

            // Only items that really have a photo get an image
            const hasPhoto = typeof item.image === "string" && item.image.trim() !== "";

            if (!hasPhoto) {
                card.classList.add("no-photo");
            }

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
                valueElement.textContent = value || "-";

                div.append(labelElement, valueElement);
                details.appendChild(div);
            });

            content.append(top, details);

            if (hasPhoto) {
                const img = document.createElement("img");
                img.src = item.image;
                img.alt = item.name;
                img.className = "product-image";
                card.append(img, content);
            } else {
                card.append(content);
            }

            container.appendChild(card);
        });
    }

    // Save + notify + draw (called after adding, editing or deleting)
    function render() {
        saveItems();
        syncNotifications();
        renderList();
    }

    function showCategory(name, button) {
        category = name;

        document.querySelectorAll(".tab").forEach(tab => {
            tab.classList.remove("active");
        });

        button.classList.add("active");
        renderList();
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
            renderList();
        }
    }

    // Search box
    document.getElementById("im-search").addEventListener("input", function () {
        searchTerm = this.value;
        renderList();
    });

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