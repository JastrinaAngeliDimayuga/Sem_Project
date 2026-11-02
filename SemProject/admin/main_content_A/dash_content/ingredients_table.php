<!DOCTYPE html>
<html lang="en">


<body>
<!-- INGREDIENTS TABLE -->
        <section
            class="table-responsive inventory-table-container"
            id="ingredientsTable"
            role="tabpanel"
            aria-labelledby="ingredientsTab">

        <table class="table inventory-table mb-0">
                <thead>
                    <tr>
                        <th>Specification</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody id="ingredientsBody"></tbody>
        </table>
        </section>

<script>
    // Same key the inventory page saves to
    const STORAGE_KEY = "inventoryItems";

    // Quantity at or below this is "Low Stock"
    const LOW_STOCK_LIMIT = 10;

    function loadIngredients() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
            return Array.isArray(saved)
                ? saved.filter(item => item.type === "Ingredients")
                : [];
        } catch (e) {
            return [];
        }
    }

    function getStatus(quantity) {
        const qty = Number(quantity);

        if (qty <= 0) return { label: "Out of Stock", css: "out-of-stock" };
        if (qty <= LOW_STOCK_LIMIT) return { label: "Low Stock", css: "low-stock" };
        return { label: "In Stock", css: "in-stock" };
    }

    function renderIngredientsTable() {
        const body = document.getElementById("ingredientsBody");
        const ingredients = loadIngredients();

        body.innerHTML = "";

        if (ingredients.length === 0) {
            const row = document.createElement("tr");
            const cell = document.createElement("td");

            cell.colSpan = 4;
            cell.className = "text-center text-muted";
            cell.textContent = "No ingredients yet.";

            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        ingredients.forEach(item => {
            const row = document.createElement("tr");

            const name = document.createElement("td");
            name.textContent = item.name;

            const quantity = document.createElement("td");
            quantity.textContent = item.quantity;

            const unit = document.createElement("td");
            unit.textContent = item.unit;

            const statusCell = document.createElement("td");
            const status = getStatus(item.quantity);
            const badge = document.createElement("span");

            badge.className = "status " + status.css;
            badge.textContent = status.label;
            statusCell.appendChild(badge);

            row.append(name, quantity, unit, statusCell);
            body.appendChild(row);
        });
    }

    renderIngredientsTable();

    // Update automatically if the inventory changes in another tab
    window.addEventListener("storage", event => {
        if (event.key === STORAGE_KEY) renderIngredientsTable();
    });

    // Refresh when coming back to this page with the browser's back button
    window.addEventListener("pageshow", renderIngredientsTable);
</script>
</body>
</html>