/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_master01_02.php";

/**
 * 送信
 *
 */
async function sendInput() {
    //.validationForm を指定した form 要素が存在すれば
    if (!validationForm) return;
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
                let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('master01_01.php');">一覧に戻る</button>`;
                blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                //「✕」ボタンも変更
                blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", "closeModalToPage('master01_01.php')");
            }
            blockModal.classList.add("is-active");
            document.documentElement.style.overflow = "hidden";
        } catch (error) {
            //通信エラー時の処理
            console.error("送信エラー:", error);
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
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
