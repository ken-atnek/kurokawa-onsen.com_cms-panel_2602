"use strict";

/**
 * 予約詳細編集UI
 *  編集値だけを専用endpointへ送り、保存結果はfull reloadで再取得する
 */
(() => {
  const requestUrl = "./assets/function/proc_reservation_detail_save.php";
  const allowedRoutes = ["web", "tel", "other"];
  const maxPartySize = 4;
  let isReservationDetailSaving = false;
  let isReservationStatusUpdating = false;
  let statusDisabledByDetail = false;

  /**
   * Unicode空白だけの文字列かを判定する
   */
  function isBlank(value) {
    return /^[\s\p{Z}\uFEFF]*$/u.test(value);
  }

  /**
   * Unicode code point単位の文字数を返す
   */
  function stringLength(value) {
    return Array.from(value).length;
  }

  /**
   * 詳細編集DOMから指定selectorの値を取得する
   */
  function getValue(form, selector) {
    return form.querySelector(selector)?.value ?? "";
  }

  /**
   * 現在の人数を1～4の整数として取得する
   */
  function getPartySize(form) {
    const value = getValue(form, "[data-reservation-person-value]");
    return /^[1-4]$/.test(value) ? Number(value) : null;
  }

  /**
   * 人数に合わせて最大4枠のmenu表示だけを切り替える
   */
  function updateMenuSlotVisibility(form) {
    const partySize = getPartySize(form);
    form.querySelectorAll("[data-reservation-menu-slot]").forEach(slot => {
      const guestNo = Number(slot.dataset.reservationMenuSlot ?? "0");
      slot.hidden = partySize === null || guestNo < 1 || guestNo > partySize;
    });
  }

  /**
   * dirty判定用の編集値を固定key順で取得する
   */
  function getEditableState(form) {
    const partySize = getPartySize(form);
    const menuSelectionType = Number(form.dataset.menuSelectionType ?? "-1");
    const menuValues = [];
    if (menuSelectionType !== 0 && partySize !== null) {
      form.querySelectorAll("[data-reservation-menu-slot]").forEach(slot => {
        const guestNo = Number(slot.dataset.reservationMenuSlot ?? "0");
        if (guestNo >= 1 && guestNo <= partySize) {
          menuValues.push(getValue(slot, "[data-reservation-menu-value]"));
        }
      });
    }
    return {
      reservationRoute: form.querySelector('input[name="reservationRoute"]:checked')?.value ?? "",
      reservationPerson: partySize === null ? "" : String(partySize),
      customerName: getValue(form, '[name="customerName"]'),
      customerKana: getValue(form, '[name="customerKana"]'),
      customerTel: getValue(form, '[name="customerTel"]'),
      customerEmail: getValue(form, '[name="customerEmail"]'),
      reservationMenu: menuValues,
      accommodationName: getValue(form, '[name="accommodationName"]'),
      reservationNote: getValue(form, '[name="reservationNote"]'),
      shopMemo: getValue(form, '[name="shopMemo"]'),
    };
  }

  /**
   * status controlの操作可否をDetail Edit由来の範囲だけ切り替える
   */
  function setStatusControlDisabled(control, disabled) {
    if (!control) return;
    const head = control.querySelector(".selectbox__head");
    if (disabled) {
      if (statusDisabledByDetail === true) return;
      if (head?.disabled === true || Array.from(control.querySelectorAll('input[type="radio"]')).some(option => option.disabled)) {
        return;
      }
      statusDisabledByDetail = true;
      if (head) {
        head.disabled = true;
        head.setAttribute("aria-disabled", "true");
      }
      control.querySelectorAll('input[type="radio"]').forEach(option => {
        option.disabled = true;
      });
      return;
    }
    if (statusDisabledByDetail !== true || isReservationStatusUpdating === true || isReservationDetailSaving === true) return;
    if (head) {
      head.disabled = false;
      head.setAttribute("aria-disabled", "false");
    }
    control.querySelectorAll('input[type="radio"]').forEach(option => {
      option.disabled = false;
    });
    statusDisabledByDetail = false;
  }

  /**
   * Detail Edit入力と操作buttonの操作可否を切り替える
   */
  function setDetailControlsDisabled(form, disabled) {
    form.querySelectorAll("[data-reservation-detail-field]").forEach(field => {
      field.disabled = disabled;
    });
    form.querySelectorAll("[data-reservation-person-option], [data-reservation-menu-option]").forEach(option => {
      option.disabled = disabled;
    });
    form.querySelectorAll("[data-reservation-person-control] .selectbox__head, [data-reservation-menu-slot] .selectbox__head").forEach(head => {
      head.disabled = disabled;
      head.setAttribute("aria-disabled", String(disabled));
    });
    const saveButton = form.querySelector("[data-reservation-detail-save]");
    const backButton = form.querySelector("[data-reservation-detail-back]");
    if (saveButton) saveButton.disabled = disabled;
    if (backButton) backButton.disabled = disabled;
  }

  /**
   * client-side syntaxを検証し送信可能な編集値を返す
   */
  function getValidatedRequestValues(form) {
    const state = getEditableState(form);
    const noUpDateKey = getValue(form, "[data-reservation-detail-no-update-key]");
    const csrfToken = getValue(form, "[data-reservation-detail-csrf-token]");
    const reservationId = getValue(form, "[data-reservation-detail-reservation-id]");
    const detailEditVersion = getValue(form, "[data-reservation-detail-version]");
    const menuSelectionType = Number(form.dataset.menuSelectionType ?? "-1");
    if (
      noUpDateKey === "" ||
      !/^[0-9a-f]{64}$/.test(csrfToken) ||
      !/^[1-9][0-9]*$/.test(reservationId) ||
      !/^[0-9a-f]{64}$/.test(detailEditVersion) ||
      !allowedRoutes.includes(state.reservationRoute) ||
      !Number.isInteger(Number(state.reservationPerson)) ||
      Number(state.reservationPerson) < 1 ||
      Number(state.reservationPerson) > maxPartySize ||
      ![0, 1, 2].includes(menuSelectionType)
    ) {
      return null;
    }

    const requiredFields = [
      [state.customerName, 101],
      [state.customerKana, 101],
      [state.customerTel, 20],
    ];
    if (requiredFields.some(([value, maxLength]) => isBlank(value) || stringLength(value) > maxLength)) {
      return null;
    }
    if (state.customerEmail !== "" && !isBlank(state.customerEmail)) {
      if (stringLength(state.customerEmail) > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.customerEmail)) {
        return null;
      }
    }
    if (state.accommodationName !== "" && !isBlank(state.accommodationName) && stringLength(state.accommodationName) > 100) {
      return null;
    }
    if (menuSelectionType !== 0 && state.reservationMenu.length !== Number(state.reservationPerson)) {
      return null;
    }
    if (state.reservationMenu.some(menuId => menuId !== "" && !/^[1-9][0-9]*$/.test(menuId))) {
      return null;
    }

    return { noUpDateKey, csrfToken, reservationId, detailEditVersion, menuSelectionType, state };
  }

  /**
   * strict allow-listの手動FormDataを生成する
   */
  function buildRequestFormData(values) {
    const formData = new FormData();
    formData.append("noUpDateKey", values.noUpDateKey);
    formData.append("csrfToken", values.csrfToken);
    formData.append("reservationId", values.reservationId);
    formData.append("detailEditVersion", values.detailEditVersion);
    formData.append("reservationRoute", values.state.reservationRoute);
    formData.append("reservationPerson", values.state.reservationPerson);
    formData.append("customerName", values.state.customerName);
    formData.append("customerKana", values.state.customerKana);
    formData.append("customerTel", values.state.customerTel);
    formData.append("customerEmail", values.state.customerEmail);
    if (values.menuSelectionType !== 0) {
      values.state.reservationMenu.forEach(menuId => {
        formData.append("reservationMenu[]", menuId);
      });
    }
    formData.append("accommodationName", values.state.accommodationName);
    formData.append("reservationNote", values.state.reservationNote);
    formData.append("shopMemo", values.state.shopMemo);
    return formData;
  }

  /**
   * 安全なmessageを表示して最新状態へfull reloadする
   */
  function showMessageAndReload(message) {
    window.alert(message);
    window.location.reload();
  }

  /**
   * Detail Edit保存を一度だけ実行する
   */
  async function saveReservationDetail(form, statusControl) {
    if (isReservationDetailSaving === true || isReservationStatusUpdating === true) return;
    const values = getValidatedRequestValues(form);
    if (!values) {
      window.alert("入力内容を確認してください。");
      return;
    }

    isReservationDetailSaving = true;
    setDetailControlsDisabled(form, true);
    setStatusControlDisabled(statusControl, true);
    try {
      const response = await fetch(requestUrl, {
        method: "POST",
        body: buildRequestFormData(values),
      });
      if (!response.ok) throw new Error("Network response was not ok");
      const result = await response.json();
      if (!result || typeof result !== "object" || !["success", "error"].includes(result.status)) {
        throw new Error("Invalid response");
      }
      if (result.status === "success") {
        const message = typeof result.msg === "string" && result.msg !== ""
          ? result.msg
          : "予約情報を保存しました。";
        showMessageAndReload(message);
        return;
      }
      const message = typeof result.msg === "string" && result.msg !== ""
        ? result.msg
        : "予約情報を保存できませんでした。ページを再読み込みして状態をご確認ください。";
      showMessageAndReload(message);
    } catch (error) {
      console.error("予約詳細保存エラー:", error);
      showMessageAndReload("保存結果を確認できませんでした。ページを再読み込みして最新の状態をご確認ください。");
    }
  }

  /**
   * Detail Edit UIを初期化する
   */
  function initializeReservationDetailEdit() {
    const form = document.querySelector("[data-reservation-detail-edit-form]");
    if (!form) return;
    const statusControl = form.querySelector("[data-reservation-status-control]");
    const saveButton = form.querySelector("[data-reservation-detail-save]");
    if (!saveButton) return;

    updateMenuSlotVisibility(form);
    const initialState = JSON.stringify(getEditableState(form));

    /**
     * dirty状態を再計算しstatus操作可否へ反映する
     */
    function synchronizeDirtyState() {
      updateMenuSlotVisibility(form);
      const isDirty = JSON.stringify(getEditableState(form)) !== initialState;
      setStatusControlDisabled(statusControl, isDirty);
    }

    form.addEventListener("submit", event => {
      event.preventDefault();
    });
    form.addEventListener("input", event => {
      if (event.target.closest?.("[data-reservation-status-control]")) return;
      synchronizeDirtyState();
    });
    form.addEventListener("change", event => {
      if (event.target.closest?.("[data-reservation-status-option]")) {
        isReservationStatusUpdating = true;
        setDetailControlsDisabled(form, true);
        return;
      }
      synchronizeDirtyState();
    });
    saveButton.addEventListener("click", () => {
      saveReservationDetail(form, statusControl);
    });
  }

  document.addEventListener("DOMContentLoaded", initializeReservationDetailEdit);
})();
