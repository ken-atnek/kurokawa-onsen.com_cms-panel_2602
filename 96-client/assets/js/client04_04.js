/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client04_05_01.php";
const reservationReadURL = "./assets/function/proc_client04_04.php";
const reservationCalendarOverrideURL = "./assets/function/proc_client04_04_override.php";
const reservationSeatChangeURL = "./assets/function/proc_reservation_seat_change.php";
const reservationTempMoveURL = "./assets/function/proc_reservation_temp_move.php";

let isReservationSubmitting = false;
let isReservationCalendarOverrideSubmitting = false;
let isReservationMonthLoading = false;
let isReservationDateLoading = false;
let isReservationSeatChangeSubmitting = false;
let reservationSeatDataErrorNotified = false;
let allowReservationTempMoveUnload = false;
let reservationActionModalTimer = null;
let hasReservationReadError = false;
let reservationShopEligible = null;
let reservationShopUnavailableReason = null;
let reservationAcceptedDate = "";
let reservationAvailabilityStatus = "";

function clearReservationActionModalTimer() {
    if (reservationActionModalTimer !== null) {
        clearTimeout(reservationActionModalTimer);
        reservationActionModalTimer = null;
    }
}
let monthRequestSequence = 0;
let dateRequestSequence = 0;
let reservationSeatPreviewState = "idle";
let seatPreviewRequestSequence = 0;
let calendarActionRequestSequence = 0;

const reservationCalendarOverrideActions = new Set(["setStopped", "setShopHoliday", "setAvailable", "restoreRegularHoliday"]);
const reservationAvailabilityStatuses = new Set(["normal", "full", "holiday", "stopped"]);
const reservationShopUnavailableReasons = new Set(["shop_type", "shop_inactive", "settings_missing", "settings_invalid", "reservation_disabled"]);
const reservationSeatPreviewStates = new Set(["idle", "loading", "assignable", "unavailable", "technical_error"]);
const reservationSeatPreviewReasons = new Set(["assignable", "full", "regular_holiday", "shop_holiday", "acceptance_stopped", "unavailable"]);

/**
 * 予約日表示用の値を生成
 *  YYYY-MM-DDから画面表示用の日付文字列を生成する
 */
function formatReservationDateLabels(dateValue) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dateValue));
    if (!match) return null;
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(Date.UTC(year, month - 1, day));
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
        return null;
    }
    const weekDays = ["日", "月", "火", "水", "木", "金", "土"];
    const weekDay = weekDays[date.getUTCDay()];
    return {
        status: `${year}年${month}月${day}日・${weekDay}`,
        short: `${month}/${day}(${weekDay})`,
        form: `${year}年${month}月${day}日（${weekDay}）`,
    };
}
/**
 * 現在のJST日付を取得
 *  browser timezoneに依存せずAsia/TokyoのYYYY-MM-DDを返す
 */
function getCurrentReservationJstDate() {
    try {
        const dateParts = new Map(
            new Intl.DateTimeFormat("en-US", {
                timeZone: "Asia/Tokyo",
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
            })
                .formatToParts(new Date())
                .map((part) => [part.type, part.value]),
        );
        const dateValue = `${dateParts.get("year") || ""}-${dateParts.get("month") || ""}-${dateParts.get("day") || ""}`;
        return formatReservationDateLabels(dateValue) ? dateValue : "";
    } catch (error) {
        return "";
    }
}
/**
 * Unicode空白だけの値か確認
 *  入力値が空白文字だけで構成されているか判定する
 */
function isReservationBlankValue(value) {
    return /^[\s\p{Z}\uFEFF]*$/u.test(String(value));
}
/**
 * 対象月を正規化
 *  YYYY-MM形式と月範囲を検証して返す
 */
function normalizeReservationTargetMonth(value) {
    const match = /^(\d{4})-(\d{2})$/.exec(String(value));
    if (!match) return null;
    const month = Number(match[2]);
    if (month < 1 || month > 12) return null;
    return `${match[1]}-${String(month).padStart(2, "0")}`;
}
/**
 * 前後の対象月を計算
 *  timezoneに依存せず年月を数値計算する
 */
function getAdjacentReservationMonth(targetMonth, offset) {
    const normalizedMonth = normalizeReservationTargetMonth(targetMonth);
    if (!normalizedMonth || !Number.isInteger(offset)) return null;
    const [yearValue, monthValue] = normalizedMonth.split("-").map(Number);
    const monthIndex = yearValue * 12 + (monthValue - 1) + offset;
    const year = Math.floor(monthIndex / 12);
    const month = (((monthIndex % 12) + 12) % 12) + 1;
    if (year < 1000 || year > 9999) return null;
    return `${String(year).padStart(4, "0")}-${String(month).padStart(2, "0")}`;
}
/**
 * calendarの選択日を取得
 *  stable container内の選択cellが1件の場合だけ日付を返す
 */
function getReservationSelectedDate() {
    const calendarContainer = document.querySelector(".inner-calender");
    const selectedCells = calendarContainer ? calendarContainer.querySelectorAll(".list-days .is-selected[data-date]") : [];
    if (selectedCells.length !== 1) return "";
    const dateValue = selectedCells[0].dataset.date || "";
    return formatReservationDateLabels(dateValue) ? dateValue : "";
}
/**
 * PHP初期予約参照stateを復元
 *  初期metadataと選択cellを厳格に照合し、accepted stateとして利用可能か返す
 */
function initializeReservationReadState(calendarContainer) {
    const detailContainer = document.querySelector(".inner-details");
    if (!calendarContainer || !detailContainer || calendarContainer.dataset.initialReadReady !== "1") {
        return false;
    }
    const targetMonth = normalizeReservationTargetMonth(calendarContainer.dataset.targetMonth || "");
    const today = calendarContainer.dataset.today || "";
    const selectedDate = detailContainer.dataset.initialSelectedDate || "";
    const availabilityStatus = detailContainer.dataset.initialAvailabilityStatus || "";
    const shopEligibleValue = calendarContainer.dataset.initialShopEligible || "";
    const shopUnavailableReason = calendarContainer.dataset.initialShopUnavailableReason || "";
    const selectedCellDate = getReservationSelectedDate();
    const validToday = formatReservationDateLabels(today) !== null;
    const validSelectedDate = formatReservationDateLabels(selectedDate) !== null;
    const shopEligible = shopEligibleValue === "1" ? true : shopEligibleValue === "0" ? false : null;
    const validShopEligibility = shopEligible === true ? shopUnavailableReason === "" : shopEligible === false && reservationShopUnavailableReasons.has(shopUnavailableReason);
    if (!targetMonth || !validToday || !validSelectedDate || targetMonth !== today.slice(0, 7) || selectedDate !== today || selectedCellDate !== selectedDate || !reservationAvailabilityStatuses.has(availabilityStatus) || !validShopEligibility) {
        return false;
    }
    reservationShopEligible = shopEligible;
    reservationShopUnavailableReason = shopEligible ? null : shopUnavailableReason;
    reservationAcceptedDate = selectedDate;
    reservationAvailabilityStatus = availabilityStatus;
    hasReservationReadError = false;
    return true;
}
/**
 * 予約参照responseの共通shapeを確認
 *  success・error共通fieldの型とstatus値を検証する
 */
function isReservationReadBaseResponse(result) {
    return Boolean(result && typeof result === "object" && !Array.isArray(result) && typeof result.status === "string" && /^(success|error)$/.test(result.status) && typeof result.title === "string" && typeof result.msg === "string" && typeof result.tag === "string" && typeof result.noUpDateKey === "string");
}
/**
 * 店舗受付可否responseを確認
 *  booleanと許可済み不可理由の組み合わせを検証する
 */
function hasValidReservationShopEligibility(result) {
    if (typeof result.shop_eligible !== "boolean") return false;
    if (result.shop_eligible === true) return result.shop_unavailable_reason === null;
    return reservationShopUnavailableReasons.has(result.shop_unavailable_reason);
}
/**
 * 予約参照HTML fragmentを確認
 *  余分なnodeを含まない単一rootと期待selectorを検証する
 */
function isValidReservationHtmlFragment(html, expectedSelector) {
    if (typeof html !== "string" || isReservationBlankValue(html)) return false;
    const template = document.createElement("template");
    template.innerHTML = html.trim();
    const childNodes = Array.from(template.content.childNodes);
    const elementNodes = childNodes.filter((node) => node.nodeType === 1);
    const hasUnexpectedNode = childNodes.some((node) => {
        return node.nodeType !== 1 && !(node.nodeType === 3 && node.textContent.trim() === "");
    });
    return !hasUnexpectedNode && elementNodes.length === 1 && elementNodes[0].matches(expectedSelector);
}
/**
 * 予約参照HTMLを差し替え
 *  現在rootの存在とresponse fragmentを確認してouterHTMLを更新する
 */
function replaceReservationHtml(targetSelector, html) {
    const target = document.querySelector(targetSelector);
    if (!target || !isValidReservationHtmlFragment(html, targetSelector)) return false;
    target.outerHTML = html.trim();
    const replacement = document.querySelector(targetSelector);
    if (replacement && typeof window.initSelectBoxes === "function") {
        window.initSelectBoxes(replacement);
    }
    if (replacement && typeof window.initTooltips === "function") {
        window.initTooltips(replacement);
    }
    return true;
}
/**
 * 日次状態変更action表示をfail-closedにする
 *  pending responseをstaleにして専用root内のbuttonだけを消去する
 */
function invalidateReservationCalendarActions(form, clearActions = true) {
    calendarActionRequestSequence++;
    const actionContainer = document.querySelector(".reservation-calendar-actions");
    if (clearActions && actionContainer) {
        actionContainer.replaceChildren();
    }
    updateReservationCalendarOverrideButtonState(form);
}
/**
 * 日次状態変更action requestの最新性を確認
 *  sequence・選択日・form表示・write状態を厳格に照合する
 */
function isCurrentReservationCalendarActionRequest(requestSequence, requestedDate) {
    const reservationAddCard = document.getElementById("reservationAddCard");
    return requestSequence === calendarActionRequestSequence && getReservationSelectedDate() === requestedDate && reservationAddCard && reservationAddCard.hidden === true && isReservationCalendarOverrideSubmitting === false;
}
/**
 * 日次状態変更action buttonを同期
 *  最新の選択日参照結果と各通信状態が揃う場合だけ操作可能にする
 */
function updateReservationCalendarOverrideButtonState(form) {
    const reservationAddCard = document.getElementById("reservationAddCard");
    const calendarContainer = document.querySelector(".inner-calender");
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    const csrfTokenInput = form.querySelector('input[name="csrfToken"]');
    const selectedDate = getReservationSelectedDate();
    const initialToday = calendarContainer ? calendarContainer.dataset.today || "" : "";
    const currentJstToday = getCurrentReservationJstDate();
    const enabled = Boolean(
        reservationAddCard &&
        reservationAddCard.hidden &&
        reservationShopEligible === true &&
        noUpDateKeyInput &&
        !isReservationBlankValue(noUpDateKeyInput.value) &&
        csrfTokenInput &&
        !isReservationBlankValue(csrfTokenInput.value) &&
        formatReservationDateLabels(selectedDate) &&
        formatReservationDateLabels(initialToday) &&
        formatReservationDateLabels(currentJstToday) &&
        selectedDate >= initialToday &&
        selectedDate >= currentJstToday &&
        reservationAcceptedDate === selectedDate &&
        !isReservationMonthLoading &&
        !isReservationDateLoading &&
        !isReservationSubmitting &&
        !isReservationCalendarOverrideSubmitting &&
        !isReservationSeatChangeSubmitting &&
        !hasReservationReadError,
    );
    document.querySelectorAll(".reservation-calendar-actions [data-reservation-calendar-action]").forEach((button) => {
        button.disabled = !enabled;
        if (enabled) {
            button.removeAttribute("aria-disabled");
        } else {
            button.setAttribute("aria-disabled", "true");
        }
    });
}
/**
 * 日次状態変更write状態を同期
 *  calendar・予約追加・日次actionの操作状態をまとめて更新する
 */
function setReservationCalendarOverrideSubmitting(form, submitting) {
    isReservationCalendarOverrideSubmitting = submitting;
    updateReservationCalendarControlState(form);
    updateReservationButtonState(form);
    updateReservationCalendarOverrideButtonState(form);
}
/**
 * 画面instance keyを同期
 *  accepted responseの空でない値だけをhiddenへ反映する
 */
function syncReservationNoUpDateKey(form, noUpDateKey) {
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    if (!noUpDateKeyInput || typeof noUpDateKey !== "string" || isReservationBlankValue(noUpDateKey)) {
        return false;
    }
    noUpDateKeyInput.value = noUpDateKey;
    return true;
}
/**
 * 店舗受付不可の表示文言を取得
 *  内部reasonを既存画面向けの一般文言へ変換する
 */
function getReservationShopUnavailableMessage(reason) {
    if (reason === "settings_missing") {
        return "予約基本設定が登録されていません。";
    }
    if (reason === "settings_invalid") {
        return "予約基本設定が正しくありません。";
    }
    if (reason === "reservation_disabled") {
        return "現在、予約受付は停止中です。";
    }
    return "現在、予約の追加はできません。";
}
/**
 * 店舗受付不可messageを同期
 *  local不可messageを保持しながらAjax由来messageだけを更新する
 */
function updateReservationShopUnavailableMessage(form) {
    const contentsDetails = form.closest(".contents-details");
    if (!contentsDetails) return;
    const children = Array.from(contentsDetails.children);
    const dynamicMessage = children.find((element) => {
        return element.matches('.reservation-add-message[data-reservation-read-message="1"]');
    });
    const localMessage = children.find((element) => {
        return element.matches('.reservation-add-message[data-reservation-local-message="1"]');
    });
    if (form.dataset.reservationFormEnabled !== "1" || reservationShopEligible !== false) {
        if (dynamicMessage) dynamicMessage.remove();
        return;
    }
    const message = dynamicMessage || document.createElement("p");
    message.className = "reservation-add-message";
    message.dataset.reservationReadMessage = "1";
    message.textContent = getReservationShopUnavailableMessage(reservationShopUnavailableReason);
    if (!dynamicMessage && !localMessage) {
        const listCustomer = children.find((element) => element.matches(".list-customer"));
        contentsDetails.insertBefore(message, listCustomer || document.getElementById("reservationAddCard"));
    }
}
/**
 * calendar操作buttonの状態を同期
 *  form表示・通信・登録中は月移動を無効化する
 */
function updateReservationCalendarControlState(form) {
    const calendarContainer = document.querySelector(".inner-calender");
    const reservationAddCard = document.getElementById("reservationAddCard");
    if (!calendarContainer || !reservationAddCard) return;
    const disabled = isReservationMonthLoading || isReservationDateLoading || isReservationSubmitting || isReservationCalendarOverrideSubmitting || isReservationSeatChangeSubmitting || !reservationAddCard.hidden;
    calendarContainer.querySelectorAll(".btn-prev, .btn-next").forEach((button) => {
        button.disabled = disabled;
        button.classList.toggle("is-inactive", disabled);
        if (disabled) {
            button.setAttribute("aria-disabled", "true");
        } else {
            button.removeAttribute("aria-disabled");
        }
    });
    updateReservationCalendarOverrideButtonState(form);
}
/**
 * 選択日の詳細表示を切り替え
 *  既存status・listだけを表示または非表示にする
 */
function setReservationDateDetailVisibility(visible) {
    [".list-status", ".list-customer"].forEach((selector) => {
        const element = document.querySelector(selector);
        if (!element) return;
        element.hidden = !visible;
        element.style.display = visible ? "" : "none";
    });
}
/**
 * 月参照loadingを同期
 *  calendarのbusy状態と操作button・追加buttonを更新する
 */
function setReservationMonthLoading(form, loading) {
    isReservationMonthLoading = loading;
    const calendarContainer = document.querySelector(".inner-calender");
    if (calendarContainer) {
        calendarContainer.setAttribute("aria-busy", loading ? "true" : "false");
    }
    updateReservationCalendarControlState(form);
    updateReservationButtonState(form);
}
/**
 * 日参照loadingを同期
 *  詳細表示を維持したままdetailのbusy状態と操作buttonを更新する
 */
function setReservationDateLoading(form, loading) {
    isReservationDateLoading = loading;
    const detailContainer = document.querySelector(".inner-details");
    if (detailContainer) {
        detailContainer.setAttribute("aria-busy", loading ? "true" : "false");
    }
    updateReservationCalendarControlState(form);
    updateReservationButtonState(form);
}
/**
 * 選択日のaccepted stateを消去
 *  日付・availabilityとread error状態を初期化する
 */
function clearReservationAcceptedDateState() {
    reservationAcceptedDate = "";
    reservationAvailabilityStatus = "";
    hasReservationReadError = false;
}
/**
 * month requestの最新性を確認
 *  現在sequenceと一致するrequestだけを有効とする
 */
function isCurrentReservationMonthRequest(requestSequence) {
    return requestSequence === monthRequestSequence;
}
/**
 * date requestの最新性を確認
 *  sequenceと現在DOM選択日の両方が一致するrequestだけを有効とする
 */
function isCurrentReservationDateRequest(requestSequence, requestedDate) {
    return requestSequence === dateRequestSequence && getReservationSelectedDate() === requestedDate;
}
/**
 * 席preview requestの最新性を確認
 *  sequence・form表示・選択日・人数が現在値と一致する場合だけ有効とする
 */
function isCurrentReservationSeatPreviewRequest(form, requestSequence, requestedDate, requestedPartySize) {
    const reservationAddCard = document.getElementById("reservationAddCard");
    const reservationDateInput = form.querySelector('input[name="reservationDate"]');
    return requestSequence === seatPreviewRequestSequence && reservationAddCard && reservationAddCard.hidden === false && isReservationSubmitting === false && getReservationSelectedDate() === requestedDate && reservationDateInput && reservationDateInput.value === requestedDate && getReservationPersonValue(form) === requestedPartySize;
}
/**
 * 席preview表示stateを同期
 *  DB由来の席名をtextContentで表示して保存button状態を更新する
 */
function setReservationSeatPreviewState(form, state, seatNames = [], relocationRequired = false, reason = "") {
    const previewNote = form.querySelector(".reservation-seat-note");
    if (!previewNote || !reservationSeatPreviewStates.has(state)) return false;
    let message = "保存時に自動割当";
    if (state === "loading") {
        message = "割当予定席を確認しています...";
    } else if (state === "assignable") {
        message = `割当予定：${seatNames.join("、")}`;
        if (relocationRequired) {
            message += "。既存予約の席移動を伴う可能性があります。";
        }
    } else if (state === "unavailable") {
        const unavailableMessages = {
            full: "この人数では割当可能な席がありません。",
            regular_holiday: "この日は定休日です。",
            shop_holiday: "この日は店休日です。",
            acceptance_stopped: "この日は受付停止です。",
            unavailable: "現在、予約を受け付けできません。",
        };
        message = unavailableMessages[reason] || unavailableMessages.unavailable;
    } else if (state === "technical_error") {
        message = "席割当を確認できませんでした。保存時に再判定します。";
    }
    reservationSeatPreviewState = state;
    previewNote.textContent = message;
    updateReservationButtonState(form);
    return true;
}
/**
 * 席preview requestを無効化
 *  pending responseをstaleにし、必要時は表示をidleへ戻す
 */
function invalidateReservationSeatPreview(form, resetState = true) {
    seatPreviewRequestSequence++;
    if (resetState) {
        setReservationSeatPreviewState(form, "idle");
    }
}
/**
 * 席preview成功responseを検証
 *  internal IDを受け取らず業務結果fieldの型と組み合わせを確認する
 */
function isReservationSeatPreviewSuccessResponse(result, requestedDate, requestedPartySize) {
    if (!isReservationReadBaseResponse(result) || result.status !== "success" || result.selected_date !== requestedDate || result.party_size !== requestedPartySize || typeof result.assignable !== "boolean" || !Array.isArray(result.seat_names) || typeof result.relocation_required !== "boolean" || !reservationSeatPreviewReasons.has(result.allocation_reason)) {
        return false;
    }
    if (result.assignable) {
        return result.allocation_reason === "assignable" && result.seat_names.length >= 1 && result.seat_names.every((seatName) => typeof seatName === "string" && !isReservationBlankValue(seatName));
    }
    return result.allocation_reason !== "assignable" && result.seat_names.length === 0 && result.relocation_required === false;
}
/**
 * 選択日と人数の席previewを取得
 *  read-only actionを呼び出し最新requestだけを既存席表示へ反映する
 */
async function readReservationSeatPreview(form) {
    const requestedDate = getReservationSelectedDate();
    const requestedPartySize = getReservationPersonValue(form);
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    const requestSequence = ++seatPreviewRequestSequence;
    if (!requestedDate || requestedPartySize === null || !noUpDateKeyInput || isReservationBlankValue(noUpDateKeyInput.value) || form.dataset.reservationFormEnabled !== "1") {
        setReservationSeatPreviewState(form, "technical_error");
        return false;
    }
    const formData = new FormData();
    formData.append("action", "previewSeat");
    formData.append("selected_date", requestedDate);
    formData.append("party_size", String(requestedPartySize));
    formData.append("noUpDateKey", noUpDateKeyInput.value);
    setReservationSeatPreviewState(form, "loading");
    try {
        const response = await fetch(reservationReadURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (!isCurrentReservationSeatPreviewRequest(form, requestSequence, requestedDate, requestedPartySize)) {
            return false;
        }
        if (!isReservationReadBaseResponse(result)) {
            throw new Error("Invalid previewSeat response shape");
        }
        if (result.status === "error") {
            if (!syncReservationNoUpDateKey(form, result.noUpDateKey)) {
                throw new Error("Invalid previewSeat noUpDateKey");
            }
            setReservationSeatPreviewState(form, "technical_error");
            return false;
        }
        if (!isReservationSeatPreviewSuccessResponse(result, requestedDate, requestedPartySize) || !syncReservationNoUpDateKey(form, result.noUpDateKey)) {
            throw new Error("Invalid previewSeat success response");
        }
        if (result.assignable) {
            setReservationSeatPreviewState(form, "assignable", result.seat_names, result.relocation_required, result.allocation_reason);
        } else {
            setReservationSeatPreviewState(form, "unavailable", [], false, result.allocation_reason);
        }
        return true;
    } catch (error) {
        if (!isCurrentReservationSeatPreviewRequest(form, requestSequence, requestedDate, requestedPartySize)) {
            return false;
        }
        console.error("席preview取得エラー:", error);
        setReservationSeatPreviewState(form, "technical_error");
        return false;
    }
}
/**
 * 店舗受付可否を画面へ反映
 *  最新accepted responseを保持してmessageとbutton状態を更新する
 */
function applyReservationShopEligibility(form, eligible, reason) {
    reservationShopEligible = eligible;
    reservationShopUnavailableReason = reason;
    const reservationAddCard = document.getElementById("reservationAddCard");
    if (eligible === false && reservationAddCard && !reservationAddCard.hidden) {
        setReservationAddView(form, false);
    }
    updateReservationShopUnavailableMessage(form);
    updateReservationButtonState(form);
}
/**
 * 初期選択日をcalendarへ設定
 *  today cellが正確に1件ある場合だけ選択して予約追加hiddenへ同期する
 */
function selectInitialReservationDate(form, today) {
    const calendarContainer = document.querySelector(".inner-calender");
    if (!calendarContainer || !formatReservationDateLabels(today)) return "";
    const todayCells = Array.from(calendarContainer.querySelectorAll(".list-days [data-date]")).filter((cell) => (cell.dataset.date || "") === today);
    calendarContainer.querySelectorAll(".list-days .is-selected[data-date]").forEach((cell) => {
        cell.classList.remove("is-selected");
    });
    if (todayCells.length !== 1) return syncSelectedReservationDateValue(form);
    todayCells[0].classList.add("is-selected");
    return syncSelectedReservationDateValue(form);
}
/**
 * 月間予約情報を取得
 *  responseを検証してcalendarを差し替え、必要時は当日参照へ進む
 */
async function readReservationMonth(form, targetMonth, selectTodayAfterLoad = false, options = null) {
    const readOptions = options && typeof options === "object" && !Array.isArray(options) ? options : {};
    const selectedDateAfterLoad = formatReservationDateLabels(readOptions.selectedDateAfterLoad) ? String(readOptions.selectedDateAfterLoad) : "";
    const preserveDetail = readOptions.preserveDetail === true && selectedDateAfterLoad !== "";
    const suppressErrorUi = readOptions.suppressErrorUi === true;
    const requestedMonth = normalizeReservationTargetMonth(targetMonth);
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    if (!requestedMonth || !noUpDateKeyInput || isReservationBlankValue(noUpDateKeyInput.value)) {
        hasReservationReadError = true;
        setReservationMonthLoading(form, false);
        if (!suppressErrorUi) {
            showReservationResultModal("取得できません", "画面情報を確認できません。ページを再読み込みしてください。");
        }
        updateReservationButtonState(form);
        return false;
    }
    const requestSequence = ++monthRequestSequence;
    const formData = new FormData();
    formData.append("action", "readMonth");
    formData.append("target_month", requestedMonth);
    formData.append("noUpDateKey", noUpDateKeyInput.value);
    setReservationMonthLoading(form, true);
    try {
        const response = await fetch(reservationReadURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (!isCurrentReservationMonthRequest(requestSequence)) return false;
        if (!isReservationReadBaseResponse(result)) {
            throw new Error("Invalid readMonth response shape");
        }
        if (result.status === "error") {
            syncReservationNoUpDateKey(form, result.noUpDateKey);
            if (!suppressErrorUi) showReservationResultModal(result.title, result.msg);
            return false;
        }
        if (normalizeReservationTargetMonth(result.target_month) !== requestedMonth || isReservationBlankValue(result.noUpDateKey) || !hasValidReservationShopEligibility(result) || !isValidReservationHtmlFragment(result.tag, ".contents-calender")) {
            throw new Error("Invalid readMonth success response");
        }
        const calendarContainer = document.querySelector(".inner-calender");
        if (!calendarContainer || !replaceReservationHtml(".contents-calender", result.tag)) {
            throw new Error("Calendar replacement failed");
        }
        calendarContainer.dataset.targetMonth = result.target_month;
        dateRequestSequence += 1;
        clearReservationAcceptedDateState();
        setReservationDateLoading(form, false);
        if (!preserveDetail) setReservationDateDetailVisibility(false);
        calendarContainer.querySelectorAll(".list-days .is-selected[data-date]").forEach((cell) => {
            cell.classList.remove("is-selected");
        });
        if (selectedDateAfterLoad) {
            const selectedDateCells = Array.from(calendarContainer.querySelectorAll(".list-days [data-date]")).filter((cell) => (cell.dataset.date || "") === selectedDateAfterLoad);
            if (selectedDateCells.length !== 1) {
                throw new Error("Selected date restoration failed");
            }
            selectedDateCells[0].classList.add("is-selected");
        }
        syncSelectedReservationDate(form);
        syncReservationNoUpDateKey(form, result.noUpDateKey);
        applyReservationShopEligibility(form, result.shop_eligible, result.shop_unavailable_reason);
        if (selectTodayAfterLoad && !selectedDateAfterLoad) {
            const today = calendarContainer.dataset.today || "";
            const selectedDate = selectInitialReservationDate(form, today);
            if (selectedDate === today) {
                const dateRead = await readReservationDate(form);
                if (dateRead) await readReservationCalendarActions(form);
            }
        }
        return true;
    } catch (error) {
        if (!isCurrentReservationMonthRequest(requestSequence)) return false;
        console.error("予約カレンダー取得エラー:", error);
        if (!suppressErrorUi) {
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
        }
        return false;
    } finally {
        if (isCurrentReservationMonthRequest(requestSequence)) {
            setReservationMonthLoading(form, false);
        }
    }
}
/**
 * 選択日の予約情報を取得
 *  DOM選択日とresponseを照合してstatus・listを差し替える
 */
async function readReservationDate(form, options = null) {
    const readOptions = options && typeof options === "object" && !Array.isArray(options) ? options : {};
    const suppressErrorUi = readOptions.suppressErrorUi === true;
    const requestedDate = getReservationSelectedDate();
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    if (!requestedDate || !noUpDateKeyInput || isReservationBlankValue(noUpDateKeyInput.value)) {
        clearReservationAcceptedDateState();
        hasReservationReadError = true;
        setReservationDateDetailVisibility(false);
        syncSelectedReservationDateLabels();
        setReservationDateLoading(form, false);
        if (!suppressErrorUi) {
            showReservationResultModal("取得できません", "画面情報を確認できません。ページを再読み込みしてください。");
        }
        updateReservationButtonState(form);
        return false;
    }
    const requestSequence = ++dateRequestSequence;
    const formData = new FormData();
    formData.append("action", "readDate");
    formData.append("selected_date", requestedDate);
    formData.append("noUpDateKey", noUpDateKeyInput.value);
    clearReservationAcceptedDateState();
    setReservationDateLoading(form, true);
    try {
        const response = await fetch(reservationReadURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (!isCurrentReservationDateRequest(requestSequence, requestedDate)) return false;
        if (!isReservationReadBaseResponse(result)) {
            throw new Error("Invalid readDate response shape");
        }
        if (result.status === "error") {
            syncReservationNoUpDateKey(form, result.noUpDateKey);
            hasReservationReadError = true;
            setReservationDateDetailVisibility(false);
            syncSelectedReservationDateLabels();
            if (!suppressErrorUi) showReservationResultModal(result.title, result.msg);
            return false;
        }
        if (
            result.selected_date !== requestedDate ||
            result.selected_date !== getReservationSelectedDate() ||
            !reservationAvailabilityStatuses.has(result.availability_status) ||
            isReservationBlankValue(result.noUpDateKey) ||
            !hasValidReservationShopEligibility(result) ||
            typeof result.status_tag !== "string" ||
            !isValidReservationHtmlFragment(result.status_tag, ".list-status") ||
            !isValidReservationHtmlFragment(result.tag, ".list-customer")
        ) {
            throw new Error("Invalid readDate success response");
        }
        if (!document.querySelector(".list-status") || !document.querySelector(".list-customer")) {
            throw new Error("Date detail replacement target was not found");
        }
        const statusReplaced = replaceReservationHtml(".list-status", result.status_tag);
        const listReplaced = replaceReservationHtml(".list-customer", result.tag);
        if (!statusReplaced || !listReplaced) {
            throw new Error("Date detail replacement failed");
        }
        syncReservationTempMoveGuardState(true);
        reservationAcceptedDate = result.selected_date;
        reservationAvailabilityStatus = result.availability_status;
        hasReservationReadError = false;
        syncReservationNoUpDateKey(form, result.noUpDateKey);
        applyReservationShopEligibility(form, result.shop_eligible, result.shop_unavailable_reason);
        setReservationDateDetailVisibility(true);
        syncSelectedReservationDate(form);
        return true;
    } catch (error) {
        if (!isCurrentReservationDateRequest(requestSequence, requestedDate)) return false;
        clearReservationAcceptedDateState();
        hasReservationReadError = true;
        setReservationDateDetailVisibility(false);
        syncSelectedReservationDateLabels();
        console.error("選択日の予約情報取得エラー:", error);
        if (!suppressErrorUi) {
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
        }
        return false;
    } finally {
        if (requestSequence === dateRequestSequence) {
            setReservationDateLoading(form, false);
        }
    }
}
/**
 * 予約人数を取得
 *  hidden値とguest_min・guest_maxを検証して人数を返す
 */
function getReservationPersonValue(form) {
    const personInput = form.querySelector('input[name="reservationPerson"][data-selectbox-hidden]');
    const personValue = personInput ? personInput.value : "";
    const guestMinValue = form.dataset.guestMin || "";
    const guestMaxValue = form.dataset.guestMax || "";
    if (!/^[1-9]\d*$/.test(personValue) || !/^[1-9]\d*$/.test(guestMinValue) || !/^[1-9]\d*$/.test(guestMaxValue)) {
        return null;
    }
    const person = Number(personValue);
    const guestMin = Number(guestMinValue);
    const guestMax = Number(guestMaxValue);
    if (guestMin < 1 || guestMax > 4 || guestMin > guestMax || person < guestMin || person > guestMax) {
        return null;
    }
    return person;
}
/**
 * メニューslotを初期値へ戻す
 *  menu typeに合わせてradio・hidden・表示ラベルを初期化する
 */
function resetReservationMenuSlot(slot, menuSelectionType) {
    const valueInput = slot.querySelector("[data-menu-slot-value]");
    const radios = Array.from(slot.querySelectorAll('input[type="radio"]'));
    const selectBox = slot.querySelector("[data-selectbox]");
    const valueLabel = slot.querySelector("[data-selectbox-value]");
    const selectHead = slot.querySelector(".selectbox__head");
    if (valueInput) valueInput.value = "";
    radios.forEach((radio) => {
        radio.checked = menuSelectionType === 1 && radio.value === "";
    });
    if (valueLabel) {
        valueLabel.textContent = menuSelectionType === 1 ? "お席のみ" : "選択してください";
    }
    if (selectBox) {
        selectBox.classList.remove("is-open");
        selectBox.classList.toggle("is-selected", menuSelectionType === 1);
        selectBox.classList.toggle("is-empty", menuSelectionType !== 1);
    }
    if (selectHead) selectHead.setAttribute("aria-expanded", "false");
}
/**
 * 人数に合わせてメニューslotを同期
 *  対象人数外のslotを非表示・無効化して選択値を消去する
 */
function syncReservationMenuSlots(form) {
    const person = getReservationPersonValue(form);
    const menuSelectionTypeValue = form.dataset.menuSelectionType || "";
    const menuSelectionType = /^(0|1|2)$/.test(menuSelectionTypeValue) ? Number(menuSelectionTypeValue) : null;
    const formEnabled = form.dataset.reservationFormEnabled === "1";
    const slots = Array.from(form.querySelectorAll("[data-menu-slot]"));
    slots.forEach((slot) => {
        const slotNumberValue = slot.dataset.menuSlot || "";
        const slotNumber = /^[1-4]$/.test(slotNumberValue) ? Number(slotNumberValue) : null;
        const shouldShow = person !== null && slotNumber !== null && slotNumber <= person;
        const wasHidden = slot.hidden;
        if (!shouldShow || wasHidden) {
            resetReservationMenuSlot(slot, menuSelectionType);
        }
        slot.hidden = !shouldShow;
        slot.querySelectorAll("button, input, select, textarea").forEach((control) => {
            control.disabled = !formEnabled || !shouldShow;
        });
    });
    return person;
}
/**
 * 予約追加buttonと保存buttonの状態を同期
 *  local設定・店舗受付可否・選択日・参照結果・通信状態を集約する
 */
function updateReservationButtonState(form) {
    const addButton = document.getElementById("reservationAddButton");
    const submitButton = document.getElementById("reservationAddSubmitButton");
    const reservationAddCard = document.getElementById("reservationAddCard");
    const calendarContainer = document.querySelector(".inner-calender");
    const formEnabled = form.dataset.reservationFormEnabled === "1";
    const selectedDate = getReservationSelectedDate();
    const initialToday = calendarContainer ? calendarContainer.dataset.today || "" : "";
    const currentJstToday = getCurrentReservationJstDate();
    const validSelectedDate = formatReservationDateLabels(selectedDate) !== null;
    const validInitialToday = formatReservationDateLabels(initialToday) !== null;
    const validCurrentJstToday = formatReservationDateLabels(currentJstToday) !== null;
    const canUseForm =
        formEnabled &&
        reservationShopEligible === true &&
        validSelectedDate &&
        validInitialToday &&
        validCurrentJstToday &&
        selectedDate >= initialToday &&
        selectedDate >= currentJstToday &&
        reservationAcceptedDate === selectedDate &&
        reservationAvailabilityStatus === "normal" &&
        !isReservationMonthLoading &&
        !isReservationDateLoading &&
        !hasReservationReadError &&
        !isReservationSubmitting &&
        !isReservationCalendarOverrideSubmitting &&
        !isReservationSeatChangeSubmitting;
    const previewAllowsSubmit = reservationSeatPreviewState === "assignable" || reservationSeatPreviewState === "technical_error";
    const canSubmit = canUseForm && reservationAddCard && reservationAddCard.hidden === false && previewAllowsSubmit;
    [
        [addButton, canUseForm],
        [submitButton, canSubmit],
    ].forEach(([button, enabled]) => {
        if (!button) return;
        const disabled = !enabled;
        button.disabled = disabled;
        if (disabled) {
            button.setAttribute("aria-disabled", "true");
        } else {
            button.removeAttribute("aria-disabled");
        }
    });
    updateReservationCalendarOverrideButtonState(form);
}
/**
 * calendarの選択日を予約追加hiddenへ同期
 *  is-selectedのdata-dateを即時に予約登録値へ反映する
 */
function syncSelectedReservationDateValue(form) {
    const reservationDate = document.getElementById("reservationDate");
    const dateValue = getReservationSelectedDate();
    const labels = formatReservationDateLabels(dateValue);
    if (!reservationDate) return "";
    reservationDate.value = labels ? dateValue : "";
    updateReservationButtonState(form);
    return labels ? dateValue : "";
}
/**
 * 予約登録後の表示を再取得
 *  保存時の表示月と選択日を維持して月・日を順に更新する
 */
async function refreshReservationViewAfterRegistration(form, savedDisplayMonth, savedSelectedDate, savedNoUpDateKey) {
    const refreshResult = {
        success: false,
        monthRefreshed: false,
        dateRefreshed: false,
    };
    try {
        if (!normalizeReservationTargetMonth(savedDisplayMonth) || !formatReservationDateLabels(savedSelectedDate) || typeof savedNoUpDateKey !== "string" || isReservationBlankValue(savedNoUpDateKey) || !syncReservationNoUpDateKey(form, savedNoUpDateKey)) {
            throw new Error("Invalid reservation refresh state");
        }
        refreshResult.monthRefreshed = await readReservationMonth(form, savedDisplayMonth, false, {
            selectedDateAfterLoad: savedSelectedDate,
            preserveDetail: true,
            suppressErrorUi: true,
        });
        if (refreshResult.monthRefreshed && getReservationSelectedDate() === savedSelectedDate) {
            refreshResult.dateRefreshed = await readReservationDate(form, {
                suppressErrorUi: true,
            });
            refreshResult.success = refreshResult.dateRefreshed;
        }
    } catch (error) {
        console.error("予約登録後画面更新エラー:", error);
    }
    if (!refreshResult.success) {
        clearReservationAcceptedDateState();
        hasReservationReadError = true;
        if (!refreshResult.monthRefreshed) {
            reservationShopEligible = null;
            reservationShopUnavailableReason = null;
            updateReservationShopUnavailableMessage(form);
        }
        setReservationDateDetailVisibility(false);
        syncSelectedReservationDate(form);
        updateReservationButtonState(form);
    }
    return refreshResult;
}
/**
 * 日次状態変更後の表示を再取得
 *  保存時の表示月と選択日を維持して月・日・actionを順に更新する
 */
async function refreshReservationViewAfterCalendarOverride(form, savedDisplayMonth, savedSelectedDate, savedNoUpDateKey) {
    const refreshResult = {
        success: false,
        monthRefreshed: false,
        dateRefreshed: false,
        actionsRefreshed: false,
    };
    try {
        if (!normalizeReservationTargetMonth(savedDisplayMonth) || !formatReservationDateLabels(savedSelectedDate) || typeof savedNoUpDateKey !== "string" || isReservationBlankValue(savedNoUpDateKey) || !syncReservationNoUpDateKey(form, savedNoUpDateKey)) {
            throw new Error("Invalid calendar override refresh state");
        }
        refreshResult.monthRefreshed = await readReservationMonth(form, savedDisplayMonth, false, {
            selectedDateAfterLoad: savedSelectedDate,
            preserveDetail: true,
            suppressErrorUi: true,
        });
        if (refreshResult.monthRefreshed && getReservationSelectedDate() === savedSelectedDate) {
            refreshResult.dateRefreshed = await readReservationDate(form, {
                suppressErrorUi: true,
            });
        }
        if (refreshResult.dateRefreshed && reservationAcceptedDate === savedSelectedDate && getReservationSelectedDate() === savedSelectedDate) {
            refreshResult.actionsRefreshed = await readReservationCalendarActions(form, {
                suppressErrorUi: true,
            });
        }
        refreshResult.success = refreshResult.monthRefreshed && refreshResult.dateRefreshed && refreshResult.actionsRefreshed;
    } catch (error) {
        console.error("日次状態変更後画面更新エラー:", error);
    }
    if (!refreshResult.success) {
        invalidateReservationCalendarActions(form);
        clearReservationAcceptedDateState();
        hasReservationReadError = true;
        if (!refreshResult.monthRefreshed) {
            reservationShopEligible = null;
            reservationShopUnavailableReason = null;
            updateReservationShopUnavailableMessage(form);
        }
        setReservationDateDetailVisibility(false);
        syncSelectedReservationDate(form);
        updateReservationButtonState(form);
    }
    return refreshResult;
}
/**
 * 日次状態変更を専用endpointへ送信
 *  二重送信を防止しCOMMIT後はserver readで表示を再構築する
 */
async function sendReservationCalendarOverride(form, action) {
    const reservationAddCard = document.getElementById("reservationAddCard");
    const calendarContainer = document.querySelector(".inner-calender");
    const reservationDateInput = form.querySelector('input[name="reservationDate"]');
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    const csrfTokenInput = form.querySelector('input[name="csrfToken"]');
    const savedSelectedDate = getReservationSelectedDate();
    const savedDisplayMonth = calendarContainer ? normalizeReservationTargetMonth(calendarContainer.dataset.targetMonth || "") : "";
    const currentJstToday = getCurrentReservationJstDate();
    if (
        isReservationCalendarOverrideSubmitting ||
        isReservationSeatChangeSubmitting ||
        isReservationSubmitting ||
        isReservationMonthLoading ||
        isReservationDateLoading ||
        !reservationCalendarOverrideActions.has(action) ||
        !reservationAddCard ||
        reservationAddCard.hidden === false ||
        !savedDisplayMonth ||
        !formatReservationDateLabels(savedSelectedDate) ||
        !formatReservationDateLabels(currentJstToday) ||
        savedSelectedDate < currentJstToday ||
        reservationAcceptedDate !== savedSelectedDate ||
        hasReservationReadError ||
        !reservationDateInput ||
        reservationDateInput.value !== savedSelectedDate ||
        !noUpDateKeyInput ||
        isReservationBlankValue(noUpDateKeyInput.value) ||
        !csrfTokenInput ||
        isReservationBlankValue(csrfTokenInput.value)
    ) {
        updateReservationCalendarOverrideButtonState(form);
        return;
    }
    const savedNoUpDateKey = noUpDateKeyInput.value;
    const formData = new FormData();
    formData.append("action", action);
    formData.append("selected_date", savedSelectedDate);
    formData.append("noUpDateKey", savedNoUpDateKey);
    formData.append("csrfToken", csrfTokenInput.value);
    invalidateReservationCalendarActions(form, false);
    setReservationCalendarOverrideSubmitting(form, true);
    try {
        let result = null;
        try {
            const response = await fetch(reservationCalendarOverrideURL, {
                method: "POST",
                body: formData,
            });
            if (!response.ok) throw new Error("Network response was not ok");
            result = await response.json();
            if (!isReservationReadBaseResponse(result)) {
                throw new Error("Invalid calendar override response shape");
            }
        } catch (error) {
            console.error("日次状態変更送信エラー:", error);
            invalidateReservationCalendarActions(form);
            hasReservationReadError = true;
            updateReservationButtonState(form);
            showReservationResultModal("通信エラー", "通信結果を確認できませんでした。変更が保存されている可能性があります。画面を再読み込みしてください。");
            return;
        }
        if (result.status === "error") {
            if (result.selected_date === savedSelectedDate && !isReservationBlankValue(result.noUpDateKey)) {
                syncReservationNoUpDateKey(form, result.noUpDateKey);
            }
            invalidateReservationCalendarActions(form);
            hasReservationReadError = true;
            updateReservationButtonState(form);
            showReservationResultModal(result.title, result.msg);
            return;
        }
        if (result.selected_date !== savedSelectedDate || isReservationBlankValue(result.noUpDateKey) || !syncReservationNoUpDateKey(form, result.noUpDateKey)) {
            invalidateReservationCalendarActions(form);
            hasReservationReadError = true;
            updateReservationButtonState(form);
            showReservationResultModal("通信エラー", "通信結果を確認できませんでした。変更が保存されている可能性があります。画面を再読み込みしてください。" + (result.jsonSyncFailed === true ? " フロント表示用JSONの更新に失敗しました。" : ""));
            return;
        }
        setReservationCalendarOverrideSubmitting(form, false);
        const refreshResult = await refreshReservationViewAfterCalendarOverride(form, savedDisplayMonth, savedSelectedDate, result.noUpDateKey);
        if (refreshResult.success) {
            showReservationResultModal(result.title, result.msg);
        } else {
            showReservationResultModal("予約受付状態変更", "変更は保存されましたが、最新の予約状況を取得できませんでした。画面を再読み込みしてください。" + (result.jsonSyncFailed === true ? " フロント表示用JSONの更新に失敗しました。" : ""));
        }
    } finally {
        setReservationCalendarOverrideSubmitting(form, false);
    }
}
/**
 * calendarの選択日表示を同期
 *  acceptedまたはfailure確定後に見出し・status・予約追加formの表示を更新する
 */
function syncSelectedReservationDateLabels() {
    const statusLabel = document.getElementById("selectedReservationDateLabel");
    const shortLabel = document.getElementById("selectedReservationDateShortLabel");
    const formLabel = document.getElementById("reservationDateDisplay");
    const dateValue = getReservationSelectedDate();
    const labels = formatReservationDateLabels(dateValue);
    if (!labels) {
        if (statusLabel) statusLabel.textContent = "（未選択）";
        if (shortLabel) shortLabel.textContent = "未選択";
        if (formLabel) formLabel.textContent = "選択してください";
        return "";
    }
    if (statusLabel) statusLabel.textContent = `（${labels.status}）`;
    if (shortLabel) shortLabel.textContent = labels.short;
    if (formLabel) formLabel.textContent = labels.form;
    return dateValue;
}
/**
 * calendarの選択日を予約追加formへ同期
 *  hidden値と3つの日付表示を同じ選択日へまとめて反映する
 */
function syncSelectedReservationDate(form) {
    const dateValue = syncSelectedReservationDateValue(form);
    syncSelectedReservationDateLabels();
    return dateValue;
}
/**
 * 予約状況と予約追加formの表示を切り替える
 *  既存兄弟要素と予約追加カードの表示状態を切り替える
 */
function setReservationAddView(form, showForm) {
    const reservationAddCard = document.getElementById("reservationAddCard");
    const contentsDetails = form.closest(".contents-details");
    if (!reservationAddCard || !contentsDetails) return;
    const statusSelectors = [".list-status", ".box-btn", ".reservation-add-message", ".list-customer"];
    Array.from(contentsDetails.children).forEach((element) => {
        if (statusSelectors.some((selector) => element.matches(selector))) {
            const isDateDetail = element.matches(".list-status, .list-customer");
            const selectedDate = getReservationSelectedDate();
            const canShowDateDetail = !showForm && isReservationDateLoading === false && hasReservationReadError === false && reservationAcceptedDate !== "" && reservationAcceptedDate === selectedDate;
            const hidden = showForm || (isDateDetail && !canShowDateDetail);
            element.hidden = hidden;
            element.style.display = hidden ? "none" : "";
        }
    });
    reservationAddCard.hidden = !showForm;
    updateReservationCalendarControlState(form);
    updateReservationButtonState(form);
}
/**
 * 予約追加formを初期状態へ戻す
 *  入力・custom select・menu slotを戻して選択日を再同期する
 */
function resetReservationAddForm(form) {
    invalidateReservationSeatPreview(form);
    form.reset();
    const guestMinValue = form.dataset.guestMin || "";
    const personInput = form.querySelector('input[name="reservationPerson"][data-selectbox-hidden]');
    const personSelectBox = personInput ? personInput.closest("[data-selectbox]") : null;
    const personLabel = personSelectBox ? personSelectBox.querySelector("[data-selectbox-value]") : null;
    const personHead = personSelectBox ? personSelectBox.querySelector(".selectbox__head") : null;
    form.querySelectorAll('input[name="reservationPerson"][type="radio"]').forEach((radio) => {
        radio.checked = radio.value === guestMinValue;
    });
    if (personInput) personInput.value = guestMinValue;
    if (personLabel) personLabel.textContent = guestMinValue ? `${guestMinValue}名` : "選択してください";
    if (personSelectBox) {
        personSelectBox.classList.remove("is-open", "is-empty");
        personSelectBox.classList.toggle("is-selected", guestMinValue !== "");
    }
    if (personHead) personHead.setAttribute("aria-expanded", "false");
    const routeTel = form.querySelector('input[name="reservationRoute"][value="tel"]');
    const routeOther = form.querySelector('input[name="reservationRoute"][value="other"]');
    if (routeTel) routeTel.checked = true;
    if (routeOther) routeOther.checked = false;
    const menuSelectionTypeValue = form.dataset.menuSelectionType || "";
    const menuSelectionType = /^(0|1|2)$/.test(menuSelectionTypeValue) ? Number(menuSelectionTypeValue) : null;
    form.querySelectorAll("[data-menu-slot]").forEach((slot) => {
        resetReservationMenuSlot(slot, menuSelectionType);
    });
    syncReservationMenuSlots(form);
    syncSelectedReservationDate(form);
}
/**
 * 予約操作modalを表示
 *  通知と確認を既存modalで共通処理し、確認時だけtrueを返す
 *  keepOpenOnConfirmがtrueの場合、確認後もmodal rootは閉じずメッセージだけ切り替える
 *  confirmationRequiredがfalseの場合は2秒で自動closeする
 */
function showReservationActionModal(title, message, confirmationRequired = false, keepOpenOnConfirm = false) {
    const blockModal = document.getElementById("modalBlock");
    const titleElement = blockModal?.querySelector(".box-title p");
    const messageElement = blockModal?.querySelector(".box-details > p");
    const closeButtons = blockModal?.querySelectorAll("[data-reservation-modal-close], [data-reservation-modal-cancel]");
    const cancelButton = blockModal?.querySelector("[data-reservation-modal-cancel]");
    const confirmButton = blockModal?.querySelector("[data-reservation-modal-confirm]");
    if (!blockModal || !titleElement || !messageElement || !closeButtons || !cancelButton || !confirmButton) {
        return Promise.resolve(confirmationRequired ? window.confirm(message) : (window.alert(message || title), false));
    }

    clearReservationActionModalTimer();
    titleElement.textContent = title;
    messageElement.textContent = message;
    messageElement.style.whiteSpace = "pre-line";
    cancelButton.textContent = confirmationRequired ? "いいえ" : "閉じる";
    confirmButton.textContent = "はい";
    confirmButton.hidden = !confirmationRequired;
    confirmButton.style.display = confirmationRequired ? "" : "none";
    closeButtons.forEach((button) => (button.disabled = false));
    confirmButton.disabled = false;
    blockModal.classList.add("is-active");
    document.documentElement.style.overflow = "hidden";

    return new Promise((resolve) => {
        let settled = false;
        const cleanup = () => {
            clearReservationActionModalTimer();
            closeButtons.forEach((button) => button.removeEventListener("click", cancel));
            confirmButton.removeEventListener("click", confirm);
        };
        const finish = (confirmed) => {
            if (settled) return;
            settled = true;
            cleanup();
            blockModal.classList.remove("is-active");
            document.documentElement.style.overflow = "";
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
            reservationActionModalTimer = setTimeout(() => finish(false), 2000);
        }
    });
}

function showReservationResultModal(title, message) {
    return showReservationActionModal(title, message, false);
}
/**
 * 予約追加formの入力値を検証
 *  native項目・必須値・menu type別の送信条件を確認する
 */
function validateReservationAddForm(form) {
    if (form.dataset.reservationFormEnabled !== "1") return null;
    const reservationDate = syncSelectedReservationDate(form);
    const currentJstToday = getCurrentReservationJstDate();
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    const csrfTokenInput = form.querySelector('input[name="csrfToken"]');
    const routeInput = form.querySelector('input[name="reservationRoute"]:checked');
    const person = getReservationPersonValue(form);
    const menuSelectionTypeValue = form.dataset.menuSelectionType || "";
    if (!noUpDateKeyInput || isReservationBlankValue(noUpDateKeyInput.value) || !csrfTokenInput || isReservationBlankValue(csrfTokenInput.value) || !reservationDate || person === null || !routeInput || !/^(tel|other)$/.test(routeInput.value) || !/^(0|1|2)$/.test(menuSelectionTypeValue)) {
        showReservationResultModal("送信できません", "画面情報を確認できません。ページを再読み込みしてください。");
        return null;
    }
    if (formatReservationDateLabels(currentJstToday) === null || reservationDate < currentJstToday) {
        showReservationResultModal("入力内容を確認してください", "予約日を正しく入力してください。");
        return null;
    }
    if (!form.checkValidity()) {
        form.reportValidity();
        return null;
    }
    const requiredTextFields = [
        ["customerName", "お客様名"],
        ["customerKana", "ふりがな"],
        ["customerTel", "電話番号"],
    ];
    for (const [fieldName, fieldLabel] of requiredTextFields) {
        const input = form.querySelector(`[name="${fieldName}"]`);
        if (!input || isReservationBlankValue(input.value)) {
            showReservationResultModal("入力内容を確認してください", `${fieldLabel}を入力してください。`);
            if (input) input.focus();
            return null;
        }
    }
    const menuSelectionType = Number(menuSelectionTypeValue);
    const menuValues = [];
    if (menuSelectionType !== 0) {
        for (let slotNumber = 1; slotNumber <= person; slotNumber++) {
            const slot = form.querySelector(`[data-menu-slot="${slotNumber}"]`);
            const valueInput = slot ? slot.querySelector("[data-menu-slot-value]") : null;
            const menuValue = valueInput ? valueInput.value : "";
            const invalidMenu = menuValue !== "" && !/^[1-9]\d*$/.test(menuValue);
            const missingRequiredMenu = menuSelectionType === 2 && menuValue === "";
            if (!slot || slot.hidden || !valueInput || invalidMenu || missingRequiredMenu) {
                const message = missingRequiredMenu ? `利用者${slotNumber}のメニューを選択してください。` : `利用者${slotNumber}のメニューを選択し直してください。`;
                showReservationResultModal("入力内容を確認してください", message);
                const selectHead = slot ? slot.querySelector(".selectbox__head") : null;
                if (selectHead) selectHead.focus();
                return null;
            }
            menuValues.push(menuValue);
        }
    }
    return {
        noUpDateKey: noUpDateKeyInput.value,
        csrfToken: csrfTokenInput.value,
        reservationDate,
        reservationPerson: String(person),
        reservationRoute: routeInput.value,
        menuSelectionType,
        menuValues,
    };
}
/**
 * C3-C契約に合わせて送信dataを生成
 *  許可されたfieldのみをFormDataへ追加する
 */
function buildReservationRegistrationFormData(form, validatedData) {
    const formData = new FormData();
    formData.append("noUpDateKey", validatedData.noUpDateKey);
    formData.append("csrfToken", validatedData.csrfToken);
    formData.append("reservationDate", validatedData.reservationDate);
    formData.append("reservationPerson", validatedData.reservationPerson);
    formData.append("reservationRoute", validatedData.reservationRoute);
    ["customerName", "customerKana", "customerTel", "customerEmail"].forEach((fieldName) => {
        const input = form.querySelector(`[name="${fieldName}"]`);
        formData.append(fieldName, input ? input.value : "");
    });
    if (validatedData.menuSelectionType !== 0) {
        validatedData.menuValues.forEach((menuValue) => {
            formData.append("reservationMenu[]", menuValue);
        });
    }
    ["accommodationName", "reservationNote", "shopMemo"].forEach((fieldName) => {
        const input = form.querySelector(`[name="${fieldName}"]`);
        formData.append(fieldName, input ? input.value : "");
    });
    return formData;
}
/**
 * 予約登録をC3-Cへ送信
 *  二重送信を防止してresponseに応じた画面処理を行う
 */
async function sendReservationRegistration(form) {
    if (isReservationSubmitting) return;
    if (reservationSeatPreviewState !== "assignable" && reservationSeatPreviewState !== "technical_error") {
        updateReservationButtonState(form);
        return;
    }
    const validatedData = validateReservationAddForm(form);
    if (!validatedData) return;
    const calendarContainer = document.querySelector(".inner-calender");
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    const savedSelectedDate = getReservationSelectedDate();
    const savedDisplayMonth = calendarContainer ? normalizeReservationTargetMonth(calendarContainer.dataset.targetMonth || "") : "";
    const savedNoUpDateKey = noUpDateKeyInput ? noUpDateKeyInput.value : "";
    if (!savedSelectedDate || !savedDisplayMonth || isReservationBlankValue(savedNoUpDateKey) || validatedData.reservationDate !== savedSelectedDate || validatedData.noUpDateKey !== savedNoUpDateKey) {
        showReservationResultModal("送信できません", "画面情報を確認できません。ページを再読み込みしてください。");
        return;
    }
    invalidateReservationSeatPreview(form, false);
    isReservationSubmitting = true;
    updateReservationButtonState(form);
    try {
        let result = null;
        try {
            const formData = buildReservationRegistrationFormData(form, validatedData);
            const response = await fetch(requestURL, {
                method: "POST",
                body: formData,
            });
            if (!response.ok) throw new Error("Network response was not ok");
            result = await response.json();
            if (!result || typeof result !== "object" || Array.isArray(result) || typeof result.status !== "string" || !/^(success|error)$/.test(result.status) || typeof result.title !== "string" || typeof result.msg !== "string") {
                throw new Error("Invalid response shape");
            }
        } catch (error) {
            console.error("予約登録送信エラー:", error);
            setReservationSeatPreviewState(form, "technical_error");
            alert("通信エラーが発生しました。ページを再読み込みしてください。");
            return;
        }
        if (result.status === "error") {
            setReservationSeatPreviewState(form, "technical_error");
            showReservationResultModal(result.title, result.msg);
            return;
        }
        let refreshResult = { success: false };
        try {
            resetReservationAddForm(form);
            setReservationAddView(form, false);
            refreshResult = await refreshReservationViewAfterRegistration(form, savedDisplayMonth, savedSelectedDate, savedNoUpDateKey);
        } catch (error) {
            console.error("予約登録後画面更新エラー:", error);
        }
        let actionsRefreshed = false;
        if (refreshResult.success) {
            actionsRefreshed = await readReservationCalendarActions(form, {
                suppressErrorUi: true,
            });
        } else {
            invalidateReservationCalendarActions(form);
        }
        if (refreshResult.success && actionsRefreshed) {
            showReservationResultModal(result.title, result.msg);
        } else {
            showReservationResultModal("予約登録", "予約は登録されましたが、最新の予約状況を取得できませんでした。\n画面を再読み込みしてください。" + (result.jsonSyncFailed === true ? " フロント表示用JSONの更新に失敗しました。" : ""));
        }
    } finally {
        isReservationSubmitting = false;
        updateReservationButtonState(form);
        updateReservationCalendarControlState(form);
    }
}

function restoreReservationSeatSelection(seatForm) {
    const currentValue = seatForm.dataset.currentSeatValue || "";
    const currentLabel = seatForm.dataset.currentSeatLabel || "---";
    const selectBox = seatForm.querySelector("[data-selectbox]");
    const hiddenInput = seatForm.querySelector("[data-reservation-seat-value]");
    const valueLabel = seatForm.querySelector("[data-selectbox-value]");
    seatForm.querySelectorAll("[data-reservation-seat-option]").forEach((radio) => {
        radio.checked = radio.value === currentValue;
    });
    if (hiddenInput) hiddenInput.value = currentValue;
    if (valueLabel) valueLabel.textContent = currentLabel;
    if (selectBox) {
        selectBox.classList.add("is-selected");
        selectBox.classList.remove("is-empty", "is-open");
    }
    const selectHead = seatForm.querySelector(".selectbox__head");
    if (selectHead) selectHead.setAttribute("aria-expanded", "false");
}

function setReservationSeatChangeBusy(busy) {
    isReservationSeatChangeSubmitting = busy;
    document.querySelectorAll("[data-reservation-seat-change]").forEach((seatForm) => {
        seatForm.querySelectorAll("button, input").forEach((control) => {
            if (!Object.prototype.hasOwnProperty.call(control.dataset, "seatChangeInitiallyDisabled")) {
                control.dataset.seatChangeInitiallyDisabled = control.disabled ? "1" : "0";
            }
            control.disabled = busy || control.dataset.seatChangeInitiallyDisabled === "1";
        });
    });
    const pageForm = document.getElementById("reservationAddForm");
    if (pageForm) {
        updateReservationCalendarControlState(pageForm);
        updateReservationButtonState(pageForm);
        updateReservationCalendarOverrideButtonState(pageForm);
    }
}

/**
 * 仮席へ移動中の予約行を見た目で識別できるようにする
 */
function applyReservationTempMoveVisualState() {
    document.querySelectorAll('[data-reservation-seat-change][data-reservation-temp-move-active="1"]').forEach((seatForm) => {
        const selectHead = seatForm.querySelector(".selectbox__head");
        if (selectHead) {
            selectHead.style.borderColor = "#f39800";
            selectHead.style.boxShadow = "0 0 0 2px rgba(243, 152, 0, 0.25)";
            selectHead.style.backgroundColor = "#fff7e8";
        }

        const row = seatForm.closest("li");
        const statusElement = row?.querySelector("nav")?.previousElementSibling;
        if (statusElement && statusElement.textContent.trim() === "確定") {
            statusElement.textContent = "仮席へ移動中";
            statusElement.style.color = "#f0911e";
            statusElement.style.fontWeight = "700";
        }
    });
}

/**
 * 仮移動ガード状態を最新の予約一覧へ同期
 *  temp予約または回復可能なtemp重複があれば離脱・画面操作ガードを有効にする
 */
function syncReservationTempMoveGuardState(authoritativeRead = false) {
    applyReservationTempMoveVisualState();
    const activeInReservationList = Boolean(document.querySelector('[data-reservation-seat-change][data-reservation-temp-move-active="1"], [data-reservation-seat-change][data-reservation-duplicate-temp-recoverable="1"]'));
    const active = document.body.dataset.reservationTempMoveInvalid === "1" || activeInReservationList || (authoritativeRead === false && document.body.dataset.reservationTempMoveActive === "1");
    document.body.dataset.reservationTempMoveActive = active ? "1" : "0";
    const unrecoverableError = document.querySelector('[data-reservation-seat-data-error="1"]:not([data-reservation-duplicate-temp-recoverable="1"])');
    if (unrecoverableError && !reservationSeatDataErrorNotified) {
        reservationSeatDataErrorNotified = true;
        showReservationResultModal("席情報エラー", "割当席情報に異常がある予約があります。該当予約の席操作を停止しました。");
    }
    return active;
}

/**
 * serverガードによる誘導理由を表示
 *  モーダル表示後は再読込で再表示しないようqueryだけを除去する
 */
function showReservationTempMoveRedirectNotice() {
    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.get("seatMoveGuard") !== "1" || document.body.dataset.reservationTempMoveActive !== "1") return;
    showReservationResultModal("席移動", "席の移動中です。席を確定させてからページを移動して下さい");
    currentUrl.searchParams.delete("seatMoveGuard");
    window.history.replaceState(null, "", `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`);
}

/**
 * 仮移動中の画面操作を共通モーダルで停止
 *  logoutと対象予約の席セレクトだけを例外として扱う
 */
function blockReservationTempMoveOperation() {
    if (document.body.dataset.reservationTempMoveActive !== "1") return false;
    showReservationResultModal("席移動", "席の移動中です。席を確定させてからページを移動して下さい");
    return true;
}

function isReservationSeatChangeResponse(result) {
    return Boolean(result && typeof result === "object" && !Array.isArray(result) && /^(success|error)$/.test(result.status) && typeof result.title === "string" && typeof result.msg === "string" && typeof result.tag === "string" && typeof result.refreshRequired === "boolean");
}

async function refreshReservationSeatChangeView(form) {
    const calendarContainer = document.querySelector(".inner-calender");
    const savedDisplayMonth = calendarContainer ? normalizeReservationTargetMonth(calendarContainer.dataset.targetMonth || "") : "";
    const savedSelectedDate = getReservationSelectedDate();
    if (!savedDisplayMonth || !formatReservationDateLabels(savedSelectedDate)) {
        invalidateReservationCalendarActions(form);
        return false;
    }

    const monthRefreshed = await readReservationMonth(form, savedDisplayMonth, false, {
        selectedDateAfterLoad: savedSelectedDate,
        preserveDetail: true,
        suppressErrorUi: true,
    });
    if (!monthRefreshed || getReservationSelectedDate() !== savedSelectedDate) {
        invalidateReservationCalendarActions(form);
        return false;
    }

    const dateRefreshed = await readReservationDate(form, { suppressErrorUi: true });
    if (!dateRefreshed || reservationAcceptedDate !== savedSelectedDate) {
        invalidateReservationCalendarActions(form);
        return false;
    }
    return readReservationCalendarActions(form, { suppressErrorUi: true });
}

async function sendReservationSeatChange(pageForm, seatForm, selectedRadio) {
    if (isReservationSeatChangeSubmitting) {
        restoreReservationSeatSelection(seatForm);
        return;
    }

    const reservationId = seatForm.dataset.reservationId || "";
    const seatChangeVersion = seatForm.dataset.seatChangeVersion || "";
    const currentValue = seatForm.dataset.currentSeatValue || "";
    const currentLabel = seatForm.dataset.currentSeatLabel || "---";
    const targetValue = selectedRadio.value || "";
    const targetLabelElement = selectedRadio.closest("li")?.querySelector("label");
    const targetLabel = targetLabelElement?.textContent.trim() || "";
    const hasTempMove = seatForm.dataset.reservationTempMoveActive === "1";
    const duplicateTempRecoverable = seatForm.dataset.reservationDuplicateTempRecoverable === "1";
    const specialAction = targetValue === "__temp_start__" ? "start" : targetValue === "__temp_restore__" ? "restore" : targetValue === "__temp_restore_duplicate__" ? "restoreDuplicateTemp" : "";
    const noUpDateKeyInput = pageForm.querySelector('input[name="noUpDateKey"]');
    const csrfTokenInput = pageForm.querySelector('input[name="csrfToken"]');

    if (targetValue === currentValue) {
        restoreReservationSeatSelection(seatForm);
        return;
    }
    const requiresVersion = specialAction !== "restoreDuplicateTemp";
    const targetIsValid = specialAction !== "" || /^[1-9]\d*(?:,[1-9]\d*){0,3}$/.test(targetValue);
    if (!/^[1-9]\d*$/.test(reservationId) || (requiresVersion && !/^[0-9a-f]{64}$/.test(seatChangeVersion)) || !targetIsValid || !targetLabel || !noUpDateKeyInput || !csrfTokenInput) {
        restoreReservationSeatSelection(seatForm);
        showReservationResultModal("席変更", "画面情報を確認できません。ページを再読み込みしてください。");
        return;
    }

    setReservationSeatChangeBusy(true);
    let confirmed = false;
    try {
        const confirmationMessage = specialAction === "start" ? "仮の席へ移動しますか？" : specialAction === "restore" || specialAction === "restoreDuplicateTemp" ? "元の席に戻しますか？" : `割当席を変更しますか？\n\n変更前：${currentLabel}\n変更後：${targetLabel}`;
        confirmed = await showReservationActionModal("席移動", confirmationMessage, true, true);
    } catch (error) {
        console.error("席変更確認モーダルエラー:", error);
    }
    if (!confirmed) {
        setReservationSeatChangeBusy(false);
        restoreReservationSeatSelection(seatForm);
        return;
    }

    const formData = new FormData();
    formData.append("noUpDateKey", noUpDateKeyInput.value);
    formData.append("csrfToken", csrfTokenInput.value);
    formData.append("reservationId", reservationId);
    let requestUrl = reservationSeatChangeURL;
    if (specialAction || hasTempMove || duplicateTempRecoverable) {
        requestUrl = reservationTempMoveURL;
        const action = specialAction || "commit";
        formData.append("action", action);
        if (action !== "restoreDuplicateTemp") formData.append("seatChangeVersion", seatChangeVersion);
        if (action === "commit") formData.append("targetSeatIds", targetValue);
    } else {
        formData.append("seatChangeVersion", seatChangeVersion);
        formData.append("targetSeatIds", targetValue);
    }
    let result = null;
    let responseUncertain = false;
    try {
        const response = await fetch(requestUrl, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        result = await response.json();
        if (!isReservationSeatChangeResponse(result)) {
            throw new Error("Invalid seat change response shape");
        }
    } catch (error) {
        console.error("席変更送信エラー:", error);
        responseUncertain = true;
    }

    let refreshed = false;
    try {
        refreshed = await refreshReservationSeatChangeView(pageForm);
    } catch (error) {
        console.error("席変更後画面更新エラー:", error);
    } finally {
        setReservationSeatChangeBusy(false);
    }

    if (responseUncertain) {
        showReservationResultModal("通信エラー", "通信結果を確認できませんでした。\n変更が保存されている可能性があります。\nページを再読み込みしてください。");
        return;
    }
    if (!refreshed) {
        showReservationResultModal(result.status === "success" ? "席変更" : result.title, (result.status === "success" ? "割当席は変更されましたが、最新の予約状況を取得できませんでした。" : result.msg) + "\nページを再読み込みしてください。");
        return;
    }
    showReservationResultModal(result.title, result.msg);
}

/**
 * 予約追加formを初期化
 *  初期表示を同期してcalendar・formのeventを登録する
 */
function initializeReservationAddForm() {
    const form = document.getElementById("reservationAddForm");
    const reservationAddCard = document.getElementById("reservationAddCard");
    const addButton = document.getElementById("reservationAddButton");
    const cancelButton = document.getElementById("reservationAddCancelButton");
    const submitButton = document.getElementById("reservationAddSubmitButton");
    const calendarContainer = document.querySelector(".inner-calender");
    const contentsDetails = form ? form.closest(".contents-details") : null;
    const actionContainer = document.querySelector(".reservation-calendar-actions");
    if (!form || !reservationAddCard || !addButton || !cancelButton || !submitButton || !calendarContainer || !contentsDetails || !actionContainer) {
        return;
    }
    const initialReservationReadReady = initializeReservationReadState(calendarContainer);
    syncReservationTempMoveGuardState();
    showReservationTempMoveRedirectNotice();
    document.addEventListener(
        "click",
        (event) => {
            if (document.body.dataset.reservationTempMoveActive !== "1") return;
            const target = event.target && typeof event.target.closest === "function" ? event.target.closest("a[href]") : null;
            if (!target) return;
            if (target.matches('a.logout[href$="logout.php"]')) {
                allowReservationTempMoveUnload = true;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            blockReservationTempMoveOperation();
        },
        true,
    );
    window.addEventListener("beforeunload", (event) => {
        if (document.body.dataset.reservationTempMoveActive !== "1" || allowReservationTempMoveUnload) return;
        event.preventDefault();
        event.returnValue = "";
    });
    syncSelectedReservationDate(form);
    syncReservationMenuSlots(form);
    setReservationDateDetailVisibility(initialReservationReadReady);
    updateReservationShopUnavailableMessage(form);
    updateReservationButtonState(form);
    updateReservationCalendarOverrideButtonState(form);
    calendarContainer.addEventListener("click", async (event) => {
        if (!reservationAddCard.hidden || isReservationMonthLoading || isReservationDateLoading || isReservationSubmitting || isReservationCalendarOverrideSubmitting || isReservationSeatChangeSubmitting) {
            return;
        }
        const eventTarget = event.target && typeof event.target.closest === "function" ? event.target : null;
        if (!eventTarget) return;
        if (document.body.dataset.reservationTempMoveActive === "1") {
            blockReservationTempMoveOperation();
            return;
        }
        const monthButton = eventTarget.closest(".btn-prev, .btn-next");
        if (monthButton && calendarContainer.contains(monthButton)) {
            if (monthButton.disabled) return;
            const currentMonth = normalizeReservationTargetMonth(calendarContainer.dataset.targetMonth || "");
            const offset = monthButton.classList.contains("btn-prev") ? -1 : 1;
            const targetMonth = getAdjacentReservationMonth(currentMonth, offset);
            if (targetMonth) {
                invalidateReservationCalendarActions(form);
                readReservationMonth(form, targetMonth);
            }
            return;
        }
        const selectedCell = eventTarget.closest(".list-days [data-date]");
        if (!selectedCell || !calendarContainer.contains(selectedCell) || !formatReservationDateLabels(selectedCell.dataset.date || "")) {
            return;
        }
        calendarContainer.querySelectorAll(".list-days .is-selected[data-date]").forEach((cell) => {
            cell.classList.remove("is-selected");
        });
        selectedCell.classList.add("is-selected");
        syncSelectedReservationDateValue(form);
        invalidateReservationCalendarActions(form);
        const dateRead = await readReservationDate(form);
        if (dateRead) await readReservationCalendarActions(form);
    });
    contentsDetails.addEventListener("click", (event) => {
        const eventTarget = event.target && typeof event.target.closest === "function" ? event.target : null;
        const detailButton = eventTarget ? eventTarget.closest("[data-reservation-detail-url]") : null;
        if (detailButton && contentsDetails.contains(detailButton)) {
            if (blockReservationTempMoveOperation()) return;
            const detailUrl = detailButton.dataset.reservationDetailUrl || "";
            if (!isReservationSeatChangeSubmitting && /^\.\/client04_05_01\.php\?reservationId=[1-9]\d*$/.test(detailUrl)) {
                window.location.href = detailUrl;
            }
            return;
        }
        const actionButton = eventTarget ? eventTarget.closest("[data-reservation-calendar-action]") : null;
        if (actionButton && blockReservationTempMoveOperation()) return;
        if (!actionButton || !contentsDetails.contains(actionButton) || actionButton.disabled || isReservationCalendarOverrideSubmitting || isReservationSeatChangeSubmitting) {
            return;
        }
        sendReservationCalendarOverride(form, actionButton.dataset.reservationCalendarAction || "");
    });
    contentsDetails.addEventListener("change", (event) => {
        const selectedRadio = event.target;
        if (!(selectedRadio instanceof HTMLInputElement) || !selectedRadio.matches("[data-reservation-seat-option]")) {
            return;
        }
        const seatForm = selectedRadio.closest("[data-reservation-seat-change]");
        if (!seatForm || !contentsDetails.contains(seatForm)) {
            return;
        }
        if (document.body.dataset.reservationTempMoveActive === "1" && seatForm.dataset.reservationTempMoveActive !== "1" && seatForm.dataset.reservationDuplicateTempRecoverable !== "1") {
            restoreReservationSeatSelection(seatForm);
            blockReservationTempMoveOperation();
            return;
        }
        void sendReservationSeatChange(form, seatForm, selectedRadio);
    });
    addButton.addEventListener("click", () => {
        if (blockReservationTempMoveOperation()) return;
        const selectedDate = syncSelectedReservationDate(form);
        if (form.dataset.reservationFormEnabled !== "1" || addButton.disabled || !selectedDate) {
            return;
        }
        setReservationAddView(form, true);
        readReservationSeatPreview(form);
    });
    cancelButton.addEventListener("click", () => {
        resetReservationAddForm(form);
        setReservationAddView(form, false);
    });
    submitButton.addEventListener("click", () => {
        sendReservationRegistration(form);
    });
    form.addEventListener("change", (event) => {
        if (event.target.matches('input[name="reservationPerson"][type="radio"]')) {
            syncReservationMenuSlots(form);
            if (reservationAddCard.hidden === false) {
                readReservationSeatPreview(form);
            }
        }
    });
    if (initialReservationReadReady) {
        calendarContainer.setAttribute("aria-busy", "false");
        updateReservationCalendarControlState(form);
    } else {
        readReservationMonth(form, calendarContainer.dataset.targetMonth || "", true);
    }
}
/**
 * 選択日の日次状態変更actionを取得
 *  専用sequenceでstale responseを除外してaction fragmentだけを差し替える
 */
async function readReservationCalendarActions(form, options = null) {
    const readOptions = options && typeof options === "object" && !Array.isArray(options) ? options : {};
    const suppressErrorUi = readOptions.suppressErrorUi === true;
    const requestedDate = getReservationSelectedDate();
    const noUpDateKeyInput = form.querySelector('input[name="noUpDateKey"]');
    const csrfTokenInput = form.querySelector('input[name="csrfToken"]');
    const reservationAddCard = document.getElementById("reservationAddCard");
    const actionContainer = document.querySelector(".reservation-calendar-actions");
    const requestSequence = ++calendarActionRequestSequence;
    if (!requestedDate || !noUpDateKeyInput || isReservationBlankValue(noUpDateKeyInput.value) || !csrfTokenInput || isReservationBlankValue(csrfTokenInput.value) || !reservationAddCard || reservationAddCard.hidden === false || isReservationCalendarOverrideSubmitting || !actionContainer) {
        invalidateReservationCalendarActions(form);
        hasReservationReadError = true;
        updateReservationButtonState(form);
        return false;
    }
    const formData = new FormData();
    formData.append("action", "readActions");
    formData.append("selected_date", requestedDate);
    formData.append("noUpDateKey", noUpDateKeyInput.value);
    formData.append("csrfToken", csrfTokenInput.value);
    setReservationDateLoading(form, true);
    try {
        const response = await fetch(reservationCalendarOverrideURL, {
            method: "POST",
            body: formData,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const result = await response.json();
        if (!isCurrentReservationCalendarActionRequest(requestSequence, requestedDate)) {
            return false;
        }
        if (!isReservationReadBaseResponse(result)) {
            throw new Error("Invalid readActions response shape");
        }
        if (result.status === "error") {
            if (!syncReservationNoUpDateKey(form, result.noUpDateKey)) {
                throw new Error("Invalid readActions noUpDateKey");
            }
            const currentActionContainer = document.querySelector(".reservation-calendar-actions");
            if (currentActionContainer) currentActionContainer.replaceChildren();
            hasReservationReadError = true;
            updateReservationButtonState(form);
            if (!suppressErrorUi) showReservationResultModal(result.title, result.msg);
            return false;
        }
        if (
            result.selected_date !== requestedDate ||
            result.selected_date !== getReservationSelectedDate() ||
            isReservationBlankValue(result.noUpDateKey) ||
            !isValidReservationHtmlFragment(result.tag, ".reservation-calendar-actions") ||
            !replaceReservationHtml(".reservation-calendar-actions", result.tag) ||
            !syncReservationNoUpDateKey(form, result.noUpDateKey)
        ) {
            throw new Error("Invalid readActions success response");
        }
        hasReservationReadError = false;
        updateReservationButtonState(form);
        updateReservationCalendarOverrideButtonState(form);
        return true;
    } catch (error) {
        if (!isCurrentReservationCalendarActionRequest(requestSequence, requestedDate)) {
            return false;
        }
        console.error("日次操作取得エラー:", error);
        const currentActionContainer = document.querySelector(".reservation-calendar-actions");
        if (currentActionContainer) currentActionContainer.replaceChildren();
        hasReservationReadError = true;
        updateReservationButtonState(form);
        if (!suppressErrorUi) {
            showReservationResultModal("取得できません", "日次操作を確認できませんでした。画面を再読み込みしてください。");
        }
        return false;
    } finally {
        if (requestSequence === calendarActionRequestSequence) {
            setReservationDateLoading(form, false);
        }
    }
}

document.addEventListener("DOMContentLoaded", initializeReservationAddForm);
