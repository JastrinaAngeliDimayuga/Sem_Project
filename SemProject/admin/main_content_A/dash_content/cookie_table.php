<!DOCTYPE html>
<html lang="en">


<body>

<!-- COOKIES TABLE -->
        <section
            class="table-responsive inventory-table-container"
            id="cookiesTable"
            role="tabpanel"
            aria-labelledby="cookiesTab"
            hidden>

            <table class="table inventory-table cookie-table mb-0">

                <thead>
                    <tr>
                        <th>Cookies Name</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Order</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody id="cookiesBody"></tbody>

            </table>

        </section>

<script>
(function () {
    // Same key the inventory page saves to
    const STORAGE_KEY = "inventoryItems";

    // Quantity at or below this is "Low Stock"
    const LOW_STOCK_LIMIT = 10;

    function loadCookies() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
            return Array.isArray(saved)
                ? saved.filter(item => item.type === "Products")
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

    function formatPrice(price) {
        return "₱" + Number(price || 0).toLocaleString("en-PH", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function makeCell(text, className) {
        const cell = document.createElement("td");
        cell.textContent = text;
        if (className) cell.className = className;
        return cell;
    }

    function renderCookiesTable() {
        const body = document.getElementById("cookiesBody");
        const cookies = loadCookies();

        body.innerHTML = "";

        if (cookies.length === 0) {
            const row = document.createElement("tr");
            const cell = makeCell("No cookies yet.", "text-center text-muted");

            cell.colSpan = 5;
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        cookies.forEach(item => {
            const row = document.createElement("tr");

            const statusCell = document.createElement("td");
            const status = getStatus(item.quantity);
            const badge = document.createElement("span");

            badge.className = "status " + status.css;
            badge.textContent = status.label;
            statusCell.appendChild(badge);

            row.append(
                makeCell(item.name),
                makeCell(item.quantity + " " + (item.unit ?? "")),
                makeCell(formatPrice(item.price), "cookie-price"),
                makeCell(item.orders ?? 0),
                statusCell
            );

            body.appendChild(row);
        });
    }

    renderCookiesTable();

    // Update automatically if the inventory changes in another tab
    window.addEventListener("storage", event => {
        if (event.key === STORAGE_KEY) renderCookiesTable();
    });

    // Refresh when coming back to this page with the browser's back button
    window.addEventListener("pageshow", renderCookiesTable);
})();
</script>
</body>
</html>