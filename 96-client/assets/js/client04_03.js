/**
 * 食事メニュー一覧の有効切替・並び替え。
 * 保存結果は必ず再読込してDB authorityへ揃える。
 */
document.addEventListener("DOMContentLoaded", () => {
    const state = document.getElementById("foodMenuList");
    if (!state) return;

    const list = state;
    const createButton = document.getElementById("foodMenuCreate");
    const modal = document.getElementById("foodMenuModal");
    const modalMessage = modal.querySelector("[data-menu-modal-message]");
    const modalClose = modal.querySelector("[data-menu-modal-close]");
    const modalOk = modal.querySelector("[data-menu-modal-ok]");
    const endpoint = "./assets/function/proc_client04_03.php";
    let busy = false;
    let draggedRow = null;
    let highlightedRow = null;
    /** ドロップ候補のinline強調を操作終了時に取り除く。 */
    const clearDropHighlight = () => {
        if (!highlightedRow) return;
        highlightedRow.style.removeProperty("outline");
        highlightedRow.style.removeProperty("outline-offset");
        if (highlightedRow.style.length === 0) highlightedRow.removeAttribute("style");
        highlightedRow = null;
    };
    /** 既存modal内でserver文字列をテキストとして表示する。 */
    const showModal = (message) =>
        new Promise((resolve) => {
            modalMessage.textContent = message;
            modal.classList.add("is-active");
            modal.setAttribute("aria-hidden", "false");
            const finish = () => {
                modal.classList.remove("is-active");
                if (modal.contains(document.activeElement)) document.activeElement.blur();
                modal.setAttribute("aria-hidden", "true");
                modalClose.removeEventListener("click", finish);
                modalOk.removeEventListener("click", finish);
                resolve();
            };
            modalClose.addEventListener("click", finish);
            modalOk.addEventListener("click", finish);
            modalOk.focus();
        });
    /** action共通のsession・CSRFだけをmanual FormDataへ載せる。 */
    const makeRequest = (action) => {
        const data = new FormData();
        data.append("noUpDateKey", state.dataset.noUpDateKey);
        data.append("csrfToken", state.dataset.csrfToken);
        data.append("action", action);
        return data;
    };
    /**
     * 保存結果に応じた通知と一覧再読込
     *  JSON書き出し警告もDB保存済みとして扱う。
     */
    const submit = async (data) => {
        if (busy) return;
        busy = true;
        try {
            const response = await fetch(endpoint, { method: "POST", body: data, cache: "no-store" });
            if (!response.ok) throw new Error("http_error");
            const result = await response.json();
            if (!result || typeof result !== "object" || !["success", "warning", "error"].includes(result.status)) {
                throw new Error("response_invalid");
            }
            if (result.status === "success" || result.status === "warning") {
                const warning = Array.isArray(result.postCommitWarnings) && result.postCommitWarnings.includes("menu_required_no_active_menu");
                const messages = [];
                if (result.status === "warning") {
                    messages.push(typeof result.msg === "string" && result.msg ? result.msg.replaceAll("<br>", " ") : "保存しました。フロント表示用JSONの更新に失敗しました。");
                }
                if (warning) {
                    messages.push("保存しました。食事メニュー選択が必須ですが、現在利用できるメニューがありません。");
                } else if (messages.length === 0 && data.get("action") === "sort") {
                    messages.push("食事メニューの並び順を変更しました。");
                }
                if (messages.length > 0) await showModal(messages.join(" "));
                window.location.reload();
                return;
            }
            await showModal(typeof result.msg === "string" && result.msg ? result.msg : "処理できませんでした。");
        } catch (error) {
            await showModal("通信結果を確認できません。保存済みの可能性があります。ページを再読み込みします。");
        }
        window.location.reload();
    };
    createButton.addEventListener("click", () => {
        if (!busy) window.location.href = "./client04_03_01.php";
    });
    list.addEventListener("click", (event) => {
        const row = event.target.closest(".food-menu-row");
        if (!row || busy || !event.target.closest(".btn-edit")) return;
        window.location.href = "./client04_03_01.php?menuId=" + encodeURIComponent(row.dataset.menuId);
    });
    list.addEventListener("change", (event) => {
        if (!event.target.matches(".food-menu-toggle") || busy) return;
        const row = event.target.closest(".food-menu-row");
        const data = makeRequest("toggle");
        data.append("menuId", row.dataset.menuId);
        data.append("menuVersion", row.dataset.menuVersion);
        data.append("isActive", event.target.checked ? "1" : "0");
        submit(data);
    });
    list.addEventListener("dragstart", (event) => {
        const handle = event.target.closest(".food-menu-drag-handle");
        if (busy || !handle) {
            event.preventDefault();
            return;
        }
        draggedRow = handle.closest(".food-menu-row");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", draggedRow.dataset.menuId);
    });
    list.addEventListener("dragover", (event) => {
        if (!draggedRow) return;
        const target = event.target.closest(".food-menu-row");
        if (target) event.preventDefault();
        if (target === highlightedRow) return;
        clearDropHighlight();
        if (target && target !== draggedRow) {
            target.style.outline = "2px solid #335d95";
            target.style.outlineOffset = "-2px";
            highlightedRow = target;
        }
    });
    list.addEventListener("drop", (event) => {
        clearDropHighlight();
        const target = event.target.closest(".food-menu-row");
        if (!draggedRow || !target || busy) return;
        event.preventDefault();
        const before = Array.from(list.querySelectorAll(".food-menu-row"), (row) => row.dataset.menuId);
        const upper = event.clientY < target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
        list.insertBefore(draggedRow, upper ? target : target.nextSibling);
        draggedRow = null;
        const rows = Array.from(list.querySelectorAll(".food-menu-row"));
        if (rows.every((row, index) => row.dataset.menuId === before[index])) return;
        const data = makeRequest("sort");
        data.append("orderVersion", state.dataset.orderVersion);
        rows.forEach((row) => data.append("menuOrder[]", row.dataset.menuId));
        submit(data);
    });
    list.addEventListener("dragend", () => {
        clearDropHighlight();
        draggedRow = null;
    });
});
