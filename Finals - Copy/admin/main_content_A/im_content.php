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
    /* =====================================================================
       Inventory Management now saves to the PostgreSQL database through
       inventory_api.php (the project root, next to login.php).

       The database is the source of truth. The list is also copied into the
       browser key below ONLY so the Dashboard, Stocking Records and the
       notifications, which still read that key, keep working.
       ===================================================================== */
    const API_URL = "database/inventory_db.php";
    const STORAGE_KEY = "inventoryItems";          // read-only mirror for the other pages
    const IMPORTED_KEY = "inventoryDbImported";    // set once the old browser items were moved to the database

    let items = [];
    let category = "Ingredients";
    let searchTerm = "";
    let loadState = "loading";                     // loading | ready | error
    let loadError = "";

    /* ---------- talk to the server ---------- */
    async function api(payload) {
        const options = payload
            ? {
                method: "POST",
                headers: {"Content-Type": "application/json", "X-Requested-With": "fetch"},
                body: JSON.stringify(payload),
                credentials: "same-origin"
            }
            : {headers: {"X-Requested-With": "fetch"}, credentials: "same-origin"};

        let res;
        try {
            res = await fetch(API_URL + (payload ? "" : "?action=list"), options);
        } catch (e) {
            throw new Error("Could not reach the server. Please try again.");
        }

        const raw = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            console.error("inventory_api.php answered (HTTP " + res.status + "):", raw.slice(0, 600));
            throw new Error("Server error (HTTP " + res.status + "). Check the PHP error log.");
        }

        if (res.status === 401) {
            window.location.href = "login.php";
            throw new Error(data.message || "Please log in first.");
        }
        if (!res.ok || !data.success) {
            throw new Error(data.message || "The request failed.");
        }
        return data;
    }

    /* ---------- copy for the other pages ---------- */
    function mirrorToStorage() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
        } catch (e) {
            console.warn("Could not update the browser copy of the inventory.", e);
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

    // items that the browser kept before the database existed
    function readOldBrowserItems() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
            return Array.isArray(saved)
                ? saved.filter(i => i && typeof i === "object" && i.name && ["Ingredients", "Products", "Supplies"].includes(i.type))
                : [];
        } catch (e) {
            return [];
        }
    }

    async function loadFromServer() {
        loadState = "loading";
        renderList();

        try {
            const data = await api();
            items = data.items;

            // one time: move the items the browser used to keep into the database
            if (items.length === 0 && !localStorage.getItem(IMPORTED_KEY)) {
                const old = readOldBrowserItems();
                if (old.length > 0) {
                    try {
                        const result = await api({action: "import", items: old});
                        items = result.items;
                        console.log("Inventory import: " + result.imported + " moved, " + result.skipped + " skipped.");
                    } catch (e) {
                        console.warn("Inventory import skipped:", e);
                    }
                }
                try { localStorage.setItem(IMPORTED_KEY, "1"); } catch (e) { }
            }

            loadState = "ready";
            render();
        } catch (e) {
            console.error("Could not load the inventory:", e);
            loadState = "error";
            loadError = e.message || "Could not load the inventory.";
            renderList();
        }
    }

    /* The three add / edit modals call this to save an item.
       editId is null for a new item, or the id of the item being edited. */
    window.saveInventoryItem = async function (data, editId) {
        const result = await api({action: "save", id: editId, item: data});
        const saved = result.item;

        const index = items.findIndex(i => String(i.id) === String(saved.id));
        if (index >= 0) {
            items[index] = saved;
        } else {
            items.push(saved);
        }

        render();
        return saved;
    };

    /* ---------- screen ---------- */

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

        document.getElementById("imCategoryTitle").textContent = category;

        if (loadState === "loading") {
            document.getElementById("imCategoryCount").textContent = "Loading...";
            container.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <h5>Loading inventory...</h5>
                </div>
            `;
            return;
        }

        if (loadState === "error") {
            document.getElementById("imCategoryCount").textContent = "Could not load";
            container.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h5>The inventory could not be loaded</h5>
                    <p></p>
                    <button type="button" class="edit" id="imRetry"><i class="bi bi-arrow-clockwise"></i> Try again</button>
                </div>
            `;
            container.querySelector("p").textContent = loadError;
            document.getElementById("imRetry").onclick = loadFromServer;
            return;
        }

        const inCategory = items.filter(item => item.type === category);
        const term = searchTerm.trim().toLowerCase();

        const filteredItems = term
            ? inCategory.filter(item =>
                [item.name, item.unit, item.texture, item.spec].some(
                    v => String(v ?? "").toLowerCase().includes(term)
                ))
            : inCategory;

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
                img.loading = "lazy";
                card.append(img, content);
            } else {
                card.append(content);
            }

            container.appendChild(card);
        });
    }

    // After the list changed: copy for the other pages + notifications + draw
    function render() {
        mirrorToStorage();
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

    /* ---------- delete (custom confirmation popup) ---------- */
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
        .addEventListener("click", async function () {
            const button = this;

            if (pendingDeleteId === null) {
                getDeleteModal().hide();
                return;
            }

            const id = pendingDeleteId;
            button.disabled = true;

            try {
                await api({action: "delete", id: id});
                items = items.filter(i => String(i.id) !== String(id));
                pendingDeleteId = null;
                render();
                getDeleteModal().hide();
            } catch (e) {
                alert(e.message || "The item could not be deleted.");
            } finally {
                button.disabled = false;
            }
        });

    loadFromServer();
</script>