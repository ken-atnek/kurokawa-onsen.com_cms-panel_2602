/**
 * API送信先 共通定数
 * 将来の編集保存・返品処理で使用する。
 */
const requestURL = "./assets/function/proc_client03_01_01.php";

const prefIdMap = {
    北海道: 1,
    青森県: 2,
    岩手県: 3,
    宮城県: 4,
    秋田県: 5,
    山形県: 6,
    福島県: 7,
    茨城県: 8,
    栃木県: 9,
    群馬県: 10,
    埼玉県: 11,
    千葉県: 12,
    東京都: 13,
    神奈川県: 14,
    新潟県: 15,
    富山県: 16,
    石川県: 17,
    福井県: 18,
    山梨県: 19,
    長野県: 20,
    岐阜県: 21,
    静岡県: 22,
    愛知県: 23,
    三重県: 24,
    滋賀県: 25,
    京都府: 26,
    大阪府: 27,
    兵庫県: 28,
    奈良県: 29,
    和歌山県: 30,
    鳥取県: 31,
    島根県: 32,
    岡山県: 33,
    広島県: 34,
    山口県: 35,
    徳島県: 36,
    香川県: 37,
    愛媛県: 38,
    高知県: 39,
    福岡県: 40,
    佐賀県: 41,
    長崎県: 42,
    熊本県: 43,
    大分県: 44,
    宮崎県: 45,
    鹿児島県: 46,
    沖縄県: 47,
};

let lastOrdererPostalCode = "";
let lastShippingPostalCode = "";
const editableBlocks = ".block-customer-info, .block-shipping-info, .block-description";
let isReturnProcessing = false;
/**
 * 入力イベント発火
 *  既存の入力監視へ値変更を通知する
 */
function triggerInputAndChange(element) {
    if (!element) return;
    element.dispatchEvent(new Event("input", { bubbles: true }));
    element.dispatchEvent(new Event("change", { bubbles: true }));
}
/**
 * 注文者情報を配送先へコピー
 *  注文者側フィールド値を配送先側フィールドへ反映する
 */
function copyOrdererInfoToShipping() {
    const mapList = [
        ["userFirstName", "shippingUserFirstName"],
        ["userLastName", "shippingUserLastName"],
        ["userFirstNameKana", "shippingUserFirstNameKana"],
        ["userLastNameKana", "shippingUserLastNameKana"],
        ["userCompanyName", "shippingUserCompanyName"],
        ["userTel", "shippingUserTel"],
        ["userPostalCode", "shippingUserPostalCode"],
        ["userAddress01", "shippingUserAddress01"],
        ["userAddress02", "shippingUserAddress02"],
        ["userAddress03", "shippingUserAddress03"],
        ["ordererPrefId", "shippingPrefId"],
    ];
    mapList.forEach(([fromId, toId]) => {
        const fromElement = document.getElementById(fromId);
        const toElement = document.getElementById(toId);
        if (!fromElement || !toElement) return;
        toElement.value = fromElement.value;
        triggerInputAndChange(toElement);
    });
}
/**
 * 注文者情報コピーボタン表示制御
 *  editモード時のみ表示・有効化する
 */
function syncCopyOrdererButtonState(isEditMode) {
    const copyButton = document.getElementById("copyOrdererToShippingButton");
    if (!copyButton) return;
    copyButton.style.display = isEditMode === true ? "" : "none";
    copyButton.disabled = isEditMode !== true;
}
/**
 * 注文者情報コピーボタンイベント登録
 *  ボタン押下時にコピー処理を実行する
 */
function bindCopyOrdererToShippingButton() {
    const copyButton = document.getElementById("copyOrdererToShippingButton");
    if (!copyButton) return;
    copyButton.addEventListener("click", copyOrdererInfoToShipping);
}

/**
 * 返品ボタン状態更新
 *  未返品商品のチェック、返金金額、返金実行チェックの3条件で返金ボタン活性を切り替える。
 */
function updateReturnButtonState() {
    const returnButton = document.getElementById("processReturnButton");
    if (!returnButton) {
        return;
    }
    const checkedItems = document.querySelectorAll('input[name="return_order_item_ids[]"]:checked:not(:disabled)');
    const refundTotal = document.getElementById("refundTotal");
    const executeZeusRefund = document.getElementById("executeZeusRefund");
    const normalizedRefundValue = String(refundTotal?.value || "").replace(/[^\d]/g, "");
    if (refundTotal) {
        refundTotal.value = normalizedRefundValue;
    }
    const hasReturnItem = checkedItems.length > 0;
    const hasRefundTotal = /^\d+$/.test(normalizedRefundValue) && parseInt(normalizedRefundValue, 10) > 0;
    const canExecute = executeZeusRefund?.checked === true;
    const paymentTotalRaw = String(document.getElementById("paymentTotalValue")?.value || "").replace(/[^\d]/g, "");
    const paymentTotal = /^\d+$/.test(paymentTotalRaw) ? parseInt(paymentTotalRaw, 10) : 0;
    const refundTotalValue = /^\d+$/.test(normalizedRefundValue) ? parseInt(normalizedRefundValue, 10) : 0;
    const changeMoney = paymentTotal - refundTotalValue;
    const canRefundByAmount = paymentTotal > 0 && refundTotalValue > 0 && refundTotalValue < paymentTotal && changeMoney >= 1;
    if (isReturnProcessing === true) {
        returnButton.disabled = true;
        return;
    }
    returnButton.disabled = !(hasReturnItem && hasRefundTotal && canExecute && canRefundByAmount);
}
/**
 * 返金参考額表示更新
 *  返品対象商品の税込小計合計と送料を使って「返金額」を表示し、全商品チェック時は警告表示へ切り替える
 */
function updateReturnEstimate() {
    const amountRow = document.getElementById("returnEstimateAmountRow");
    const amountText = document.getElementById("returnEstimateAmountText");
    const allErrorRow = document.getElementById("returnEstimateAllErrorRow");
    if (!amountRow || !amountText || !allErrorRow) {
        return;
    }
    const allCheckboxes = Array.from(document.querySelectorAll('input[name="return_order_item_ids[]"]:not(:disabled)'));
    const checkedCheckboxes = allCheckboxes.filter((checkbox) => checkbox.checked === true);
    if (checkedCheckboxes.length < 1) {
        amountRow.style.display = "none";
        allErrorRow.style.display = "none";
        amountText.textContent = "";
        return;
    }
    if (allCheckboxes.length > 0 && checkedCheckboxes.length === allCheckboxes.length) {
        amountRow.style.display = "none";
        allErrorRow.style.display = "";
        amountText.textContent = "";
        return;
    }
    const itemSubtotalTotal = checkedCheckboxes.reduce((sum, checkbox) => {
        const value = String(checkbox.dataset.itemSubtotal || "").replace(/[^\d]/g, "");
        const subtotal = /^\d+$/.test(value) ? parseInt(value, 10) : 0;
        return sum + subtotal;
    }, 0);
    const deliveryFeeValue = String(document.getElementById("deliveryFeeTotalDisplay")?.dataset.deliveryFeeTotal || "").replace(/[^\d]/g, "");
    const deliveryFee = /^\d+$/.test(deliveryFeeValue) ? parseInt(deliveryFeeValue, 10) : 0;
    const estimateTotal = itemSubtotalTotal + deliveryFee;
    amountText.textContent = `${itemSubtotalTotal.toLocaleString("ja-JP")} + ${deliveryFee.toLocaleString("ja-JP")} = ${estimateTotal.toLocaleString("ja-JP")}`;
    amountRow.style.display = "";
    allErrorRow.style.display = "none";
}
/**
 * 返品未実装モーダル表示
 *  返金ボタン押下時に、サーバー未実装の案内モーダルを表示する。
 */
function showReturnNotImplementedModal() {
    const opened = setOrderDetailModal("返金処理", "返品処理のサーバー実装は未実装です。", '<button type="button" class="btn-cancel" id="btnReturnNotImplementedClose">閉じる</button>', closeOrderDetailModal);
    if (!opened) {
        return;
    }
    document.getElementById("btnReturnNotImplementedClose")?.addEventListener("click", closeOrderDetailModal);
}
/**
 * 返金ボタン押下処理
 *  返金ボタン有効時のみ未実装案内モーダルを表示する。
 */
function handleReturnButtonClick() {
    const returnButton = document.getElementById("processReturnButton");
    if (!returnButton || returnButton.disabled === true || isReturnProcessing === true) {
        return;
    }
    openReturnConfirmModal();
}
/**
 * 返品処理確認モーダル表示
 *  返金ボタン押下時に確認モーダルを表示し、はい押下時のみ返品Ajax送信を実行する。
 */
function openReturnConfirmModal() {
    const opened = setOrderDetailModal("返金処理確認", "選択した商品を返品し、入力した金額にて返金を行います。<br>よろしいですか？", '<button type="button" class="btn-cancel" id="btnReturnNo">いいえ</button><button type="button" class="btn-confirm" id="btnReturnYes">はい</button>', closeOrderDetailModal);
    if (!opened) {
        return;
    }
    document.getElementById("btnReturnNo")?.addEventListener("click", closeOrderDetailModal);
    document.getElementById("btnReturnYes")?.addEventListener("click", processOrderReturn);
}
/**
 * 返品処理結果モーダル表示
 *  success/warning時に結果モーダルを表示し、閉じる操作後にページ再読み込みする。
 */
function openReturnResultReloadModal(title, message) {
    const closeAndReload = () => {
        closeOrderDetailModal();
        window.location.reload();
    };
    const opened = setOrderDetailModal(title, message, '<button type="button" class="btn-cancel" id="btnReturnResultClose">閉じる</button>', closeAndReload);
    if (!opened) {
        window.location.reload();
        return;
    }
    document.getElementById("btnReturnResultClose")?.addEventListener("click", closeAndReload);
}
/**
 * 返品Ajax送信
 *  action=processReturn をPOST送信し、success/warning/errorでモーダル表示を切り替える。
 */
async function processOrderReturn() {
    closeOrderDetailModal();
    const returnButton = document.getElementById("processReturnButton");
    const inputForm = document.querySelector('form[name="inputForm"]');
    if (!returnButton || !inputForm) {
        return;
    }
    if (isReturnProcessing === true) {
        return;
    }
    isReturnProcessing = true;
    returnButton.disabled = true;
    const formData = new FormData(inputForm);
    const orderId = new URLSearchParams(window.location.search).get("orderId") || "";
    formData.set("action", "processReturn");
    formData.set("orderId", orderId);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) {
            throw new Error("Network error");
        }
        const result = await response.json();
        const status = String(result?.status || "").toLowerCase();
        if (status === "success") {
            openReturnResultReloadModal("返品処理完了", result.msg || "返品処理が完了しました。");
            return;
        }
        if (status === "warning") {
            openReturnResultReloadModal("返品処理完了（要確認）", result.msg || "返金処理は完了しましたが、EC-CUBE返品通知に失敗しました。管理者に確認してください。");
            return;
        }
        isReturnProcessing = false;
        updateReturnButtonState();
        openOrderDetailErrorModal(result.msg || "返品処理に失敗しました。");
    } catch (error) {
        console.error("返品処理エラー:", error);
        isReturnProcessing = false;
        updateReturnButtonState();
        openOrderDetailErrorModal("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 返品イベント登録
 *  返品対象チェック、返金金額、返金実行チェック、返金ボタン押下のイベントを登録する。
 */
function bindReturnEvents() {
    const returnItemCheckboxes = document.querySelectorAll('input[name="return_order_item_ids[]"]');
    returnItemCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener("change", () => {
            updateReturnEstimate();
            updateReturnButtonState();
        });
    });
    const refundTotal = document.getElementById("refundTotal");
    if (refundTotal) {
        refundTotal.addEventListener("input", updateReturnButtonState);
        refundTotal.addEventListener("blur", updateReturnButtonState);
    }
    const executeZeusRefund = document.getElementById("executeZeusRefund");
    if (executeZeusRefund) {
        executeZeusRefund.addEventListener("change", updateReturnButtonState);
    }
    const returnButton = document.getElementById("processReturnButton");
    if (returnButton) {
        returnButton.addEventListener("click", handleReturnButtonClick);
    }
    updateReturnEstimate();
    updateReturnButtonState();
}
/**
 * 郵便番号正規化
 *  ハイフン・空白を除去し、7桁数字の場合のみ正規化済み郵便番号を返す。
 */
function normalizePostalCode(postalCode) {
    const normalizedPostalCode = String(postalCode || "").replace(/[-\s　]/g, "");
    return /^\d{7}$/.test(normalizedPostalCode) ? normalizedPostalCode : "";
}
/**
 * 郵便番号住所取得
 *  zipcloud APIから都道府県・市区町村・町域を取得する。
 */
async function fetchAddressByPostalCode(postalCode) {
    const normalizedPostalCode = normalizePostalCode(postalCode);
    if (normalizedPostalCode === "") {
        return null;
    }
    try {
        const response = await fetch(`https://zipcloud.ibsnet.co.jp/api/search?zipcode=${normalizedPostalCode}`);
        if (!response.ok) {
            return null;
        }
        const list = await response.json();
        if (!list || !Array.isArray(list.results) || list.results.length < 1) {
            return null;
        }
        const result = list.results[0];
        return {
            pref: result.address1 || "",
            city: result.address2 || "",
            town: result.address3 || "",
        };
    } catch (error) {
        console.warn("郵便番号住所取得エラー:", error);
        return null;
    }
}
/**
 * 都道府県ID取得
 *  都道府県名からEC-CUBE mtb_pref.idに対応するIDを返す。
 */
function getPrefIdByName(prefName) {
    return prefIdMap[prefName] || "";
}
/**
 * 要素値セット
 *  指定IDの要素が存在する場合のみvalueをセットする。
 */
function setValueIfElementExists(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.value = value == null ? "" : value;
    }
}
/**
 * 要素値取得
 *  指定IDの要素が存在する場合のみvalueを取得する。
 */
function getValueIfElementExists(id) {
    const element = document.getElementById(id);
    return element ? element.value : "";
}
/**
 * 注文者住所反映
 *  郵便番号APIの結果を注文者住所欄と都道府県hiddenへ反映する。
 */
function applyAddressToOrderer(result, options = {}) {
    if (!result) {
        return;
    }
    const prefId = getPrefIdByName(result.pref);
    setValueIfElementExists("userAddress01", result.pref);
    setValueIfElementExists("userAddress02", result.city);
    if (options.forceTownOverwrite === true || getValueIfElementExists("userAddress03") === "") {
        setValueIfElementExists("userAddress03", result.town);
    }
    if (prefId === "") {
        console.warn("注文者都道府県IDを取得できません:", result.pref);
    }
    setValueIfElementExists("ordererPrefId", prefId);
    setValueIfElementExists("ordererPrefName", result.pref);
}
/**
 * 配送先住所反映
 *  郵便番号APIの結果を配送先住所欄と都道府県hiddenへ反映する。
 */
function applyAddressToShipping(result, options = {}) {
    if (!result) {
        return;
    }
    const prefId = getPrefIdByName(result.pref);
    setValueIfElementExists("shippingUserAddress01", result.pref);
    setValueIfElementExists("shippingUserAddress02", result.city);
    if (options.forceTownOverwrite === true || getValueIfElementExists("shippingUserAddress03") === "") {
        setValueIfElementExists("shippingUserAddress03", result.town);
    }
    if (prefId === "") {
        console.warn("配送先都道府県IDを取得できません:", result.pref);
    }
    setValueIfElementExists("shippingPrefId", prefId);
    setValueIfElementExists("shippingPrefName", result.pref);
}
/**
 * 初期都道府県ID補完
 *  hiddenのpref_idが空で郵便番号が7桁の場合のみ住所APIで補完する。
 */
async function initializePrefIdFromPostalCode() {
    const ordererPrefId = getValueIfElementExists("ordererPrefId");
    const ordererPostalCode = normalizePostalCode(getValueIfElementExists("userPostalCode"));
    if (ordererPrefId === "" && ordererPostalCode !== "") {
        const ordererAddress = await fetchAddressByPostalCode(ordererPostalCode);
        if (ordererAddress) {
            applyAddressToOrderer(ordererAddress);
            lastOrdererPostalCode = ordererPostalCode;
        }
    }
    const shippingPrefId = getValueIfElementExists("shippingPrefId");
    const shippingPostalCode = normalizePostalCode(getValueIfElementExists("shippingUserPostalCode"));
    if (shippingPrefId === "" && shippingPostalCode !== "") {
        const shippingAddress = await fetchAddressByPostalCode(shippingPostalCode);
        if (shippingAddress) {
            applyAddressToShipping(shippingAddress);
            lastShippingPostalCode = shippingPostalCode;
        }
    }
}
/**
 * 注文者郵便番号変更処理
 *  blur時に注文者郵便番号から住所を取得して反映する。
 */
async function handleOrdererPostalCodeChange() {
    const postalCode = normalizePostalCode(getValueIfElementExists("userPostalCode"));
    if (postalCode === "" || postalCode === lastOrdererPostalCode) {
        return;
    }
    lastOrdererPostalCode = postalCode;
    const result = await fetchAddressByPostalCode(postalCode);
    if (result) {
        applyAddressToOrderer(result, { forceTownOverwrite: false });
    } else {
        console.warn("注文者住所を取得できません:", postalCode);
    }
}
/**
 * 配送先郵便番号変更処理
 *  blur時に配送先郵便番号から住所を取得して反映する。
 */
async function handleShippingPostalCodeChange() {
    const postalCode = normalizePostalCode(getValueIfElementExists("shippingUserPostalCode"));
    if (postalCode === "" || postalCode === lastShippingPostalCode) {
        return;
    }
    lastShippingPostalCode = postalCode;
    const result = await fetchAddressByPostalCode(postalCode);
    if (result) {
        applyAddressToShipping(result, { forceTownOverwrite: false });
    } else {
        console.warn("配送先住所を取得できません:", postalCode);
    }
}
/**
 * 郵便番号イベント登録
 *  注文者・配送先の郵便番号入力にblurイベントを設定する。
 */
function bindPostalCodeEvents() {
    const ordererPostalCodeInput = document.getElementById("userPostalCode");
    if (ordererPostalCodeInput) {
        ordererPostalCodeInput.addEventListener("blur", handleOrdererPostalCodeChange);
    }
    const shippingPostalCodeInput = document.getElementById("shippingUserPostalCode");
    if (shippingPostalCodeInput) {
        shippingPostalCodeInput.addEventListener("blur", handleShippingPostalCodeChange);
    }
}
document.addEventListener("DOMContentLoaded", () => {
    bindPostalCodeEvents();
    initializePrefIdFromPostalCode();
    bindReturnEvents();
    bindCopyOrdererToShippingButton();
    syncCopyOrdererButtonState(false);
    document.getElementById("btnEdit")?.addEventListener("click", openOrderDetailEditConfirmModal);
    document.getElementById("btnCancel")?.addEventListener("click", () => {
        window.location.reload();
    });
    document.getElementById("btnSave")?.addEventListener("click", openOrderDetailSaveConfirmModal);
    //納品書PDF出力ボタン
    const btnPdf = document.getElementById("btnPdf");
    if (btnPdf) {
        btnPdf.addEventListener("click", function () {
            const shopId = btnPdf.dataset.shopId;
            const orderId = btnPdf.dataset.orderId;
            checkDeliverySlipPdf(shopId, orderId);
        });
    }
});
/**
 * 編集モード切替
 *  編集対象ブロックの操作制限を解除し、保存・キャンセルボタンを表示する。
 */
function switchToEditMode() {
    document.querySelectorAll(editableBlocks).forEach((el) => {
        el.style.pointerEvents = "";
    });
    const btnPdf = document.getElementById("btnPdf");
    const btnEdit = document.getElementById("btnEdit");
    const btnCancel = document.getElementById("btnCancel");
    const btnSave = document.getElementById("btnSave");
    if (btnPdf) btnPdf.style.display = "none";
    if (btnEdit) btnEdit.style.display = "none";
    if (btnCancel) btnCancel.style.display = "";
    if (btnSave) btnSave.style.display = "";
    syncCopyOrdererButtonState(true);
}
/**
 * 閲覧モード切替
 *  編集対象ブロックを操作不可に戻し、編集・PDFボタンを表示する。
 */
function switchToReadonlyMode() {
    document.querySelectorAll(editableBlocks).forEach((el) => {
        el.style.pointerEvents = "none";
    });
    const btnPdf = document.getElementById("btnPdf");
    const btnEdit = document.getElementById("btnEdit");
    const btnCancel = document.getElementById("btnCancel");
    const btnSave = document.getElementById("btnSave");
    if (btnPdf) btnPdf.style.display = "";
    if (btnEdit) btnEdit.style.display = "";
    if (btnCancel) btnCancel.style.display = "none";
    if (btnSave) btnSave.style.display = "none";
    syncCopyOrdererButtonState(false);
}
/**
 * 受注詳細モーダル閉じる
 *  既存closeModalがあれば使用し、なければmodalBlockを直接閉じる。
 */
function closeOrderDetailModal() {
    if (typeof closeModal === "function") {
        closeModal();
        return;
    }
    const blockModal = document.getElementById("modalBlock");
    if (blockModal) {
        blockModal.classList.remove("is-active");
    }
    document.documentElement.style.overflow = "";
}
/**
 * 受注詳細モーダル本文設定
 *  文字列内の <br>/<br />/\n を分解し、安全に改行として表示する。
 */
function setOrderDetailModalMessage(messageEl, message) {
    if (!messageEl) {
        return;
    }
    messageEl.textContent = "";
    const parts = String(message || "").split(/<br\s*\/?>|\n/i);
    parts.forEach((part, index) => {
        if (index > 0) {
            messageEl.appendChild(document.createElement("br"));
        }
        messageEl.appendChild(document.createTextNode(part));
    });
}
/**
 * 受注詳細モーダル設定
 *  既存modalBlockのタイトル・本文・ボタンを差し替えて表示する。
 */
function setOrderDetailModal(title, message, buttonsHtml, topCloseHandler) {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        return false;
    }
    const titleEl = blockModal.querySelector(".box-title p");
    if (titleEl) titleEl.textContent = title;
    const messageEl = blockModal.querySelector(".box-details p");
    setOrderDetailModalMessage(messageEl, message);
    const btnArea = blockModal.querySelector(".box-btn");
    if (btnArea) {
        btnArea.querySelectorAll("button").forEach((button) => button.remove());
        btnArea.insertAdjacentHTML("beforeend", buttonsHtml);
    }
    const topCloseButton = blockModal.querySelector(".box-title button");
    if (topCloseButton) {
        topCloseButton.removeAttribute("onclick");
        topCloseButton.onclick = topCloseHandler || closeOrderDetailModal;
    }
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
    return true;
}
/**
 * 保存確認モーダル表示
 *  保存前の確認モーダルを表示し、はいの場合のみ保存処理を実行する。
 */
function openOrderDetailSaveConfirmModal() {
    const opened = setOrderDetailModal("受注情報変更", "受注情報を変更します。<br>よろしいですか？", '<button type="button" class="btn-cancel" id="btnOrderDetailSaveNo">いいえ</button><button type="button" class="btn-confirm" id="btnOrderDetailSaveYes">はい</button>', closeOrderDetailModal);
    if (!opened) {
        if (window.confirm("受注情報を変更します。<br>よろしいですか？")) {
            saveOrderDetail();
        }
        return;
    }
    document.getElementById("btnOrderDetailSaveNo")?.addEventListener("click", closeOrderDetailModal);
    document.getElementById("btnOrderDetailSaveYes")?.addEventListener("click", saveOrderDetail);
}
/**
 * 編集確認モーダル表示
 *  編集開始前の確認モーダルを表示し、はいの場合のみ編集モードへ切り替える。
 */
function openOrderDetailEditConfirmModal() {
    const opened = setOrderDetailModal("受注詳細編集", "受注詳細情報を編集しますか？", '<button type="button" class="btn-cancel" id="btnOrderDetailEditNo">いいえ</button><button type="button" class="btn-confirm" id="btnOrderDetailEditYes">はい</button>', closeOrderDetailModal);
    if (!opened) {
        if (window.confirm("受注詳細情報を編集しますか？")) {
            switchToEditMode();
        }
        return;
    }
    document.getElementById("btnOrderDetailEditNo")?.addEventListener("click", closeOrderDetailModal);
    document.getElementById("btnOrderDetailEditYes")?.addEventListener("click", () => {
        closeOrderDetailModal();
        switchToEditMode();
        //フォームの上端までスクロール
        document.querySelector(".inner-03-01-01").scrollIntoView(true);
    });
}
/**
 * 保存完了モーダル表示
 *  保存成功メッセージを表示し、閉じた後にページを再読み込みする。
 */
function openOrderDetailCompleteModal(message = "保存しました。") {
    const closeAndReload = () => {
        closeOrderDetailModal();
        window.location.reload();
    };
    const opened = setOrderDetailModal("受注情報保存", message, '<button type="button" class="btn-cancel" id="btnOrderDetailCompleteClose">閉じる</button>', closeAndReload);
    if (!opened) {
        alert(message);
        window.location.reload();
        return;
    }
    document.getElementById("btnOrderDetailCompleteClose")?.addEventListener("click", closeAndReload);
}
/**
 * 保存エラーモーダル表示
 *  保存失敗または通信エラーの内容を既存モーダルで表示する。
 */
function openOrderDetailErrorModal(message = "保存に失敗しました。") {
    const opened = setOrderDetailModal("受注情報更新失敗", message, '<button type="button" class="btn-cancel" id="btnOrderDetailErrorClose">閉じる</button>', closeOrderDetailModal);
    if (!opened) {
        alert(message);
        return;
    }
    document.getElementById("btnOrderDetailErrorClose")?.addEventListener("click", closeOrderDetailModal);
}
/**
 * 受注詳細保存
 *  フォーム内容をAjax送信し、成功時は完了モーダル、失敗時はエラーモーダルを表示する。
 */
async function saveOrderDetail() {
    closeOrderDetailModal();
    const inputForm = document.querySelector('form[name="inputForm"]');
    if (!inputForm) {
        openOrderDetailErrorModal("フォーム情報を取得できませんでした。");
        return;
    }
    const formData = new FormData(inputForm);
    const orderId = new URLSearchParams(window.location.search).get("orderId") || "";
    formData.set("action", "saveOrderDetail");
    formData.set("orderId", orderId);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) {
            throw new Error("Network error");
        }
        const result = await response.json();
        if (result.status === "success") {
            switchToReadonlyMode();
            openOrderDetailCompleteModal(result.msg || "保存しました。");
        } else {
            openOrderDetailErrorModal(result.msg || "保存に失敗しました。");
        }
    } catch (error) {
        console.error("保存エラー:", error);
        openOrderDetailErrorModal("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
