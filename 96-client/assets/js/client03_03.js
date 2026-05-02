/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client03_03.php";

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

function appendProductImageOrder(formData) {
    const previewBlock = document.getElementById("js-previewBlock-productImage");
    if (!previewBlock) {
        return;
    }
    previewBlock.querySelectorAll("li").forEach((li) => {
        const fileName = li.getAttribute("data-file-name") || li.getAttribute("data-name") || "";
        if (fileName !== "") {
            formData.append("image_order[]", fileName);
        }
    });
}

async function saveProductImagesOnly(options = {}) {
    const method = getCurrentMethod();
    const productId = getCurrentProductId();
    if (method !== "edit" || !productId) {
        return true;
    }
    const form = document.querySelector("form[name='inputForm']");
    if (!form) {
        return false;
    }
    const formData = new FormData();
    formData.append("action", "saveProductImages");
    formData.append("method", "edit");
    formData.append("productId", productId);
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    if (noUpDateKeyInput && noUpDateKeyInput.value) {
        formData.append("noUpDateKey", noUpDateKeyInput.value);
    }
    formData.append("up_image_area[]", "product_image");
    appendProductImageOrder(formData);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) {
            throw new Error("Network response was not ok");
        }
        const list = await response.json();
        if (list["status"] === "error") {
            showModalMessage(list["msg"] || "画像の保存に失敗しました。", list["title"] || "画像保存エラー");
            return false;
        }
        if (options.showCompleteModal === true) {
            showModalMessage(list["msg"] || "画像を保存しました。", list["title"] || "画像保存");
        }
        return true;
    } catch (error) {
        console.error("画像保存エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
        return false;
    }
}

window.confirmProductImageDelete = function () {
    return new Promise((resolve) => {
        const blockModal = document.getElementById("modalBlock");
        if (!blockModal) {
            resolve(window.confirm("この商品画像を削除しますか？"));
            return;
        }
        const titleArea = blockModal.querySelector(".box-title p");
        const messageArea = blockModal.querySelector(".box-details p");
        const buttonArea = blockModal.querySelector(".box-btn");
        const topCloseButton = blockModal.querySelector(".box-title")?.querySelector("button");
        const closeConfirmModal = (result) => {
            blockModal.classList.remove("is-active");
            document.documentElement.style.overflow = "";
            resolve(result);
        };
        if (titleArea) {
            titleArea.innerHTML = "商品画像削除";
        }
        if (messageArea) {
            messageArea.innerHTML = "この商品画像を削除しますか？";
        }
        if (buttonArea) {
            buttonArea.querySelectorAll("button").forEach((button) => {
                button.remove();
            });
            const cancelButton = document.createElement("button");
            cancelButton.type = "button";
            cancelButton.className = "btn-cancel";
            cancelButton.textContent = "いいえ";
            cancelButton.addEventListener("click", () => closeConfirmModal(false));
            const confirmButton = document.createElement("button");
            confirmButton.type = "button";
            confirmButton.className = "btn-confirm";
            confirmButton.textContent = "はい";
            confirmButton.addEventListener("click", () => closeConfirmModal(true));
            buttonArea.appendChild(cancelButton);
            buttonArea.appendChild(confirmButton);
        }
        if (topCloseButton) {
            topCloseButton.removeAttribute("onclick");
            topCloseButton.onclick = () => closeConfirmModal(false);
        }
        blockModal.style.display = "";
        blockModal.classList.add("is-active");
        document.documentElement.style.overflow = "hidden";
    });
};
/**
 * 送信
 *
 */
async function sendInput(targetForm = null) {
    //クリックされたボタンの属するformを優先（編集フォーム対応）
    const target = typeof event !== "undefined" ? event.target : null;
    const currentForm = target?.closest ? target.closest("form") : null;
    const fallbackValidationForm = typeof validationForm !== "undefined" ? validationForm : null;
    const form = targetForm || currentForm || fallbackValidationForm;
    if (!form) {
        return null;
    }
    //HTMLのrequired等を最優先でチェック（radio等のグループも含む）
    if (!form.checkValidity()) {
        form.reportValidity();
        return null;
    }
    let errFlag = 0;
    //inputForm は従来通り checkRequiredElem() を使用（editForm は動的要素のためHTML requiredに委ねる）
    if (typeof validationForm !== "undefined" && form === validationForm) {
        //「required」を指定した要素を検証
        let chk_required = checkRequiredElem();
        if (chk_required == "err") {
            errFlag = 1;
        }
    }
    if (errFlag === 0) {
        //送信用FormData生成
        const sFd = new FormData(form);
        sFd.append("action", "sendInput");
        appendProductImageOrder(sFd);
        try {
            const response = await fetch(requestURL, {
                method: "POST",
                body: sFd,
            });
            if (!response.ok) throw new Error("Network response was not ok");
            const list = await response.json();
            //モーダルボックス
            let blockModal = document.getElementById("modalBlock");
            //サーバー応答がエラーの場合
            if (list["status"] == "error") {
                const title = list["title"] || "登録失敗";
                const msgRaw = list["msg"] || "登録に失敗しました。\nお手数ですが最初からやり直してください。";
                const msg = String(msgRaw).replace(/\n/g, "<br>");
                blockModal.querySelector(".box-title p").innerHTML = title;
                blockModal.querySelector(".box-details p").innerHTML = msg;
                //ボタン再生成
                let buttonList = blockModal.querySelector(".box-btn").querySelectorAll("button");
                //ボタンタグを削除
                buttonList.forEach((ElementButton) => {
                    ElementButton.remove();
                });
                //ボタン生成
                let newButton = '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>';
                blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
            } else {
                //サーバー応答が正常の場合
                blockModal.querySelector(".box-title p").innerHTML = list["title"];
                blockModal.querySelector(".box-details p").innerHTML = String(list["msg"]).replace(/\n/g, "<br>");
                //ボタン再生成
                let buttonList = blockModal.querySelector(".box-btn").querySelectorAll("button");
                //ボタンタグを削除
                buttonList.forEach((ElementButton) => {
                    ElementButton.remove();
                });
                const savedProductId = list["product_id"] || getCurrentProductId();
                const returnUrl = savedProductId ? "client03_03.php?method=edit&productId=" + encodeURIComponent(savedProductId) : "client03_02.php";
                //一覧へ戻るボタン生成
                let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('${returnUrl}');">閉じる</button>`;
                blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                //「✕」ボタンも変更
                blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", `closeModalToPage('${returnUrl}')`);
            }
            blockModal.classList.add("is-active");
            document.documentElement.style.overflow = "hidden";
            return list;
        } catch (error) {
            //通信エラー時の処理
            console.error("送信エラー:", error);
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
            return null;
        }
    }
    return null;
}
/**
 * 商品規格：「使用する」「使用しない」切り替え
 *
 */
function toggleSpecUsage() {
    const selectedSpecUsage = document.querySelector('input[name="specUsageFlg_01"]:checked');
    const specUsageValue = selectedSpecUsage ? selectedSpecUsage.value : "1";
    const priceBoxes = document.querySelectorAll(".box-price");
    const stockBoxes = document.querySelectorAll(".box-stock");
    const lockBoxes = document.querySelectorAll(".box-lock");
    const variantBlock = document.querySelector("article.block-variant");
    if (specUsageValue === "2") {
        priceBoxes.forEach((elem) => {
            elem.style.display = "none";
        });
        stockBoxes.forEach((elem) => {
            elem.style.display = "none";
        });
        lockBoxes.forEach((elem) => {
            elem.style.display = "";
        });
        if (variantBlock) {
            variantBlock.style.display = "";
        }
        return;
    }
    priceBoxes.forEach((elem) => {
        elem.style.display = "";
    });
    stockBoxes.forEach((elem) => {
        elem.style.display = "";
    });
    lockBoxes.forEach((elem) => {
        elem.style.display = "none";
    });
    if (variantBlock) {
        variantBlock.style.display = "none";
    }
}
/**
 * 商品規格：保存して移動
 *
 */
async function moveToProductClassSettingWithSave() {
    const targetForm = document.querySelector("form[name='inputForm']");
    const result = await sendInput(targetForm);
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        return;
    }
    const titleText = blockModal.querySelector(".box-title p")?.textContent?.trim() ?? "";
    if (titleText === "セッションエラー" || titleText === "権限エラー" || titleText === "入力エラー" || titleText === "登録エラー" || titleText === "更新エラー" || titleText === "削除エラー" || titleText === "未対応" || titleText === "不正なリクエスト") {
        return;
    }
    if (!result) {
        return;
    }
    const productId = result.product_id || getCurrentProductId();
    const url = buildProductClassSettingUrl(productId);
    if (!url) {
        showModalMessage("商品IDを取得できなかったため、規格設定へ移動できませんでした。", "入力エラー");
        return;
    }
    window.location.href = url;
}
/**
 * 「この商品の規格を確認」ボタン押下
 *
 */
function goToProductClassSetting(event) {
    if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
    }
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        return;
    }
    const titleArea = blockModal.querySelector(".box-title p");
    const messageArea = blockModal.querySelector(".box-details p");
    const buttonArea = blockModal.querySelector(".box-btn");
    const topCloseButton = blockModal.querySelector(".box-title")?.querySelector("button");
    if (!titleArea || !messageArea || !buttonArea) {
        return;
    }
    const method = getCurrentMethod();
    const productId = getCurrentProductId();
    const isNewProduct = method === "new";
    titleArea.innerHTML = "規格設定へ移動";
    if (isNewProduct) {
        messageArea.innerHTML = "この商品はまだ保存されていません。<br>規格を設定するには先に商品情報を保存する必要があります。<br><br>「保存して移動」を押すと商品情報を保存してから規格設定画面へ移動します。";
    } else {
        messageArea.innerHTML = "現在の入力内容を保存してから規格設定画面へ移動しますか？";
    }
    buttonArea.querySelectorAll("button").forEach((button) => {
        button.remove();
    });
    if (isNewProduct) {
        buttonArea.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="closeModal();">キャンセル</button>' + '<button type="button" class="btn-confirm" onclick="moveToProductClassSettingWithSave();">保存して移動</button>');
    } else {
        const withoutSaveUrl = buildProductClassSettingUrl(productId);
        const withoutSaveOnclick = withoutSaveUrl ? `window.location.href='${withoutSaveUrl}';` : `showModalMessage('商品を保存してから規格設定へ移動してください。', '入力エラー');`;
        buttonArea.insertAdjacentHTML("beforeend", `<button type="button" class="btn-cancel" onclick="${withoutSaveOnclick}">保存せずに移動</button>` + '<button type="button" class="btn-confirm" onclick="moveToProductClassSettingWithSave();">保存して移動</button>');
    }
    if (topCloseButton) {
        topCloseButton.setAttribute("onclick", "closeModal()");
    }
    blockModal.style.display = "";
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}

function syncStockUnlimitedState() {
    const stockUnlimited = document.getElementById("stockUnlimited");
    const stockQuantity = document.getElementById("stockQuantity");
    if (!stockUnlimited || !stockQuantity) {
        return;
    }
    if (stockUnlimited.checked) {
        stockQuantity.disabled = false;
        stockQuantity.readOnly = true;
        stockQuantity.value = "";
        return;
    }
    stockQuantity.disabled = false;
    stockQuantity.readOnly = false;
}
/**
 * 現在画面の productId を取得（hidden input → URLパラメータの順で探す）
 */
function getCurrentProductId() {
    const hidden = document.querySelector('input[name="productId"]');
    const value = hidden && hidden.value ? hidden.value : new URLSearchParams(window.location.search).get("productId");
    const id = parseInt(value, 10);
    return Number.isInteger(id) && id > 0 ? id : null;
}
/**
 * 現在画面の method を取得（hidden input → URLパラメータ → productId の順で判定）
 */
function getCurrentMethod() {
    const methodInput = document.querySelector('input[name="method"]');
    if (methodInput && methodInput.value) {
        return methodInput.value;
    }
    const params = new URLSearchParams(window.location.search);
    const methodParam = params.get("method");
    if (methodParam) {
        return methodParam;
    }
    const productIdParam = params.get("productId");
    if (productIdParam) {
        return "edit";
    }
    return "new";
}
/**
 * 規格設定画面URLを生成（productId が無効なら null を返す）
 */
function buildProductClassSettingUrl(productId) {
    const id = parseInt(productId, 10);
    if (!Number.isInteger(id) || id < 1) {
        return null;
    }
    return "./client03_03_01.php?productId=" + encodeURIComponent(id);
}

document.addEventListener("DOMContentLoaded", () => {
    const specUsageRadios = document.querySelectorAll('input[name="specUsageFlg_01"]');
    const stockUnlimited = document.getElementById("stockUnlimited");
    const stockQuantity = document.getElementById("stockQuantity");
    specUsageRadios.forEach((radio) => {
        radio.addEventListener("change", toggleSpecUsage);
    });
    toggleSpecUsage();
    if (stockUnlimited) {
        stockUnlimited.addEventListener("change", syncStockUnlimitedState);
    }
    if (stockUnlimited && stockQuantity) {
        const unlockStockInput = () => {
            if (stockUnlimited.checked) {
                stockUnlimited.checked = false;
                stockQuantity.disabled = false;
                stockQuantity.readOnly = false;
                stockQuantity.focus();
            }
        };
        stockQuantity.addEventListener("click", unlockStockInput);
        stockQuantity.addEventListener("focus", unlockStockInput);
    }
    syncStockUnlimitedState();
    const productImagePreviewBlock = document.getElementById("js-previewBlock-productImage");
    if (productImagePreviewBlock) {
        productImagePreviewBlock.addEventListener("productImageOrderChanged", async () => {
            await saveProductImagesOnly({ showCompleteModal: false });
        });
        productImagePreviewBlock.addEventListener("productImageDeleted", async () => {
            const saved = await saveProductImagesOnly({ showCompleteModal: false });
            if (saved && getCurrentMethod() === "edit") {
                showModalMessage("商品画像を削除しました。", "商品画像削除");
            }
        });
    }
});
