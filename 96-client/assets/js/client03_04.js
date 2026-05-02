/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client03_04.php";

/**
 * カテゴリ並び替え（↑↓／D&D）
 *
 */
function getCategoryListRoot() {
    return document.querySelector(".drag-area");
}
function getCategoryItemElements() {
    const ul = getCategoryListRoot();
    if (!ul) return [];
    return Array.from(ul.querySelectorAll(":scope > li")).filter((li) => {
        //ヘッダ行・no-data を除外し、ID列を持つ行のみ対象
        if (li.classList.contains("no-data")) return false;
        return !!li.querySelector(".item-id span");
    });
}
function getCategoryIdFromItem(li) {
    const raw = (li?.querySelector(".item-id span")?.textContent || "").trim();
    if (!raw) return null;
    if (!/^[0-9]+$/.test(raw)) return null;
    return parseInt(raw, 10);
}
function getCurrentOrderIds() {
    return getCategoryItemElements()
        .map((li) => getCategoryIdFromItem(li))
        .filter((id) => Number.isInteger(id) && id > 0);
}
function updateSortButtonVisibility() {
    const items = getCategoryItemElements();
    if (items.length === 0) return;
    items.forEach((li, idx) => {
        const btnUp = li.querySelector("button.btn-up");
        const btnDown = li.querySelector("button.btn-down");
        if (btnUp) btnUp.style.visibility = idx === 0 ? "hidden" : "";
        if (btnDown) btnDown.style.visibility = idx === items.length - 1 ? "hidden" : "";
    });
}
function restoreOrderByIds(orderIds) {
    const ul = getCategoryListRoot();
    if (!ul) return;
    const header = ul.querySelector(":scope > li");
    const items = getCategoryItemElements();
    const map = new Map();
    items.forEach((li) => {
        const id = getCategoryIdFromItem(li);
        if (id) map.set(id, li);
    });
    //既存カテゴリ行を一旦除去（DOMから消すだけ）
    items.forEach((li) => li.remove());
    //header の直後に順番通り差し戻し
    let insertPoint = header;
    orderIds.forEach((id) => {
        const li = map.get(id);
        if (!li) return;
        if (insertPoint && insertPoint.nextSibling) {
            ul.insertBefore(li, insertPoint.nextSibling);
        } else {
            ul.appendChild(li);
        }
        insertPoint = li;
    });
    updateSortButtonVisibility();
}
function showErrorModal(title, msgRaw) {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) return;
    const titleText = title || "更新失敗";
    const msg = String(msgRaw || "並び替えに失敗しました。\nページを再読み込みしてください。").replace(/\n/g, "<br>");
    blockModal.querySelector(".box-title p").innerHTML = titleText;
    blockModal.querySelector(".box-details p").innerHTML = msg;
    const btnBox = blockModal.querySelector(".box-btn");
    btnBox.querySelectorAll("button").forEach((b) => b.remove());
    btnBox.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>');
    blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", "closeModal()");
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}
async function saveSortOrder(orderIds) {
    const noUpDateKey = document.querySelector('form[name="inputForm"] input[name="noUpDateKey"]')?.value || "";
    if (!noUpDateKey) {
        return { ok: false, title: "セッションエラー", msg: "セッションが切れました。\nページを再読み込みしてください。" };
    }
    if (!Array.isArray(orderIds) || orderIds.length === 0) {
        return { ok: false, title: "入力エラー", msg: "並び順情報が取得できませんでした。\nページを再読み込みしてください。" };
    }
    const sFd = new FormData();
    sFd.append("action", "updateSortOrder");
    sFd.append("method", "sort");
    sFd.append("orderedIds", JSON.stringify(orderIds));
    sFd.append("noUpDateKey", noUpDateKey);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (result["status"] === "success") {
            return { ok: true };
        }
        return {
            ok: false,
            title: result["title"] || "更新失敗",
            msg: result["msg"] || "更新に失敗しました。\nページを再読み込みしてください。",
        };
    } catch (e) {
        return { ok: false, title: "通信エラー", msg: "通信エラーが発生しました。\nページを再読み込みしてください。" };
    }
}
async function persistCurrentOrderOrRollback(prevOrderIds) {
    const nextOrderIds = getCurrentOrderIds();
    const res = await saveSortOrder(nextOrderIds);
    if (res.ok) return true;
    //失敗時は元に戻す
    if (Array.isArray(prevOrderIds) && prevOrderIds.length > 0) {
        restoreOrderByIds(prevOrderIds);
    }
    showErrorModal(res.title, res.msg);
    return false;
}
function moveItemUp(li) {
    const ul = getCategoryListRoot();
    if (!ul) return;
    const items = getCategoryItemElements();
    const idx = items.indexOf(li);
    if (idx <= 0) return;
    const prev = items[idx - 1];
    ul.insertBefore(li, prev);
    updateSortButtonVisibility();
}
function moveItemDown(li) {
    const ul = getCategoryListRoot();
    if (!ul) return;
    const items = getCategoryItemElements();
    const idx = items.indexOf(li);
    if (idx < 0 || idx >= items.length - 1) return;
    const next = items[idx + 1];
    ul.insertBefore(next, li);
    updateSortButtonVisibility();
}
function initSortButtons() {
    const ul = getCategoryListRoot();
    if (!ul) return;
    ul.addEventListener("click", async (e) => {
        const target = e.target;
        if (!(target instanceof Element)) return;
        const btnUp = target.closest("button.btn-up");
        const btnDown = target.closest("button.btn-down");
        if (!btnUp && !btnDown) return;
        const li = target.closest("li");
        if (!li) return;
        //ヘッダ行は除外
        if (!li.querySelector(".item-id span")) return;
        const prevOrder = getCurrentOrderIds();
        if (btnUp) {
            moveItemUp(li);
        } else {
            moveItemDown(li);
        }
        await persistCurrentOrderOrRollback(prevOrder);
    });
    updateSortButtonVisibility();
}
function getDragAfterElement(container, y) {
    const sortableLis = getCategoryItemElements().filter((li) => !li.classList.contains("is-dragging"));
    return sortableLis.reduce(
        (closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            }
            return closest;
        },
        { offset: Number.NEGATIVE_INFINITY, element: null },
    ).element;
}
function initDragAndDrop() {
    const ul = getCategoryListRoot();
    if (!ul) return;
    const items = getCategoryItemElements();
    if (items.length === 0) return;
    items.forEach((li) => {
        // li 全体はドラッグ不可、ハンドル（.item-control）のみドラッグ可
        li.removeAttribute("draggable");
        const handle = li.querySelector(".item-control");
        if (handle) {
            handle.setAttribute("draggable", "true");
            //子要素(button)から掴んでもドラッグできるようにする
            const handleBtn = handle.querySelector("button");
            if (handleBtn) handleBtn.setAttribute("draggable", "true");
        }
    });
    let prevOrder = null;
    let didDropInList = false;
    let highlightedLi = null;
    const clearHighlight = () => {
        if (highlightedLi) {
            highlightedLi.style.border = "";
            highlightedLi = null;
        }
    };
    const setHighlight = (li) => {
        if (!li || li === highlightedLi) return;
        clearHighlight();
        highlightedLi = li;
        highlightedLi.style.border = "2px solid #d9c2c2";
    };
    ul.addEventListener("dragstart", (e) => {
        const target = e.target;
        if (!(target instanceof Element)) return;
        const li = target.closest("li");
        if (!li) return;
        if (!li.querySelector(".item-id span")) return;
        clearHighlight();
        //編集フォーム操作中はドラッグさせない
        if (target.closest("form")) {
            e.preventDefault();
            return;
        }
        //操作ボタン領域ではドラッグ開始しない
        if (target.closest("nav")) {
            e.preventDefault();
            return;
        }
        //ドラッグ開始はハンドル（.item-control）からのみ許可
        if (!target.closest(".item-control")) {
            e.preventDefault();
            return;
        }
        prevOrder = getCurrentOrderIds();
        didDropInList = false;
        li.classList.add("is-dragging");
        try {
            e.dataTransfer.effectAllowed = "move";
            e.dataTransfer.setData("text/plain", "drag");
        } catch {
            //no-op
        }
    });
    ul.addEventListener("drop", (e) => {
        // drop先は li 全体でOK（保存判定のため、dropした事実だけ記録）
        e.preventDefault();
        didDropInList = true;
        const dragging = ul.querySelector(":scope > li.is-dragging");
        if (dragging) {
            // B：DOMの並び替えは drop 時のみ確定させる
            const afterElement = getDragAfterElement(ul, e.clientY);
            if (afterElement == null) {
                ul.appendChild(dragging);
            } else {
                ul.insertBefore(dragging, afterElement);
            }
        }
        clearHighlight();
        updateSortButtonVisibility();
    });
    ul.addEventListener("dragend", async (e) => {
        const target = e.target;
        if (!(target instanceof Element)) return;
        const li = target.closest("li");
        if (!li) return;
        li.classList.remove("is-dragging");
        clearHighlight();
        // dropしていない（リスト外で離した等）場合は元の順序に戻して保存しない
        if (!didDropInList) {
            if (Array.isArray(prevOrder) && prevOrder.length > 0) {
                restoreOrderByIds(prevOrder);
            }
            prevOrder = null;
            didDropInList = false;
            updateSortButtonVisibility();
            return;
        }
        if (Array.isArray(prevOrder) && prevOrder.length > 0) {
            await persistCurrentOrderOrRollback(prevOrder);
        }
        prevOrder = null;
        didDropInList = false;
        updateSortButtonVisibility();
    });
    ul.addEventListener("dragover", (e) => {
        const dragging = ul.querySelector(":scope > li.is-dragging");
        if (!dragging) return;
        e.preventDefault();
        const overTarget = e.target;
        if (overTarget instanceof Element) {
            const overLi = overTarget.closest("li");
            if (overLi && overLi !== dragging && !overLi.classList.contains("no-data") && overLi.querySelector(".item-id span")) {
                setHighlight(overLi);
            } else {
                clearHighlight();
            }
        }
    });
    updateSortButtonVisibility();
}
/**
 * 送信
 *
 */
async function sendInput() {
    //クリックされたボタンの属するformを優先（編集フォーム対応）
    const target = typeof event !== "undefined" ? event.target : null;
    const currentForm = target?.closest ? target.closest("form") : null;
    const form = currentForm || validationForm;
    if (!form) return;
    //HTMLのrequired等を最優先でチェック（radio等のグループも含む）
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    let errFlag = 0;
    //inputForm は従来通り checkRequiredElem() を使用（editForm は動的要素のためHTML requiredに委ねる）
    if (form === validationForm) {
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
                let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('client03_04.php');">閉じる</button>`;
                blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                //「✕」ボタンも変更
                blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", "closeModalToPage('client03_04.php')");
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
 * ゴミ箱ボタン：カテゴリ削除チェック
 *
 */
function deleteCategory(el) {
    const listItem = el?.closest("li");
    if (!listItem) return;
    //カテゴリID（一覧の表示IDをそのまま利用）
    const categoryId = (listItem.querySelector(".item-id span")?.textContent || "").trim();
    if (!categoryId) return;
    //カテゴリ名（表示用）
    const categoryName = (listItem.querySelector(".item-name span")?.textContent || "").trim();
    //セッションキー
    const noUpDateKey = document.querySelector('form[name="inputForm"] input[name="noUpDateKey"]')?.value || "";
    if (!noUpDateKey) return;
    //モーダルボックス
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) return;
    //削除対象をモーダルに保持
    blockModal.dataset.categoryId = categoryId;
    blockModal.dataset.noUpDateKey = noUpDateKey;
    //表示
    const titleEl = blockModal.querySelector(".box-title p");
    const msgEl = blockModal.querySelector(".box-details p");
    if (titleEl) titleEl.textContent = "カテゴリ削除";
    if (msgEl) {
        const label = categoryName ? `「${categoryName}」` : "このカテゴリ";
        msgEl.textContent = `${label}を削除します。よろしいですか？`;
    }
    //ボタン再生成
    const btnBox = blockModal.querySelector(".box-btn");
    if (!btnBox) return;
    btnBox.querySelectorAll("button").forEach((b) => b.remove());
    const buttons = `
        <button type="button" class="btn-cancel" onclick="closeModal();">キャンセル</button>
        <button type="button" class="btn-confirm" onclick="confirmDeleteCategory();">はい</button>
    `;
    btnBox.insertAdjacentHTML("beforeend", buttons);
    //「✕」ボタン
    const topCloseButton = blockModal.querySelector(".box-title")?.querySelector("button");
    if (topCloseButton) topCloseButton.setAttribute("onclick", "closeModal()");
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}
/**
 * ゴミ箱ボタン：カテゴリ削除実行
 *
 */
async function confirmDeleteCategory() {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) return;
    const categoryId = blockModal.dataset.categoryId || "";
    const noUpDateKey = blockModal.dataset.noUpDateKey || "";
    if (!categoryId || !noUpDateKey) return;
    const sFd = new FormData();
    sFd.append("action", "deleteCategory");
    sFd.append("method", "delete");
    sFd.append("categoryId", categoryId);
    sFd.append("noUpDateKey", noUpDateKey);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (result["status"] == "error") {
            const title = result["title"] || "削除失敗";
            const msgRaw = result["msg"] || "削除に失敗しました。\nお手数ですが最初からやり直してください。";
            const msg = String(msgRaw).replace(/\n/g, "<br>");
            blockModal.querySelector(".box-title p").innerHTML = title;
            blockModal.querySelector(".box-details p").innerHTML = msg;
            const btnBox = blockModal.querySelector(".box-btn");
            btnBox.querySelectorAll("button").forEach((b) => b.remove());
            btnBox.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>');
            blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", "closeModal()");
        } else {
            blockModal.querySelector(".box-title p").innerHTML = result["title"] || "カテゴリ削除";
            blockModal.querySelector(".box-details p").innerHTML = String(result["msg"] || "削除が完了しました。").replace(/\n/g, "<br>");
            const btnBox = blockModal.querySelector(".box-btn");
            btnBox.querySelectorAll("button").forEach((b) => b.remove());
            btnBox.insertAdjacentHTML("beforeend", `<button type="button" class="btn-cancel" onclick="closeModalToPage('client03_04.php');">閉じる</button>`);
            blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", "closeModalToPage('client03_04.php')");
        }
        blockModal.classList.add("is-active");
        document.documentElement.style.overflow = "hidden";
    } catch (error) {
        console.error("削除エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 編集ボタン：編集用 form 表示
 *
 */
async function editCategory(el) {
    const listItem = el?.closest("li");
    if (!listItem) return;
    //カテゴリID（一覧の表示IDをそのまま利用）
    const categoryId = (listItem.querySelector(".item-id span")?.textContent || "").trim();
    if (!categoryId) return;
    //セッションキー
    const noUpDateKey = document.querySelector('form[name="inputForm"] input[name="noUpDateKey"]')?.value || "";
    if (!noUpDateKey) return;
    //送信用FormData生成（編集フォーム生成用）
    const sFd = new FormData();
    sFd.append("action", "makeEditForm");
    sFd.append("method", "edit");
    sFd.append("categoryId", categoryId);
    sFd.append("noUpDateKey", noUpDateKey);
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
            const title = list["title"] || "カテゴリ情報";
            const msgRaw = list["msg"] || "カテゴリ情報の取得に失敗しました。\nお手数ですが最初からやり直してください。";
            const msg = String(msgRaw).replace(/\n/g, "<br>");
            blockModal.querySelector(".box-title p").innerHTML = title;
            blockModal.querySelector(".box-details p").innerHTML = msg;
            //ボタン再生成
            blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", "closeModalToPage('client03_04.php')");
            let buttonList = blockModal.querySelector(".box-btn").querySelectorAll("button");
            buttonList.forEach((ElementButton) => {
                ElementButton.remove();
            });
            //ボタン生成
            let newButton = '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>';
            blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
            blockModal.classList.add("is-active");
            document.documentElement.style.overflow = "hidden";
        } else if (list["status"] == "editForm") {
            //既存の編集フォームがあれば削除
            const existingForm = listItem.querySelector('form[name="editForm"]');
            if (existingForm) existingForm.remove();
            //nav の後ろに編集用formを追加
            const nav = listItem.querySelector("nav");
            if (!nav) return;
            nav.insertAdjacentHTML("afterend", list["tag"] || "");
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
            blockModal.classList.add("is-active");
            document.documentElement.style.overflow = "hidden";
        }
    } catch (error) {
        //通信エラー時の処理
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * キャンセルボタン：編集用 form 削除
 *
 */
function cancelEdit() {
    //inline onclick から呼ばれる前提のため、event を利用して対象formを特定
    const target = typeof event !== "undefined" ? event.target : null;
    const form = target?.closest ? target.closest('form[name="editForm"]') : null;
    if (form) {
        form.remove();
        return;
    }
    //フォールバック：存在する編集フォームを全て削除
    document.querySelectorAll('form[name="editForm"]').forEach((f) => f.remove());
}
//HTML側（inline onclick）から呼べるようにグローバルへ公開
window.editCategory = editCategory;
window.cancelEdit = cancelEdit;
window.deleteCategory = deleteCategory;
window.confirmDeleteCategory = confirmDeleteCategory;
//初期化
(() => {
    const init = () => {
        initSortButtons();
        initDragAndDrop();
    };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
