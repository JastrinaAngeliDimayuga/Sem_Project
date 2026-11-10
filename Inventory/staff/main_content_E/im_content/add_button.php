<?php
// staff/main_content_E/im_content/add_button.php
// Fragment only: no <html>, <head>, or <body>.
?>

<div class="add-buttons">

    <div class="ingredients-add-btn">
        <button type="button" class="add-btn add-ingredient-btn" onclick="openForm()"
            title="Add ingredient" aria-label="Add ingredient">
            <i class="bi bi-plus-lg"></i>
            <span>📋</span>
        </button>
    </div>

    <div class="cookies-add-btn">
        <button type="button" class="add-btn add-cookie-btn" onclick="openStaffForm()"
            title="Add cookie" aria-label="Add cookie">
            <i class="bi bi-plus-lg"></i>
            <span>🍪</span>
        </button>
    </div>

    <div class="supplies-add-btn">
        <button type="button" class="add-btn add-supplies-btn" onclick="openSuppliesForm()"
            title="Add supplies" aria-label="Add supplies">
            <i class="bi bi-plus-lg"></i>
            <span>📦</span>
        </button>
    </div>

</div>