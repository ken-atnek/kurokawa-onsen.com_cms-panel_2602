/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_master01_01_01.php";

/**
 * モーダル共通ヘルパ
 *  - HTML側は #modalBlock .box-details p / .box-btn を想定
 *
 */
function getModalParts() {
    const modal = document.getElementById("modalBlock");
    if (!modal) return { modal: null, messageEl: null, buttonBox: null, root: document.documentElement };
    const messageEl = modal.querySelector(".box-details p");
    const buttonBox = modal.querySelector(".box-details .box-btn");
    const root = typeof htmlElement !== "undefined" && htmlElement ? htmlElement : document.documentElement;
    return { modal, messageEl, buttonBox, root };
}
function showModalMessage(html) {
    const { modal, messageEl, root } = getModalParts();
    if (!modal) return;
    modal.classList.add("is-active");
    root.style.overflow = "hidden";
    if (messageEl) messageEl.innerHTML = html;
}
function clearModalDeleteButton() {
    const { modal } = getModalParts();
    if (!modal) return;
    const old = modal.querySelector(".btn-confirm");
    if (old) old.remove();
}
function appendModalDeleteButton(onClick) {
    const { modal, buttonBox } = getModalParts();
    if (!modal || !buttonBox) return;
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn-confirm";
    btn.textContent = "削除";
    btn.addEventListener("click", onClick);
    buttonBox.appendChild(btn);
}
//選択されたファイル情報を保持（複数関数から参照）
let keepFiles = null;

/**
 * 写真選択（file選択のみ）初期化：画面差し替え後も呼べるようにしておく
 *
 */
function initPhotoFileSelect(area = "photoImage") {
    const dropZone = document.getElementById("js-dragDrop-" + area);
    const selectFileButton = document.getElementById("js-fileSelect-" + area);
    const fileInput = document.getElementById("js-fileElem-" + area);
    const inputMode = document.getElementById("js-uploadImageMode-" + area);
    const inputArea = document.getElementById("js-uploadImageArea-" + area);
    const previewBlock = dropZone ? dropZone.querySelector("#fileList") : null;
    const fileError = document.getElementById("js-fileError-" + area);
    if (!dropZone || !selectFileButton || !fileInput || !previewBlock) return;
    if (typeof initDropZone !== "function") return;
    initDropZone({
        dropZone: dropZone,
        selectFileButton: selectFileButton,
        fileInput: fileInput,
        inputMode: inputMode,
        inputArea: inputArea,
        previewBlock: previewBlock,
        fileError: fileError,
    });
}
/**
 * 新規フォルダ追加
 *
 */
async function addFolders(el, action) {
    //フォルダ名入力チェック
    let folderName = document.querySelector("input[name='addFolderName']").value;
    if (folderName == "") {
        showModalMessage("フォルダ名を入力してください。");
        return false;
    }
    //送信用フォーム生成
    const addFolder = document.querySelector("form[name='addFolder']");
    let sFd = new FormData(addFolder);
    sFd.append("action", action);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const list = await response.json();
        if (list["status"] == "error") {
            showModalMessage(list["msg"]);
        } else {
            showModalMessage(list["msg"]);
            //表示変更
            document.getElementById("block01").remove();
            document.getElementById("block02").remove();
            document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
            //差し替え後のイベント再バインド
            initPhotoFileSelect("photoImage");
        }
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * フォルダ名変更
 *
 */
async function editFolderNames() {
    //フォルダ名入力チェック
    let folderName = document.querySelector("input[name='addFolderName']").value;
    if (folderName == "") {
        showModalMessage("フォルダ名を入力してください。");
        return false;
    }
    //送信用フォーム生成
    let addFolder = document.querySelector("form[name='addFolder']");
    let sFd = new FormData(addFolder);
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
        if (list["status"] == "error") {
            showModalMessage(list["msg"]);
        } else {
            showModalMessage(list["msg"]);
            //表示変更
            document.getElementById("block01").remove();
            document.getElementById("block02").remove();
            document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
            //差し替え後のイベント再バインド
            initPhotoFileSelect("photoImage");
        }
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * フォルダ名変更：エリア設定
 *
 */
async function setEditFolderName(el, action, shopId, folderId, folderName, noUpDateKey) {
    //送信用フォーム生成
    let sFd = new FormData();
    sFd.append("action", action);
    sFd.append("shopId", shopId);
    sFd.append("folderId", folderId);
    sFd.append("oldName", folderName);
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
        //console.log(list);
        //表示変更
        document.getElementById("block01").remove();
        document.getElementById("block02").remove();
        document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
        //input要素の背景カラー変更
        document.querySelector("input[name='addFolderName']").style.backgroundColor = "#ffe9e9";
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * フォルダ削除確認
 *
 */
function folderDeleteCheck(el, action, shopId, folderId, folderName, noUpDateKey) {
    clearModalDeleteButton();
    showModalMessage("「" + folderName + "」を削除します。<br>よろしいですか？");
    appendModalDeleteButton(() => deleteFolder(null, action, shopId, folderId, folderName, noUpDateKey));
}
/**
 * フォルダ削除
 *
 */
async function deleteFolder(el, action, shopId, folderId, folderName, noUpDateKey) {
    //送信用フォーム生成
    let sFd = new FormData();
    sFd.append("action", action);
    sFd.append("shopId", shopId);
    sFd.append("folderId", folderId);
    sFd.append("folderName", folderName);
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
        clearModalDeleteButton();
        showModalMessage(list["msg"]);
        document.getElementById("block01").remove();
        document.getElementById("block02").remove();
        document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
        //再度写真登録ファンクション生成
        restartPhotoFunction();
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
        //console.log(list);
        //表示変更
        document.getElementById("block02").remove();
        document.getElementById("block01").insertAdjacentHTML("afterend", list["tag"]);
        document.querySelector(".box_add-folder h4").innerHTML = "新規フォルダ名";
        document.querySelector("input[name='addFolderName']").value = "";
        //setEditFolderName で付与した背景色が残る場合があるためリセット
        document.querySelector("input[name='addFolderName']").style.backgroundColor = "";
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 選択ファイル削除
 *
 */
function deleteFile(el) {
    keepFiles = null;
    const fileList = document.getElementById("fileList");
    if (fileList) {
        fileList.innerHTML = "<li>追加する写真、画像を選択して下さい。</li>";
    }
    //同名ファイルを再選択できるよう、file input をリセット
    const fileInput = document.getElementById("js-fileElem-photoImage");
    if (fileInput) {
        fileInput.value = "";
        fileInput.removeAttribute("data-mode");
        fileInput.removeAttribute("data-replace-index");
    }
    //onlyモード時に選択UIが隠れたままにならないようにする
    const dropZone = document.getElementById("js-dragDrop-photoImage");
    if (dropZone) {
        dropZone.classList.remove("is-active");
    }
}
/**
 * 写真更新用ファンクション再生成
 *
 */
function restartPhotoFunction() {
    if (typeof window.initSelectBoxes === "function") {
        window.initSelectBoxes(document);
    }
    initPhotoFileSelect("photoImage");
    initPhotoPreviewReplace("photoImage");
    installPreviewDraftDiscardListener("photoImage");
}
/**
 * プレビュー（checkPhoto）専用：写真入れ替え
 *  - 通常のdropZone(initDropZone)は fileList 前提のためプレビューでは初期化されない
 *
 */
function initPhotoPreviewReplace(area = "photoImage") {
    const previewContainer = document.getElementById("preview-container");
    if (!previewContainer) return;
    const selectButton = document.getElementById("js-fileSelect-" + area);
    const fileInput = document.getElementById("js-fileElem-" + area);
    const sourceEl = document.getElementById("preview-source");
    const imgEl = document.getElementById("preview-image");
    if (!selectButton || !fileInput || !sourceEl || !imgEl) return;
    if (selectButton.dataset.bound === "1") return;
    selectButton.dataset.bound = "1";
    selectButton.addEventListener("click", () => {
        //同名ファイルを再選択できるようクリアしてから開く
        fileInput.value = "";
        fileInput.click();
    });
    fileInput.addEventListener("change", async () => {
        if (!fileInput.files || fileInput.files.length === 0) return;
        const file = fileInput.files[0];
        //送信用
        const sFd = new FormData();
        sFd.append("action", "replaceUploadImage");
        sFd.append("replace_index", "0");
        sFd.append("method", "new_image");
        sFd.append("up_image_mode", "only");
        sFd.append("up_image_area[]", "photo_image");
        sFd.append("images_tmp", file);
        const noUpDateKeyEl = document.querySelector('input[type=hidden][name="noUpDateKey"]');
        const noUpDateKey = noUpDateKeyEl ? String(noUpDateKeyEl.value || "") : "";
        if (noUpDateKey) sFd.append("noUpDateKey", noUpDateKey);
        try {
            const response = await fetch(requestURL, {
                method: "POST",
                body: sFd,
            });
            if (!response.ok) throw new Error("Network response was not ok");
            let list = await response.json();
            if (typeof list === "string") list = JSON.parse(list);
            if (list["status"] === "error") {
                showModalMessage(list["msg"] || "写真の入れ替えに失敗しました。");
                return;
            }
            const url = list["file_url"] || "";
            if (url) {
                sourceEl.setAttribute("srcset", url);
                imgEl.setAttribute("src", url);
            }
        } catch (error) {
            console.error("送信エラー:", error);
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
        }
    });
}
/**
 * プレビュー（checkPhoto）離脱時：アップロードドラフト破棄
 *
 */
function installPreviewDraftDiscardListener(area = "photoImage") {
    const previewContainer = document.getElementById("preview-container");
    if (!previewContainer) return;
    if (window.__rwPreviewDiscardInstalled) return;
    window.__rwPreviewDiscardInstalled = true;
    const buildParams = () => {
        const params = new URLSearchParams();
        params.append("action", "discardUploadDraft");
        const noUpDateKeyEl = document.querySelector('input[type=hidden][name="noUpDateKey"]');
        const noUpDateKey = noUpDateKeyEl ? String(noUpDateKeyEl.value || "") : "";
        if (noUpDateKey) params.append("noUpDateKey", noUpDateKey);
        params.append("up_image_area[]", "photo_image");
        return params;
    };
    const discardNow = () => {
        if (window.__rwUploadDraftDiscardDisable) return;
        if (window.__rwPreviewDiscardSent) return;
        window.__rwPreviewDiscardSent = true;
        const params = buildParams();
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(requestURL, params);
            } else {
                fetch(requestURL, { method: "POST", body: params, keepalive: true }).catch(() => {});
            }
        } catch {
            //no-op
        }
    };
    window.addEventListener("pagehide", discardNow);
    window.addEventListener("beforeunload", discardNow);
}
/**
 * 確認ページからのファイル削除
 *
 */
async function deleteFile_for_checkPage(el, action, type, shopId, folderId, folderName, photoName, noUpDateKey) {
    //選択されたファイル情報を破棄
    keepFiles = null;
    //送信用フォーム生成
    let sFd = new FormData();
    sFd.append("action", action);
    sFd.append("type", type);
    sFd.append("shopId", shopId);
    sFd.append("folderId", folderId);
    sFd.append("folderName", folderName);
    sFd.append("photoName", photoName);
    sFd.append("noUpDateKey", noUpDateKey);
    //プレビューでの削除時もアップロードドラフト領域を特定して掃除できるようにする
    sFd.append("up_image_area[]", "photo_image");
    //送信先
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
        document.getElementById("block01").remove();
        document.getElementById("block02").remove();
        document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
        //差し替え後のイベント再バインド
        restartPhotoFunction();
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 写真詳細情報変更
 *
 */
async function editPhotoDetail(el, action, type, shopId, folderId, folderName, photoKey, photoName, noUpDateKey) {
    //送信用フォーム生成
    let sFd = new FormData();
    //編集画面は必ず edit モード
    sFd.append("method", "edit");
    sFd.append("action", action);
    sFd.append("type", type);
    sFd.append("shopId", shopId);
    sFd.append("folderId", folderId);
    sFd.append("folderName", folderName);
    //格納フォルダ選択の復元（サーバ側の共通ロジックが selectFolder/select-folder を見るため）
    sFd.append("selectFolder", folderId);
    sFd.append("select-folder", folderId);
    sFd.append("photoKey", photoKey);
    sFd.append("photoName", photoName);
    sFd.append("noUpDateKey", noUpDateKey);
    //送信先
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
        document.getElementById("block01").remove();
        document.getElementById("block02").remove();
        document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
        //再度写真登録ファンクション生成
        restartPhotoFunction();
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 写真登録
 *
 */
async function sendSubmit() {
    //送信用フォーム生成
    let addPhoto = document.querySelector("form[name='addPhoto']");
    let sFd = new FormData(addPhoto);
    //送信先
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
        if (list["status"] == "error") {
            showModalMessage(list["msg"]);
        } else {
            showModalMessage(list["msg"]);
            //表示変更
            document.getElementById("block01").remove();
            document.getElementById("block02").remove();
            document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
            document.querySelector("input[name='photoName']").value = "";
            //再度写真登録ファンクション生成
            restartPhotoFunction();
        }
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 写真登録確認
 *
 */
async function checkSubmit() {
    //写真選択チェック
    let fileInput = document.getElementById("js-fileElem-photoImage");
    if (fileInput.files.length == 0) {
        showModalMessage("追加する写真を選択してください。");
        return false;
    }
    //格納フォルダ選択チェック
    let folderKey = "";
    const selected = document.querySelector('input[name="selectFolder"]:checked');
    if (selected) {
        folderKey = selected.value;
    }
    if (folderKey == "") {
        showModalMessage("格納フォルダを選択してください。");
        return false;
    }
    //画像タイトル入力チェック
    let folderName = document.querySelector("input[name='photoName']").value;
    if (folderName == "") {
        showModalMessage("画像タイトルを入力してください。");
        return false;
    }
    //送信用フォーム生成
    let addPhoto = document.querySelector("form[name='addPhoto']");
    let sFd = new FormData(addPhoto);
    //送信先
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
        if (list["status"] == "error") {
            showModalMessage(list["msg"]);
        } else {
            //表示変更
            document.getElementById("block01").remove();
            document.getElementById("block02").remove();
            document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
            //再度写真登録ファンクション生成
            restartPhotoFunction();
        }
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 写真削除確認
 *
 */
function photoDeleteCheck(el, action, shopId, folderName, photoKey, photoName, noUpDateKey) {
    clearModalDeleteButton();
    showModalMessage("「" + photoName + "」を削除します。<br>よろしいですか？");
    appendModalDeleteButton(() => deletePhoto(null, action, shopId, folderName, photoKey, photoName, noUpDateKey));
}
/**
 * 写真削除
 *
 */
async function deletePhoto(el, action, shopId, folderName, photoKey, photoName, noUpDateKey) {
    //送信用フォーム生成
    let sFd = new FormData();
    sFd.append("action", action);
    sFd.append("shopId", shopId);
    sFd.append("folderName", folderName);
    sFd.append("deletePhotoKey", photoKey);
    sFd.append("deletePhotoName", photoName);
    sFd.append("noUpDateKey", noUpDateKey);
    //送信先
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
        clearModalDeleteButton();
        showModalMessage(list["msg"]);
        document.getElementById("block01").remove();
        document.getElementById("block02").remove();
        document.querySelector(".block_inner h2").insertAdjacentHTML("afterend", list["tag"]);
        //再度写真登録ファンクション生成
        restartPhotoFunction();
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 初期化：DOMContentLoaded
 *
 */
document.addEventListener("DOMContentLoaded", () => {
    initPhotoFileSelect("photoImage");
});
