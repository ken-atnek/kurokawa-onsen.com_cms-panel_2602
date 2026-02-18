/**
 * 複数領域・複数ページ対応 ドラッグ&ドロップ画像アップロード
 *
 * @param {Object} options
 *   - dropZone: ドロップエリア要素 or セレクタ
 *   - selectFileButton: ファイル選択ボタン要素 or セレクタ
 *   - fileInput: input[type=file]要素 or セレクタ
 *   - previewBlock: プレビュー表示エリア要素 or セレクタ
 *   - fileError: エラー表示エリア要素 or セレクタ
 */
function initDropZone(options) {
    //要素取得
    const dropZone = typeof options.dropZone === "string" ? document.querySelector(options.dropZone) : options.dropZone;
    const selectFileButton = typeof options.selectFileButton === "string" ? document.querySelector(options.selectFileButton) : options.selectFileButton;
    const fileInput = typeof options.fileInput === "string" ? document.querySelector(options.fileInput) : options.fileInput;
    const inputMode = typeof options.inputMode === "string" ? document.querySelector(options.inputMode) : options.inputMode;
    const inputArea = typeof options.inputArea === "string" ? document.querySelector(options.inputArea) : options.inputArea;
    const previewBlock = typeof options.previewBlock === "string" ? document.querySelector(options.previewBlock) : options.previewBlock;
    const fileError = options.fileError ? (typeof options.fileError === "string" ? document.querySelector(options.fileError) : options.fileError) : null;
    if (!dropZone || !selectFileButton || !fileInput || !previewBlock) {
        return;
    }
    const alreadyInitialized = dropZone.dataset.dropzoneInitialized === "1";
    dropZone.dataset.dropzoneInitialized = "1";
    //hidden値の有無判定
    const getHiddenValue = (name, defaultValue = "") => {
        const el = document.querySelector(`input[type=hidden][name="${name}"]`);
        if (!el) return defaultValue;
        const v = el.value;
        return v == null ? defaultValue : v;
    };
    //==============================
    // ページ離脱・リロード時のドラフト破棄
    //  - 求人カード（proc_master03_02_01.php）のみ対象
    //  - sendBeacon でベストエフォート送信
    //==============================
    try {
        const sendPHP = getHiddenValue("send_php", "");
        const areaName = inputArea && inputArea.value ? String(inputArea.value) : "";
        const discardTargets = ["proc_master01_01_01.php"];
        if (discardTargets.includes(sendPHP) && areaName) {
            if (!window.__rwUploadDraftDiscard) {
                window.__rwUploadDraftDiscard = { sendPHP: sendPHP, areas: [] };
            }
            if (window.__rwUploadDraftDiscard.sendPHP !== sendPHP) {
                window.__rwUploadDraftDiscard.sendPHP = sendPHP;
            }
            if (!Array.isArray(window.__rwUploadDraftDiscard.areas)) {
                window.__rwUploadDraftDiscard.areas = [];
            }
            if (!window.__rwUploadDraftDiscard.areas.includes(areaName)) {
                window.__rwUploadDraftDiscard.areas.push(areaName);
            }
            if (!window.__rwUploadDraftDiscardListenerInstalled) {
                window.__rwUploadDraftDiscardListenerInstalled = true;
                const discardNow = () => {
                    if (window.__rwUploadDraftDiscardDisable) return;
                    if (window.__rwUploadDraftDiscardSent) return;
                    const state = window.__rwUploadDraftDiscard;
                    if (!state || !state.sendPHP || !Array.isArray(state.areas) || state.areas.length === 0) return;
                    window.__rwUploadDraftDiscardSent = true;
                    const url = "./assets/function/" + state.sendPHP;
                    const params = new URLSearchParams();
                    params.append("action", "discardUploadDraft");
                    const noUpDateKey = getHiddenValue("noUpDateKey", "");
                    if (noUpDateKey) params.append("noUpDateKey", noUpDateKey);
                    //jobId（編集時）を渡して pending deletes も破棄
                    const jobIdEl = document.querySelector('input[type=hidden][name="jobId"]');
                    const jobId = jobIdEl ? String(jobIdEl.value || "") : "";
                    if (jobId) params.append("jobId", jobId);
                    state.areas.forEach((a) => params.append("up_image_area[]", a));
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(url, params);
                    } else {
                        //フォールバック（離脱時に送れない場合もある）
                        fetch(url, { method: "POST", body: params, keepalive: true }).catch(() => {});
                    }
                };
                window.addEventListener("pagehide", discardNow);
                window.addEventListener("beforeunload", discardNow);
                //安全側の調整:
                //visibilitychange は「タブ切替」でも発火するため、デフォルトでは破棄送信しない。
                //どうしても必要な環境のみ、ページ側で window.__rwUploadDraftDiscardOnVisibilityHidden = true を設定して有効化する。
                document.addEventListener("visibilitychange", () => {
                    if (document.visibilityState !== "hidden") return;
                    if (window.__rwUploadDraftDiscardOnVisibilityHidden !== true) return;
                    discardNow();
                });
            }
        }
    } catch {
        //no-op
    }
    //画像格納変数初期化
    let keepFiles = null;
    if (alreadyInitialized) {
        //プレビュー内ボタンの再バインドだけ行う（イベント二重登録防止）
        //bindPreviewButtons() はこの後定義される
    }
    //ファイル選択ボタン
    if (!alreadyInitialized) {
        selectFileButton.addEventListener("click", () => {
            fileInput.setAttribute("data-mode", "add");
            fileInput.removeAttribute("data-replace-index");
            fileInput.click();
        });
    }
    //ファイル選択時
    if (!alreadyInitialized) {
        fileInput.addEventListener("change", (event) => {
            const mode = fileInput.getAttribute("data-mode") || "add";
            const replaceIndex = fileInput.getAttribute("data-replace-index");
            handleFiles(event.target.files, mode, replaceIndex);
            fileInput.removeAttribute("data-mode");
            fileInput.removeAttribute("data-replace-index");
        });
    }
    //ドラッグ＆ドロップ
    if (!alreadyInitialized) {
        dropZone.addEventListener("dragover", (event) => {
            event.preventDefault();
            dropZone.classList.add("dragover");
        });
        dropZone.addEventListener("dragleave", () => {
            dropZone.classList.remove("dragover");
        });
        dropZone.addEventListener("drop", (event) => {
            event.preventDefault();
            dropZone.classList.remove("dragover");
            handleFiles(event.dataTransfer.files);
        });
    }
    //D&Dでも required 判定が通るよう fileInput に files を同期
    function syncFileInputFiles(files) {
        try {
            if (!files || files.length === 0) return;
            const dt = new DataTransfer();
            for (let i = 0; i < files.length; i++) {
                dt.items.add(files[i]);
            }
            fileInput.files = dt.files;
        } catch (e) {
            console.warn("syncFileInputFiles failed:", e);
        }
    }
    //プレビューエリアのボタンイベント登録
    function bindPreviewButtons() {
        //再選択（入れ替え）
        previewBlock.querySelectorAll(".btn-change").forEach((btn, idx) => {
            btn.onclick = function () {
                fileInput.setAttribute("data-mode", "replace");
                fileInput.setAttribute("data-replace-index", idx);
                fileInput.click();
            };
        });
        //削除
        previewBlock.querySelectorAll(".btn-delate").forEach((btn) => {
            btn.onclick = function (e) {
                e.preventDefault();
                const li = btn.closest("li");
                const img = li.querySelector("img");
                if (!img) return;
                const src = img.getAttribute("src");
                const fileName = src.split("/").pop();
                let sendPHP = getHiddenValue("send_php", "");
                let facId = getHiddenValue("facId", "");
                let jobId = getHiddenValue("jobId", "");
                let upImageArea = inputArea.value;
                let sFd = new FormData();
                sFd.append("action", "deleteUploadImage");
                sFd.append("file_name", fileName);
                const noUpDateKey = getHiddenValue("noUpDateKey", "");
                if (noUpDateKey) sFd.append("noUpDateKey", noUpDateKey);
                if (facId !== "") sFd.append("facId", facId);
                if (jobId !== "") sFd.append("jobId", jobId);
                sFd.append("up_image_area[]", upImageArea);
                let requestURL = "./assets/function/" + sendPHP;
                (async () => {
                    try {
                        const response = await fetch(requestURL, {
                            method: "POST",
                            body: sFd,
                        });
                        if (!response.ok) throw new Error("Network response was not ok");
                        let res = await response.json();
                        if (res.status === "success") {
                            li.remove();
                            bindPreviewButtons();
                            const liCount = previewBlock.querySelectorAll("li").length;
                            let upImageMode = document.querySelector("input[type=hidden][name=upload_image_mode]").value;
                            if (liCount === 0) {
                                //previewBlock.style.display = 'none';
                                //dropZone.style.display = '';
                                dropZone.classList.remove("is-active");
                                fileInput.value = "";
                            } else if (upImageMode === "multiple") {
                                if (liCount < 10) {
                                    //dropZone.style.display = '';
                                    dropZone.classList.remove("is-active");
                                }
                            }
                        } else {
                            alert("削除に失敗しました: " + (res.msg || ""));
                        }
                    } catch (error) {
                        console.error(error);
                        alert("通信エラーが発生しました。ページを再読み込みしてください。");
                    }
                })();
            };
        });
    }
    //ファイル選択・ドロップ時の処理
    function handleFiles(files, mode = "add", replaceIndex = null) {
        if (files.length > 0) {
            //file選択と同様に fileInput 側へも反映して必須判定を通す
            syncFileInputFiles(files);
            const file = files[0];
            keepFiles = files;
            if (file.type.startsWith("image/")) {
                const reader = new FileReader();
                reader.onload = () => {
                    togglePreview(true, mode, replaceIndex);
                };
                reader.readAsDataURL(file);
            } else {
                alert("画像ファイルを選択してください。");
            }
        }
    }
    //プレビュー表示の切り替え
    function togglePreview(show, mode = "add", replaceIndex = null) {
        if (show) {
            if (!inputMode || !inputArea) {
                alert("画像アップロードの設定が正しくありません（inputModeまたはinputAreaが未設定）");
                return;
            }
            let sendPHP = getHiddenValue("send_php", "");
            let upImageMode = inputMode.value;
            let upImageArea = inputArea.value;
            let facId = getHiddenValue("facId", "");
            let jobId = getHiddenValue("jobId", "");
            let sFd = new FormData();
            const noUpDateKey = getHiddenValue("noUpDateKey", "");
            if (noUpDateKey) sFd.append("noUpDateKey", noUpDateKey);
            if (mode === "replace") {
                sFd.append("action", "replaceUploadImage");
                sFd.append("replace_index", replaceIndex);
                if (facId !== "") sFd.append("facId", facId);
                if (jobId !== "") sFd.append("jobId", jobId);
            } else {
                sFd.append("action", "preUploadImage");
                if (facId !== "") sFd.append("facId", facId);
                if (jobId !== "") sFd.append("jobId", jobId);
            }
            sFd.append("method", "new_image");
            sFd.append("up_image_mode", upImageMode);
            sFd.append("up_image_area[]", upImageArea);
            if (keepFiles && keepFiles.length > 0) {
                sFd.append("images_tmp", keepFiles[0]);
            }
            let requestURL = "./assets/function/" + sendPHP;
            (async () => {
                try {
                    const response = await fetch(requestURL, {
                        method: "POST",
                        body: sFd,
                    });
                    if (!response.ok) throw new Error("Network response was not ok");
                    let list = await response.json();
                    if (list["status"] == "error") {
                        if (fileError) {
                            fileError.style.display = "flex";
                            fileError.querySelector("h5").innerHTML = list["title"];
                            fileError.querySelector("p").innerHTML = list["msg"];
                        }
                    } else {
                        previewBlock.style.display = "grid";
                        if (mode === "replace") {
                            previewBlock.innerHTML = list["tag"];
                        } else {
                            if (upImageMode === "only") {
                                previewBlock.innerHTML = list["tag"];
                            } else {
                                previewBlock.insertAdjacentHTML("beforeend", list["tag"]);
                            }
                        }
                        const liCount = previewBlock.querySelectorAll("li").length;
                        if (upImageMode === "only" && liCount >= 1) {
                            //dropZone.style.display = 'none';
                            dropZone.classList.add("is-active");
                        } else if (upImageMode === "multiple" && liCount >= 10) {
                            //dropZone.style.display = 'none';
                            dropZone.classList.add("is-active");
                        } else {
                            //dropZone.style.display = '';
                            dropZone.classList.remove("is-active");
                        }
                        bindPreviewButtons();
                    }
                } catch (error) {
                    console.error(error);
                    alert("通信エラーが発生しました。ページを再読み込みしてください。");
                }
            })();
        } else {
            previewBlock.style.display = "none";
            fileInput.value = "";
            keepFiles = null;
            let upImageMode = document.querySelector("input[type=hidden][name=upload_image_mode]").value;
            if (upImageMode === "only") {
                //dropZone.style.display = '';
                dropZone.classList.remove("is-active");
            }
        }
    }
    //初期バインド
    bindPreviewButtons();
}
//既存ページの後方互換：1領域用（従来のIDで初期化）
document.addEventListener("DOMContentLoaded", () => {
    const drop = document.getElementById("js-dragDrop");
    const btn = document.getElementById("js-fileSelect");
    const input = document.getElementById("js-fileElem");
    const inputMode = document.getElementById("js-uploadImageMode");
    const inputArea = document.getElementById("js-uploadImageArea");
    const preview = document.getElementById("js-previewBlock");
    const error = document.getElementById("js-fileError");
    if (drop && btn && input && preview) {
        initDropZone({
            dropZone: drop,
            selectFileButton: btn,
            fileInput: input,
            inputMode: inputMode,
            inputArea: inputArea,
            previewBlock: preview,
            fileError: error,
        });
    }
});
