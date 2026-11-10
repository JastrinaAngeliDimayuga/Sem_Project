<?php
// admin/em_content/edit_delete.php
// The Add Employee, Edit Employee and Delete Employee modals (markup + scripts).
// Included at the bottom of admin/employees.php. It talks to the database only
// through window.EM (set up by employees.php) -> employees_api.php.
?>

<style>
    /* ---------- profile picture block (Add + Edit modals) ---------- */
    .em-modal .em-photo {
        display: flex;
        align-items: center;
        gap: 18px;
        margin-bottom: 18px;
        padding: 14px 16px;
        background: #fff;
        border: 1px solid #e8dccf;
        border-radius: 14px;
    }

    .em-modal .em-photo-preview {
        display: flex;
        flex: 0 0 88px;
        align-items: center;
        justify-content: center;
        width: 88px;
        height: 88px;
        overflow: hidden;
        background: linear-gradient(135deg, #efe2d6, #e4cfbd);
        border: 3px solid #fff;
        border-radius: 50%;
        box-shadow: 0 3px 10px rgba(80, 52, 36, 0.2);
        color: #6f4e3d;
        font-size: 1.9rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .em-modal .em-photo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .em-modal .em-photo-side {
        min-width: 0;
    }

    .em-modal .em-photo-side .form-label {
        display: block;
        margin-bottom: 6px;
    }

    .em-modal .em-photo-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 6px;
    }

    .em-modal .em-photo-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 36px;
        padding: 6px 14px;
        background: #efe2d6;
        border: 1px solid #d6bba6;
        border-radius: 10px;
        color: #6f4e3d;
        font-family: inherit;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .em-modal .em-photo-btn:hover {
        background: #e4d2c2;
    }

    .em-modal .em-photo-btn[hidden] {
        display: none;
    }

    .em-modal .em-photo-btn-danger {
        background: #fde9eb;
        border-color: #f3b9bd;
        color: #b3261e;
    }

    .em-modal .em-photo-btn-danger:hover {
        background: #f8d0d4;
    }

    .em-modal .em-photo .em-hint {
        margin: 0;
    }

    @media (max-width: 520px) {
        .em-modal .em-photo {
            flex-direction: column;
            text-align: center;
        }

        .em-modal .em-photo-actions {
            justify-content: center;
        }
    }
</style>

<!-- =====================================================
     ADD EMPLOYEE MODAL
===================================================== -->
<div class="modal fade em-modal" id="employeeCreateModal" tabindex="-1"
    aria-labelledby="employeeCreateTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Staff Management</span>
                    <h5 class="modal-title" id="employeeCreateTitle">Add Employee</h5>
                </div>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <div class="modal-body">
                <form id="employeeCreateForm" novalidate>

                    <!-- Profile picture -->
                    <div class="em-photo">
                        <div class="em-photo-preview" id="emNewPicPreview"></div>

                        <div class="em-photo-side">
                            <span class="form-label">
                                Profile Picture
                                <span class="em-optional">Optional</span>
                            </span>

                            <div class="em-photo-actions">
                                <button type="button" class="em-photo-btn" id="emNewPicChoose">
                                    <i class="bi bi-cloud-upload"></i> Choose photo
                                </button>
                                <button type="button" class="em-photo-btn em-photo-btn-danger"
                                    id="emNewPicRemove" hidden>
                                    <i class="bi bi-trash"></i> Remove
                                </button>
                            </div>

                            <p class="em-hint">JPG, PNG or WebP. The photo is cropped to a square.</p>
                            <input type="file" id="emNewPicFile" accept="image/*" hidden>
                        </div>
                    </div>

                    <!-- 1. User Information -->
                    <div class="em-section">
                        <div class="em-section-head">
                            <span class="em-step">1</span>
                            <h6 class="em-section-title">User Information</h6>
                        </div>

                        <div class="em-fields">
                            <div class="em-field">
                                <label for="emNewUsername" class="form-label">Username</label>
                                <input type="text" id="emNewUsername" class="form-control"
                                    placeholder="Create Username" autocomplete="off" required>
                            </div>

                            <div class="em-field">
                                <label for="emNewEmail" class="form-label">Email</label>
                                <input type="email" id="emNewEmail" class="form-control"
                                    placeholder="Email" autocomplete="off" required>
                            </div>

                            <div class="em-field">
                                <label for="emNewPassword" class="form-label">Password</label>
                                <input type="password" id="emNewPassword" class="form-control"
                                    placeholder="Create Password" autocomplete="new-password"
                                    minlength="8" required>
                            </div>

                            <div class="em-field">
                                <label for="emNewConfirm" class="form-label">Confirm Password</label>
                                <input type="password" id="emNewConfirm" class="form-control"
                                    placeholder="Confirm Password" autocomplete="new-password" required>
                            </div>

                            <div class="em-field" id="emNewStatusRow">
                                <label for="emNewStatus" class="form-label">Account Status</label>
                                <select id="emNewStatus" class="form-select">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>

                            <div class="em-field" id="emNewCreatedByRow">
                                <label for="emNewCreatedBy" class="form-label">
                                    Created By
                                    <span class="em-auto">Auto</span>
                                </label>
                                <input type="text" id="emNewCreatedBy" class="form-control"
                                    readonly tabindex="-1">
                            </div>
                        </div>
                    </div>

                    <!-- 2. Personal Information -->
                    <div class="em-section">
                        <div class="em-section-head">
                            <span class="em-step">2</span>
                            <h6 class="em-section-title">Personal Information</h6>
                        </div>

                        <div class="em-fields">
                            <div class="em-field">
                                <label for="emNewFirst" class="form-label">First Name</label>
                                <input type="text" id="emNewFirst" class="form-control"
                                    placeholder="First Name" required>
                            </div>

                            <div class="em-field">
                                <label for="emNewMiddle" class="form-label">Middle Name</label>
                                <input type="text" id="emNewMiddle" class="form-control"
                                    placeholder="Middle Name">
                            </div>

                            <div class="em-field">
                                <label for="emNewLast" class="form-label">Last Name</label>
                                <input type="text" id="emNewLast" class="form-control"
                                    placeholder="Last Name" required>
                            </div>

                            <div class="em-field">
                                <label for="emNewExt" class="form-label">Extension Name</label>
                                <input type="text" id="emNewExt" class="form-control"
                                    placeholder="Extension Name">
                            </div>

                            <div class="em-field">
                                <label for="emNewBirth" class="form-label">Birth Date</label>
                                <input type="date" id="emNewBirth" class="form-control" required>
                            </div>

                            <div class="em-field">
                                <label for="emNewAge" class="form-label">Age</label>
                                <input type="text" id="emNewAge" class="form-control"
                                    placeholder="Age" readonly tabindex="-1">
                            </div>

                            <div class="em-field full">
                                <label for="emNewPhone" class="form-label">Phone Number</label>
                                <input type="tel" id="emNewPhone" class="form-control"
                                    placeholder="Phone Number" required>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Valid ID -->
                    <div class="em-section">
                        <div class="em-section-head">
                            <span class="em-step">3</span>
                            <h6 class="em-section-title">Valid ID</h6>
                        </div>

                        <p class="em-hint">
                            The Valid ID upload and face verification are done by the employee on the
                            sign-up page. Accounts added here are created without them.
                        </p>
                    </div>

                    <p class="em-form-message" id="emCreateMessage" role="alert"></p>

                    <div class="em-modal-actions">
                        <button type="button" class="em-btn em-btn-cancel"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="em-btn em-btn-save"
                            id="emCreateBtn">Create Account</button>
                    </div>

                </form>
            </div>

        </div>
    </div>
</div>

<!-- =====================================================
     EDIT EMPLOYEE MODAL
===================================================== -->
<div class="modal fade em-modal" id="employeeModal" tabindex="-1"
    aria-labelledby="employeeModalTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Staff Management</span>
                    <h5 class="modal-title" id="employeeModalTitle">Edit Employee</h5>
                </div>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <div class="modal-body">
                <form id="employeeForm" novalidate>

                    <!-- Profile picture -->
                    <div class="em-photo">
                        <div class="em-photo-preview" id="emEditPicPreview"></div>

                        <div class="em-photo-side">
                            <span class="form-label">Profile Picture</span>

                            <div class="em-photo-actions">
                                <button type="button" class="em-photo-btn" id="emEditPicChoose">
                                    <i class="bi bi-cloud-upload"></i> Change photo
                                </button>
                                <button type="button" class="em-photo-btn em-photo-btn-danger"
                                    id="emEditPicRemove" hidden>
                                    <i class="bi bi-trash"></i> Remove
                                </button>
                            </div>

                            <p class="em-hint">JPG, PNG or WebP. The photo is cropped to a square.</p>
                            <input type="file" id="emEditPicFile" accept="image/*" hidden>
                        </div>
                    </div>

                    <!-- 1. User Information -->
                    <div class="em-section">
                        <div class="em-section-head">
                            <span class="em-step">1</span>
                            <h6 class="em-section-title">User Information</h6>
                        </div>

                        <div class="em-fields">
                            <div class="em-field">
                                <label for="emUsername" class="form-label">Username</label>
                                <input type="text" id="emUsername" class="form-control"
                                    placeholder="Username" autocomplete="off" required>
                            </div>

                            <div class="em-field">
                                <label for="emEmail" class="form-label">Email</label>
                                <input type="email" id="emEmail" class="form-control"
                                    placeholder="Email" autocomplete="off" required>
                            </div>

                            <div class="em-field">
                                <label for="emPassword" class="form-label">
                                    New Password
                                    <span class="em-optional">Optional</span>
                                </label>
                                <input type="password" id="emPassword" class="form-control"
                                    placeholder="Leave blank to keep the current password"
                                    autocomplete="new-password">
                            </div>

                            <div class="em-field">
                                <label for="emConfirm" class="form-label">Confirm Password</label>
                                <input type="password" id="emConfirm" class="form-control"
                                    placeholder="Confirm Password" autocomplete="new-password">
                            </div>

                            <div class="em-field" id="emStatusRow">
                                <label for="emStatus" class="form-label">Account Status</label>
                                <select id="emStatus" class="form-select">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>

                            <div class="em-field" id="emCreatedByRow">
                                <label for="emCreatedBy" class="form-label">Created By</label>
                                <input type="text" id="emCreatedBy" class="form-control"
                                    readonly tabindex="-1">
                            </div>

                            <p class="em-hint em-field full" id="emStatusHint" hidden>
                                Your users table has no usable status column yet, so every
                                employee is shown as Active. Add an is_active column to turn this on.
                            </p>
                        </div>
                    </div>

                    <!-- 2. Personal Information -->
                    <div class="em-section">
                        <div class="em-section-head">
                            <span class="em-step">2</span>
                            <h6 class="em-section-title">Personal Information</h6>
                        </div>

                        <div class="em-fields">
                            <div class="em-field">
                                <label for="emFirst" class="form-label">First Name</label>
                                <input type="text" id="emFirst" class="form-control"
                                    placeholder="First Name" required>
                            </div>

                            <div class="em-field">
                                <label for="emMiddle" class="form-label">Middle Name</label>
                                <input type="text" id="emMiddle" class="form-control"
                                    placeholder="Middle Name">
                            </div>

                            <div class="em-field">
                                <label for="emLast" class="form-label">Last Name</label>
                                <input type="text" id="emLast" class="form-control"
                                    placeholder="Last Name" required>
                            </div>

                            <div class="em-field">
                                <label for="emExt" class="form-label">Extension Name</label>
                                <input type="text" id="emExt" class="form-control"
                                    placeholder="Extension Name">
                            </div>

                            <div class="em-field">
                                <label for="emBirth" class="form-label">Birth Date</label>
                                <input type="date" id="emBirth" class="form-control" required>
                            </div>

                            <div class="em-field">
                                <label for="emAge" class="form-label">Age</label>
                                <input type="text" id="emAge" class="form-control"
                                    placeholder="Age" readonly tabindex="-1">
                            </div>

                            <div class="em-field full">
                                <label for="emPhone" class="form-label">Phone Number</label>
                                <input type="tel" id="emPhone" class="form-control"
                                    placeholder="Phone Number" required>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Valid ID -->
                    <div class="em-section">
                        <div class="em-section-head">
                            <span class="em-step">3</span>
                            <h6 class="em-section-title">Valid ID</h6>
                        </div>

                        <div class="em-fields">
                            <div class="em-field full">
                                <label for="emIdType" class="form-label">ID Type</label>
                                <input type="text" id="emIdType" class="form-control"
                                    readonly tabindex="-1">
                                <p class="em-hint">
                                    The ID and face verification are done at sign-up and cannot be changed here.
                                </p>
                            </div>
                        </div>
                    </div>

                    <p class="em-form-message" id="emFormMessage" role="alert"></p>

                    <div class="em-modal-actions">
                        <button type="button" class="em-btn em-btn-cancel"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="em-btn em-btn-save"
                            id="emSaveBtn">Save Changes</button>
                    </div>

                </form>
            </div>

        </div>
    </div>
</div>

<!-- =====================================================
     DELETE EMPLOYEE MODAL
===================================================== -->
<div class="modal fade em-modal" id="employeeDeleteModal" tabindex="-1"
    aria-labelledby="employeeDeleteTitle" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Staff Management</span>
                    <h5 class="modal-title" id="employeeDeleteTitle">Delete Employee</h5>
                </div>

                <button type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>

            <div class="modal-body">
                <div class="em-delete-body">
                    <div class="em-delete-icon"><i class="bi bi-trash"></i></div>
                    <p>
                        Are you sure you want to delete
                        <strong id="emDeleteName">this employee</strong>?
                        Their account, profile and uploaded ID photos will be removed.
                        This cannot be undone.
                    </p>
                    <p class="em-form-message" id="emDeleteMessage" role="alert"></p>
                </div>

                <div class="em-modal-actions">
                    <button type="button" class="em-btn em-btn-cancel"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="em-btn em-btn-danger"
                        id="emDeleteConfirm">Delete</button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    "use strict";

    const EM = window.EM;
    if (!EM) return;   // employees.php did not load; nothing to attach to

    const BOOTSTRAP_URL =
        "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js";

    const $ = function (id) { return document.getElementById(id); };

    // Move the modals to <body> so the admin layout's containers can't clip them
    // or hide them behind the backdrop. Copies left over from an earlier load of
    // this page are removed first (the fresh copy is the one not yet in <body>).
    ["employeeCreateModal", "employeeModal", "employeeDeleteModal"].forEach(function (id) {
        const copies = Array.prototype.slice.call(document.querySelectorAll("#" + id));
        if (!copies.length) return;

        const fresh = copies.find(function (n) { return n.parentElement !== document.body; })
            || copies[copies.length - 1];

        copies.forEach(function (n) { if (n !== fresh) n.remove(); });
        if (fresh.parentElement !== document.body) document.body.appendChild(fresh);
    });

    // ----- edit modal -----
    const f = {
        username: $("emUsername"),
        email: $("emEmail"),
        password: $("emPassword"),
        confirm: $("emConfirm"),
        status: $("emStatus"),
        createdBy: $("emCreatedBy"),
        first: $("emFirst"),
        middle: $("emMiddle"),
        last: $("emLast"),
        ext: $("emExt"),
        birth: $("emBirth"),
        age: $("emAge"),
        phone: $("emPhone"),
        idType: $("emIdType")
    };

    const form = $("employeeForm");
    const formMessage = $("emFormMessage");
    const saveBtn = $("emSaveBtn");
    const statusHint = $("emStatusHint");
    const editModalEl = $("employeeModal");

    // ----- add modal -----
    const c = {
        username: $("emNewUsername"),
        email: $("emNewEmail"),
        password: $("emNewPassword"),
        confirm: $("emNewConfirm"),
        status: $("emNewStatus"),
        createdBy: $("emNewCreatedBy"),
        first: $("emNewFirst"),
        middle: $("emNewMiddle"),
        last: $("emNewLast"),
        ext: $("emNewExt"),
        birth: $("emNewBirth"),
        age: $("emNewAge"),
        phone: $("emNewPhone")
    };

    const createForm = $("employeeCreateForm");
    const createMessage = $("emCreateMessage");
    const createBtn = $("emCreateBtn");
    const createModalEl = $("employeeCreateModal");

    // ----- delete modal -----
    const deleteModalEl = $("employeeDeleteModal");
    const deleteConfirm = $("emDeleteConfirm");
    const deleteMessage = $("emDeleteMessage");

    let editing = null;
    let deleting = null;

    /* ---------- helpers ---------- */

    // Bootstrap is normally loaded by the admin layout; load it only if it is missing.
    function loadBootstrap() {
        if (window.bootstrap) return Promise.resolve();

        return new Promise(function (resolve, reject) {
            const s = document.createElement("script");
            s.src = BOOTSTRAP_URL;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    function getModal(el) {
        return loadBootstrap().then(function () {
            return window.bootstrap.Modal.getOrCreateInstance(el);
        });
    }

    // Shared form checks for the add and edit modals. Returns true when valid.
    function checkProfile(x, setMessage) {
        const required = [x.username, x.email, x.first, x.last, x.birth, x.phone];

        for (const field of required) {
            if (!field.value.trim()) {
                setMessage("Please fill in all required fields.");
                field.focus();
                return false;
            }
        }

        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x.email.value.trim())) {
            setMessage("Please enter a valid email address.");
            x.email.focus();
            return false;
        }

        return true;
    }

    function checkPasswords(x, setMessage, mustHave) {
        const pw = x.password.value;
        const confirm = x.confirm.value;

        if (!mustHave && pw === "" && confirm === "") return true;

        if (pw.length < 8) {
            setMessage("Password must be at least 8 characters.");
            x.password.focus();
            return false;
        }
        if (pw !== confirm) {
            setMessage("Passwords do not match.");
            x.confirm.focus();
            return false;
        }
        return true;
    }

    /* ---------- profile picture picker (used by the add and edit modals) ---------- */
    // value: undefined = not touched, "" = removed, "data:image/..." = new photo

    function photoPicker(cfg) {
        let original = "";
        let value;

        function current() {
            return value !== undefined ? value : original;
        }

        function draw() {
            const src = current();

            cfg.preview.innerHTML = src
                ? '<img src="' + EM.esc(src) + '" alt="Profile picture preview">'
                : cfg.placeholder();

            cfg.remove.hidden = !src;
        }

        cfg.choose.addEventListener("click", function () { cfg.file.click(); });

        cfg.file.addEventListener("change", async function () {
            const file = cfg.file.files[0];
            if (!file) return;

            cfg.onError("");

            if (!file.type.startsWith("image/")) {
                cfg.onError("Please choose an image file (JPG, PNG or WebP).");
                return;
            }
            if (file.size > 8 * 1024 * 1024) {
                cfg.onError("That image is too large. Please choose one under 8 MB.");
                return;
            }

            try {
                value = await EM.resizeImage(file);
                draw();
            } catch (err) {
                cfg.onError(err.message);
            }
        });

        cfg.remove.addEventListener("click", function () {
            value = "";
            cfg.file.value = "";
            cfg.onError("");
            draw();
        });

        return {
            reset: function (photo) {
                original = photo || "";
                value = undefined;
                cfg.file.value = "";
                draw();
            },
            changed: function () {
                return value !== undefined && value !== original;
            },
            value: function () {
                return current();
            }
        };
    }

    const createPic = photoPicker({
        preview: $("emNewPicPreview"),
        file: $("emNewPicFile"),
        choose: $("emNewPicChoose"),
        remove: $("emNewPicRemove"),
        placeholder: function () { return '<i class="bi bi-person"></i>'; },
        onError: function (text) { createMessage.textContent = text; }
    });

    const editPic = photoPicker({
        preview: $("emEditPicPreview"),
        file: $("emEditPicFile"),
        choose: $("emEditPicChoose"),
        remove: $("emEditPicRemove"),
        placeholder: function () {
            return editing ? "<span>" + EM.esc(EM.initials(editing)) + "</span>" : '<i class="bi bi-person"></i>';
        },
        onError: function (text) { formMessage.textContent = text; }
    });

    /* ---------- add employee modal ---------- */

    const todayMax = new Date().toISOString().split("T")[0];
    c.birth.max = todayMax;
    f.birth.max = todayMax;

    c.birth.addEventListener("change", function () {
        c.age.value = EM.calcAge(c.birth.value);
    });

    f.birth.addEventListener("change", function () {
        f.age.value = EM.calcAge(f.birth.value);
    });

    $("emAddBtn").addEventListener("click", function () {
        const meta = EM.getMeta();

        createForm.reset();
        createPic.reset("");
        c.age.value = "";
        c.status.value = "1";
        c.createdBy.value = meta.currentUser || "Admin";

        // these two fields only show when the database supports them
        $("emNewStatusRow").hidden = !meta.statusEditable;
        $("emNewCreatedByRow").hidden = !meta.hasCreatedBy;

        createMessage.textContent = "";
        createBtn.disabled = false;
        createBtn.textContent = "Create Account";

        getModal(createModalEl).then(function (m) { m.show(); });
    });

    createForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const setMessage = function (text) { createMessage.textContent = text; };
        setMessage("");

        if (!checkProfile(c, setMessage)) return;
        if (!checkPasswords(c, setMessage, true)) return;

        createBtn.disabled = true;
        createBtn.textContent = "Creating...";

        try {
            await EM.api("create", {
                username: c.username.value.trim(),
                email: c.email.value.trim(),
                newPassword: c.password.value,
                confirmPassword: c.confirm.value,
                isActive: c.status.value === "1",
                firstName: c.first.value.trim(),
                middleName: c.middle.value.trim(),
                lastName: c.last.value.trim(),
                extension: c.ext.value.trim(),
                birthDate: c.birth.value,
                phone: c.phone.value.trim(),
                photo: createPic.changed() ? createPic.value() : ""
            });

            // reload so the new account appears in its sorted place and the stats update
            await EM.reload();

            getModal(createModalEl).then(function (m) { m.hide(); });
            EM.notice("Employee account created successfully.", "success");
        } catch (err) {
            setMessage(err.message);
            createBtn.disabled = false;
            createBtn.textContent = "Create Account";
        }
    });

    /* ---------- edit modal ---------- */

    function openEdit(id) {
        const e = EM.getEmployee(id);
        if (!e) return;

        const meta = EM.getMeta();
        editing = e;

        f.username.value = e.username || "";
        f.email.value = e.email || "";
        f.password.value = "";
        f.confirm.value = "";
        f.status.value = e.is_active ? "1" : "0";
        f.createdBy.value = e.created_by || "Not recorded";

        // these fields only show when the database supports them
        $("emStatusRow").hidden = !meta.statusEditable;
        $("emCreatedByRow").hidden = !meta.hasCreatedBy;
        statusHint.hidden = meta.statusEditable;

        f.first.value = e.first_name || "";
        f.middle.value = e.middle_name || "";
        f.last.value = e.last_name || "";
        f.ext.value = e.extension_name || "";
        f.birth.value = e.birth_date || "";
        f.age.value = EM.calcAge(e.birth_date);
        f.phone.value = e.phone || "";
        f.idType.value = e.id_type || "Not recorded";

        editPic.reset(e.profile_picture || "");

        formMessage.textContent = "";
        saveBtn.disabled = false;
        saveBtn.textContent = "Save Changes";
        $("employeeModalTitle").textContent = "Edit " + EM.fullName(e);

        getModal(editModalEl).then(function (m) { m.show(); });
    }

    form.addEventListener("submit", async function (event) {
        event.preventDefault();
        if (!editing) return;

        const setMessage = function (text) { formMessage.textContent = text; };
        setMessage("");

        if (!checkProfile(f, setMessage)) return;
        if (!checkPasswords(f, setMessage, false)) return;

        const target = editing;

        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";

        try {
            const data = await EM.api("update", {
                id: target.id,
                username: f.username.value.trim(),
                email: f.email.value.trim(),
                newPassword: f.password.value,
                confirmPassword: f.confirm.value,
                isActive: f.status.value === "1",
                firstName: f.first.value.trim(),
                middleName: f.middle.value.trim(),
                lastName: f.last.value.trim(),
                extension: f.ext.value.trim(),
                birthDate: f.birth.value,
                phone: f.phone.value.trim()
            });

            // keep the current picture if the reply does not include one
            const updated = Object.assign({}, target, data.employee || {});

            // the profile picture is saved with its own request, only when it changed
            let photoError = "";

            if (editPic.changed()) {
                try {
                    await EM.api("update_photo", { id: target.id, photo: editPic.value() });
                    updated.profile_picture = editPic.value() || null;
                } catch (err) {
                    photoError = err.message;
                }
            }

            EM.replaceEmployee(updated);
            getModal(editModalEl).then(function (m) { m.hide(); });

            if (photoError) {
                EM.notice("Employee updated, but the profile picture could not be saved: " + photoError, "error");
            } else {
                EM.notice("Employee updated successfully.", "success");
            }

            editing = null;
        } catch (err) {
            setMessage(err.message);
            saveBtn.disabled = false;
            saveBtn.textContent = "Save Changes";
        }
    });

    /* ---------- delete modal ---------- */

    function openDelete(id) {
        const e = EM.getEmployee(id);
        if (!e) return;

        deleting = e;
        $("emDeleteName").textContent = EM.fullName(e);
        deleteMessage.textContent = "";
        deleteConfirm.disabled = false;
        deleteConfirm.textContent = "Delete";

        getModal(deleteModalEl).then(function (m) { m.show(); });
    }

    deleteConfirm.addEventListener("click", async function () {
        if (!deleting) return;

        deleteConfirm.disabled = true;
        deleteConfirm.textContent = "Deleting...";
        deleteMessage.textContent = "";

        try {
            await EM.api("delete", { id: deleting.id });

            EM.removeEmployee(deleting.id);
            getModal(deleteModalEl).then(function (m) { m.hide(); });
            EM.notice("Employee deleted successfully.", "success");
            deleting = null;
        } catch (err) {
            deleteMessage.textContent = err.message;
            deleteConfirm.disabled = false;
            deleteConfirm.textContent = "Delete";
        }
    });

    /* ---------- Edit / Delete buttons on the employee cards ---------- */

    $("employeeList").addEventListener("click", function (event) {
        const btn = event.target.closest("[data-action]");
        if (!btn) return;

        const id = Number(btn.dataset.id);

        if (btn.dataset.action === "edit") openEdit(id);
        if (btn.dataset.action === "delete") openDelete(id);
    });
})();
</script>