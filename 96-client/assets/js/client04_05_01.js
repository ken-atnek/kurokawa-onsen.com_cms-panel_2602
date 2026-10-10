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
   * フロントと同じ日本語名順で「その他の国・地域」を並べる
   */
  function sortOtherNationalityOptions(form) {
    const select = form.querySelector('[name="customerNationalityCode"]');
    const group = select?.querySelector("[data-nationality-other-options]");
    if (!group) return;
    const selectedCode = select.value;
    Array.from(group.querySelectorAll("option"))
      .sort((a, b) => a.textContent.localeCompare(b.textContent, "ja"))
      .forEach(option => group.appendChild(option));
    select.value = selectedCode;
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
      customerNationalityCode: getValue(form, '[name="customerNationalityCode"]'),
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
    if (state.customerNationalityCode !== "" && !/^[A-Z]{2}$/.test(state.customerNationalityCode)) {
      return null;
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
    formData.append("customerNationalityCode", values.state.customerNationalityCode);
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
   * 保存結果をモーダルで表示して最新状態へfull reloadする
   *  閉じる操作まで結果を表示し、DBの最新状態は再読込で取得する
   */
  function showMessageAndReload(title, message) {
    const modal = document.querySelector("[data-reservation-detail-result-modal]");
    const titleElement = modal?.querySelector("#reservationDetailResultTitle");
    const messageElement = modal?.querySelector("#reservationDetailResultMessage");
    const closeButtons = modal?.querySelectorAll("[data-reservation-detail-result-close]");
    if (!modal || !titleElement || !messageElement || !closeButtons?.length) {
      window.alert(message);
      window.location.reload();
      return;
    }

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.setAttribute("aria-hidden", "false");
    modal.classList.add("is-active");
    closeButtons[0].focus();

    /**
     * モーダルを閉じて保存後の最新状態を読み込む
     */
    function closeAndReload() {
      document.removeEventListener("keydown", onKeyDown);
      modal.classList.remove("is-active");
      modal.setAttribute("aria-hidden", "true");
      window.location.reload();
    }

    /**
     * Escapeで閉じ、Tab移動を結果モーダル内に留める
     */
    function onKeyDown(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        closeAndReload();
        return;
      }
      if (event.key !== "Tab") return;

      const firstButton = closeButtons[0];
      const lastButton = closeButtons[closeButtons.length - 1];
      if (!modal.contains(document.activeElement)) {
        event.preventDefault();
        firstButton.focus();
      } else if (event.shiftKey && document.activeElement === firstButton) {
        event.preventDefault();
        lastButton.focus();
      } else if (!event.shiftKey && document.activeElement === lastButton) {
        event.preventDefault();
        firstButton.focus();
      }
    }

    closeButtons.forEach(button => button.addEventListener("click", closeAndReload, { once: true }));
    document.addEventListener("keydown", onKeyDown);
  }

  /**
   * 登録済み国籍を未設定へ戻す前に確認する
   */
  function confirmNationalityClear() {
    const modal = document.querySelector("[data-nationality-clear-modal]");
    if (!modal) return Promise.resolve(false);
    const confirmButton = modal.querySelector("[data-nationality-clear-confirm]");
    const cancelButtons = modal.querySelectorAll("[data-nationality-clear-cancel]");
    const previousFocus = document.activeElement;
    modal.classList.add("is-active");
    confirmButton?.focus();
    return new Promise(resolve => {
      function finish(confirmed) {
        modal.classList.remove("is-active");
        confirmButton?.removeEventListener("click", onConfirm);
        cancelButtons.forEach(button => button.removeEventListener("click", onCancel));
        document.removeEventListener("keydown", onKeyDown);
        previousFocus?.focus();
        resolve(confirmed);
      }
      function onConfirm() { finish(true); }
      function onCancel() { finish(false); }
      function onKeyDown(event) {
        if (event.key === "Escape") finish(false);
      }
      confirmButton?.addEventListener("click", onConfirm);
      cancelButtons.forEach(button => button.addEventListener("click", onCancel));
      document.addEventListener("keydown", onKeyDown);
    });
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

    const initialNationalityCode = form.dataset.initialNationalityCode ?? "";
    if (initialNationalityCode !== "" && values.state.customerNationalityCode === "") {
      if (await confirmNationalityClear() !== true) return;
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
        const title = typeof result.title === "string" && result.title !== ""
          ? result.title
          : "予約詳細保存";
        const message = typeof result.msg === "string" && result.msg !== ""
          ? result.msg
          : "予約情報を保存しました。";
        showMessageAndReload(title, message);
        return;
      }
      const title = typeof result.title === "string" && result.title !== ""
        ? result.title
        : "保存エラー";
      const message = typeof result.msg === "string" && result.msg !== ""
        ? result.msg
        : "予約情報を保存できませんでした。ページを再読み込みして状態をご確認ください。";
      showMessageAndReload(title, message);
    } catch (error) {
      console.error("予約詳細保存エラー:", error);
      showMessageAndReload("保存結果を確認できません", "保存結果を確認できませんでした。ページを再読み込みして最新の状態をご確認ください。");
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
    form.dataset.initialNationalityCode = getValue(form, '[name="customerNationalityCode"]');

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

  document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("reservationDetailEditForm");
    if (form) sortOtherNationalityOptions(form);
    initializeReservationDetailEdit();
  });
})();
