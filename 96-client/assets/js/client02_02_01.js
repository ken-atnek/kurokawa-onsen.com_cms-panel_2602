/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client02_02_01.php";
let isArticleSubmitting = false;
let articleTempDiscardSent = false;
const articleThumbnailState = {
    1: { hasImage: false },
    2: { hasImage: false },
    3: { hasImage: false },
};
const articleThumbnailDeleteSavedState = {
    1: false,
    2: false,
    3: false,
};

/**
 * 段落入力ボックスの表示・非表示を切り替える
 *
 */
if (typeof window.toggleDisplay !== "function") {
    window.toggleDisplay = function (button, targetId) {
        const target = document.getElementById(targetId);
        if (!target) {
            return;
        }
        const isOpen = target.style.display !== "none";
        if (isOpen) {
            target.style.display = "none";
            button.classList.remove("is-active");
        } else {
            target.style.display = "grid";
            button.classList.add("is-active");
        }
    };
}
/**
 * idまたはnameからinput値を取得
 *
 */
function getInputValue(idOrName, fallback = "") {
    const el = document.getElementById(idOrName) || document.querySelector(`[name="${idOrName}"]`);
    if (!el) return fallback;
    return String(el.value ?? fallback);
}
/**
 * FormDataをprocへ送信
 *
 */
async function postFormData(fd) {
    const res = await fetch(requestURL, { method: "POST", body: fd });
    const response = await res.json().catch(() => null);
    if (!res.ok || !response) {
        throw new Error("通信に失敗しました");
    }
    if (response.noUpDateKey) syncNoUpDateKey(response.noUpDateKey);
    if (response.status === "error") {
        throw new Error(response.msg || "処理に失敗しました");
    }
    return response;
}
/**
 * フォームメッセージモーダルを表示する
 *
 */
function showArticleFormModal(title, message, redirect = "") {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        alert(message);
        if (redirect) {
            window.location.href = redirect;
        }
        return;
    }
    const titleArea = blockModal.querySelector(".box-title p");
    const messageArea = blockModal.querySelector(".box-details p");
    const buttonArea = blockModal.querySelector(".box-btn");
    const topCloseButton = blockModal.querySelector(".box-title button");
    if (titleArea) {
        titleArea.textContent = title || "";
    }
    if (messageArea) {
        messageArea.innerHTML = String(message || "").replace(/\n/g, "<br>");
    }
    if (buttonArea) {
        buttonArea.innerHTML = "";
        const closeButton = document.createElement("button");
        closeButton.type = "button";
        closeButton.className = "btn-cancel";
        closeButton.textContent = "閉じる";
        closeButton.addEventListener("click", () => {
            if (redirect) {
                window.location.href = redirect;
                return;
            }
            if (typeof closeModal === "function") {
                closeModal();
            } else {
                blockModal.classList.remove("is-active");
            }
        });
        buttonArea.appendChild(closeButton);
    }
    if (topCloseButton) {
        topCloseButton.onclick = () => {
            if (redirect) {
                window.location.href = redirect;
                return;
            }
            if (typeof closeModal === "function") {
                closeModal();
            } else {
                blockModal.classList.remove("is-active");
            }
        };
    }
    blockModal.classList.add("is-active");
}
/**
 * チェックされているラジオボタンの値を取得する
 *
 */
function getCheckedRadioValue(name, fallback = "") {
    const checked = document.querySelector(`input[name="${name}"]:checked`);
    return checked ? String(checked.value ?? fallback) : fallback;
}
/**
 * エディタHTMLからプレーンテキストを取得する
 *
 */
function getPlainTextFromHtml(html) {
    const div = document.createElement("div");
    div.innerHTML = String(html || "");
    return div.textContent.trim();
}
/**
 * 段落フォームの値をFormDataへ追加する
 *
 */
function appendParagraphFormData(fd, paragraphNo) {
    const prefix = "paragraphs" + paragraphNo;
    const bodyHtml = typeof window.getClient020201EditorHtml === "function" ? window.getClient020201EditorHtml(paragraphNo) : "";
    fd.append(`paragraphs[${paragraphNo}][title]`, getInputValue(prefix + "Title", ""));
    fd.append(`paragraphs[${paragraphNo}][body_html]`, bodyHtml);
    fd.append(`paragraphs[${paragraphNo}][link_enabled]`, getCheckedRadioValue(prefix + "LinkMode", "0"));
    fd.append(`paragraphs[${paragraphNo}][link_text]`, getInputValue(prefix + "LinkText", ""));
    fd.append(`paragraphs[${paragraphNo}][link_url]`, getInputValue(prefix + "LinkUrl", ""));
    fd.append(`paragraphs[${paragraphNo}][link_target]`, getCheckedRadioValue(prefix + "LinWindow", "1"));
    return bodyHtml;
}
/**
 * 定型記事フォームを送信する
 *
 */
window.sendInput = async function () {
    const articleTitle = getInputValue("articleTitle", "").trim();
    const paragraph1Title = getInputValue("paragraphs1Title", "").trim();
    const paragraph1Body = typeof window.getClient020201EditorHtml === "function" ? window.getClient020201EditorHtml(1) : "";
    const errors = [];
    if (articleTitle === "") {
        errors.push("ページタイトルは必須です。");
    }
    if (paragraph1Title === "") {
        errors.push("段落1タイトルは必須です。");
    }
    if (getPlainTextFromHtml(paragraph1Body) === "") {
        errors.push("段落1本文は必須です。");
    }
    if (errors.length > 0) {
        showArticleFormModal("入力エラー", errors.join("\n"));
        return;
    }
    const fd = new FormData();
    fd.append("action", "sendInput");
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("shopId", getInputValue("shopId", ""));
    fd.append("articleStatus", getCheckedRadioValue("articleStatus", "1"));
    fd.append("articleTitle", articleTitle);
    fd.append("articleDisplayOrder", getInputValue("articleDisplayOrder", "1"));
    appendParagraphFormData(fd, 1);
    appendParagraphFormData(fd, 2);
    appendParagraphFormData(fd, 3);
    [1, 2, 3].forEach((paragraphNo) => {
        if (articleThumbnailDeleteSavedState[paragraphNo] === true) {
            fd.append(`delete_saved_thumbnail[${paragraphNo}]`, "1");
        }
    });
    isArticleSubmitting = true;
    try {
        const response = await postFormData(fd);
        showArticleFormModal(response.title || "自由記事登録", response.msg || "保存が完了しました。", response.redirect || "./client02_01.php");
    } catch (error) {
        isArticleSubmitting = false;
        showArticleFormModal("入力エラー", error.message || "保存に失敗しました。");
    }
};
/**
 * TipTap本文内画像の対象段落番号を保持
 *
 */
function setActiveParagraphNo(paragraphNo) {
    const no = Number(paragraphNo);
    if ([1, 2, 3].includes(no)) {
        window.CLIENT02_02_01_ACTIVE_PARAGRAPH_NO = no;
    }
}
/**
 * TipTap本文内画像アップロードフック
 *
 */
window.tipTapUploadImage = async (file, context = {}) => {
    const fd = new FormData();
    const contextParagraphNo = Number(context && context.paragraphNo ? context.paragraphNo : 0);
    const activeParagraphNo = Number(window.CLIENT02_02_01_ACTIVE_PARAGRAPH_NO || 1);
    const paragraphNo = [1, 2, 3].includes(contextParagraphNo) ? contextParagraphNo : [1, 2, 3].includes(activeParagraphNo) ? activeParagraphNo : 1;
    fd.append("action", "uploadInlineImage");
    //form(hidden) を単一の情報源にする
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("paragraphNo", String(paragraphNo));
    fd.append("file", file);
    const json = await postFormData(fd);
    if (!json.url) throw new Error("画像URLの取得に失敗しました");
    return String(json.url);
};
/**
 * 段落本文の初期値を取得する
 *
 */
function getInitialBody(paragraphNo) {
    const initCfg = window.CLIENT02_02_01 || {};
    const initialBodies = initCfg.initialBodies || {};
    return initialBodies[String(paragraphNo)] ?? initialBodies[paragraphNo] ?? "";
}
/**
 * TipTapが初期化できない場合に本文を安全に表示する
 *
 */
function applyInitialBodiesFallback() {
    [
        { id: "TipTapEditor01", paragraphNo: 1 },
        { id: "TipTapEditor02", paragraphNo: 2 },
        { id: "TipTapEditor03", paragraphNo: 3 },
    ].forEach((editorConfig) => {
        const target = document.getElementById(editorConfig.id);
        if (!target) {
            return;
        }
        target.textContent = String(getInitialBody(editorConfig.paragraphNo));
    });
}
/**
 * 既存TipTap実装が参照するIDを段落ごとに一時設定する
 *
 */
function withTemporaryTiptapIds(editorConfig, callback) {
    const idMap = [
        ["editorId", "TipTapEditor"],
        ["toolbarId", "toolbar"],
        ["textColorId", "textColor"],
        ["imageInputId", "imageInput"],
    ];
    const changed = [];
    idMap.forEach(([key, temporaryId]) => {
        const element = document.getElementById(editorConfig[key]);
        if (!element) {
            return;
        }
        changed.push([element, element.id]);
        element.id = temporaryId;
    });
    return Promise.resolve(callback()).finally(() => {
        changed.forEach(([element, originalId]) => {
            element.id = originalId;
        });
    });
}
/**
 * 定型記事用本文エリアを段落ごとに初期化する
 *
 */
async function initializeArticleEditors() {
    const editorConfigs = [
        { editorId: "TipTapEditor01", toolbarId: "toolbar01", textColorId: "textColor01", imageInputId: "imageInput01", paragraphNo: 1 },
        { editorId: "TipTapEditor02", toolbarId: "toolbar02", textColorId: "textColor02", imageInputId: "imageInput02", paragraphNo: 2 },
        { editorId: "TipTapEditor03", toolbarId: "toolbar03", textColorId: "textColor03", imageInputId: "imageInput03", paragraphNo: 3 },
    ];
    window.CLIENT02_02_01_EDITORS = {};
    const tiptapModuleUrl = new URL("../assets/lib/TipTap/js/tiptap_app.js", window.location.href).href;
    for (const editorConfig of editorConfigs) {
        const target = document.getElementById(editorConfig.editorId);
        if (!target) {
            continue;
        }
        target.addEventListener("focusin", () => setActiveParagraphNo(editorConfig.paragraphNo));
        target.addEventListener("mousedown", () => setActiveParagraphNo(editorConfig.paragraphNo));
        window.TIPTAP_INITIAL = {
            json: null,
            html: String(getInitialBody(editorConfig.paragraphNo)),
        };
        setActiveParagraphNo(editorConfig.paragraphNo);
        await withTemporaryTiptapIds(editorConfig, async () => {
            window.TIPTAP_CONTEXT = {
                paragraphNo: editorConfig.paragraphNo,
            };
            await import(tiptapModuleUrl + "?client020201=" + editorConfig.paragraphNo);
            if (window.TIPTAP_EDITOR && typeof window.TIPTAP_EDITOR.getHTML === "function") {
                window.CLIENT02_02_01_EDITORS[editorConfig.paragraphNo] = window.TIPTAP_EDITOR;
            }
        });
    }
    if (Object.keys(window.CLIENT02_02_01_EDITORS).length === 0) {
        applyInitialBodiesFallback();
    }
}
/**
 * ツールバー内の本文画像ボタンを段落ごとのfile inputへ紐づける
 *
 */
function bindArticleBodyImageInputs() {
    [
        { toolbarId: "toolbar01", imageInputId: "imageInput01", paragraphNo: 1 },
        { toolbarId: "toolbar02", imageInputId: "imageInput02", paragraphNo: 2 },
        { toolbarId: "toolbar03", imageInputId: "imageInput03", paragraphNo: 3 },
    ].forEach((config) => {
        const toolbar = document.getElementById(config.toolbarId);
        const imageInput = document.getElementById(config.imageInputId);
        if (!toolbar || !imageInput) {
            return;
        }
        toolbar.addEventListener("mousedown", () => setActiveParagraphNo(config.paragraphNo));
        toolbar.addEventListener("focusin", () => setActiveParagraphNo(config.paragraphNo));
        const imageButton = toolbar.querySelector('button[data-cmd="imageUpload"]');
        if (!imageButton) {
            return;
        }
        imageButton.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
            setActiveParagraphNo(config.paragraphNo);
            imageInput.click();
        });
        imageInput.addEventListener("change", () => {
            setActiveParagraphNo(config.paragraphNo);
            imageInput.dataset.selectedCount = String(imageInput.files ? imageInput.files.length : 0);
        });
    });
}
/**
 * 段落サムネイル画像アップロードエラーを表示する
 *
 */
function showArticleThumbnailError(errorBlock, message, title = "アップロード失敗") {
    if (!errorBlock) {
        alert(message);
        return;
    }
    const titleEl = errorBlock.querySelector("h5");
    const messageEl = errorBlock.querySelector("p");
    if (titleEl) {
        titleEl.textContent = title;
    }
    if (messageEl) {
        messageEl.textContent = message;
    }
    errorBlock.style.display = "flex";
}
/**
 * 段落サムネイル画像アップロードエラーを隠す
 *
 */
function hideArticleThumbnailError(errorBlock) {
    if (!errorBlock) {
        return;
    }
    errorBlock.style.display = "none";
}
/**
 * 段落サムネイル画像UIの表示状態を切り替える
 *
 */
function setArticleThumbnailUiState(paragraphNo, hasImage) {
    const suffix = String(paragraphNo).padStart(2, "0");
    articleThumbnailState[paragraphNo] = articleThumbnailState[paragraphNo] || {};
    articleThumbnailState[paragraphNo].hasImage = !!hasImage;
    const emptyItems = document.querySelectorAll(`[data-article-thumbnail-empty-ui="${suffix}"]`);
    const deleteUi = document.getElementById("articleThumbnailDeleteUi" + suffix);
    emptyItems.forEach((item) => {
        item.style.display = hasImage ? "none" : "";
    });
    if (deleteUi) {
        deleteUi.style.display = hasImage ? "" : "none";
    }
}
/**
 * 段落サムネイル画像プレビューを未登録状態へ戻す
 *
 */
function clearArticleThumbnailPreview(previewBlock, paragraphNo) {
    if (previewBlock) {
        const img = previewBlock.querySelector("img");
        if (img) {
            img.removeAttribute("src");
        }
        previewBlock.dataset.kind = "";
        previewBlock.dataset.fileName = "";
        previewBlock.dataset.paragraphNo = "";
        previewBlock.style.display = "none";
    }
    setArticleThumbnailUiState(paragraphNo, false);
}
/**
 * 段落サムネイル画像の一時アップロードを実行する
 *
 */
async function uploadArticleThumbnail(file, paragraphNo) {
    const fd = new FormData();
    fd.append("action", "preUploadArticleThumbnail");
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("paragraphNo", String(paragraphNo));
    fd.append("file", file);
    return await postFormData(fd);
}
/**
 * 段落サムネイル画像の一時ファイルを削除する
 *
 */
async function deleteArticleThumbnailTemp(paragraphNo, fileName = "") {
    const fd = new FormData();
    fd.append("action", "deleteArticleThumbnailTemp");
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("paragraphNo", String(paragraphNo));
    fd.append("fileName", String(fileName || ""));
    return await postFormData(fd);
}
/**
 * TipTap本文内画像の一時ファイルを削除する
 *
 */
async function deleteArticleInlineTemp(paragraphNo, fileName = "") {
    const fd = new FormData();
    fd.append("action", "deleteArticleInlineTemp");
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("paragraphNo", String(paragraphNo));
    fd.append("fileName", String(fileName || ""));
    return await postFormData(fd);
}
/**
 * 画像URLからファイル名を取得する
 *
 */
function getFileNameFromImageSrc(src) {
    const value = String(src || "");
    if (value === "") {
        return "";
    }
    try {
        const url = new URL(value, window.location.href);
        return decodeURIComponent(url.pathname.split("/").pop() || "");
    } catch {
        return decodeURIComponent(value.split("?")[0].split("#")[0].split("/").pop() || "");
    }
}
/**
 * 段落サムネイル画像プレビューを反映する
 *
 */
function applyArticleThumbnailPreview(previewBlock, response, paragraphNo) {
    if (!previewBlock || !response || !response.file_url) {
        return;
    }
    let img = previewBlock.querySelector("img");
    if (!img) {
        img = document.createElement("img");
        img.alt = "";
        img.className = "preview-image";
        previewBlock.appendChild(img);
    }
    img.src = String(response.file_url);
    previewBlock.dataset.kind = "tmp";
    previewBlock.dataset.fileName = String(response.file_name || "");
    previewBlock.dataset.paragraphNo = String(response.paragraphNo || paragraphNo || "");
    previewBlock.style.display = "block";
    setArticleThumbnailUiState(response.paragraphNo || paragraphNo, true);
}
/**
 * 段落サムネイル画像欄を初期化する
 *
 */
function initializeArticleThumbnailUpload() {
    [
        { key: "articleThumbnail01", paragraphNo: 1 },
        { key: "articleThumbnail02", paragraphNo: 2 },
        { key: "articleThumbnail03", paragraphNo: 3 },
    ].forEach((config) => {
        const dropZone = document.getElementById("js-dragDrop-" + config.key);
        const selectButton = document.getElementById("js-fileSelect-" + config.key);
        const fileInput = document.getElementById("js-fileElem-" + config.key);
        const previewBlock = document.getElementById("js-previewBlock-" + config.key);
        const errorBlock = document.getElementById("js-fileError-" + config.key);
        if (!dropZone || !selectButton || !fileInput || !previewBlock) {
            return;
        }
        const existingImg = previewBlock.querySelector("img");
        const hasInitialImage = !!(existingImg && existingImg.getAttribute("src"));
        setArticleThumbnailUiState(config.paragraphNo, hasInitialImage);
        const handleFiles = async (files) => {
            if (articleThumbnailState[config.paragraphNo]?.hasImage) {
                return;
            }
            const file = files && files.length > 0 ? files[0] : null;
            if (!file) {
                return;
            }
            if (!file.type || !file.type.startsWith("image/")) {
                showArticleThumbnailError(errorBlock, "画像ファイルを選択してください。");
                return;
            }
            hideArticleThumbnailError(errorBlock);
            try {
                const response = await uploadArticleThumbnail(file, config.paragraphNo);
                applyArticleThumbnailPreview(previewBlock, response, config.paragraphNo);
            } catch (error) {
                showArticleThumbnailError(errorBlock, error.message || "アップロードに失敗しました。");
            } finally {
                fileInput.value = "";
            }
        };
        selectButton.addEventListener("click", () => {
            fileInput.click();
        });
        fileInput.addEventListener("change", () => {
            handleFiles(fileInput.files);
        });
        const deleteButton = document.getElementById("reset" + String(config.paragraphNo).padStart(2, "0") + "-button");
        if (deleteButton) {
            deleteButton.addEventListener("click", async () => {
                hideArticleThumbnailError(errorBlock);
                if (previewBlock.dataset.kind !== "tmp") {
                    articleThumbnailDeleteSavedState[config.paragraphNo] = true;
                    clearArticleThumbnailPreview(previewBlock, config.paragraphNo);
                    showArticleFormModal("画像削除", "画像の削除を行いました。\n保存ボタンにて、記事の更新をお願いします。");
                    return;
                }
                let response = null;
                try {
                    response = await deleteArticleThumbnailTemp(config.paragraphNo, previewBlock.dataset.fileName || "");
                } catch (error) {
                    showArticleThumbnailError(errorBlock, error.message || "画像削除に失敗しました。");
                    return;
                }
                if (!response || response.deleted !== true) {
                    showArticleThumbnailError(errorBlock, response?.msg || "削除対象の一時画像はありません。", "画像削除未完了");
                    return;
                }
                clearArticleThumbnailPreview(previewBlock, config.paragraphNo);
            });
        }
        dropZone.addEventListener("dragover", (event) => {
            if (articleThumbnailState[config.paragraphNo]?.hasImage) {
                event.preventDefault();
                event.stopPropagation();
                dropZone.classList.remove("dragover");
                return;
            }
            event.preventDefault();
            dropZone.classList.add("dragover");
        });
        dropZone.addEventListener("dragleave", () => {
            dropZone.classList.remove("dragover");
        });
        dropZone.addEventListener("drop", (event) => {
            if (articleThumbnailState[config.paragraphNo]?.hasImage) {
                event.preventDefault();
                event.stopPropagation();
                dropZone.classList.remove("dragover");
                return;
            }
            event.preventDefault();
            dropZone.classList.remove("dragover");
            handleFiles(event.dataTransfer ? event.dataTransfer.files : null);
        });
    });
}
/**
 * TipTap本文内画像の削除ボタンをページ側で補助する
 *
 */
function removeInlineImageBySrc(editor, targetSrc) {
    let target = null;
    editor.state.doc.descendants((node, pos) => {
        if (target) {
            return false;
        }
        if (node.type?.name === "image" && String(node.attrs?.src || "") === String(targetSrc)) {
            target = { node, pos };
            return false;
        }
        return true;
    });
    if (!target) {
        return false;
    }
    editor.commands.command(({ tr, dispatch }) => {
        if (dispatch) {
            dispatch(tr.delete(target.pos, target.pos + target.node.nodeSize));
        }
        return true;
    });
    if (editor.view && typeof editor.view.focus === "function") {
        editor.view.focus();
    }
    return true;
}
/**
 * TipTap本文内画像の削除ボタンをdocument captureで拾う
 *
 */
function bindArticleInlineImageRemoveFallback() {
    document.addEventListener(
        "click",
        (event) => {
            const button = event.target?.closest?.(".image-remove");
            if (!button) {
                return;
            }
            const editorRoot = button.closest("#TipTapEditor01, #TipTapEditor02, #TipTapEditor03");
            if (!editorRoot) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            const paragraphNo = Number(editorRoot.id.replace("TipTapEditor", ""));
            const editor = window.CLIENT02_02_01_EDITORS?.[paragraphNo];
            if (!editor || !editor.state || !editor.view) {
                return;
            }
            const imageNode = button.closest(".image-node");
            const img = imageNode ? imageNode.querySelector("img") : null;
            const targetSrc = img ? img.getAttribute("src") : "";
            if (!targetSrc) {
                return;
            }
            removeInlineImageBySrc(editor, targetSrc);
            const fileName = getFileNameFromImageSrc(targetSrc);
            if (fileName) {
                deleteArticleInlineTemp(paragraphNo, fileName).catch((error) => {
                    console.warn(error);
                });
            }
        },
        true,
    );
}
/**
 * ページ離脱時に未保存の自由記事一時ファイルを破棄する
 *
 */
function discardArticleTemps() {
    if (isArticleSubmitting || articleTempDiscardSent) {
        return;
    }
    const noUpDateKey = getInputValue("noUpDateKey", "");
    if (!noUpDateKey) {
        return;
    }
    articleTempDiscardSent = true;
    ["discardArticleThumbnailTemps", "discardArticleInlineTemps"].forEach((action) => {
        const params = new URLSearchParams();
        params.append("action", action);
        params.append("noUpDateKey", noUpDateKey);
        params.append("method", getInputValue("method", "new"));
        params.append("articleId", getInputValue("articleId", "0"));
        if (navigator.sendBeacon) {
            navigator.sendBeacon(requestURL, params);
            return;
        }
        fetch(requestURL, { method: "POST", body: params, keepalive: true }).catch(() => {});
    });
}

document.addEventListener("DOMContentLoaded", () => {
    bindArticleBodyImageInputs();
    bindArticleInlineImageRemoveFallback();
    initializeArticleThumbnailUpload();
    initializeArticleEditors();
    document.addEventListener("click", (event) => {
        if (event.target?.closest?.(".btn-confirmed")) {
            isArticleSubmitting = true;
        }
    });
});
window.addEventListener("pagehide", discardArticleTemps);
window.addEventListener("beforeunload", discardArticleTemps);

/**
 * 後続保存処理用に段落本文HTMLを取得する
 *
 */
window.getClient020201EditorHtml = function (paragraphNo) {
    const editors = window.CLIENT02_02_01_EDITORS || {};
    if (editors[paragraphNo] && typeof editors[paragraphNo].getHTML === "function") {
        return editors[paragraphNo].getHTML();
    }
    return "";
};
