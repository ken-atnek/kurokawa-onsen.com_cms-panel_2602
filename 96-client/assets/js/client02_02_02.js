/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client02_02_02.php";
let isArticleSubmitting = false;
let articleTempDiscardSent = false;

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
 * チェックされているラジオボタンの値を取得
 *
 */
function getCheckedRadioValue(name, fallback = "") {
    const checked = document.querySelector(`input[name="${name}"]:checked`);
    return checked ? String(checked.value ?? fallback) : fallback;
}

/**
 * HTMLからプレーンテキストを取得
 *
 */
function getPlainTextFromHtml(html) {
    const div = document.createElement("div");
    div.innerHTML = String(html || "");
    return div.textContent.trim();
}

/**
 * 画像srcからファイル名を取得
 *
 */
function getFileNameFromImageSrc(src) {
    try {
        const url = new URL(String(src || ""), window.location.href);
        const parts = url.pathname.split("/");
        return parts.pop() || "";
    } catch (error) {
        const srcParts = String(src || "").split(/[?#]/)[0].split("/");
        return srcParts.pop() || "";
    }
}

/**
 * フォームメッセージモーダルを表示
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
 * FormDataをprocへ送信
 *
 */
async function postFormData(fd) {
    const res = await fetch(requestURL, { method: "POST", body: fd });
    const response = await res.json().catch(() => null);
    if (!res.ok || !response) {
        throw new Error("通信に失敗しました。");
    }
    if (response.status === "error") {
        throw new Error(response.msg || "処理に失敗しました。");
    }
    return response;
}

/**
 * HTMLフリー本文エディタのHTMLを取得
 *
 */
window.getClient020202EditorHtml = function () {
    const editor = window.CLIENT02_02_02_EDITOR;
    if (editor && typeof editor.getHTML === "function") {
        return editor.getHTML();
    }
    const target = document.getElementById("TipTapEditor");
    return target ? target.innerHTML || "" : "";
};

/**
 * TipTap本文内画像アップロードフック
 *
 */
window.tipTapUploadImage = async (file) => {
    const fd = new FormData();
    fd.append("action", "uploadInlineImage");
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("file", file);
    const json = await postFormData(fd);
    if (!json.url) throw new Error("画像URLの取得に失敗しました。");
    return String(json.url);
};

/**
 * HTMLフリー本文内tmp画像を削除
 *
 */
async function deleteArticleHtmlInlineTemp(fileName = "") {
    const fd = new FormData();
    fd.append("action", "deleteArticleHtmlInlineTemp");
    fd.append("noUpDateKey", getInputValue("noUpDateKey", ""));
    fd.append("method", getInputValue("method", "new"));
    fd.append("articleId", getInputValue("articleId", "0"));
    fd.append("fileName", String(fileName || ""));
    return await postFormData(fd);
}

/**
 * HTMLフリー記事フォームを送信
 *
 */
window.sendInput = async function () {
    const articleTitle = getInputValue("articleTitle", "").trim();
    const bodyHtml = window.getClient020202EditorHtml();
    const errors = [];
    if (articleTitle === "") {
        errors.push("ページタイトルは必須です。");
    }
    if (getPlainTextFromHtml(bodyHtml) === "") {
        errors.push("本文は必須です。");
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
    fd.append("body_html", bodyHtml);
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
 * HTMLフリー記事用TipTapエディタを初期化
 *
 */
async function initializeHtmlArticleEditor() {
    const target = document.getElementById("TipTapEditor");
    if (!target) {
        return;
    }
    const initCfg = window.CLIENT02_02_02 || {};
    window.TIPTAP_INITIAL = {
        json: null,
        html: String(initCfg.initialBody || ""),
    };
    window.TIPTAP_CONTEXT = {
        articleType: 2,
    };
    const tiptapModuleUrl = new URL("../assets/lib/TipTap/js/tiptap_app.js", window.location.href).href;
    await import(tiptapModuleUrl + "?client020202=1");
    if (window.TIPTAP_EDITOR && typeof window.TIPTAP_EDITOR.getHTML === "function") {
        window.CLIENT02_02_02_EDITOR = window.TIPTAP_EDITOR;
    } else {
        target.innerHTML = String(initCfg.initialBody || "");
    }
}

/**
 * HTMLフリー本文内画像の削除ボタン押下時にtmp画像を削除
 *
 */
function bindArticleHtmlInlineImageRemoveFallback() {
    document.addEventListener(
        "click",
        (event) => {
            const button = event.target?.closest?.(".image-remove");
            if (!button || !button.closest("#TipTapEditor")) {
                return;
            }
            const imageNode = button.closest(".image-node");
            const img = imageNode ? imageNode.querySelector("img") : null;
            const targetSrc = img ? img.getAttribute("src") : "";
            if (!targetSrc || targetSrc.indexOf("tmp_upload/article_html_inline") === -1) {
                return;
            }
            const fileName = getFileNameFromImageSrc(targetSrc);
            if (fileName) {
                deleteArticleHtmlInlineTemp(fileName).catch((error) => {
                    console.warn(error);
                });
            }
        },
        true,
    );
}

/**
 * ページ離脱時に未保存のHTMLフリー本文内tmp画像を破棄
 *
 */
function discardArticleHtmlInlineTemps() {
    if (isArticleSubmitting || articleTempDiscardSent) {
        return;
    }
    const noUpDateKey = getInputValue("noUpDateKey", "");
    if (!noUpDateKey) {
        return;
    }
    articleTempDiscardSent = true;
    const params = new URLSearchParams();
    params.append("action", "discardArticleHtmlInlineTemps");
    params.append("noUpDateKey", noUpDateKey);
    params.append("method", getInputValue("method", "new"));
    params.append("articleId", getInputValue("articleId", "0"));
    if (navigator.sendBeacon) {
        navigator.sendBeacon(requestURL, params);
        return;
    }
    fetch(requestURL, { method: "POST", body: params, keepalive: true }).catch(() => {});
}

document.addEventListener("DOMContentLoaded", () => {
    bindArticleHtmlInlineImageRemoveFallback();
    initializeHtmlArticleEditor().catch((error) => {
        console.error(error);
        const target = document.getElementById("TipTapEditor");
        const initCfg = window.CLIENT02_02_02 || {};
        if (target) {
            target.innerHTML = String(initCfg.initialBody || "");
        }
    });
});
window.addEventListener("pagehide", discardArticleHtmlInlineTemps);
window.addEventListener("beforeunload", discardArticleHtmlInlineTemps);
