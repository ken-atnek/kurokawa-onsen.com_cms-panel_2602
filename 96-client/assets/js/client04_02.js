"use strict";

const reservationSettingsRequestUrl = "./assets/function/proc_client04_02.php";
const reservationSettingsWarningMessages = {
    guest_max_decrease_oversized_seat: "新しい最大予約人数を超える定員の席があります。\n席は自動変更されません。",
    guest_max_increase: "最大予約人数を引き上げます。\n必要に応じて席管理で定員を確認してください。",
    reservation_disabled: "予約受付を停止すると、Web・電話・その他の\n新規予約を受け付けられなくなります。",
};
const reservationSettingsPostCommitMessages = {
    menu_required_no_active_menu: "食事メニュー必須の設定ですが、現在利用可能なメニューがありません。\n食事メニュー管理を確認してください。",
};

let reservationSettingsSubmitting = false;
let reservationSettingsModalTimer = null;
function clearReservationSettingsModalTimer() {
    if (reservationSettingsModalTimer !== null) {
        clearTimeout(reservationSettingsModalTimer);
        reservationSettingsModalTimer = null;
    }
}

/**
 * 予約基本設定modalを表示
 *  固定textだけをtextContentへ設定し、確認結果をPromiseで返す
 *  keepOpenOnConfirmがtrueの場合、確認modalで「変更する」を押してもmodal rootは閉じずtrueを返す（連続transitionでのチラつき防止）
 *  confirmationRequiredがfalseの場合は2秒で自動closeする（手動closeも可）
 */
function showReservationSettingsModal(title, message, confirmationRequired = false, keepOpenOnConfirm = false) {
    const modal = document.getElementById("modalBlock");
    const titleElement = modal?.querySelector(".box-title p");
    const messageElement = modal?.querySelector(".box-details > p");
    const closeButtons = modal?.querySelectorAll("[data-settings-modal-close], [data-settings-modal-cancel]");
    const cancelButton = modal?.querySelector("[data-settings-modal-cancel]");
    const confirmButton = modal?.querySelector("[data-settings-modal-confirm]");
    if (!modal || !titleElement || !messageElement || !closeButtons || !cancelButton || !confirmButton) {
        return Promise.resolve(confirmationRequired ? window.confirm(message) : (window.alert(message), false));
    }
    clearReservationSettingsModalTimer();
    titleElement.textContent = title;
    messageElement.textContent = message;
    messageElement.style.whiteSpace = "pre-line";
    cancelButton.textContent = confirmationRequired ? "キャンセル" : "閉じる";
    confirmButton.hidden = !confirmationRequired;
    confirmButton.style.display = confirmationRequired ? "" : "none";
    closeButtons.forEach((button) => (button.disabled = false));
    confirmButton.disabled = false;
    modal.classList.add("is-active");
    return new Promise((resolve) => {
        let settled = false;
        const cleanup = () => {
            clearReservationSettingsModalTimer();
            closeButtons.forEach((button) => button.removeEventListener("click", cancel));
            confirmButton.removeEventListener("click", confirm);
        };
        const finish = (confirmed) => {
            if (settled) return;
            settled = true;
            cleanup();
            modal.classList.remove("is-active");
            resolve(confirmed);
        };
        const cancel = () => finish(false);
        const confirm = () => {
            if (settled) return;
            if (confirmationRequired && keepOpenOnConfirm) {
                settled = true;
                cleanup();
                closeButtons.forEach((button) => (button.disabled = true));
                confirmButton.disabled = true;
                resolve(true);
                return;
            }
            finish(true);
        };
        closeButtons.forEach((button) => button.addEventListener("click", cancel));
        confirmButton.addEventListener("click", confirm);
        if (!confirmationRequired) {
            reservationSettingsModalTimer = setTimeout(() => finish(false), 2000);
        }
    });
}
/**
 * 予約基本設定formを正式requestへ変換
 *  表示用radioではなくhidden authorityとchecked fieldだけを収集する
 */
function buildReservationSettingsFormData(form, confirmedWarnings = []) {
    const getCheckedValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value ?? "";
    const getValue = (name) => form.elements.namedItem(name)?.value ?? "";
    const formData = new FormData();
    formData.append("noUpDateKey", getValue("noUpDateKey"));
    formData.append("csrfToken", getValue("csrfToken"));
    formData.append("reservationEnabled", getCheckedValue("reservationEnabled"));
    formData.append("menuSelectionType", getCheckedValue("menuSelectionType"));
    formData.append("acceptStartDaysBefore", getValue("acceptStartDaysBefore"));
    formData.append("acceptEndDaysBefore", getValue("acceptEndDaysBefore"));
    formData.append("guestMin", getValue("guestMin"));
    formData.append("guestMax", getValue("guestMax"));
    form.querySelectorAll('input[name="closedWeekdays[]"]:checked').forEach((input) => {
        formData.append("closedWeekdays[]", input.value);
    });
    confirmedWarnings.forEach((warningCode) => {
        formData.append("confirmedWarnings[]", warningCode);
    });
    return formData;
}
/**
 * 保存対象の現在値を比較用に取得する。
 *  custom selectはhidden値、定休日は順序に依存しない集合として扱う。
 */
function captureReservationSettingsFormState(form) {
    const data = buildReservationSettingsFormData(form);
    return JSON.stringify([data.get("reservationEnabled"), data.get("menuSelectionType"), data.get("acceptStartDaysBefore"), data.get("acceptEndDaysBefore"), data.get("guestMin"), data.get("guestMax"), data.getAll("closedWeekdays[]").sort()]);
}
/**
 * 予約基本設定のclient-side値域検証
 *  server authorityを代替せず明白な入力矛盾だけを送信前に止める
 */
function validateReservationSettingsFormData(formData) {
    const reservationEnabled = formData.get("reservationEnabled");
    const menuSelectionType = formData.get("menuSelectionType");
    const acceptStart = formData.get("acceptStartDaysBefore");
    const acceptEnd = formData.get("acceptEndDaysBefore");
    const guestMin = formData.get("guestMin");
    const guestMax = formData.get("guestMax");
    if (!["0", "1"].includes(reservationEnabled)) return false;
    if (!["0", "1", "2"].includes(menuSelectionType)) return false;
    if (!["unlimited", "90", "60", "30"].includes(acceptStart)) return false;
    if (!["0", "1", "3", "7"].includes(acceptEnd)) return false;
    if (!["1", "2", "3", "4"].includes(guestMin)) return false;
    if (!["1", "2", "3", "4"].includes(guestMax)) return false;
    if (Number(guestMin) > Number(guestMax)) return false;
    if (acceptStart !== "unlimited" && Number(acceptStart) < Number(acceptEnd)) return false;
    return true;
}
/**
 * 予約基本設定responseを検証
 *  warning codeは既知の固定mappingに存在するものだけ許可する
 */
function normalizeReservationSettingsResponse(data) {
    if (!data || typeof data !== "object" || Array.isArray(data)) return null;
    if (!["success", "warning", "error"].includes(data.status)) return null;
    if (typeof data.title !== "string" || typeof data.msg !== "string") return null;
    if (typeof data.requiresConfirmation !== "boolean") return null;
    if (!Array.isArray(data.warningCodes) || !Array.isArray(data.postCommitWarnings)) return null;
    const warningCodes = data.warningCodes;
    const postCommitWarnings = data.postCommitWarnings;
    if (warningCodes.some((code, index) => typeof code !== "string" || !Object.hasOwn(reservationSettingsWarningMessages, code) || warningCodes.indexOf(code) !== index) || postCommitWarnings.some((code, index) => typeof code !== "string" || !Object.hasOwn(reservationSettingsPostCommitMessages, code) || postCommitWarnings.indexOf(code) !== index)) {
        return null;
    }
    return {
        status: data.status,
        title: data.title,
        msg: data.msg,
        requiresConfirmation: data.requiresConfirmation,
        warningCodes,
        postCommitWarnings,
    };
}
/**
 * 予約基本設定を保存
 *  server warning確認後は同じform stateへ確認済codeを付けて再送する
 */
async function submitReservationSettings(form, confirmedWarnings = []) {
    const formData = buildReservationSettingsFormData(form, confirmedWarnings);
    if (!validateReservationSettingsFormData(formData)) {
        await showReservationSettingsModal("入力エラー", "入力内容を確認してください。");
        reservationSettingsSubmitting = false;
        setReservationSettingsSubmitDisabled(form, false);
        return;
    }
    let response;
    try {
        response = await fetch(reservationSettingsRequestUrl, {
            method: "POST",
            body: formData,
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!response.ok) throw new Error("http_error");
        response = normalizeReservationSettingsResponse(await response.json());
        if (!response) throw new Error("invalid_response");
    } catch (error) {
        await showReservationSettingsModal("通信結果を確認できません", "保存された可能性があります。\n画面を再読み込みして最新状態を確認してください。");
        window.location.reload();
        return;
    }
    if (response.status === "warning" && response.requiresConfirmation) {
        const message = response.warningCodes.map((code) => reservationSettingsWarningMessages[code]).join("\n");
        const confirmed = await showReservationSettingsModal("確認", message, true, true);
        if (confirmed) {
            await submitReservationSettings(form, response.warningCodes);
            return;
        }
        reservationSettingsSubmitting = false;
        setReservationSettingsSubmitDisabled(form, false);
        return;
    }
    if (response.status === "error") {
        await showReservationSettingsModal(response.title || "保存エラー", response.msg || "保存できませんでした。");
        window.location.reload();
        return;
    }
    const postCommitMessage = response.postCommitWarnings.map((code) => reservationSettingsPostCommitMessages[code]).join("\n");
    const committedMessage = response.msg.replaceAll("<br>", "\n");
    const successMessage = postCommitMessage ? `${committedMessage}\n${postCommitMessage}` : committedMessage;
    await showReservationSettingsModal(response.title, successMessage);
    window.location.reload();
}
/**
 * 予約基本設定保存button状態更新
 *  保存処理中の多重submitを防ぐ
 */
function setReservationSettingsSubmitDisabled(form, disabled) {
    const saveButton = form.querySelector("#reservationSettingsSaveButton");
    if (saveButton) saveButton.disabled = disabled;
}
document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("reservationSettingsForm");
    const resetButton = document.getElementById("reservationSettingsResetButton");
    if (!form) return;
    if (resetButton && !resetButton.disabled) {
        const initialState = captureReservationSettingsFormState(form);
        form.addEventListener("change", () => {
            const dirty = captureReservationSettingsFormState(form) !== initialState;
            resetButton.hidden = !dirty;
            resetButton.style.display = dirty ? "" : "none";
        });
    }
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (reservationSettingsSubmitting) return;
        reservationSettingsSubmitting = true;
        setReservationSettingsSubmitDisabled(form, true);
        await submitReservationSettings(form);
    });
    resetButton?.addEventListener("click", () => {
        if (!reservationSettingsSubmitting) window.location.reload();
    });
});
