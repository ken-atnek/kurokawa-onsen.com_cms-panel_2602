/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_master01_01_03.php";

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
    if (errFlag === 0) {
        //送信用FormData生成
        const sFd = new FormData(validationForm);
        // hiddenのactionが存在しても上書きできるよう set を使う
        sFd.set("action", "sendInput");
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
 * 画像セレクトモーダルボックス
 *
 */
async function selectFileModal(el, action, type, target, shopId, noUpDateKey) {
    //送信用フォーム生成
    let sFd = new FormData();
    sFd.append("action", action);
    sFd.append("selectType", type);
    sFd.append("target", target);
    sFd.append("shopId", shopId);
    sFd.append("noUpDateKey", noUpDateKey);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        let list = await response.json();
        if (typeof list === "string") {
            list = JSON.parse(list);
        }
        //表示変更
        const modal = document.getElementById("modalSelectBlock");
        if (modal) modal.remove();
        document.querySelector("main").insertAdjacentHTML("afterend", list["tag"]);
        //モーダル表示中はスクロール固定
        document.documentElement.style.overflow = "hidden";
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * ナビボタンからフォルダ変更
 *
 */
async function changeFolder(el, action, shopId, folderId, folderName, noUpDateKey) {
    //送信用フォーム生成
    let sFd = new FormData();
    sFd.append("action", action);
    sFd.append("shopId", shopId);
    sFd.append("folderId", folderId);
    sFd.append("folderName", folderName);
    sFd.append("noUpDateKey", noUpDateKey);
    //フォルダ切替後も、選択対象(target)と種別(selectType)を保持する
    if (arguments.length >= 8) {
        const selectType = arguments[6];
        const target = arguments[7];
        if (selectType !== undefined && selectType !== null) sFd.append("selectType", selectType);
        if (target !== undefined && target !== null) sFd.append("target", target);
    }
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        let list = await response.json();
        if (typeof list === "string") {
            list = JSON.parse(list);
        }
        //表示変更
        const modal = document.getElementById("modalSelectBlock");
        if (modal) modal.remove();
        document.querySelector("main").insertAdjacentHTML("afterend", list["tag"]);
        //モーダル表示中はスクロール固定
        document.documentElement.style.overflow = "hidden";
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 画像セレクト
 *
 */
function selectFile(el, action, type, target, shopId, photoFilePath, photoPreviewUrl, photoTitle, noUpDateKey) {
    //ターゲットタグのvalueを上書き
    let targetTag = document.querySelector("input[name=" + target + "]");
    if (targetTag) targetTag.value = photoFilePath;
    //プレビュー画像の表示
    if (type == "main") {
        document.getElementById("ps_" + target).srcset = photoPreviewUrl;
        document.getElementById("pi_" + target).src = photoPreviewUrl;
    } else {
        const previewTag = `
                        <div class="check-details">
                            <div class="image">
                                <picture>
                                    <source srcset="${photoPreviewUrl}" id="ps_${target}">
                                    <img src="${photoPreviewUrl}" id="pi_${target}" alt="">
                                </picture>
                            </div>
                            <div class="wrap_btn">
                                <div class="item_reload">
                                    <button type="button" onclick="selectFileModal(this,'selectFileModal','${type}','${target}','${shopId}','${noUpDateKey}');"></button>
                                </div>
                                <div class="item_delate">
                                    <button type="button" onclick="deleteFile(this,'deleteFile','${type}','${target}','${shopId}','${noUpDateKey}');"></button>
                                </div>
                            </div>
                        </div>
                `;
        let liElement = document.getElementById("select_image_" + target);
        if (liElement) {
            if (liElement.children && liElement.children[0]) {
                liElement.children[0].remove();
            }
            liElement.classList.remove("list_select-image");
            liElement.insertAdjacentHTML("afterbegin", previewTag);
        }
    }
    const modal = document.getElementById("modalSelectBlock");
    if (modal) modal.classList.remove("is-active");
    //モーダルを閉じたのでスクロール復帰
    document.documentElement.style.overflow = "";
}
/**
 * 選択ファイル削除
 *
 */
function deleteFile(el, mode, type, target, shopId, noUpDateKey) {
    //ターゲットタグのvalueを上書き
    let targetTag = document.querySelector("input[name=" + target + "]");
    if (targetTag) targetTag.value = "";
    //プレビュー画像の表示
    const previewImage = "../assets/images/no-image.webp";
    const sourceEl = document.getElementById("ps_" + target);
    const imgEl = document.getElementById("pi_" + target);
    if (sourceEl) sourceEl.srcset = previewImage;
    if (imgEl) imgEl.src = previewImage;
    if (type == "image") {
        const liElement = document.getElementById("select_image_" + target);
        if (liElement) {
            //既存のcheck-detailsを除去して「写真を選択」へ戻す
            const checkDetails = liElement.querySelector(".check-details");
            if (checkDetails) checkDetails.remove();
            liElement.classList.add("list_select-image");
            const selectButton = `<button type="button" onclick="selectFileModal(this,'selectFileModal','${type}','${target}','${shopId}','${noUpDateKey}');">写真を選択</button>`;
            //既にボタンがある場合は二重追加しない
            if (!liElement.querySelector("button")) {
                liElement.insertAdjacentHTML("afterbegin", selectButton);
            }
        }
    }
}
