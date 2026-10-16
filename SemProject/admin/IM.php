<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Inventory Management</title>

    <link rel="stylesheet" href="admin/assets/im.css">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

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

                <button type="button"
                        class="tab active"
                        onclick="showCategory('Ingredients', this)"
                        aria-label="Ingredients"
                        title="Ingredients">
                    <i class="bi bi-clipboard2-data-fill"></i>
                </button>

                <button type="button"
                        class="tab"
                        onclick="showCategory('Products', this)"
                        aria-label="Products"
                        title="Products">
                    <i class="bi bi-cookie"></i>
                </button>

                <button type="button"
                        class="tab"
                        onclick="showCategory('Supplies', this)"
                        aria-label="Supplies"
                        title="Supplies">
                    <i class="bi bi-box-seam-fill"></i>
                </button>

            </div>

            <!-- ADD BUTTONS: ALL IN ONE ROW -->
            <div class="add-buttons">

                <div class="ingredients-add-btn">
                    <button type="button"
                            class="add-btn add-ingredient-btn"
                            onclick="openForm()"
                            title="Add ingredient"
                            aria-label="Add ingredient">
                        <i class="bi bi-plus-lg"></i>
                        <span>📋</span>
                    </button>
                </div>

                <div class="cookies-add-btn">
                    <button type="button"
                            class="add-btn add-cookie-btn"
                            onclick="openCookieForm()"
                            title="Add cookie"
                            aria-label="Add cookie">
                        <i class="bi bi-plus-lg"></i>
                        <span>🍪</span>
                    </button>
                </div>

                <div class="supplies-add-btn">
                    <button type="button"
                            class="add-btn add-supplies-btn"
                            onclick="openSuppliesForm()"
                            title="Add supplies"
                            aria-label="Add supplies">
                        <i class="bi bi-plus-lg"></i>
                        <span>📦</span>
                    </button>
                </div>

            </div>
            <!-- END ADD BUTTONS -->

        </div>
        <!-- END TOOLBAR -->

        <!-- MODAL FILES -->
        <?php include 'admin/add_content/add_ingredients.php'; ?>
        <?php include 'admin/add_content/add_cookies.php'; ?>

        <!-- INVENTORY PANEL -->
        <div class="panel">
            <div id="inventory"></div>
        </div>

    </main>
</div>

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
                    items = items.filter(i => i.id !== item.id);
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

    render();
</script>

</body>
</html>
