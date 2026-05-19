/**
 * API送信先
 *
 */
const requestURL = "./assets/function/proc_client02_01.php";
let currentArticlePageNumber = 1;

/**
 * 一覧レスポンスを画面へ反映
 *
 */
function applyArticleListResponse(list, searchForm) {
    if (list && list["noUpDateKey"] && searchForm) {
        const noUpDateKeyInput = searchForm.querySelector('input[name="noUpDateKey"]');
        if (noUpDateKeyInput) noUpDateKeyInput.value = list["noUpDateKey"];
    }
    if (list && list["tag"]) {
        const currentUl = document.querySelector(".inner_search-list > ul");
        if (currentUl) currentUl.outerHTML = list["tag"];
    }
    const countEl = document.getElementById("search-result-count");
    if (countEl && typeof list["total_items"] !== "undefined") {
        countEl.textContent = `${list["total_items"]}件`;
    }
    if (list && list["pager"]) {
        const currentPager = document.querySelector(".inner_search-list > .box-pager");
        if (currentPager) {
            currentPager.outerHTML = list["pager"];
        } else {
            document.querySelector(".inner_search-list")?.insertAdjacentHTML("beforeend", list["pager"]);
        }
    }
    if (list && typeof list["page_number"] !== "undefined") {
        currentArticlePageNumber = Number(list["page_number"]) || 1;
    }
}
/**
 * 一覧検索フォームの送信用データを作成
 *
 */
function buildArticleListFormData(action, pageNumber = 1) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        return null;
    }
    const sFd = new FormData(searchForm);
    sFd.set("action", action);
    sFd.set("pageNumber", pageNumber);
    const displayNumberInput = document.querySelector('.select-display-number input[name="displayNumber"][type="hidden"]');
    if (displayNumberInput) {
        sFd.set("displayNumber", displayNumberInput.value);
    }
    return sFd;
}
/**
 * モーダル本文とボタンを差し替えて表示
 *
 */
function setArticleModal(title, message, buttonHtml) {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        return false;
    }
    const titleEl = blockModal.querySelector(".box-title p");
    const detailEl = blockModal.querySelector(".box-details p");
    const btnArea = blockModal.querySelector(".box-btn");
    if (titleEl) titleEl.textContent = title;
    if (detailEl) detailEl.innerHTML = message;
    if (btnArea) btnArea.innerHTML = buttonHtml;
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
    return true;
}
/**
 * メッセージモーダルを表示
 *
 */
function showArticleMessage(title, message) {
    const opened = setArticleModal(title, message, '<button type="button" class="btn-cancel" id="articleModalClose">閉じる</button>');
    if (!opened) {
        alert(message.replace(/<br\s*\/?>/gi, "\n"));
        return;
    }
    document.getElementById("articleModalClose")?.addEventListener("click", () => {
        if (typeof closeModal === "function") {
            closeModal();
        }
    });
}
/**
 * 確認モーダルを表示
 *
 */
function showArticleConfirm(title, message, onConfirm) {
    const opened = setArticleModal(title, message, '<button type="button" class="btn-cancel" id="articleModalCancel">いいえ</button><button type="button" class="btn-confirm" id="articleModalConfirm">はい</button>');
    if (!opened) {
        if (window.confirm(message.replace(/<br\s*\/?>/gi, "\n"))) {
            onConfirm();
        }
        return;
    }
    document.getElementById("articleModalCancel")?.addEventListener("click", () => {
        if (typeof closeModal === "function") {
            closeModal();
        }
    });
    document.getElementById("articleModalConfirm")?.addEventListener("click", () => {
        if (typeof closeModal === "function") {
            closeModal();
        }
        onConfirm();
    });
}
/**
 * 自由記事一覧APIへ送信
 *
 */
async function postArticleListFormData(formData) {
    const response = await fetch(requestURL, {
        method: "POST",
        body: formData,
    });
    if (!response.ok) throw new Error("Network response was not ok");
    return (await response.json()) || {};
}
/**
 * 検索条件で一覧を再取得
 *
 */
async function searchConditions(action, pageNumber = 1) {
    currentArticlePageNumber = Number(pageNumber) || 1;
    const searchForm = document.querySelector('form[name="searchForm"]');
    const sFd = buildArticleListFormData(action, pageNumber);
    if (!sFd || !searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
    try {
        const list = await postArticleListFormData(sFd);
        if (list && list["status"] === "error") {
            showArticleMessage(list["title"] || "エラー", list["msg"] || "エラーが発生しました。ページを再読み込みしてください。");
            return;
        }
        applyArticleListResponse(list, searchForm);
        switch (action) {
            case "reset": {
                const searchTitle = searchForm.querySelector('input[name="searchTitle"]');
                if (searchTitle) searchTitle.value = "";
                const categoryRadios = searchForm.querySelectorAll('input[name="select-search-category"][type="radio"]');
                categoryRadios.forEach((radio) => {
                    radio.checked = false;
                });
                const displayFlgPublic = searchForm.querySelector('input[name="displayFlg"][value="1"]');
                if (displayFlgPublic) displayFlgPublic.checked = true;
                const displayNumberHidden = document.querySelector('.select-display-number input[name="displayNumber"][type="hidden"]');
                const displayNumberRadios = document.querySelectorAll('.select-display-number input[name="displayNumber"][type="radio"]');
                const displayNumberRadio = document.querySelector('.select-display-number input[name="displayNumber"][value="10"]') || document.querySelector('.select-display-number input[name="displayNumber"][type="radio"]');
                displayNumberRadios.forEach((radio) => {
                    radio.checked = false;
                });
                if (displayNumberRadio) displayNumberRadio.checked = true;
                if (displayNumberHidden && displayNumberRadio) displayNumberHidden.value = displayNumberRadio.value;
                const displayNumberSelectBox = document.querySelector(".select-display-number[data-selectbox]");
                const displayNumberValue = displayNumberSelectBox?.querySelector("[data-selectbox-value]");
                if (displayNumberValue && displayNumberRadio) displayNumberValue.textContent = displayNumberRadio.value;
                if (displayNumberSelectBox) {
                    displayNumberSelectBox.classList.remove("is-empty");
                    displayNumberSelectBox.classList.add("is-selected");
                }
                break;
            }
        }
        const areaMaster = document.querySelector("main");
        if (areaMaster) areaMaster.scrollIntoView(true);
    } catch (error) {
        console.error("送信エラー:", error);
        showArticleMessage("通信エラー", "通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 指定ページへ移動
 *
 */
function movePage(pageNumber) {
    searchConditions("search", pageNumber);
}
/**
 * 公開状態を切り替え
 *
 */
function changeArticleStatus(articleId, nextStatus) {
    const statusValue = Number(nextStatus);
    const message = statusValue === 1 ? "この記事を公開します。<br>よろしいですか？" : "この記事を非公開にします。<br>よろしいですか？";
    showArticleConfirm("公開設定変更", message, async () => {
        const searchForm = document.querySelector('form[name="searchForm"]');
        const sFd = buildArticleListFormData("changeStatus", currentArticlePageNumber);
        if (!sFd || !searchForm) {
            showArticleMessage("エラー", "フォームが見つかりません。ページを再読み込みしてください。");
            return;
        }
        sFd.set("articleId", String(articleId));
        sFd.set("status", String(statusValue));
        try {
            const list = await postArticleListFormData(sFd);
            if (list.status === "error") {
                showArticleMessage(list.title || "エラー", list.msg || "公開設定の変更に失敗しました。");
                return;
            }
            applyArticleListResponse(list, searchForm);
            showArticleMessage(list.title || "公開設定変更", list.msg || "公開設定を変更しました。");
        } catch (error) {
            console.error("公開設定変更エラー:", error);
            showArticleMessage("通信エラー", "通信エラーが発生しました。ページを再読み込みしてください。");
        }
    });
}
/**
 * 自由記事を論理削除
 *
 */
function deleteArticle(articleId) {
    showArticleConfirm("自由記事削除", "この記事を削除します。<br>よろしいですか？", async () => {
        const searchForm = document.querySelector('form[name="searchForm"]');
        const sFd = buildArticleListFormData("deleteArticle", currentArticlePageNumber);
        if (!sFd || !searchForm) {
            showArticleMessage("エラー", "フォームが見つかりません。ページを再読み込みしてください。");
            return;
        }
        sFd.set("articleId", String(articleId));
        try {
            const list = await postArticleListFormData(sFd);
            if (list.status === "error") {
                showArticleMessage(list.title || "エラー", list.msg || "削除に失敗しました。");
                return;
            }
            applyArticleListResponse(list, searchForm);
            showArticleMessage(list.title || "自由記事削除", list.msg || "削除が完了しました。");
        } catch (error) {
            console.error("自由記事削除エラー:", error);
            showArticleMessage("通信エラー", "通信エラーが発生しました。ページを再読み込みしてください。");
        }
    });
}
