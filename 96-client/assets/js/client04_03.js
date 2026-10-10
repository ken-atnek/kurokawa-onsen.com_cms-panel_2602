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
    let dropArea = null;
    let dropMessage = null;
    /**
     * 移動先エリアと吹き出しを取り除く
     *  ドロップ後とドラッグ中止時に一時表示を消す。
     */
    const clearDropPreview = () => {
        if (dropArea) dropArea.remove();
        dropArea = null;
        dropMessage = null;
    };
    /**
     * 移動先エリアと吹き出しを作成する
     *  メニュー一覧の全列にまたがる候補位置をinlineで表示する。
     */
    const createDropPreview = () => {
        dropArea = document.createElement("li");
        dropArea.className = "food-menu-drop-area";
        dropArea.setAttribute("aria-hidden", "true");
        dropArea.style.cssText = "grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; min-height: 6rem; box-sizing: border-box; border: 2px dashed #e47900; background-color: #edf1f8; cursor: default; transition: none;";

        const bubble = document.createElement("span");
        bubble.style.cssText = "position: relative; display: inline-block; max-width: calc(100% - 2rem); padding: 0.5rem 1rem; border-radius: 0.5rem; background-color: #e47900; color: #fff; font-size: 1.2rem; line-height: 1.4; text-align: center; pointer-events: none;";
        dropMessage = document.createElement("span");
        bubble.appendChild(dropMessage);

        const tail = document.createElement("span");
        tail.style.cssText = "position: absolute; left: calc(50% - 0.8rem); bottom: -0.6rem; width: 0; height: 0; border-left: 0.8rem solid transparent; border-right: 0.8rem solid transparent; border-top: 0.8rem solid #e47900; pointer-events: none;";
        bubble.appendChild(tail);
        dropArea.appendChild(bubble);
    };
    /**
     * 候補行の上下へ移動先エリアを置く
     *  表示中の位置と吹き出し文言を同期する。
     */
    const updateDropPreview = (target, upper) => {
        if (!dropArea) createDropPreview();
        const reference = upper ? target : target.nextSibling;
        if (!dropArea.isConnected || (reference !== dropArea && dropArea.nextSibling !== reference)) {
            list.insertBefore(dropArea, reference);
        }
        const menuName = target.querySelector(".item-name span")?.textContent?.trim() || "このメニュー";
        dropMessage.textContent = `${menuName}の${upper ? "上" : "下"}に移動`;
    };
    /**
     * 既存modal内でserver文字列を表示する
     *  並び替え成功時だけ2秒後に自動終了する。
     */
    const showModal = (message, autoClose = false) =>
        new Promise((resolve) => {
            modalMessage.textContent = message;
            modal.classList.add("is-active");
            modal.setAttribute("aria-hidden", "false");
            let timer = null;
            const finish = () => {
                if (timer !== null) window.clearTimeout(timer);
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
            if (autoClose) timer = window.setTimeout(finish, 2000);
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
                if (messages.length > 0) {
                    await showModal(messages.join(" "), result.status === "success" && !warning && data.get("action") === "sort");
                }
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
        clearDropPreview();
        draggedRow = handle.closest(".food-menu-row");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", draggedRow.dataset.menuId);
    });
    list.addEventListener("dragover", (event) => {
        if (!draggedRow || busy) return;
        const target = event.target.closest(".food-menu-row");
        if (event.target.closest(".food-menu-drop-area")) {
            event.preventDefault();
            event.dataTransfer.dropEffect = "move";
            return;
        }
        if (!target || target === draggedRow) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
        const upper = event.clientY < target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
        updateDropPreview(target, upper);
    });
    list.addEventListener("drop", (event) => {
        const target = event.target.closest(".food-menu-row");
        const onDropArea = event.target.closest(".food-menu-drop-area");
        if (!draggedRow || !dropArea || (!target && !onDropArea) || busy) return;
        event.preventDefault();
        const before = Array.from(list.querySelectorAll(".food-menu-row"), (row) => row.dataset.menuId);
        list.insertBefore(draggedRow, dropArea);
        clearDropPreview();
        draggedRow = null;
        const rows = Array.from(list.querySelectorAll(".food-menu-row"));
        if (rows.every((row, index) => row.dataset.menuId === before[index])) return;
        const data = makeRequest("sort");
        data.append("orderVersion", state.dataset.orderVersion);
        rows.forEach((row) => data.append("menuOrder[]", row.dataset.menuId));
        submit(data);
    });
    list.addEventListener("dragend", () => {
        clearDropPreview();
        draggedRow = null;
    });
});
