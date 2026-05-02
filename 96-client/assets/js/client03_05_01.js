/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client03_05_01.php";
let pendingPublicToggle = null;

/**
 * 分類並び替え（↑↓／D&D）
 *
 */
function getClassifyListRoot() {
    return document.querySelector(".drag-area");
}
function getSpecificationId() {
    const raw = document.querySelector('form[name="inputForm"] input[name="specificationId"]')?.value || "";
    if (!/^[0-9]+$/.test(raw)) return "";
    return raw;
}
function getCurrentPageUrl() {
    const specificationId = getSpecificationId();
    if (!specificationId) return "client03_05_01.php";
    return `client03_05_01.php?specification_id=${encodeURIComponent(specificationId)}`;
}
function clearPendingPublicToggle() {
    pendingPublicToggle = null;
}
function restorePendingPublicToggle() {
    if (pendingPublicToggle?.checkbox) {
        pendingPublicToggle.checkbox.checked = pendingPublicToggle.previousChecked;
    }
    clearPendingPublicToggle();
}
function cancelClassifyPublicToggle() {
    restorePendingPublicToggle();
    closeModal();
}
async function confirmClassifyPublicToggle() {
    if (!pendingPublicToggle?.checkbox || !pendingPublicToggle.classifyId) return;
    const sFd = new FormData();
    sFd.append("action", "updatePublicStatus");
    sFd.append("method", "togglePublic");
    sFd.append("classifyId", pendingPublicToggle.classifyId);
    sFd.append("isActive", pendingPublicToggle.nextChecked ? "1" : "0");
    sFd.append("noUpDateKey", pendingPublicToggle.noUpDateKey);
    sFd.append("specificationId", getSpecificationId());
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (result["status"] !== "success") {
            restorePendingPublicToggle();
            showErrorModal(result["title"] || "更新失敗", result["msg"] || "表示設定の更新に失敗しました。\nページを再読み込みしてください。");
            return;
        }
        clearPendingPublicToggle();
        closeModal();
    } catch (error) {
        restorePendingPublicToggle();
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
function checkClassifyPublic(el) {
    const checkbox = el;
    const listItem = checkbox?.closest("li");
    const noUpDateKey = document.querySelector('form[name="inputForm"] input[name="noUpDateKey"]')?.value || "";
    if (!checkbox || !listItem || !noUpDateKey) {
        if (checkbox) checkbox.checked = !checkbox.checked;
        return;
    }
    const classifyId = (listItem.querySelector(".item-id span")?.textContent || "").trim();
    if (!classifyId) {
        checkbox.checked = !checkbox.checked;
        return;
    }
    const classifyName = (listItem.querySelector(".item-name .name")?.textContent || "").trim();
    const adminName = (listItem.querySelector(".item-name .admin")?.textContent || "").trim();
    const nextChecked = checkbox.checked;
    const nextLabel = nextChecked ? "表示" : "非表示";
    pendingPublicToggle = {
        checkbox,
        classifyId,
        noUpDateKey,
        previousChecked: !nextChecked,
        nextChecked,
    };
    const blockModal = document.getElementById("modalBlock");
    const message = `${classifyName}： [管理名：${adminName}] を「${nextLabel}」に切り替えます。<br>よろしいですか？`;
    if (!blockModal) {
        if (window.confirm(`${classifyName}： [管理名：${adminName}] を「${nextLabel}」に切り替えます。\nよろしいですか？`)) {
            confirmClassifyPublicToggle();
        } else {
            restorePendingPublicToggle();
        }
        return;
    }
    blockModal.querySelector(".box-title p").innerHTML = "分類表示設定";
    blockModal.querySelector(".box-details p").innerHTML = message;
    const btnBox = blockModal.querySelector(".box-btn");
    btnBox.querySelectorAll("button").forEach((button) => button.remove());
    btnBox.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="cancelClassifyPublicToggle();">いいえ</button>');
    btnBox.insertAdjacentHTML("beforeend", '<button type="button" class="btn-confirm" onclick="confirmClassifyPublicToggle();">はい</button>');
    blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", "cancelClassifyPublicToggle()");
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}
function getClassifyItemElements() {
    const ul = getClassifyListRoot();
    if (!ul) return [];
    return Array.from(ul.querySelectorAll(":scope > li")).filter((li) => {
        //ヘッダ行・no-data を除外し、ID列を持つ行のみ対象
        if (li.classList.contains("no-data")) return false;
        return !!li.querySelector(".item-id span");
    });
}
function getClassifyIdFromItem(li) {
    const raw = (li?.querySelector(".item-id span")?.textContent || "").trim();
    if (!raw) return null;
    if (!/^[0-9]+$/.test(raw)) return null;
    return parseInt(raw, 10);
}
function getCurrentOrderIds() {
    return getClassifyItemElements()
        .map((li) => getClassifyIdFromItem(li))
        .filter((id) => Number.isInteger(id) && id > 0);
}
function updateSortButtonVisibility() {
    const items = getClassifyItemElements();
    if (items.length === 0) return;
    items.forEach((li, idx) => {
        const btnUp = li.querySelector("button.btn-up");
        const btnDown = li.querySelector("button.btn-down");
        if (btnUp) btnUp.style.visibility = idx === 0 ? "hidden" : "";
        if (btnDown) btnDown.style.visibility = idx === items.length - 1 ? "hidden" : "";
    });
}
function restoreOrderByIds(orderIds) {
    const ul = getClassifyListRoot();
    if (!ul) return;
    const header = ul.querySelector(":scope > li");
    const items = getClassifyItemElements();
    const map = new Map();
    items.forEach((li) => {
        const id = getClassifyIdFromItem(li);
        if (id) map.set(id, li);
    });
    //既存分類行を一旦除去（DOMから消すだけ）
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
    if (!blockModal) {
        return;
    }
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
    sFd.append("specificationId", getSpecificationId());
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
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
        return { ok: false, handled: true };
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
    if (!res.handled) {
        showErrorModal(res.title, res.msg);
    }
    return false;
}
function moveItemUp(li) {
    const ul = getClassifyListRoot();
    if (!ul) return;
    const items = getClassifyItemElements();
    const idx = items.indexOf(li);
    if (idx <= 0) return;
    const prev = items[idx - 1];
    ul.insertBefore(li, prev);
    updateSortButtonVisibility();
}
function moveItemDown(li) {
    const ul = getClassifyListRoot();
    if (!ul) return;
    const items = getClassifyItemElements();
    const idx = items.indexOf(li);
    if (idx < 0 || idx >= items.length - 1) return;
    const next = items[idx + 1];
    ul.insertBefore(next, li);
    updateSortButtonVisibility();
}
function initSortButtons() {
    const ul = getClassifyListRoot();
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
    const sortableLis = getClassifyItemElements().filter((li) => !li.classList.contains("is-dragging"));
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
    const ul = getClassifyListRoot();
    if (!ul) return;
    const items = getClassifyItemElements();
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
    const baseForm = document.querySelector('form[name="inputForm"]');
    const form = currentForm || baseForm;
    if (!form) return;
    //HTMLのrequired等を最優先でチェック（radio等のグループも含む）
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    let errFlag = 0;
    //inputForm は従来通り checkRequiredElem() を使用（editForm は動的要素のためHTML requiredに委ねる）
    if (form === baseForm && typeof checkRequiredElem === "function") {
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
            if (!blockModal) {
                if (list["status"] == "error") {
                    showErrorModal(list["title"] || "登録失敗", list["msg"] || "登録に失敗しました。");
                } else {
                    window.location.href = getCurrentPageUrl();
                }
                return;
            }
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
                let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('${getCurrentPageUrl()}');">閉じる</button>`;
                blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
                //「✕」ボタンも変更
                blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", `closeModalToPage('${getCurrentPageUrl()}')`);
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
 * ゴミ箱ボタン：分類削除チェック
 *
 */
function deleteClassify(el) {
    const listItem = el?.closest("li");
    if (!listItem) return;
    //分類ID（一覧の表示IDをそのまま利用）
    const classifyId = (listItem.querySelector(".item-id span")?.textContent || "").trim();
    if (!classifyId) return;
    //分類名（表示用）
    const classifyName = (listItem.querySelector(".item-name span")?.textContent || "").trim();
    //セッションキー
    const noUpDateKey = document.querySelector('form[name="inputForm"] input[name="noUpDateKey"]')?.value || "";
    if (!noUpDateKey) return;
    //モーダルボックス
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) {
        const label = classifyName ? `「${classifyName}」` : "この分類";
        if (window.confirm(`${label}を削除します。よろしいですか？`)) {
            confirmDeleteClassify(classifyId, noUpDateKey);
        }
        return;
    }
    //削除対象をモーダルに保持
    blockModal.dataset.classifyId = classifyId;
    blockModal.dataset.noUpDateKey = noUpDateKey;
    //表示
    const titleEl = blockModal.querySelector(".box-title p");
    const msgEl = blockModal.querySelector(".box-details p");
    if (titleEl) titleEl.textContent = "分類削除";
    if (msgEl) {
        const label = classifyName ? `「${classifyName}」` : "この分類";
        msgEl.textContent = `${label}を削除します。よろしいですか？`;
    }
    //ボタン再生成
    const btnBox = blockModal.querySelector(".box-btn");
    if (!btnBox) return;
    btnBox.querySelectorAll("button").forEach((b) => b.remove());
    const buttons = `
        <button type="button" class="btn-cancel" onclick="closeModal();">キャンセル</button>
        <button type="button" class="btn-confirm" onclick="confirmDeleteClassify();">はい</button>
    `;
    btnBox.insertAdjacentHTML("beforeend", buttons);
    //「✕」ボタン
    const topCloseButton = blockModal.querySelector(".box-title")?.querySelector("button");
    if (topCloseButton) topCloseButton.setAttribute("onclick", "closeModal()");
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";
}
/**
 * ゴミ箱ボタン：分類削除実行
 *
 */
async function confirmDeleteClassify(forcedClassifyId, forcedNoUpDateKey) {
    const blockModal = document.getElementById("modalBlock");
    const classifyId = forcedClassifyId || blockModal?.dataset.classifyId || "";
    const noUpDateKey = forcedNoUpDateKey || blockModal?.dataset.noUpDateKey || "";
    if (!classifyId || !noUpDateKey) return;
    const sFd = new FormData();
    sFd.append("action", "deleteClassify");
    sFd.append("method", "delete");
    sFd.append("classifyId", classifyId);
    sFd.append("noUpDateKey", noUpDateKey);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (!blockModal) {
            if (result["status"] == "error") {
                showErrorModal(result["title"] || "削除失敗", result["msg"] || "削除に失敗しました。");
            } else {
                window.location.href = getCurrentPageUrl();
            }
            return;
        }
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
            blockModal.querySelector(".box-title p").innerHTML = result["title"] || "分類削除";
            blockModal.querySelector(".box-details p").innerHTML = String(result["msg"] || "削除が完了しました。").replace(/\n/g, "<br>");
            const btnBox = blockModal.querySelector(".box-btn");
            btnBox.querySelectorAll("button").forEach((b) => b.remove());
            btnBox.insertAdjacentHTML("beforeend", `<button type="button" class="btn-cancel" onclick="closeModalToPage('${getCurrentPageUrl()}');">閉じる</button>`);
            blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", `closeModalToPage('${getCurrentPageUrl()}')`);
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
async function editClassify(el) {
    const listItem = el?.closest("li");
    if (!listItem) return;
    //分類ID（一覧の表示IDをそのまま利用）
    const classifyId = (listItem.querySelector(".item-id span")?.textContent || "").trim();
    if (!classifyId) return;
    //セッションキー
    const noUpDateKey = document.querySelector('form[name="inputForm"] input[name="noUpDateKey"]')?.value || "";
    if (!noUpDateKey) return;
    //送信用FormData生成（編集フォーム生成用）
    const sFd = new FormData();
    sFd.append("action", "makeEditForm");
    sFd.append("method", "edit");
    sFd.append("classifyId", classifyId);
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
        if (!blockModal && list["status"] == "error") {
            showErrorModal(list["title"] || "分類情報", list["msg"] || "分類情報の取得に失敗しました。");
            return;
        }
        //サーバー応答がエラーの場合
        if (list["status"] == "error") {
            const title = list["title"] || "分類情報";
            const msgRaw = list["msg"] || "分類情報の取得に失敗しました。\nお手数ですが最初からやり直してください。";
            const msg = String(msgRaw).replace(/\n/g, "<br>");
            blockModal.querySelector(".box-title p").innerHTML = title;
            blockModal.querySelector(".box-details p").innerHTML = msg;
            //ボタン再生成
            blockModal.querySelector(".box-title")?.querySelector("button")?.setAttribute("onclick", `closeModalToPage('${getCurrentPageUrl()}')`);
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
            let newButton = `<button type="button" class="btn-cancel" onclick="closeModalToPage('${getCurrentPageUrl()}');">閉じる</button>`;
            blockModal.querySelector(".box-btn").insertAdjacentHTML("beforeend", newButton);
            //「✕」ボタンも変更
            blockModal.querySelector(".box-title").querySelector("button").setAttribute("onclick", `closeModalToPage('${getCurrentPageUrl()}')`);
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
window.editClassify = editClassify;
window.cancelEdit = cancelEdit;
window.deleteClassify = deleteClassify;
window.confirmDeleteClassify = confirmDeleteClassify;
window.checkClassifyPublic = checkClassifyPublic;
window.cancelClassifyPublicToggle = cancelClassifyPublicToggle;
window.confirmClassifyPublicToggle = confirmClassifyPublicToggle;
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
