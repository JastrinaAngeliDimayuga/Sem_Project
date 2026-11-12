<?php
// admin/employees.php  -  Employees Management page (content only)
// The sidebar and top bar come from the shared admin layout.
// Contains: the list, stat cards, search and tabs, and the profile picture window.
// The Add, Edit and Delete modals are in em_content/edit_delete.php (included at the bottom).
// Data comes from em_content/employees_api.php (PostgreSQL, via signupdb.php).

// A per-session token. The API only answers requests that carry it, so only
// someone who has opened this page (through the admin layout) can use the API.
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    session_start();
}

$emToken = '';
if (session_status() === PHP_SESSION_ACTIVE) {
    if (empty($_SESSION['em_token'])) {
        $_SESSION['em_token'] = bin2hex(random_bytes(16));
    }
    $emToken = (string) $_SESSION['em_token'];
}

// URL of the API file (em_content/employees_api.php next to this page)
$emApiUrl = 'admin/em_content/employees_api.php';   // fallback
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$apiFile = realpath(__DIR__ . '/em_content/employees_api.php');
if ($docRoot && $apiFile) {
    $root = rtrim(str_replace('\\', '/', $docRoot), '/');
    $file = str_replace('\\', '/', $apiFile);
    if (stripos($file, $root . '/') === 0) {
        $emApiUrl = substr($file, strlen($root));
    }
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?php $emCssVer = @filemtime(__DIR__ . "/assets/em.css") ?: time(); ?>
<link rel="stylesheet" href="admin/assets/em.css?v=<?= (int) $emCssVer ?>">

<style>
    /* ---------- profile picture on the employee cards ---------- */
    .em-avatar-wrap {
        position: relative;
        flex: 0 0 auto;
        align-self: flex-start;
    }

    .em-avatar {
        overflow: hidden;
    }

    .em-avatar img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: inherit;
    }

    .em-avatar-edit {
        position: absolute;
        right: -6px;
        bottom: -6px;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        padding: 0;
        background: linear-gradient(135deg, #7a5642, #5a3f31);
        border: 2px solid #fff;
        border-radius: 50%;
        color: #fff;
        font-size: 12px;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(60, 38, 24, 0.3);
        cursor: pointer;
        transition: filter 0.15s ease, transform 0.15s ease;
    }

    .em-avatar-edit:hover,
    .em-avatar-edit:focus-visible {
        filter: brightness(1.15);
        transform: scale(1.08);
    }

    /* ---------- profile picture window ---------- */
    #emPhotoModal .modal-dialog { max-width: 420px; }

    #emPhotoModal .modal-content {
        overflow: hidden;
        padding: 0;
        background: #fbf7f2;
        border: 1px solid #b9967f;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(47, 32, 25, 0.35);
        color: #2f2019;
        font-family: 'Inter', Arial, sans-serif;
    }

    #emPhotoModal .modal-header {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 20px 26px;
        background: linear-gradient(120deg, #5a3d2d 0%, #7a4f31 55%, #9a6a45 100%);
        border-bottom: 0;
        color: #fff;
    }

    #emPhotoModal .modal-header::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -110px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.07);
        pointer-events: none;
    }

    #emPhotoModal .modal-heading { position: relative; z-index: 1; }

    #emPhotoModal .modal-eyebrow {
        display: block;
        margin-bottom: 3px;
        color: #ecd9c8;
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 1.8px;
        text-transform: uppercase;
    }

    #emPhotoModal .modal-title {
        margin: 0;
        color: #fff;
        font-size: 1.4rem;
        font-weight: 700;
        line-height: 1.2;
    }

    #emPhotoModal .modal-header .btn-close {
        position: relative;
        z-index: 1;
        margin: 0;
        filter: invert(1) grayscale(1) brightness(2);
        opacity: 0.85;
    }

    #emPhotoModal .modal-body {
        padding: 24px 26px 8px;
        text-align: center;
    }

    #emPhotoModal .emph-preview {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 150px;
        height: 150px;
        margin: 0 auto 12px;
        overflow: hidden;
        background: linear-gradient(135deg, #efe2d6, #e4cfbd);
        border: 3px solid #fff;
        border-radius: 50%;
        box-shadow: 0 4px 14px rgba(80, 52, 36, 0.2);
        color: #6f4e3d;
        font-size: 2.6rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    #emPhotoModal .emph-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    #emPhotoModal .emph-name {
        margin: 0 0 16px;
        color: #2f2019;
        font-size: 1.05rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    #emPhotoModal .emph-row {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    #emPhotoModal .emph-hint {
        margin: 14px 0 0;
        color: #8a7a6e;
        font-size: 0.78rem;
    }

    #emPhotoModal .emph-error {
        margin: 12px 0 0;
        padding: 10px 12px;
        background: #fde9eb;
        border-radius: 10px;
        color: #b3261e;
        font-size: 0.82rem;
        font-weight: 600;
    }

    #emPhotoModal .emph-error[hidden] { display: none; }

    #emPhotoModal .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 16px 20px;
        background: #fbf7f2;
        border-top: 1px solid #e8dccf;
    }

    #emPhotoModal .emph-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 40px;
        padding: 8px 18px;
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        transition: filter 0.15s ease, background 0.15s ease;
    }

    #emPhotoModal .emph-btn[hidden] { display: none; }

    #emPhotoModal .emph-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    #emPhotoModal .emph-btn-soft {
        background: #efe2d6;
        border: 1px solid #d6bba6;
        color: #6f4e3d;
    }

    #emPhotoModal .emph-btn-soft:hover:not(:disabled) { background: #e4d2c2; }

    #emPhotoModal .emph-btn-danger {
        background: #fde9eb;
        border: 1px solid #f3b9bd;
        color: #b3261e;
    }

    #emPhotoModal .emph-btn-danger:hover:not(:disabled) { background: #f8d0d4; }

    #emPhotoModal .emph-btn-main {
        background: linear-gradient(135deg, #7a5642, #5a3f31);
        border: 1px solid #5a3f31;
        color: #fff;
        box-shadow: 0 3px 8px rgba(90, 50, 25, 0.22);
    }

    #emPhotoModal .emph-btn-main:hover:not(:disabled) { filter: brightness(1.1); }
</style>

<div class="em-wrap">

    <!-- Notice -->
    <div class="em-notice" id="emNotice" role="status" hidden></div>

    <!-- Hero -->
    <section class="em-hero">
        <div class="em-hero-copy">
            <span class="em-eyebrow">Staff Management</span>
            <h1 class="em-hero-title">Employees Management</h1>
            <p class="em-hero-text">
                View, add, edit and manage the employees registered in your system.
            </p>
        </div>

        <button type="button" class="em-hero-btn" id="emAddBtn">
            <i class="bi bi-person-plus"></i>
            Add Employee
        </button>
    </section>

    <!-- Stat cards -->
    <section class="em-stats">
        <div class="em-stat">
            <div>
                <span class="em-stat-label">Total Employees</span>
                <span class="em-stat-value" id="emStatTotal">0</span>
                <span class="em-stat-sub">Registered accounts</span>
            </div>
            <span class="em-stat-icon em-ic-brown"><i class="bi bi-people"></i></span>
        </div>

        <div class="em-stat">
            <div>
                <span class="em-stat-label">Active</span>
                <span class="em-stat-value" id="emStatActive">0</span>
                <span class="em-stat-sub">Can sign in</span>
            </div>
            <span class="em-stat-icon em-ic-green"><i class="bi bi-person-check"></i></span>
        </div>

        <div class="em-stat">
            <div>
                <span class="em-stat-label">Inactive</span>
                <span class="em-stat-value" id="emStatInactive">0</span>
                <span class="em-stat-sub">Disabled accounts</span>
            </div>
            <span class="em-stat-icon em-ic-red"><i class="bi bi-person-slash"></i></span>
        </div>

        <div class="em-stat" id="emStatNewCard" hidden>
            <div>
                <span class="em-stat-label">New This Month</span>
                <span class="em-stat-value" id="emStatNew">0</span>
                <span class="em-stat-sub">Recently registered</span>
            </div>
            <span class="em-stat-icon em-ic-amber"><i class="bi bi-calendar-plus"></i></span>
        </div>
    </section>

    <!-- Main panel -->
    <section class="em-panel">

        <div class="em-toolbar">
            <div class="em-tabs" id="emTabs">
                <button type="button" class="em-tab active" data-filter="all">
                    <i class="bi bi-people"></i> All
                    <span class="em-tab-count" id="emCountAll">0</span>
                </button>
                <button type="button" class="em-tab" data-filter="active">
                    <i class="bi bi-person-check"></i> Active
                    <span class="em-tab-count" id="emCountActive">0</span>
                </button>
                <button type="button" class="em-tab" data-filter="inactive">
                    <i class="bi bi-person-slash"></i> Inactive
                    <span class="em-tab-count" id="emCountInactive">0</span>
                </button>
            </div>

            <div class="em-search">
                <i class="bi bi-search"></i>
                <input type="search" id="emSearch" placeholder="Search employees"
                    aria-label="Search employees" autocomplete="off">
            </div>
        </div>

        <div class="em-list-head">
            <h2 class="em-list-title" id="emListTitle">All Employees</h2>
            <p class="em-list-sub" id="emListSub">Loading...</p>
        </div>

        <div class="em-list">
            <div class="em-grid" id="employeeList">
                <div class="em-empty">
                    <i class="bi bi-hourglass-split"></i>
                    <h5>Loading employees</h5>
                    <p>Please wait a moment.</p>
                </div>
            </div>
        </div>

    </section>
</div>

<!-- =====================================================
     PROFILE PICTURE WINDOW (opened by the camera button on a card)
===================================================== -->
<div class="modal fade" id="emPhotoModal" tabindex="-1" aria-labelledby="emPhotoTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Employee</span>
                    <h5 class="modal-title" id="emPhotoTitle">Profile Picture</h5>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="emph-preview" id="emPhotoPreview"></div>
                <p class="emph-name" id="emPhotoName"></p>

                <input type="file" id="emPhotoFile" accept="image/*" hidden>

                <div class="emph-row">
                    <button type="button" class="emph-btn emph-btn-soft" id="emPhotoChoose">
                        <i class="bi bi-cloud-upload"></i> Choose photo
                    </button>
                    <button type="button" class="emph-btn emph-btn-danger" id="emPhotoRemove" hidden>
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>

                <p class="emph-hint">JPG, PNG or WebP. The photo is cropped to a square.</p>
                <p class="emph-error" id="emPhotoError" role="alert" hidden></p>
            </div>

            <div class="modal-footer">
                <button type="button" class="emph-btn emph-btn-soft" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="emph-btn emph-btn-main" id="emPhotoSave" disabled>Save photo</button>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    "use strict";

    const API_URL = <?= json_encode($emApiUrl, $jsonFlags) ?>;
    const TOKEN = <?= json_encode($emToken, $jsonFlags) ?>;

    const $ = function (id) { return document.getElementById(id); };

    const noticeEl = $("emNotice");
    const listEl = $("employeeList");
    const searchEl = $("emSearch");
    const tabsEl = $("emTabs");

    let employees = [];
    let meta = {
        statusEditable: false,
        hasCreatedAt: false,
        hasCreatedBy: false,
        currentUser: ""
    };
    let filter = "all";
    let query = "";
    let noticeTimer = null;

    /* ---------- helpers ---------- */

    function esc(value) {
        return String(value === null || value === undefined ? "" : value)
            .replace(/[&<>"']/g, function (ch) {
                return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
            });
    }

    function fullName(e) {
        const name = [e.first_name, e.middle_name, e.last_name, e.extension_name]
            .filter(Boolean).join(" ");
        return name || e.username || "Unnamed";
    }

    function initials(e) {
        const a = (e.first_name || "").trim().charAt(0);
        const b = (e.last_name || "").trim().charAt(0);
        return (a + b) || (e.username || "?").slice(0, 2);
    }

    function calcAge(iso) {
        if (!iso) return "";
        const parts = String(iso).slice(0, 10).split("-").map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) return "";

        const today = new Date();
        let age = today.getFullYear() - parts[0];
        const month = today.getMonth() + 1 - parts[1];
        if (month < 0 || (month === 0 && today.getDate() < parts[2])) age--;

        return age >= 0 ? age : "";
    }

    function formatDate(iso) {
        if (!iso) return "";
        const parts = String(iso).slice(0, 10).split("-").map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) return String(iso);

        return new Date(parts[0], parts[1] - 1, parts[2])
            .toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" });
    }

    function showNotice(text, type) {
        noticeEl.textContent = text;
        noticeEl.className = "em-notice is-" + type;
        noticeEl.hidden = false;

        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(function () { noticeEl.hidden = true; }, 4500);
    }

    async function api(action, payload) {
        let res;

        try {
            res = await fetch(API_URL, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-EM-Token": TOKEN
                },
                body: JSON.stringify(Object.assign({ action: action }, payload || {}))
            });
        } catch (err) {
            throw new Error("Could not reach the server. Please try again.");
        }

        let data = null;
        try { data = await res.json(); } catch (err) { /* not JSON */ }

        if (!data) {
            throw new Error("The server sent an unexpected reply. Check the PHP error log.");
        }
        if (!res.ok || data.success === false) {
            throw new Error(data.message || "Something went wrong.");
        }
        return data;
    }

    /* ---------- rendering ---------- */

    function detail(label, value) {
        return '<div class="em-detail">' +
            '<div class="em-detail-label">' + esc(label) + "</div>" +
            '<div class="em-detail-value">' + (value ? esc(value) : "&mdash;") + "</div>" +
            "</div>";
    }

    function avatarHTML(e) {
        const hasPhoto = !!e.profile_picture;

        return '<div class="em-avatar-wrap">' +
            '<div class="em-avatar" aria-hidden="true">' +
                (hasPhoto
                    ? '<img src="' + esc(e.profile_picture) + '" alt="">'
                    : esc(initials(e))) +
            "</div>" +
            '<button type="button" class="em-avatar-edit" data-photo-id="' + esc(e.id) + '"' +
                ' title="' + (hasPhoto ? "Change" : "Add") + ' profile picture"' +
                ' aria-label="' + (hasPhoto ? "Change" : "Add") + " profile picture of " + esc(fullName(e)) + '">' +
                '<i class="bi bi-camera-fill"></i></button>' +
        "</div>";
    }

    function cardHTML(e) {
        const age = calcAge(e.birth_date);
        const birth = e.birth_date
            ? formatDate(e.birth_date) + (age !== "" ? " (" + age + ")" : "")
            : "";

        return '<article class="em-item">' +
            avatarHTML(e) +
            '<div class="em-item-content">' +
                '<div class="em-item-top">' +
                    '<div class="em-item-title">' +
                        '<span class="em-item-name">' + esc(fullName(e)) + "</span>" +
                        '<span class="em-status ' + (e.is_active ? "is-active" : "is-inactive") + '">' +
                            (e.is_active ? "Active" : "Inactive") + "</span>" +
                    "</div>" +
                    '<div class="em-actions">' +
                        '<button type="button" class="em-edit" data-action="edit" data-id="' + e.id + '">' +
                            '<i class="bi bi-pencil-square"></i> Edit</button>' +
                        '<button type="button" class="em-delete" data-action="delete" data-id="' + e.id + '">' +
                            '<i class="bi bi-trash"></i> Delete</button>' +
                    "</div>" +
                "</div>" +
                '<div class="em-details">' +
                    detail("Username", e.username) +
                    detail("Email", e.email) +
                    detail("Phone", e.phone) +
                    detail("Birth Date", birth) +
                    detail("ID Type", e.id_type) +
                    (meta.hasCreatedAt ? detail("Date Joined", formatDate(e.created_at)) : "") +
                    (meta.hasCreatedBy ? detail("Created By", e.created_by) : "") +
                "</div>" +
            "</div>" +
        "</article>";
    }

    function visibleEmployees() {
        const q = query.toLowerCase().trim();

        return employees.filter(function (e) {
            if (filter === "active" && !e.is_active) return false;
            if (filter === "inactive" && e.is_active) return false;
            if (!q) return true;

            return [fullName(e), e.username, e.email, e.phone, e.id_type, e.created_by]
                .join(" ").toLowerCase().includes(q);
        });
    }

    function renderStats() {
        const total = employees.length;
        const active = employees.filter(function (e) { return e.is_active; }).length;
        const now = new Date();
        const ym = now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0");
        const fresh = employees.filter(function (e) {
            return String(e.created_at || "").indexOf(ym) === 0;
        }).length;

        $("emStatTotal").textContent = total;
        $("emStatActive").textContent = active;
        $("emStatInactive").textContent = total - active;
        $("emStatNew").textContent = fresh;
        $("emStatNewCard").hidden = !meta.hasCreatedAt;

        $("emCountAll").textContent = total;
        $("emCountActive").textContent = active;
        $("emCountInactive").textContent = total - active;
    }

    function renderList() {
        const rows = visibleEmployees();
        const titles = { all: "All Employees", active: "Active Employees", inactive: "Inactive Employees" };

        $("emListTitle").textContent = titles[filter];
        $("emListSub").textContent = rows.length + (rows.length === 1 ? " employee" : " employees");

        if (!rows.length) {
            listEl.innerHTML =
                '<div class="em-empty"><i class="bi bi-people"></i>' +
                "<h5>No employees found</h5>" +
                "<p>" + (employees.length
                    ? "Try a different search or filter."
                    : "Employees who sign up, or that you add, will appear here.") + "</p></div>";
            return;
        }

        listEl.innerHTML = rows.map(cardHTML).join("");
    }

    function renderAll() {
        renderStats();
        renderList();
    }

    function renderError(message) {
        $("emListSub").textContent = "Could not load employees";
        listEl.innerHTML =
            '<div class="em-empty"><i class="bi bi-exclamation-triangle"></i>' +
            "<h5>Something went wrong</h5><p>" + esc(message) + "</p>" +
            '<button type="button" class="em-retry" id="emRetry">Try again</button></div>';

        $("emRetry").addEventListener("click", loadEmployees);
    }

    async function loadEmployees() {
        $("emListSub").textContent = "Loading...";

        try {
            const data = await api("list");
            employees = data.employees || [];
            meta = Object.assign(meta, data.meta || {});
            renderAll();
        } catch (err) {
            renderError(err.message);
        }
    }

    /* ---------- tabs + search ---------- */

    tabsEl.addEventListener("click", function (event) {
        const btn = event.target.closest("[data-filter]");
        if (!btn) return;

        tabsEl.querySelectorAll(".em-tab").forEach(function (tab) {
            tab.classList.toggle("active", tab === btn);
        });

        filter = btn.dataset.filter;
        renderList();
    });

    searchEl.addEventListener("input", function () {
        query = searchEl.value;
        renderList();
    });

    /* ---------- profile picture ---------- */

    // Move the window to <body> so the layout can't clip it or hide it behind the backdrop.
    // Copies left over from an earlier load of the page are removed first.
    const photoCopies = Array.prototype.slice.call(document.querySelectorAll("#emPhotoModal"));
    const photoModalEl = photoCopies.find(function (n) { return n.parentElement !== document.body; })
        || photoCopies[photoCopies.length - 1];
    photoCopies.forEach(function (n) { if (n !== photoModalEl) n.remove(); });
    if (photoModalEl.parentElement !== document.body) document.body.appendChild(photoModalEl);

    const photoPreviewEl = photoModalEl.querySelector("#emPhotoPreview");
    const photoNameEl = photoModalEl.querySelector("#emPhotoName");
    const photoFileEl = photoModalEl.querySelector("#emPhotoFile");
    const photoChooseBtn = photoModalEl.querySelector("#emPhotoChoose");
    const photoRemoveBtn = photoModalEl.querySelector("#emPhotoRemove");
    const photoSaveBtn = photoModalEl.querySelector("#emPhotoSave");
    const photoErrorEl = photoModalEl.querySelector("#emPhotoError");

    let photoEmployee = null;   // the employee being edited
    let photoNew;               // undefined = no change, "" = remove, "data:..." = new photo

    function currentPhoto() {
        if (photoNew !== undefined) return photoNew;
        return (photoEmployee && photoEmployee.profile_picture) || "";
    }

    function drawPhotoPreview() {
        const src = currentPhoto();

        photoPreviewEl.innerHTML = src
            ? '<img src="' + esc(src) + '" alt="Profile picture preview">'
            : "<span>" + esc(initials(photoEmployee)) + "</span>";

        photoRemoveBtn.hidden = !src;

        const original = (photoEmployee && photoEmployee.profile_picture) || "";
        photoSaveBtn.disabled = photoNew === undefined || photoNew === original;
    }

    function showPhotoError(text) {
        photoErrorEl.textContent = text;
        photoErrorEl.hidden = !text;
    }

    // Crops the picture to a square and shrinks it, so it stays small in the database.
    function resizeImage(file) {
        return new Promise(function (resolve, reject) {
            const url = URL.createObjectURL(file);
            const img = new Image();

            img.onload = function () {
                const size = 256;
                const side = Math.min(img.width, img.height);
                const sx = (img.width - side) / 2;
                const sy = (img.height - side) / 2;

                const canvas = document.createElement("canvas");
                canvas.width = size;
                canvas.height = size;

                const ctx = canvas.getContext("2d");
                ctx.fillStyle = "#ffffff";
                ctx.fillRect(0, 0, size, size);
                ctx.drawImage(img, sx, sy, side, side, 0, 0, size, size);

                URL.revokeObjectURL(url);
                resolve(canvas.toDataURL("image/jpeg", 0.85));
            };

            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error("That file could not be read as an image."));
            };

            img.src = url;
        });
    }

    function openPhotoModal(emp) {
        if (typeof bootstrap === "undefined") {
            showNotice("The page is still loading. Please try again in a moment.", "error");
            return;
        }

        photoEmployee = emp;
        photoNew = undefined;
        photoFileEl.value = "";
        photoNameEl.textContent = fullName(emp);
        photoSaveBtn.textContent = "Save photo";
        showPhotoError("");
        drawPhotoPreview();

        bootstrap.Modal.getOrCreateInstance(photoModalEl).show();
    }

    listEl.addEventListener("click", function (event) {
        const btn = event.target.closest("[data-photo-id]");
        if (!btn) return;

        const emp = employees.find(function (x) { return String(x.id) === btn.dataset.photoId; });
        if (emp) openPhotoModal(emp);
    });

    photoChooseBtn.addEventListener("click", function () { photoFileEl.click(); });

    photoFileEl.addEventListener("change", async function () {
        const file = photoFileEl.files[0];
        if (!file) return;

        showPhotoError("");

        if (!file.type.startsWith("image/")) {
            showPhotoError("Please choose an image file (JPG, PNG or WebP).");
            return;
        }
        if (file.size > 8 * 1024 * 1024) {
            showPhotoError("That image is too large. Please choose one under 8 MB.");
            return;
        }

        try {
            photoNew = await resizeImage(file);
            drawPhotoPreview();
        } catch (err) {
            showPhotoError(err.message);
        }
    });

    photoRemoveBtn.addEventListener("click", function () {
        photoNew = "";
        photoFileEl.value = "";
        showPhotoError("");
        drawPhotoPreview();
    });

    photoSaveBtn.addEventListener("click", async function () {
        if (!photoEmployee || photoNew === undefined) return;

        photoSaveBtn.disabled = true;
        photoSaveBtn.textContent = "Saving...";
        showPhotoError("");

        try {
            await api("update_photo", { id: photoEmployee.id, photo: photoNew });

            photoEmployee.profile_picture = photoNew || null;
            renderAll();

            bootstrap.Modal.getOrCreateInstance(photoModalEl).hide();
            showNotice(
                photoNew ? "Profile picture updated." : "Profile picture removed.",
                "success"
            );
        } catch (err) {
            showPhotoError(err.message);
            photoSaveBtn.disabled = false;
        }

        photoSaveBtn.textContent = "Save photo";
    });

    /* ---------- bridge to em_content/edit_delete.php ---------- */
    // The Add / Edit / Delete modals live in em_content/edit_delete.php.
    // That file uses this small object to read data and report back.

    window.EM = {
        api: api,
        getEmployee: function (id) {
            return employees.find(function (x) { return x.id === id; }) || null;
        },
        getMeta: function () { return meta; },
        reload: loadEmployees,
        replaceEmployee: function (emp) {
            const i = employees.findIndex(function (x) { return x.id === emp.id; });
            if (i !== -1) employees[i] = emp;
            renderAll();
        },
        removeEmployee: function (id) {
            employees = employees.filter(function (x) { return x.id !== id; });
            renderAll();
        },
        notice: showNotice,
        fullName: fullName,
        calcAge: calcAge,
        esc: esc,
        initials: initials,
        resizeImage: resizeImage
    };

    /* ---------- start ---------- */

    if (!TOKEN) {
        renderError("A secure session could not be started. Reload the page or sign in again.");
    } else {
        loadEmployees();
    }
})();
</script>

<?php require __DIR__ . '/em_content/edit_delete.php'; ?>