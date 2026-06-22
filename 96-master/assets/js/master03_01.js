/**
 * API送信先
 */
const requestURL = "./assets/function/proc_master03_01.php";
let pendingOrderStatusRadio = null;
let pendingOrderStatusPreviousValue = "";

function resetSelectBox(searchForm, selector, hiddenName, defaultLabel) {
    const hidden = searchForm.querySelector(`input[name="${hiddenName}"][type="hidden"]`);
    if (hidden) hidden.value = "";
    searchForm.querySelectorAll(`input[name="${hiddenName}"][type="radio"]`).forEach((radio) => {
        radio.checked = false;
    });
    const selectBox = searchForm.querySelector(selector);
    const selectBoxValue = selectBox?.querySelector("[data-selectbox-value]");
    if (selectBoxValue) selectBoxValue.textContent = defaultLabel;
    if (selectBox) {
        selectBox.classList.remove("is-selected");
        selectBox.classList.add("is-empty");
    }
}

function resetOrderSearchForm(searchForm) {
    ["searchOrderNo", "searchOrdererName", "searchOrdererEmail", "searchOrdererTel", "searchOrderDateFrom", "searchOrderDateTo"].forEach((name) => {
        const input = searchForm.querySelector(`[name="${name}"]`);
        if (input) input.value = "";
    });
    resetSelectBox(searchForm, ".select-shop-name[data-selectbox]", "searchShopId", "選択してください");
    resetSelectBox(searchForm, ".select-search-status[data-selectbox]", "searchStatus", "選択してください");
}

async function updateOrderStatus(radio) {
    const statusBox = radio.closest(".apply-status[data-order-id]");
    if (!statusBox) return;
    const orderId = statusBox.getAttribute("data-order-id") || "";
    const previousValue = statusBox.getAttribute("data-current-status") || "";
    const sFd = new FormData();
    const noUpDateKeyInput = document.querySelector('form[name="searchForm"] input[name="noUpDateKey"]');
    sFd.set("action", "updateStatus");
    sFd.set("orderId", orderId);
    sFd.set("statusId", radio.value);
    if (noUpDateKeyInput) sFd.set("noUpDateKey", noUpDateKeyInput.value);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const list = (await response.json()) || {};
        if (list["status"] !== "success") {
            restoreOrderStatusSelection(statusBox, previousValue);
            alert(list["msg"] || "ステータス更新に失敗しました。");
            return;
        }
        statusBox.setAttribute("data-current-status", radio.value);
        updateSelectboxDisplay(statusBox, radio);
        const wrapStatus = statusBox.closest(".wrap-status");
        if (wrapStatus && list["changed_at"]) {
            let dateEl = wrapStatus.querySelector(".date");
            if (!dateEl) {
                dateEl = document.createElement("span");
                dateEl.className = "date";
                wrapStatus.appendChild(dateEl);
            }
            dateEl.textContent = list["changed_at"];
        }
        openOrderStatusCompleteModal(list["msg"] || "対応状況を更新しました。");
    } catch (error) {
        console.error("ステータス更新エラー:", error);
        restoreOrderStatusSelection(statusBox, previousValue);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}

function updateSelectboxDisplay(statusBox, radio) {
    if (!statusBox || !radio) return;
    const hidden = statusBox.querySelector("input[data-selectbox-hidden]");
    if (hidden) hidden.value = radio.value;
    const valueEl = statusBox.querySelector("[data-selectbox-value]");
    const label = statusBox.querySelector(`label[for="${radio.id}"]`);
    const headButton = statusBox.querySelector("button.selectbox__head");
    if (valueEl && label) {
        const statusClassNames = ["status-registered", "status-preparing", "status-shipped", "status-completed"];
        valueEl.textContent = label.textContent;
        statusClassNames.forEach((className) => {
            valueEl.classList.remove(className);
            statusBox.classList.remove(className);
            if (headButton) headButton.classList.remove(className);
        });
        statusClassNames.forEach((className) => {
            if (label.classList.contains(className)) {
                valueEl.classList.add(className);
                statusBox.classList.add(className);
                if (headButton) headButton.classList.add(className);
            }
        });
    }
    statusBox.classList.add("is-selected");
    statusBox.classList.remove("is-empty");
}

function restoreOrderStatusSelection(statusBox, previousValue) {
    if (!statusBox) return;
    const previousRadio = previousValue ? statusBox.querySelector(`input[data-order-status-radio][value="${previousValue}"]`) : null;
    if (previousRadio) {
        previousRadio.checked = true;
        updateSelectboxDisplay(statusBox, previousRadio);
    }
}

function closeOrderStatusConfirmModal() {
    const blockModal = document.getElementById("modalBlock");
    if (blockModal) blockModal.classList.remove("is-active");
    document.documentElement.style.overflow = "";
}

function closeOrderStatusCompleteModal() {
    const blockModal = document.getElementById("modalBlock");
    if (blockModal) blockModal.classList.remove("is-active");
    document.documentElement.style.overflow = "";
}

function openOrderStatusCompleteModal(message = "対応状況を更新しました。") {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        alert(message);
        return;
    }
    const titleEl = blockModal.querySelector(".box-title p");
    if (titleEl) titleEl.textContent = "ステータス更新";
    const messageEl = blockModal.querySelector(".box-details p");
    if (messageEl) messageEl.textContent = message;
    const btnArea = blockModal.querySelector(".box-btn");
    if (btnArea) {
        btnArea.querySelectorAll("button").forEach((button) => button.remove());
        btnArea.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel">閉じる</button>');
        btnArea.querySelector(".btn-cancel")?.addEventListener("click", closeOrderStatusCompleteModal);
    }
    const topCloseButton = blockModal.querySelector(".box-title button");
    if (topCloseButton) {
        topCloseButton.removeAttribute("onclick");
        topCloseButton.onclick = closeOrderStatusCompleteModal;
    }
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}

function cancelOrderStatusChange() {
    const statusBox = pendingOrderStatusRadio ? pendingOrderStatusRadio.closest(".apply-status[data-order-id]") : null;
    restoreOrderStatusSelection(statusBox, pendingOrderStatusPreviousValue);
    pendingOrderStatusRadio = null;
    pendingOrderStatusPreviousValue = "";
    closeOrderStatusConfirmModal();
}

async function confirmOrderStatusChange() {
    const radio = pendingOrderStatusRadio;
    pendingOrderStatusRadio = null;
    pendingOrderStatusPreviousValue = "";
    closeOrderStatusConfirmModal();
    if (radio) await updateOrderStatus(radio);
}

function openOrderStatusConfirmModal(radio) {
    const statusBox = radio.closest(".apply-status[data-order-id]");
    if (!statusBox) return;
    const previousValue = statusBox.getAttribute("data-current-status") || "";
    if (radio.value === previousValue) {
        updateSelectboxDisplay(statusBox, radio);
        return;
    }
    pendingOrderStatusRadio = radio;
    pendingOrderStatusPreviousValue = previousValue;
    const statusName = statusBox.querySelector(`label[for="${radio.id}"]`)?.textContent || "";
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        if (window.confirm(`対応状況を「${statusName}」に変更します。\nよろしいですか？`)) {
            confirmOrderStatusChange();
        } else {
            cancelOrderStatusChange();
        }
        return;
    }
    const titleEl = blockModal.querySelector(".box-title p");
    if (titleEl) titleEl.textContent = "対応状況の変更";
    const messageEl = blockModal.querySelector(".box-details p");
    if (messageEl) messageEl.textContent = `対応状況を「${statusName}」に変更します。よろしいですか？`;
    const btnArea = blockModal.querySelector(".box-btn");
    if (btnArea) {
        btnArea.querySelectorAll("button").forEach((button) => button.remove());
        btnArea.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel">いいえ</button>');
        btnArea.insertAdjacentHTML("beforeend", '<button type="button" class="btn-confirm">はい</button>');
        btnArea.querySelector(".btn-cancel")?.addEventListener("click", cancelOrderStatusChange);
        btnArea.querySelector(".btn-confirm")?.addEventListener("click", confirmOrderStatusChange);
    }
    const topCloseButton = blockModal.querySelector(".box-title button");
    if (topCloseButton) {
        topCloseButton.removeAttribute("onclick");
        topCloseButton.onclick = cancelOrderStatusChange;
    }
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}

function bindOrderStatusChange(root = document) {
    root.querySelectorAll("input[data-order-status-radio]").forEach((radio) => {
        if (radio.dataset.statusChangeBound === "1") return;
        radio.dataset.statusChangeBound = "1";
        radio.addEventListener("change", () => {
            if (radio.checked) openOrderStatusConfirmModal(radio);
        });
    });
}

async function searchConditions(action, pageNumber = 1) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
    const sFd = new FormData(searchForm);
    sFd.set("action", action);
    sFd.set("pageNumber", pageNumber);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const list = (await response.json()) || {};
        if (list["noUpDateKey"]) {
            const noUpDateKeyInput = searchForm.querySelector('input[name="noUpDateKey"]');
            if (noUpDateKeyInput) noUpDateKeyInput.value = list["noUpDateKey"];
        }
        if (list["status"] === "error") {
            alert(list["msg"] || "エラーが発生しました。ページを再読み込みしてください。");
            return;
        }
        if (list["tag"]) {
            const currentUl = document.querySelector(".inner_search-list > ul");
            if (currentUl) currentUl.outerHTML = list["tag"];
            if (typeof window.initSelectBoxes === "function") window.initSelectBoxes(document);
            bindOrderStatusChange(document);
        }
        if (list["pager"]) {
            const currentPager = document.querySelector(".inner_search-list > .box-pager");
            if (currentPager) {
                currentPager.outerHTML = list["pager"];
            } else {
                document.querySelector(".inner_search-list")?.insertAdjacentHTML("beforeend", list["pager"]);
            }
        }
        if (action === "reset") {
            resetOrderSearchForm(searchForm);
        }
        const areaMaster = document.querySelector("main");
        if (areaMaster) areaMaster.scrollIntoView(true);
    } catch (error) {
        console.error("通信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}

function movePage(pageNumber) {
    searchConditions("search", pageNumber);
}

document.addEventListener("DOMContentLoaded", () => {
    bindOrderStatusChange(document);
});
