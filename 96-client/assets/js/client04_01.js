/**
 * 席管理画面の登録・編集・並び替えを接続する。
 * 保存結果は再読込してDB上の最新状態へ揃える。
 */
document.addEventListener("DOMContentLoaded", () => {
    const state = document.getElementById("seatManagementState");
    if (!state) return;

    const endpoint = "./assets/function/proc_client04_01.php";
    const list = document.getElementById("seatManagementList");
    const modal = document.getElementById("modalBlock");
    const modalMessage = modal.querySelector("[data-seat-modal-message]");
    const confirmButton = modal.querySelector("[data-seat-modal-confirm]");
    const cancelButton = modal.querySelector("[data-seat-modal-cancel]");
    const closeButton = modal.querySelector("[data-seat-modal-close]");
    const warningLabels = {
        seat_capacity_change_with_reservation: "定員の変更は、現在または今後の予約に影響する可能性があります。",
        seat_active_change_with_reservation: "有効状態の変更は、現在または今後の予約に影響する可能性があります。",
        seat_counter_area_change_with_reservation: "カウンターエリアの変更は、現在または今後の予約に影響する可能性があります。",
        seat_type_change_with_reservation: "席種の変更は、現在または今後の予約に影響する可能性があります。",
    };
    let busy = false;
    let editingRow = null;
    let draggedRow = null;
    let highlightedRow = null;
    const capacityState = new WeakMap();
    /**
     * 既存modalで安全なテキストだけを表示する。
     * warning時のみ確認ボタンを有効化する。
     */
    const showModal = (message, confirmation = false) =>
        new Promise((resolve) => {
            modalMessage.textContent = message;
            confirmButton.hidden = !confirmation;
            confirmButton.style.display = confirmation ? "" : "none";
            cancelButton.textContent = confirmation ? "キャンセル" : "閉じる";
            modal.classList.add("is-active");
            modal.setAttribute("aria-hidden", "false");
            const finish = (confirmed) => {
                modal.classList.remove("is-active");
                modal.setAttribute("aria-hidden", "true");
                confirmButton.removeEventListener("click", onConfirm);
                cancelButton.removeEventListener("click", onCancel);
                closeButton.removeEventListener("click", onCancel);
                resolve(confirmed);
            };
            const onConfirm = () => finish(true);
            const onCancel = () => finish(false);
            confirmButton.addEventListener("click", onConfirm);
            cancelButton.addEventListener("click", onCancel);
            closeButton.addEventListener("click", onCancel);
            (confirmation ? confirmButton : cancelButton).focus();
        });
    /**
     * action共通のsession・CSRF情報を付ける。
     * 店舗IDはPOSTせずSESSIONだけをauthorityとする。
     */
    const createRequest = (action) => {
        const data = new FormData();
        data.append("noUpDateKey", state.dataset.noUpDateKey);
        data.append("csrfToken", state.dataset.csrfToken);
        data.append("action", action);
        return data;
    };
    /**
     * server warningを確認後、同じversionで再送する。
     * 通信結果が不明な場合は再送せず再読込する。
     */
    const submitRequest = async (data, successMessage = "") => {
        if (busy) return false;
        busy = true;
        const confirmed = new Set();
        try {
            for (let attempt = 0; attempt < 5; attempt += 1) {
                const response = await fetch(endpoint, { method: "POST", body: data, cache: "no-store" });
                if (!response.ok) throw new Error("http_error");
                const result = await response.json();
                if (!result || typeof result !== "object") throw new Error("response_invalid");
                if (result.status === "warning" && result.requiresConfirmation === true) {
                    const codes = result.warningCodes;
                    if (!Array.isArray(codes) || codes.length === 0 || new Set(codes).size !== codes.length || codes.some((code) => !Object.hasOwn(warningLabels, code))) {
                        throw new Error("warning_invalid");
                    }
                    const pending = codes.filter((code) => !confirmed.has(code));
                    if (pending.length === 0) throw new Error("warning_loop");
                    const accepted = await showModal(pending.map((code) => warningLabels[code]).join("\n") + "\n変更しますか？", true);
                    if (!accepted) return false;
                    pending.forEach((code) => confirmed.add(code));
                    data.delete("confirmedWarnings[]");
                    confirmed.forEach((code) => data.append("confirmedWarnings[]", code));
                    continue;
                }
                if (result.status === "warning") {
                    await showModal(typeof result.msg === "string" && result.msg ? result.msg.replaceAll("<br>", "\n") : "保存後のJSON更新結果を確認できませんでした。ページを再読み込みしてください。");
                    window.location.reload();
                    return true;
                }
                if (result.status === "success") {
                    if (successMessage) await showModal(successMessage);
                    window.location.reload();
                    return true;
                }
                await showModal(typeof result.msg === "string" && result.msg ? result.msg : "処理できませんでした。ページを再読み込みしてください。");
                window.location.reload();
                return false;
            }
            throw new Error("warning_limit");
        } catch (error) {
            await showModal("通信結果を確認できません。保存済みの可能性があります。ページを再読み込みします。");
            window.location.reload();
            return false;
        } finally {
            busy = false;
        }
    };
    /**
     * 席種に応じて定員とエリアの入力状態を切り替える。
     * tableの選択値を退避し、counter用の1とは分けて保持する。
     */
    const syncFormType = (form) => {
        const type = form.querySelector('[name="seatType"][data-selectbox-hidden]').value;
        const capacityBox = form.querySelector(".select-capacity");
        const areaBox = form.querySelector(".select-counter-area");
        const capacityHidden = capacityBox.querySelector("[data-selectbox-hidden]");
        const previous = capacityState.get(form) || { type: null, lastTableCapacity: "" };
        if (previous.type === "2" && type !== "2") {
            previous.lastTableCapacity = capacityHidden.value;
        } else if (previous.type === null && type === "2") {
            previous.lastTableCapacity = capacityHidden.value;
        }
        let capacityValue = null;
        if (type === "1") {
            capacityValue = "1";
        } else if (type === "2" && previous.type !== "2") {
            capacityValue = previous.lastTableCapacity;
        }
        if (capacityValue !== null) {
            const radios = Array.from(capacityBox.querySelectorAll('input[type="radio"]'));
            const selected = radios.find((radio) => radio.value === capacityValue);
            capacityHidden.value = selected ? capacityValue : "";
            radios.forEach((radio) => {
                radio.checked = radio === selected;
            });
            capacityBox.querySelector("[data-selectbox-value]").textContent = selected ? selected.nextElementSibling.textContent.trim() : "--";
            capacityBox.classList.toggle("is-selected", Boolean(selected));
            capacityBox.classList.toggle("is-empty", !selected);
        }
        capacityBox.querySelector(".selectbox__head").disabled = type === "1";
        capacityBox.querySelectorAll('input[type="radio"]').forEach((radio) => {
            radio.disabled = type === "1";
        });
        areaBox.querySelector(".selectbox__head").disabled = type === "2";
        areaBox.querySelectorAll('input[type="radio"]').forEach((radio) => {
            radio.disabled = type === "2";
        });
        areaBox.closest("dd").parentElement.style.display = type === "1" ? "" : "none";
        previous.type = type;
        capacityState.set(form, previous);
    };
    /**
     * 追加・編集フォームの値をaction別requestへ移す。
     * custom selectboxのhidden値だけを読み、重複radio名を送らない。
     */
    const formRequest = (form, action, row = null) => {
        const getSelect = (name) => form.querySelector(`[name="${name}"][data-selectbox-hidden]`).value;
        const name = form.querySelector('[name="seatName"]').value;
        const type = getSelect("seatType");
        const capacity = type === "1" ? "1" : getSelect("capacity");
        const area = type === "1" ? getSelect("counterArea") : "";
        if (!form.reportValidity()) return null;
        if (!type || !capacity || (type === "1" && !area)) return false;
        const data = createRequest(action);
        if (row) {
            data.append("seatId", row.dataset.seatId);
            data.append("seatVersion", row.dataset.seatVersion);
        }
        data.append("seatName", name);
        data.append("seatType", type);
        data.append("capacity", capacity);
        data.append("counterArea", area);
        return data;
    };
    const createForm = state.querySelector(".seat-create-form");
    createForm.addEventListener("submit", (event) => {
        event.preventDefault();
        const data = formRequest(createForm, "create");
        if (data === false) {
            showModal("席種・定員・カウンターエリアを確認してください。");
        } else if (data) {
            submitRequest(data);
        }
    });
    state.querySelectorAll(".seat-create-form, .seat-edit-form").forEach((form) => {
        syncFormType(form);
        form.querySelectorAll('[name="seatType"]:not([data-selectbox-hidden])').forEach((radio) => {
            radio.addEventListener("change", () => syncFormType(form));
        });
    });
    list.addEventListener("click", (event) => {
        const row = event.target.closest(".seat-row");
        if (!row || busy) return;
        const view = row.querySelector(".inner-list");
        const form = row.querySelector(".seat-edit-form");
        if (event.target.closest(".btn-edit")) {
            if (editingRow === row) return;
            if (editingRow) {
                editingRow.querySelector(".inner-list").style.display = "";
                editingRow.querySelector(".seat-edit-form").style.display = "none";
            }
            view.style.display = "none";
            form.style.display = "";
            editingRow = row;
            form.querySelector('[name="seatName"]').focus();
        } else if (event.target.closest(".btn-cancel")) {
            window.location.reload();
        } else if (event.target.closest(".btn-delate")) {
            if (!window.confirm("この席を削除しますか？")) return;
            const data = createRequest("delete");
            data.append("seatId", row.dataset.seatId);
            data.append("seatVersion", row.dataset.seatVersion);
            submitRequest(data);
        }
    });
    list.addEventListener("submit", (event) => {
        if (!event.target.matches(".seat-edit-form")) return;
        event.preventDefault();
        const row = event.target.closest(".seat-row");
        const data = formRequest(event.target, "update", row);
        if (data === false) {
            showModal("席種・定員・カウンターエリアを確認してください。");
        } else if (data) {
            submitRequest(data);
        }
    });
    list.addEventListener("change", (event) => {
        if (!event.target.matches(".seat-active-toggle")) return;
        const toggle = event.target;
        const row = toggle.closest(".seat-row");
        const data = createRequest("toggle");
        data.append("seatId", row.dataset.seatId);
        data.append("seatVersion", row.dataset.seatVersion);
        data.append("isActive", toggle.checked ? "1" : "0");
        submitRequest(data).then((saved) => {
            if (!saved) toggle.checked = !toggle.checked;
        });
    });
    /**
     * ドラッグ終了時や候補変更時に移動先の強調表示を解除する。
     */
    const clearDropHighlight = () => {
        if (!highlightedRow) return;
        highlightedRow.style.outline = "";
        highlightedRow.style.outlineOffset = "";
        highlightedRow = null;
    };
    list.addEventListener("dragstart", (event) => {
        if (busy || !event.target.closest(".seat-drag-handle")) {
            event.preventDefault();
            return;
        }
        draggedRow = event.target.closest(".seat-row");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", draggedRow.dataset.seatId);
    });
    list.addEventListener("dragover", (event) => {
        const target = event.target.closest(".seat-row");
        if (draggedRow && target) event.preventDefault();
        if (!draggedRow || !target || target === draggedRow || busy) {
            clearDropHighlight();
            return;
        }
        if (highlightedRow === target) return;
        clearDropHighlight();
        highlightedRow = target;
        target.style.outline = "2px solid #335d95";
        target.style.outlineOffset = "-2px";
    });
    list.addEventListener("drop", (event) => {
        const target = event.target.closest(".seat-row");
        clearDropHighlight();
        if (!draggedRow || !target || busy) return;
        event.preventDefault();
        const before = Array.from(list.querySelectorAll(".seat-row"), (row) => row.dataset.seatId);
        const upper = event.clientY < target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
        list.insertBefore(draggedRow, upper ? target : target.nextSibling);
        draggedRow = null;
        const rows = Array.from(list.querySelectorAll(".seat-row"));
        if (rows.every((row, index) => row.dataset.seatId === before[index])) return;
        const data = createRequest("sort");
        data.append("orderVersion", state.dataset.orderVersion);
        rows.forEach((row) => data.append("seatOrder[]", row.dataset.seatId));
        submitRequest(data, "席の並び順を変更しました。");
    });
    list.addEventListener("dragend", () => {
        clearDropHighlight();
        draggedRow = null;
    });
});
