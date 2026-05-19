/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client02_03.php";
let isSortUpdating = false;
let dragSrcLi = null;
let dropPlaceholder = null;
let dragStartOrder = "";

/**
 * 並び替えリストの li 要素一覧を取得
 */
function getArticleListItems() {
    const ul = document.querySelector('form[name="sortForm"] ul');
    if (!ul) return [];
    return Array.from(ul.querySelectorAll("li[data-article-id]"));
}
/**
 * 現在の並び順を article_id の配列で返す
 */
function getArticleIdOrder() {
    return getArticleListItems().map((li) => li.dataset.articleId);
}
/**
 * 最初・最後の li の上下ボタンを現在位置に合わせて再生成
 */
function updateSortButtons() {
    const items = getArticleListItems();
    items.forEach((li, idx) => {
        const isFirst = idx === 0;
        const isLast = idx === items.length - 1;
        const btnDown = li.querySelector(".btn_down");
        const btnUp = li.querySelector(".btn_up");
        if (btnDown) {
            btnDown.innerHTML = isLast ? "" : '<button type="button" onclick="goSortDown(this);"></button>';
        }
        if (btnUp) {
            btnUp.innerHTML = isFirst ? "" : '<button type="button" onclick="goSortUp(this);"></button>';
        }
    });
}
/**
 * 移動完了した li を一時的にハイライト
 */
function highlightMovedItem(li) {
    if (!li) return;
    li.style.transition = "background-color 0.4s ease";
    li.style.backgroundColor = "#fff7d6";
    window.setTimeout(function () {
        li.style.backgroundColor = "";
    }, 1200);
}
/**
 * ドラッグ移動先を示すプレースホルダー li を生成
 */
function createDropPlaceholder() {
    const li = document.createElement("li");
    li.className = "sort-drop-placeholder";
    li.style.height = "10px";
    li.style.margin = "4px 0";
    li.style.backgroundColor = "#fff7d6";
    li.style.border = "2px dashed #c8a45d";
    li.style.borderRadius = "unset";
    li.style.boxSizing = "border-box";
    li.style.listStyle = "none";
    return li;
}
/**
 * プレースホルダーを DOM から取り除く
 */
function removeDropPlaceholder() {
    if (dropPlaceholder && dropPlaceholder.parentNode) {
        dropPlaceholder.parentNode.removeChild(dropPlaceholder);
    }
    dropPlaceholder = null;
}
/**
 * モーダルを表示
 */
function showSortModal(title, message) {
    const modal = document.getElementById("modalBlock");
    if (!modal) return;
    const titleEl = modal.querySelector(".box-title p");
    const msgEl = modal.querySelector(".box-details p");
    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.innerHTML = message;
    modal.classList.add("is-active");
}
/**
 * 並び順を POST 送信
 */
async function postDisplayOrder() {
    if (isSortUpdating) return;
    isSortUpdating = true;
    const sortForm = document.querySelector('form[name="sortForm"]');
    if (!sortForm) {
        isSortUpdating = false;
        return;
    }
    const fd = new FormData(sortForm);
    fd.set("action", "updateDisplayOrder");
    fd.set("articleIds", JSON.stringify(getArticleIdOrder()));
    try {
        const res = await fetch(requestURL, { method: "POST", body: fd });
        const json = await res.json();
        if (json.noUpDateKey) {
            const keyInput = sortForm.querySelector('input[name="noUpDateKey"]');
            if (keyInput) keyInput.value = json.noUpDateKey;
        }
        if (json.status !== "success") {
            showSortModal(json.title || "エラー", json.msg || "更新に失敗しました。");
        } else {
            showSortModal(json.title || "完了", json.msg || "並び順を更新しました。");
        }
    } catch (e) {
        showSortModal("エラー", "通信エラーが発生しました。<br>再度お試しください。");
    } finally {
        isSortUpdating = false;
    }
}
/**
 * 上へ移動
 */
window.goSortUp = function (btn) {
    if (isSortUpdating) return;
    const li = btn.closest("li[data-article-id]");
    if (!li) return;
    const prev = li.previousElementSibling;
    if (!prev || !prev.dataset.articleId) return;
    li.parentNode.insertBefore(li, prev);
    updateSortButtons();
    highlightMovedItem(li);
    postDisplayOrder();
};
/**
 * 下へ移動
 */
window.goSortDown = function (btn) {
    if (isSortUpdating) return;
    const li = btn.closest("li[data-article-id]");
    if (!li) return;
    const next = li.nextElementSibling;
    if (!next || !next.dataset.articleId) return;
    li.parentNode.insertBefore(next, li);
    updateSortButtons();
    highlightMovedItem(li);
    postDisplayOrder();
};
/**
 * ドラッグ&ドロップで並び替え
 */
function initDragDrop() {
    const ul = document.querySelector('form[name="sortForm"] ul');
    if (!ul) return;
    ul.addEventListener("dragstart", function (e) {
        const li = e.target.closest("li[data-article-id]");
        if (!li) {
            e.preventDefault();
            return;
        }
        dragSrcLi = li;
        dragStartOrder = getArticleIdOrder().join(",");
        dropPlaceholder = createDropPlaceholder();
        li.classList.add("is-dragging");
        li.style.opacity = "0.6";
        e.dataTransfer.effectAllowed = "move";
    });
    ul.addEventListener("dragover", function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = "move";
        const li = e.target.closest("li[data-article-id]");
        if (!li || li === dragSrcLi) return;
        if (!dropPlaceholder) {
            dropPlaceholder = createDropPlaceholder();
        }
        const rect = li.getBoundingClientRect();
        const mid = rect.top + rect.height / 2;
        if (e.clientY < mid) {
            ul.insertBefore(dropPlaceholder, li);
        } else {
            ul.insertBefore(dropPlaceholder, li.nextElementSibling);
        }
    });
    ul.addEventListener("drop", function (e) {
        e.preventDefault();
        if (!dragSrcLi || !dropPlaceholder || !dropPlaceholder.parentNode) {
            return;
        }
        dropPlaceholder.parentNode.insertBefore(dragSrcLi, dropPlaceholder);
    });
    ul.addEventListener("dragend", function () {
        const movedLi = dragSrcLi;
        if (dragSrcLi) {
            dragSrcLi.classList.remove("is-dragging");
            dragSrcLi.style.opacity = "";
        }
        removeDropPlaceholder();
        const currentOrder = getArticleIdOrder().join(",");
        const changed = dragStartOrder !== "" && dragStartOrder !== currentOrder;
        dragSrcLi = null;
        dragStartOrder = "";
        updateSortButtons();
        if (changed) {
            highlightMovedItem(movedLi);
            postDisplayOrder();
        }
    });
}
document.addEventListener("DOMContentLoaded", function () {
    const items = getArticleListItems();
    if (items.length === 0) return;
    updateSortButtons();
    initDragDrop();
});
