/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client01_02.php";
//送信中フラグ
let isSending = false;

/**
 * 店舗基本情報の入力エラーを既存モーダルで表示する
 *  送信前の営業時間・英語文言検証に使用する
 */
function showShopInputError(message) {
    const modal = document.getElementById("modalBlock");
    modal.querySelector(".box-title p").textContent = "入力エラー";
    modal.querySelector(".box-details p").textContent = message;
    const buttons = modal.querySelector(".box-btn");
    buttons.querySelectorAll("button").forEach((button) => button.remove());
    buttons.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>');
    modal.querySelector(".box-title button").setAttribute("onclick", "closeModal()");
    modal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}

/**
 * 共通営業時間の入力ペアと英語表示文・住所の文字種を検証する
 *  英語住所1と営業時間の不足を送信前に拒否する
 */
function validateShopEnglishInputs(form) {
    for (const [field, label] of [["form04_01_en", "住所1 (英語)"], ["form04_02_en", "住所2 (英語)"], ["form04_03_en", "住所3 (英語)"]]) {
        const value = form.elements.namedItem(field)?.value ?? "";
        if (field === "form04_01_en" && value.trim() === "") {
            showShopInputError("住所1 (英語)は必須です。");
            return false;
        }
        if (value.length > 255) {
            showShopInputError(`${label}は255文字以内で入力してください。`);
            return false;
        }
        if (/[^\x20-\x7E¥–]/u.test(value)) {
            showShopInputError(`${label}に使用できない文字が含まれています。`);
            return false;
        }
    }
    for (const prefix of ["form08_01", "form08_02"]) {
        const value = (suffix) => form.querySelector(`input[data-selectbox-hidden][name="${prefix}_${suffix}"]`)?.value ?? "";
        const openHour = value("open_hour");
        const openMinute = value("open_minute");
        const closeHour = value("close_hour");
        const closeMinute = value("close_minute");
        const openComplete = openHour !== "" && openMinute !== "";
        const closeComplete = closeHour !== "" && closeMinute !== "";
        if ((openHour !== "" || openMinute !== "") && !openComplete || closeComplete && !openComplete) {
            showShopInputError("OPEN時間が入力されていません。");
            return false;
        }
        if ((closeHour !== "" || closeMinute !== "") && !closeComplete || openComplete && !closeComplete) {
            showShopInputError("CLOSE時間が入力されていません。");
            return false;
        }
        const note = form.elements.namedItem(`${prefix}_note_en`)?.value ?? "";
        if (/[^\x20-\x7E¥–]/u.test(note) || new TextEncoder().encode(note).length > 65535) {
            showShopInputError("英語備考に使用できない文字が含まれています。");
            return false;
        }
        if (note.trim() !== "" && !openComplete) {
            showShopInputError("英語の営業時間が未入力です。");
            return false;
        }
    }
    const holiday = form.elements.namedItem("form09_en")?.value ?? "";
    if (/[^\x20-\x7E¥–\r\n]/u.test(holiday) || new TextEncoder().encode(holiday).length > 65535) {
        showShopInputError("英語の店休日に使用できない文字が含まれています。");
        return false;
    }
    return true;
}

/**
 * 送信
 *
 */
async function sendInput() {
    if (isSending) return;
    //.validationForm を指定した form 要素が存在すれば
    if (!validationForm) return;
    if (!validateShopEnglishInputs(validationForm)) return;
    //HTMLのrequired等を最優先でチェック（radio等のグループも含む）
    if (!validationForm.checkValidity()) {
        validationForm.reportValidity();
        return;
    }
    let errFlag = 0;
    //「required」を指定した要素を検証
    let chk_required = checkRequiredElem();
    if (chk_required == "err") {
        errFlag = 1;
    }
    //.email を指定した要素
    if (errFlag === 0) {
        //.email を指定した要素を検証
        let chk_email = checkEmailElem();
        if (chk_email == "err") {
            errFlag = 1;
        }
    }
    //.phone_number を指定した要素
    if (errFlag === 0) {
        //.phone_number を指定した要素を検証
        let chk_tel = checkTelElem();
        if (chk_tel == "err") {
            errFlag = 1;
        }
    }
    //.required-selectbox を指定した要素
    if (errFlag === 0) {
        //.required-selectbox を指定した要素を検証
        let chk_selectbox = checkSelectboxElem();
        if (chk_selectbox == "err") {
            errFlag = 1;
        }
    }
    //.required-checkbox を指定した要素
    if (errFlag === 0) {
        //.required-checkbox を指定した要素を検証
        let chk_checkbox = checkCheckboxElem();
        if (chk_checkbox == "err") {
            errFlag = 1;
        }
    }
    if (errFlag === 0) {
        isSending = true;
        const submitButton = validationForm.querySelector(".btn-submit");
        if (submitButton) submitButton.disabled = true;
        //送信用FormData生成
        const sFd = new FormData(validationForm);
        sFd.append("action", "sendInput");
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
                //一覧へ戻るボタン生成
                let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('client01_02.php');">閉じる</button>`;
                blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                //「✕」ボタンも変更
                blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", "closeModalToPage('client01_02.php')");
            }
            blockModal.classList.add("is-active");
            document.documentElement.style.overflow = "hidden";
        } catch (error) {
            //通信エラー時の処理
            console.error("送信エラー:", error);
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
        } finally {
            isSending = false;
            if (submitButton) submitButton.disabled = false;
        }
    }
}
/**
 * パスワード表示ボタン
 *
 */
function togglePassword(el, target) {
    const targetTag = document.getElementById(target);
    targetTag.type = targetTag.type === "password" ? "text" : "password";
    //アイコン変更
    if (el.classList.contains("is-close") === true) {
        el.classList.remove("is-close");
        el.classList.add("is-open");
    } else {
        el.classList.remove("is-open");
        el.classList.add("is-close");
    }
}
/**
 * 営業時間帯表示切替
 *
 */
function toggleBusinessHoursTypes() {
    const businessHoursBlock = document.getElementById("blockBusinessHoursTypes");
    if (!businessHoursBlock) return;
    const checkedShopType = document.querySelector('input[name="form02"]:checked');
    const isFoodShop = checkedShopType && checkedShopType.value === "food";
    const checkboxes = businessHoursBlock.querySelectorAll('input[type="checkbox"]');
    businessHoursBlock.style.display = isFoodShop ? "" : "none";
    checkboxes.forEach((checkbox) => {
        checkbox.disabled = !isFoodShop;
    });
}
document.addEventListener("DOMContentLoaded", () => {
    toggleBusinessHoursTypes();
    document.querySelectorAll('input[name="form02"]').forEach((radio) => {
        radio.addEventListener("change", toggleBusinessHoursTypes);
    });
});
