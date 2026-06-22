/**
 * API送信先 共通定数
 */
const requestURL = "./assets/function/proc_client03_03_01.php";

/**
 * モーダルアラート（共通）
 */
function showModalMessage(message, title = "入力エラー") {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        return;
    }
    const titleArea = blockModal.querySelector(".box-title p");
    const messageArea = blockModal.querySelector(".box-details p");
    const buttonArea = blockModal.querySelector(".box-btn");
    const topCloseButton = blockModal.querySelector(".box-title")?.querySelector("button");
    if (titleArea) {
        titleArea.innerHTML = title;
    }
    if (messageArea) {
        messageArea.innerHTML = String(message || "").replace(/\n/g, "<br>");
    }
    if (buttonArea) {
        buttonArea.querySelectorAll("button").forEach((button) => {
            button.remove();
        });
        buttonArea.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>');
    }
    if (topCloseButton) {
        topCloseButton.setAttribute("onclick", "closeModal()");
    }
    blockModal.style.display = "";
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}
/**
 * 送信（共通）
 */
async function sendInput() {
    const target = typeof event !== "undefined" ? event.target : null;
    const currentForm = target?.closest ? target.closest("form") : null;
    const fallbackValidationForm = typeof validationForm !== "undefined" ? validationForm : null;
    const form = currentForm || fallbackValidationForm || document.querySelector("form[name='blockDetailsForm']");
    if (!form) {
        return;
    }
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    let errFlag = 0;
    if (typeof validationForm !== "undefined" && form === validationForm) {
        let chk_required = checkRequiredElem();
        if (chk_required == "err") {
            errFlag = 1;
        }
    }
    if (errFlag === 0) {
        const sFd = new FormData(form);
        sFd.append("action", "sendInput");
        try {
            const response = await fetch(requestURL, { method: "POST", body: sFd });
            if (!response.ok) throw new Error("Network response was not ok");
            const list = await response.json();
            const blockModal = document.getElementById("modalBlock");
            if (list["status"] == "error") {
                const title = list["title"] || "登録失敗";
                const msgRaw = list["msg"] || "登録に失敗しました。\nお手数ですが最初からやり直してください。";
                const msg = String(msgRaw).replace(/\n/g, "<br>");
                if (blockModal) {
                    blockModal.querySelector(".box-title p").innerHTML = title;
                    blockModal.querySelector(".box-details p").innerHTML = msg;
                    let buttonList = blockModal.querySelector(".box-btn").querySelectorAll("button");
                    buttonList.forEach((ElementButton) => {
                        ElementButton.remove();
                    });
                    let newButton = '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>';
                    blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                    blockModal.classList.add("is-active");
                    document.documentElement.style.overflow = "hidden";
                } else {
                    showModalMessage(msgRaw, title);
                }
            } else {
                if (blockModal) {
                    blockModal.querySelector(".box-title p").innerHTML = list["title"] || "";
                    const responseProductId = list["product_id"] ? String(list["product_id"]) : "";
                    const formProductId = form.querySelector('input[name="productId"]')?.value ? String(form.querySelector('input[name="productId"]').value) : "";
                    const productId = /^[1-9][0-9]*$/.test(responseProductId) ? responseProductId : /^[1-9][0-9]*$/.test(formProductId) ? formProductId : "";
                    const returnUrl = productId ? `client03_03.php?method=edit&productId=${encodeURIComponent(productId)}` : "client03_02.php";
                    blockModal.querySelector(".box-details p").innerHTML = String(list["msg"] || "").replace(/\n/g, "<br>");
                    let buttonList = blockModal.querySelector(".box-btn").querySelectorAll("button");
                    buttonList.forEach((ElementButton) => {
                        ElementButton.remove();
                    });
                    let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('${returnUrl}');">閉じる</button>`;
                    blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                    blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", `closeModalToPage('${returnUrl}')`);
                    blockModal.classList.add("is-active");
                    document.documentElement.style.overflow = "hidden";
                } else {
                    showModalMessage(list["msg"] || "完了", list["title"] || "商品規格登録");
                }
            }
            return list;
        } catch (error) {
            console.error("送信エラー:", error);
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
            return null;
        }
    }
}
/**
 * モーダルアラート（商品規格の設定用）
 */
function showVariantModalMessage(msg, title = "入力エラー") {
    showModalMessage(msg, title);
}
/**
 * 「商品規格の設定」ボタン押下
 */
async function buildProductVariants(action) {
    const blockHeadForm = document.querySelector("form[name='blockHeadForm']");
    if (!blockHeadForm) {
        showVariantModalMessage("商品規格フォームが見つかりません。");
        return;
    }
    const sFd = new FormData(blockHeadForm);
    sFd.append("action", action);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) {
            throw new Error("Network response was not ok");
        }
        const list = await response.json();
        if (list["status"] == "error") {
            showVariantModalMessage(list["msg"] || "処理に失敗しました。", list["title"] || "入力エラー");
            return;
        }
        const oldForm = document.querySelector("form[name='blockDetailsForm']");
        if (oldForm && list["tag"]) {
            oldForm.outerHTML = list["tag"];
        }
        bindVariantDetailEvents();
        bindVariantTooltipEvents();
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 在庫無制限チェックに合わせて在庫入力欄を切り替え
 */
function syncRowStockState(row) {
    if (!row) return;
    const stockUnlimited = row.querySelector(".stock-unlimited-cb");
    const stockInput = row.querySelector(".stock-input");
    if (!stockUnlimited || !stockInput) return;
    if (stockUnlimited.checked) {
        stockInput.readOnly = true;
        stockInput.value = "";
        return;
    }
    stockInput.readOnly = false;
}
/**
 * 在庫入力欄クリック時に無制限を解除
 */
function unlockStockInput(stockInput) {
    if (!stockInput) return;
    const row = stockInput.closest("li");
    if (!row) return;
    const stockUnlimited = row.querySelector(".stock-unlimited-cb");
    if (!stockUnlimited) return;
    if (stockUnlimited.checked) {
        stockUnlimited.checked = false;
        stockInput.readOnly = false;
        stockInput.focus();
    }
}
/**
 * 1行目の価格・在庫を全行に反映
 */
function copyFirstRowToAll() {
    const rows = document.querySelectorAll("#variant-table-body li[data-class-category-id1]");
    if (!rows || rows.length <= 1) return;
    const firstRow = rows[0];
    const firstPrice = firstRow.querySelector(".price-input")?.value ?? "";
    const firstStock = firstRow.querySelector(".stock-input")?.value ?? "";
    const firstUnlimited = firstRow.querySelector(".stock-unlimited-cb")?.checked ?? false;
    rows.forEach((row, index) => {
        if (index === 0) return;
        const priceInput = row.querySelector(".price-input");
        const stockInput = row.querySelector(".stock-input");
        const unlimited = row.querySelector(".stock-unlimited-cb");
        if (priceInput) priceInput.value = firstPrice;
        if (stockInput) stockInput.value = firstStock;
        if (unlimited) unlimited.checked = firstUnlimited;
        syncRowStockState(row);
    });
}
/**
 * 規格詳細行のイベント状態を初期化
 */
function bindVariantDetailEvents() {
    const rows = document.querySelectorAll("#variant-table-body li[data-class-category-id1]");
    rows.forEach((row) => {
        syncRowStockState(row);
    });
}
/**
 * 有効・無効トグルのツールチップ文言を取得
 */
function getVariantTooltipText(element) {
    if (!element) return "";
    if (element.dataset.tooltip) {
        return element.dataset.tooltip;
    }
    const checkbox = element.querySelector('input[type="checkbox"]');
    if (checkbox) {
        return checkbox.checked ? element.dataset.tooltipOn : element.dataset.tooltipOff;
    }
    return "";
}
/**
 * ツールチップ要素を取得または生成
 */
function ensureVariantTooltip() {
    let tooltip = document.querySelector(".global-tooltip");
    if (!tooltip) {
        tooltip = document.createElement("div");
        tooltip.className = "global-tooltip";
        tooltip.setAttribute("aria-hidden", "true");
        document.body.appendChild(tooltip);
    }
    return tooltip;
}
/**
 * 有効・無効トグルのツールチップを表示
 */
function showVariantTooltip(element) {
    const tooltip = ensureVariantTooltip();
    const text = getVariantTooltipText(element);
    if (!text) return;
    tooltip.textContent = text;
    tooltip.classList.add("is-show");
    const rect = element.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    let top = rect.top - tooltipRect.height - 12;
    let left = rect.left + rect.width / 2 - tooltipRect.width / 2;
    if (top < 8) {
        top = rect.bottom + 12;
    }
    const minLeft = 8;
    const maxLeft = window.innerWidth - tooltipRect.width - 8;
    left = Math.max(minLeft, Math.min(left, maxLeft));
    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${left}px`;
}
/**
 * 有効・無効トグルのツールチップを非表示
 */
function hideVariantTooltip() {
    const tooltip = document.querySelector(".global-tooltip");
    if (tooltip) {
        tooltip.classList.remove("is-show");
    }
}
/**
 * 有効・無効トグルのツールチップイベントを設定
 */
function bindVariantTooltipEvents() {
    const targets = document.querySelectorAll("form[name='blockDetailsForm'] .wrap-toggle-button[data-tooltip-on], form[name='blockDetailsForm'] .wrap-toggle-button[data-tooltip-off]");
    targets.forEach((element) => {
        if (element.dataset.variantTooltipBound === "1") return;
        if (element.dataset.tooltipOff === "無効中 | 有効する") {
            element.dataset.tooltipOff = "無効中 | 有効にする";
        }
        element.dataset.variantTooltipBound = "1";
        element.addEventListener("mouseenter", () => showVariantTooltip(element));
        element.addEventListener("mouseleave", hideVariantTooltip);
        element.addEventListener("focusin", () => showVariantTooltip(element));
        element.addEventListener("focusout", hideVariantTooltip);
        const checkbox = element.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.addEventListener("change", () => {
                const tooltip = document.querySelector(".global-tooltip");
                if (tooltip && tooltip.classList.contains("is-show")) {
                    showVariantTooltip(element);
                }
            });
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    const blockHeadForm = document.querySelector("form[name='blockHeadForm']");
    if (blockHeadForm) {
        const autoBuildInput = blockHeadForm.querySelector('input[name="autoBuildVariants"]');
        const selectVariable01 = blockHeadForm.querySelector('input[name="selectVariable01"]');
        const autoBuildFlg = autoBuildInput ? autoBuildInput.value : "0";
        const spec1Value = selectVariable01 ? selectVariable01.value : "0";
        if (autoBuildFlg === "1" && /^[1-9][0-9]*$/.test(spec1Value) && typeof buildProductVariants === "function") {
            buildProductVariants("buildVariants");
        }
    }
    document.addEventListener("change", (e) => {
        if (e.target && e.target.classList && e.target.classList.contains("stock-unlimited-cb")) {
            const row = e.target.closest("li");
            syncRowStockState(row);
        }
    });
    document.addEventListener("click", (e) => {
        const stockInput = e.target.closest ? e.target.closest(".stock-input") : null;
        if (stockInput) {
            unlockStockInput(stockInput);
        }
        if (e.target.closest && e.target.closest("#copy-first-row-btn")) {
            copyFirstRowToAll();
        }
    });
    document.addEventListener("focusin", (e) => {
        if (e.target && e.target.classList && e.target.classList.contains("stock-input")) {
            unlockStockInput(e.target);
        }
    });
    bindVariantDetailEvents();
    bindVariantTooltipEvents();
});
