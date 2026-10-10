/**
 * API送信先 共通定数
 *  表示順の保存処理だけを送信する。
 */
const requestURL = "./assets/function/proc_master04_01.php";

/**
 * トップページ飲食店予約の店舗表示順を管理する
 *  ドラッグ＆ドロップ後はDBの最新状態を再読込する。
 */
document.addEventListener("DOMContentLoaded", () => {
    const list = document.getElementById("foodShopOrderList");
    const modal = document.getElementById("foodShopOrderModal");
    if (!modal) return;
    const modalMessage = modal.querySelector("[data-order-modal-message]");
    const modalClose = modal.querySelector("[data-order-modal-close]");
    const modalOk = modal.querySelector("[data-order-modal-ok]");
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
     *  装飾は既存の行やスタイルシートを変更せずinlineで設定する。
     */
    const createDropPreview = () => {
        dropArea = document.createElement("li");
        dropArea.className = "food-shop-drop-area";
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
     * 候補行の上下に移動先エリアを置く
     *  表示中の候補位置と吹き出し文言を同期する。
     */
    const updateDropPreview = (target, upper) => {
        if (!dropArea) createDropPreview();
        const reference = upper ? target : target.nextSibling;
        if (!dropArea.isConnected || (reference !== dropArea && dropArea.nextSibling !== reference)) {
            list.insertBefore(dropArea, reference);
        }
        const shopName = target.querySelector(".item-name span")?.textContent?.trim() || "この店舗";
        dropMessage.textContent = `${shopName}の${upper ? "上" : "下"}に移動`;
    };

    /**
     * 管理画面の通知モーダルを表示する
     *  成功時だけ2秒後に自動終了し、案内付きの失敗時は操作を待つ。
     */
    const showModal = (message, buttonLabel, autoClose, reloadOnClose) => {
        modalMessage.textContent = message;
        modalOk.textContent = buttonLabel;
        modal.classList.add("is-active");
        modal.setAttribute("aria-hidden", "false");
        document.documentElement.style.overflow = "hidden";
        let timer = null;
        const finish = () => {
            if (timer !== null) window.clearTimeout(timer);
            modal.classList.remove("is-active");
            if (modal.contains(document.activeElement)) document.activeElement.blur();
            modal.setAttribute("aria-hidden", "true");
            document.documentElement.style.overflow = "";
            modalClose.removeEventListener("click", finish);
            modalOk.removeEventListener("click", finish);
            if (reloadOnClose) window.location.reload();
        };
        modalClose.addEventListener("click", finish);
        modalOk.addEventListener("click", finish);
        modalOk.focus();
        if (autoClose) timer = window.setTimeout(finish, 2000);
    };

    if (modal.dataset.pageJsonError === "1") {
        showModal("トップページの並び順を更新できませんでした。\n時間を置いてから、再度このページを開き直してお試しください。", "閉じる", false, false);
    }
    if (!list || list.querySelectorAll(".food-shop-order-row").length < 2) return;

    /**
     * 変更後の全店舗IDを送信する
     *  成否が不明なときも再送せず、画面を再読込してDBの状態を確認する。
     */
    const submitOrder = async (rows) => {
        if (busy) return;
        busy = true;
        const data = new FormData();
        data.append("action", "sort");
        data.append("noUpDateKey", list.dataset.noUpDateKey);
        data.append("orderVersion", list.dataset.orderVersion);
        rows.forEach((row) => data.append("shopOrder[]", row.dataset.shopId));
        try {
            const response = await fetch(requestURL, { method: "POST", body: data, cache: "no-store" });
            if (!response.ok) throw new Error("http_error");
            const result = await response.json();
            if (!result || typeof result !== "object") throw new Error("response_invalid");
            if (result.status === "success") {
                showModal("トップページの並び順を更新しました。", "閉じる", true, true);
                return;
            }
            if (result.status === "stale") {
                showModal("一覧に表示中の店舗情報が更新されています。\n「更新」ボタンを押して、最新の一覧でやり直してください。", "更新", false, true);
                return;
            }
            if (result.status === "json_error") {
                showModal("トップページの並び順を更新できませんでした。\n「更新」ボタンを押して、もう一度お試しください。", "更新", false, true);
                return;
            }
            if (result.status === "error") {
                showModal("並び順を変更できませんでした。\n「更新」ボタンを押して、最新の一覧をご確認ください。", "更新", false, true);
                return;
            }
            if (result.status === "unknown") {
                showModal("並び順の更新結果を確認できませんでした。\n「更新」ボタンを押して、最新の一覧をご確認ください。", "更新", false, true);
                return;
            }
            throw new Error("response_invalid");
        } catch (error) {
            showModal("並び順の更新結果を確認できませんでした。\n「更新」ボタンを押して、最新の一覧をご確認ください。", "更新", false, true);
        }
    };

    /**
     * 画像の取得失敗を処理する
     *  代替画像を一度だけ設定し、再帰的なerror発火を防ぐ。
     */
    list.addEventListener(
        "error",
        (event) => {
            const image = event.target;
            if (!(image instanceof HTMLImageElement) || !image.closest(".food-shop-order-row")) return;
            if (image.getAttribute("src") === "../assets/images/no-image.webp") return;
            image.src = "../assets/images/no-image.webp";
            image.alt = "店舗画像なし";
        },
        true,
    );
    /**
     * 店舗行のドラッグを開始する
     *  保存中とハンドル以外の操作は受け付けない。
     */
    list.addEventListener("dragstart", (event) => {
        const handle = event.target.closest(".food-shop-drag-handle");
        if (busy || !handle) {
            event.preventDefault();
            return;
        }
        clearDropPreview();
        draggedRow = handle.closest(".food-shop-order-row");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", draggedRow.dataset.shopId);
    });
    /**
     * ドロップ候補の位置を表示する
     *  行の上下半分でエリアを移動し、エリア上では現在位置を維持する。
     */
    list.addEventListener("dragover", (event) => {
        if (!draggedRow || busy) return;
        const target = event.target.closest(".food-shop-order-row");
        if (event.target.closest(".food-shop-drop-area")) {
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
    /**
     * ドロップされた店舗を移動する
     *  表示中の移動先エリアへ置き、実変更時だけ保存する。
     */
    list.addEventListener("drop", (event) => {
        const target = event.target.closest(".food-shop-order-row");
        const onDropArea = event.target.closest(".food-shop-drop-area");
        if (!draggedRow || !dropArea || (!target && !onDropArea) || busy) return;
        event.preventDefault();
        const before = Array.from(list.querySelectorAll(".food-shop-order-row"), (row) => row.dataset.shopId);
        list.insertBefore(draggedRow, dropArea);
        clearDropPreview();
        draggedRow = null;
        const rows = Array.from(list.querySelectorAll(".food-shop-order-row"));
        if (rows.every((row, index) => row.dataset.shopId === before[index])) return;
        submitOrder(rows);
    });
    /**
     * ドラッグの一時状態を消す
     *  ドロップせずに終了したときも一覧の元の順序を維持する。
     */
    list.addEventListener("dragend", () => {
        clearDropPreview();
        draggedRow = null;
    });
});
