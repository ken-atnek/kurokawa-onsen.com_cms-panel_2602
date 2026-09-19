"use strict";

/**
 * 予約ステータス変更UI
 *  semantic statusだけを専用endpointへ送信し結果をfull reloadで再取得する
 */
(() => {
  const requestUrl = "./assets/function/proc_reservation_status_change.php";
  const allowedStatuses = ["confirmed", "visited", "canceled", "noShow"];
  let isReservationStatusUpdating = false;

  /**
   * status control操作可否切替
   *  通信中またはDOM不整合時の追加操作を防ぐ
   */
  function setReservationStatusControlDisabled(control, disabled) {
    const head = control.querySelector(".selectbox__head");
    if (head) {
      head.disabled = disabled;
      head.setAttribute("aria-disabled", String(disabled));
    }
    control.querySelectorAll('input[type="radio"]').forEach(option => {
      option.disabled = disabled;
    });
  }

  /**
   * message表示後再読込
   *  保存成否をlocal表示で断定せずDBの最新状態を取得する
   */
  function showReservationStatusMessageAndReload(message) {
    window.alert(message);
    window.location.reload();
  }

  /**
   * status変更用DOM検証
   *  4fieldを安全に組み立てられる場合だけ送信値を返す
   */
  function getReservationStatusRequestValues(control, targetStatus) {
    const noUpDateKey = document.querySelector("[data-reservation-status-no-update-key]")?.value ?? "";
    const csrfToken = document.querySelector("[data-reservation-status-csrf-token]")?.value ?? "";
    const reservationId = document.querySelector("[data-reservation-status-reservation-id]")?.value ?? "";
    const currentStatus = control.dataset.currentStatus ?? "";
    if (
      noUpDateKey === "" ||
      !/^[0-9a-f]{64}$/.test(csrfToken) ||
      !/^[1-9][0-9]*$/.test(reservationId) ||
      !allowedStatuses.includes(currentStatus) ||
      !allowedStatuses.includes(targetStatus)
    ) {
      return null;
    }
    return { noUpDateKey, csrfToken, reservationId, currentStatus, targetStatus };
  }

  /**
   * 予約ステータス変更送信
   *  許可4fieldだけを手動appendし全結果をfull reloadへ収束させる
   */
  async function updateReservationStatus(control, targetStatus) {
    if (isReservationStatusUpdating) return;

    const values = getReservationStatusRequestValues(control, targetStatus);
    if (!values) {
      setReservationStatusControlDisabled(control, true);
      showReservationStatusMessageAndReload(
        "予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。"
      );
      return;
    }
    if (values.currentStatus === values.targetStatus) return;

    isReservationStatusUpdating = true;
    setReservationStatusControlDisabled(control, true);
    const formData = new FormData();
    formData.append("noUpDateKey", values.noUpDateKey);
    formData.append("csrfToken", values.csrfToken);
    formData.append("reservationId", values.reservationId);
    formData.append("reservationStatus", values.targetStatus);

    try {
      const response = await fetch(requestUrl, {
        method: "POST",
        body: formData,
      });
      if (!response.ok) throw new Error("Network response was not ok");

      const result = await response.json();
      if (!result || typeof result !== "object" || !["success", "error"].includes(result.status)) {
        throw new Error("Invalid response");
      }
      if (result.status === "error") {
        const message = typeof result.msg === "string" && result.msg !== ""
          ? result.msg
          : "予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。";
        showReservationStatusMessageAndReload(message);
        return;
      }
      if (result.jsonSyncFailed === true) {
        showReservationStatusMessageAndReload(result.msg);
        return;
      }
      window.location.reload();
    } catch (error) {
      console.error("予約ステータス送信エラー:", error);
      showReservationStatusMessageAndReload("通信結果を確認できませんでした。ページを再読み込みして最新の状態をご確認ください。");
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const control = document.querySelector("[data-reservation-status-control]");
    if (!control) return;

    const currentStatus = control.dataset.currentStatus ?? "";
    if (!allowedStatuses.includes(currentStatus)) {
      setReservationStatusControlDisabled(control, true);
      return;
    }

    control.addEventListener("change", event => {
      const option = event.target.closest?.("[data-reservation-status-option]");
      if (!option || option.checked !== true) return;
      updateReservationStatus(control, option.value);
    });
  });
})();
