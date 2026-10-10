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
    let highlightedRow = null;

    /**
     * ドロップ候補の強調を取り除く
     *  既存のstyle属性が空になったときだけ属性ごと除去する。
     */
    const clearDropHighlight = () => {
        if (!highlightedRow) return;
        highlightedRow.style.removeProperty("outline");
        highlightedRow.style.removeProperty("outline-offset");
        if (highlightedRow.style.length === 0) highlightedRow.removeAttribute("style");
        highlightedRow = null;
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
        showModal(
            "トップページの並び順を更新できませんでした。\n時間を置いてから、再度このページを開き直してお試しください。",
            "閉じる", false, false
        );
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
                showModal(
                    "一覧に表示中の店舗情報が更新されています。\n「更新」ボタンを押して、最新の一覧でやり直してください。",
                    "更新", false, true
                );
                return;
            }
            if (result.status === "json_error") {
                showModal(
                    "トップページの並び順を更新できませんでした。\n「更新」ボタンを押して、もう一度お試しください。",
                    "更新", false, true
                );
                return;
            }
            if (result.status === "error") {
                showModal(
                    "並び順を変更できませんでした。\n「更新」ボタンを押して、最新の一覧をご確認ください。",
                    "更新", false, true
                );
                return;
            }
            if (result.status === "unknown") {
                showModal(
                    "並び順の更新結果を確認できませんでした。\n「更新」ボタンを押して、最新の一覧をご確認ください。",
                    "更新", false, true
                );
                return;
            }
            throw new Error("response_invalid");
        } catch (error) {
            showModal(
                "並び順の更新結果を確認できませんでした。\n「更新」ボタンを押して、最新の一覧をご確認ください。",
                "更新", false, true
            );
        }
    };

    /**
     * 画像の取得失敗を処理する
     *  代替画像を一度だけ設定し、再帰的なerror発火を防ぐ。
     */
    list.addEventListener("error", (event) => {
        const image = event.target;
        if (!(image instanceof HTMLImageElement) || !image.closest(".food-shop-order-row")) return;
        if (image.getAttribute("src") === "../assets/images/no-image.webp") return;
        image.src = "../assets/images/no-image.webp";
        image.alt = "店舗画像なし";
    }, true);
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
        draggedRow = handle.closest(".food-shop-order-row");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", draggedRow.dataset.shopId);
    });
    /**
     * ドロップ候補を表示する
     *  候補行へinlineの強調を設定する。
     */
    list.addEventListener("dragover", (event) => {
        if (!draggedRow || busy) return;
        const target = event.target.closest(".food-shop-order-row");
        if (target) event.preventDefault();
        if (target === highlightedRow) return;
        clearDropHighlight();
        if (target && target !== draggedRow) {
            target.style.outline = "2px solid #335d95";
            target.style.outlineOffset = "-2px";
            highlightedRow = target;
        }
    });
    /**
     * ドロップされた店舗を移動する
     *  上下半分で挿入先を決め、実変更時だけ保存する。
     */
    list.addEventListener("drop", (event) => {
        clearDropHighlight();
        const target = event.target.closest(".food-shop-order-row");
        if (!draggedRow || !target || busy) return;
        event.preventDefault();
        const before = Array.from(list.querySelectorAll(".food-shop-order-row"), (row) => row.dataset.shopId);
        const upper = event.clientY < target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
        list.insertBefore(draggedRow, upper ? target : target.nextSibling);
        draggedRow = null;
        const rows = Array.from(list.querySelectorAll(".food-shop-order-row"));
        if (rows.every((row, index) => row.dataset.shopId === before[index])) return;
        submitOrder(rows);
    });
    /**
     * ドラッグの一時状態を消す
     *  候補行の強調と移動元の参照を解除する。
     */
    list.addEventListener("dragend", () => {
        clearDropHighlight();
        draggedRow = null;
    });
});
