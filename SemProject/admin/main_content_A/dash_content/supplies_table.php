<!DOCTYPE html>
<html lang="en">


<body>
        <!-- SUPPLIES TABLE -->
        <section
            class="table-responsive inventory-table-container"
            id="suppliesTable"
            role="tabpanel"
            aria-labelledby="suppliesTab"
            hidden>

            <table class="table inventory-table mb-0">

                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                    </tr>
                </thead>

                <tbody id="suppliesBody"></tbody>

            </table>

        </section>

<script>
(function () {
    // Same key the inventory page saves to
    const STORAGE_KEY = "inventoryItems";

    function loadSupplies() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
            return Array.isArray(saved)
                ? saved.filter(item => item.type === "Supplies")
                : [];
        } catch (e) {
            return [];
        }
    }

    function makeCell(text, className) {
        const cell = document.createElement("td");
        cell.textContent = text;
        if (className) cell.className = className;
        return cell;
    }

    function renderSuppliesTable() {
        const body = document.getElementById("suppliesBody");
        const supplies = loadSupplies();

        body.innerHTML = "";

        if (supplies.length === 0) {
            const row = document.createElement("tr");
            const cell = makeCell("No supplies yet.", "text-center text-muted");

            cell.colSpan = 3;
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        supplies.forEach(item => {
            const row = document.createElement("tr");

            row.append(
                makeCell(item.name),
                makeCell(item.quantity),
                makeCell(item.unit ?? "")
            );

            body.appendChild(row);
        });
    }

    renderSuppliesTable();

    // Update automatically if the inventory changes in another tab
    window.addEventListener("storage", event => {
        if (event.key === STORAGE_KEY) renderSuppliesTable();
    });

    // Refresh when coming back to this page with the browser's back button
    window.addEventListener("pageshow", renderSuppliesTable);
})();
</script>
</body>
</html>