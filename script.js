document.addEventListener("DOMContentLoaded", function () {
    var PRODUCT_PRICE = 1000;
    var STOCK_UNAVAILABLE_MESSAGE = "Maaf, saat ini stok belum tersedia.";
    var moodDataElement = document.getElementById("raiceMoodData");
    var MOOD_PRODUCTS = {};

    if (moodDataElement) {
        try {
            MOOD_PRODUCTS = JSON.parse(moodDataElement.textContent || "{}");
        } catch (error) {
            MOOD_PRODUCTS = {};
        }
    }

    var moodButtons = document.querySelectorAll(".mood-button");
    var moodResult = document.getElementById("recommendation-result");
    var moodSearchButton = document.getElementById("moodSearchBtn");
    var moodLoading = document.getElementById("moodLoading");
    var orderQuantityInputs = document.querySelectorAll(".quantity-input");
    var orderProductButtons = document.querySelectorAll("[data-order-product]");
    var orderForm = document.getElementById("orderForm");
    var orderSummary = document.getElementById("orderSummary");
    var navLinks = document.querySelectorAll(".raice-navbar .nav-link");
    var mainNavbar = document.getElementById("mainNavbar");
    var minusButtons = document.querySelectorAll(".minus-btn");
    var plusButtons = document.querySelectorAll(".plus-btn");
    var confirmForms = document.querySelectorAll("[data-confirm-message]");

    var selectedMood = "";
    var moodTimer = null;

    function removeClass(elements, className) {
        elements.forEach(function (element) {
            element.classList.remove(className);
        });
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function formatRupiah(value) {
        return "Rp" + Number(value || 0).toLocaleString("id-ID");
    }

    function getOrderQuantityInput(productId) {
        var matchedInput = null;

        orderQuantityInputs.forEach(function (input) {
            if (input.dataset.productId === String(productId)) {
                matchedInput = input;
            }
        });

        return matchedInput;
    }

    function showOrderSummary(message) {
        if (!orderSummary || !message) {
            return;
        }

        orderSummary.classList.remove("d-none");
        orderSummary.textContent = message;
    }

    function getStockUnavailableMessage(input) {
        var productName = input && input.dataset.productName ? input.dataset.productName : "";

        if (productName) {
            return "Maaf, saat ini stok " + productName + " belum tersedia.";
        }

        return STOCK_UNAVAILABLE_MESSAGE;
    }

    function isInputOutOfStock(input) {
        if (!input) {
            return false;
        }

        var maxValue = parseInt(input.dataset.max, 10);
        return !isNaN(maxValue) && maxValue <= 0;
    }

    function clampQuantityInput(input, value) {
        if (!input) {
            return;
        }

        var minValue = parseInt(input.dataset.min, 10) || 0;
        var maxRawValue = input.dataset.max;
        var hasMaxValue = maxRawValue !== undefined && maxRawValue !== "";
        var maxValue = parseInt(maxRawValue, 10);
        var nextValue = parseInt(value, 10);

        if (isNaN(nextValue)) {
            nextValue = minValue;
        }

        nextValue = Math.max(minValue, nextValue);

        if (hasMaxValue && !isNaN(maxValue)) {
            nextValue = Math.min(nextValue, maxValue);
        }

        input.value = String(nextValue);
    }

    function selectOrderProduct(productId) {
        var input = getOrderQuantityInput(productId);

        if (isInputOutOfStock(input)) {
            clampQuantityInput(input, 0);
            showOrderSummary(getStockUnavailableMessage(input));
            return;
        }

        if (input && !input.disabled) {
            var currentValue = parseInt(input.value, 10) || 0;
            clampQuantityInput(input, currentValue + 1);
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.focus();
        }
    }

    function resetMoodResult() {
        if (moodResult) {
            moodResult.classList.add("d-none");
            moodResult.innerHTML = "";
        }

        if (moodLoading) {
            moodLoading.classList.add("d-none");
        }
    }

    function renderMoodResult(moodKey) {
        var moodGroup = MOOD_PRODUCTS[moodKey];
        var items = moodGroup && Array.isArray(moodGroup.items) ? moodGroup.items : [];

        if (!items.length || !moodResult) {
            return;
        }

        var moodName = moodGroup.mood_name || items[0].mood_name || moodKey;
        var rows = items.map(function (item, index) {
            var productId = item.product_id || "";
            var image = escapeHtml(item.image || "");
            var name = escapeHtml(item.name || "RA-ICE");
            var label = escapeHtml(item.mood_label || moodName);
            var reason = escapeHtml(item.mood_reason || "");
            var color = escapeHtml(item.color || "#F2619C");
            var price = formatRupiah(item.price || PRODUCT_PRICE);
            var borderClass = index > 0 ? " pt-4 mt-4 border-top" : "";

            return (
                '<div class="row align-items-center mood-result-row' + borderClass + '">' +
                    '<div class="col-md-5">' +
                        '<img class="mood-result-image" src="' + image + '" alt="' + name + '">' +
                    '</div>' +
                    '<div class="col-md-7">' +
                        '<span class="mood-tag" style="background-color: ' + color + ';">' + label + '</span>' +
                        '<p class="mood-result-copy mb-2 mt-3">Mood dipilih: <strong>' + escapeHtml(moodName) + '</strong></p>' +
                        '<h3 class="mood-result-title">Rekomendasi: ' + name + '</h3>' +
                        '<p class="mood-result-copy">' + reason + '</p>' +
                        '<p class="mood-result-copy mb-3">Harga: <strong>' + price + '</strong></p>' +
                        '<a class="btn raice-btn raice-btn-primary" href="index.php?page=home#order" data-mood-order-product="' + productId + '">Pesan Sekarang</a>' +
                    '</div>' +
                '</div>'
            );
        }).join("");

        moodResult.classList.remove("d-none");
        moodResult.innerHTML = rows;

        moodResult.querySelectorAll("[data-mood-order-product]").forEach(function (link) {
            link.addEventListener("click", function () {
                selectOrderProduct(link.dataset.moodOrderProduct);
            });
        });
    }

    // ===== NAVBAR SCROLL =====
    navLinks.forEach(function (link) {
        link.addEventListener("click", function () {
            removeClass(navLinks, "active");
            link.classList.add("active");

            if (mainNavbar && mainNavbar.classList.contains("show") && window.bootstrap) {
                window.bootstrap.Collapse.getOrCreateInstance(mainNavbar).hide();
            }
        });
    });

    // ===== MOOD RECOMMENDATION =====
    moodButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            removeClass(moodButtons, "active");
            button.classList.add("active");
            selectedMood = button.dataset.mood;

            if (moodTimer) {
                window.clearTimeout(moodTimer);
            }

            resetMoodResult();

            if (moodSearchButton) {
                moodSearchButton.disabled = false;
            }
        });
    });

    if (moodSearchButton) {
        moodSearchButton.addEventListener("click", function () {
            if (!selectedMood) {
                return;
            }

            if (moodTimer) {
                window.clearTimeout(moodTimer);
            }

            resetMoodResult();
            moodSearchButton.disabled = true;

            if (moodLoading) {
                moodLoading.classList.remove("d-none");
            }

            moodTimer = window.setTimeout(function () {
                if (moodLoading) {
                    moodLoading.classList.add("d-none");
                }

                moodSearchButton.disabled = false;
                renderMoodResult(selectedMood);
            }, 700);
        });
    }

    // ===== PRODUCT QUANTITY BUTTON =====
    minusButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            var box = button.closest(".quantity-box");
            var input = box ? box.querySelector(".quantity-input") : null;

            if (!input) {
                return;
            }

            var currentValue = parseInt(input.value, 10) || 0;
            clampQuantityInput(input, currentValue - 1);
            input.dispatchEvent(new Event("input", { bubbles: true }));
        });
    });

    plusButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            var box = button.closest(".quantity-box");
            var input = box ? box.querySelector(".quantity-input") : null;

            if (!input) {
                return;
            }

            if (isInputOutOfStock(input)) {
                clampQuantityInput(input, 0);
                showOrderSummary(getStockUnavailableMessage(input));
                return;
            }

            var currentValue = parseInt(input.value, 10) || 0;
            clampQuantityInput(input, currentValue + 1);
            input.dispatchEvent(new Event("input", { bubbles: true }));
        });
    });

    orderQuantityInputs.forEach(function (input) {
        input.addEventListener("input", function () {
            var onlyNumbers = String(input.value || "").replace(/[^0-9]/g, "");

            if (isInputOutOfStock(input) && Number(onlyNumbers || 0) > 0) {
                clampQuantityInput(input, 0);
                showOrderSummary(getStockUnavailableMessage(input));
                return;
            }

            clampQuantityInput(input, onlyNumbers);

            if (orderSummary) {
                orderSummary.classList.add("d-none");
                orderSummary.textContent = "";
            }
        });

        input.addEventListener("blur", function () {
            if (input.value === "") {
                input.value = "0";
            }
        });
    });

    // ===== ORDER FORM =====
    orderProductButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            selectOrderProduct(button.dataset.orderProduct);
        });
    });

    if (orderForm) {
        orderForm.addEventListener("submit", function (event) {
            var hasSelectedProduct = false;

            orderQuantityInputs.forEach(function (input) {
                if (Number(input.value || 0) > 0) {
                    hasSelectedProduct = true;
                }
            });

            if (!hasSelectedProduct) {
                event.preventDefault();
                showOrderSummary("Pilih minimal satu produk.");
            }
        });
    }

    // ===== ADMIN DASHBOARD =====
    confirmForms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            var message = form.dataset.confirmMessage || "Lanjutkan proses ini?";

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
