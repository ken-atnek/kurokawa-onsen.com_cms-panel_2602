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
    document.getElementById("btnEdit")?.addEventListener("click", openOrderDetailEditConfirmModal);
    document.getElementById("btnCancel")?.addEventListener("click", () => {
        window.location.reload();
    });
    document.getElementById("btnSave")?.addEventListener("click", openOrderDetailSaveConfirmModal);
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
    if (messageEl) messageEl.textContent = message;
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
    const opened = setOrderDetailModal("受注情報保存エラー", message, '<button type="button" class="btn-cancel" id="btnOrderDetailErrorClose">閉じる</button>', closeOrderDetailModal);
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
