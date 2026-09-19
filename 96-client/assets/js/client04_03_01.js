/** 食事メニューの追加・編集フォームと単一画像draftを接続する。 */
document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("foodMenuForm");
    if (!form) return;

    const endpoint = "./assets/function/proc_client04_03_01.php";
    const preview = document.getElementById("foodMenuImagePreview");
    const imageError = document.getElementById("foodMenuImageError");
    const saveButton = document.getElementById("foodMenuSave");
    const modal = document.getElementById("foodMenuModal");
    const modalMessage = modal.querySelector("[data-menu-modal-message]");
    const modalClose = modal.querySelector("[data-menu-modal-close]");
    const modalOk = modal.querySelector("[data-menu-modal-ok]");
    const modalConfirm = modal.querySelector("[data-menu-modal-confirm]");
    const existingUrl = preview.querySelector("img")?.getAttribute("src") || null;
    const existingName = preview.querySelector("li")?.dataset.name || "";
    let existingRemoved = false;
    let draftUrl = null;
    let draftName = null;
    let saving = false;
    let uploading = false;
    let uncertainImageState = false;
    let removalConfirmationPending = false;
    let modalTimer = null;
    /** 通知と画像削除確認を同じmodalで安全に表示する。通知（confirmationRequired=false）は2秒で自動closeする。 */
    const showModal = (message, confirmationRequired = false) =>
        new Promise((resolve) => {
            if (modalTimer !== null) {
                clearTimeout(modalTimer);
                modalTimer = null;
            }
            modalMessage.textContent = message;
            modalOk.textContent = confirmationRequired ? "キャンセル" : "閉じる";
            modalConfirm.hidden = !confirmationRequired;
            modalConfirm.style.display = confirmationRequired ? "" : "none";
            modal.classList.add("is-active");
            modal.setAttribute("aria-hidden", "false");
            let settled = false;
            const finish = (confirmed) => {
                if (settled) return;
                settled = true;
                if (modalTimer !== null) {
                    clearTimeout(modalTimer);
                    modalTimer = null;
                }
                modal.classList.remove("is-active");
                if (modal.contains(document.activeElement)) document.activeElement.blur();
                modal.setAttribute("aria-hidden", "true");
                modalClose.removeEventListener("click", cancel);
                modalOk.removeEventListener("click", cancel);
                modalConfirm.removeEventListener("click", confirm);
                resolve(confirmed);
            };
            const cancel = () => finish(false);
            const confirm = () => finish(true);
            modalClose.addEventListener("click", cancel);
            modalOk.addEventListener("click", cancel);
            if (confirmationRequired) modalConfirm.addEventListener("click", confirm);
            else modalTimer = setTimeout(() => finish(false), 2000);
            modalOk.focus();
        });
    /** 画像状態から確定保存modeを決める。 */
    const imageMode = () => (draftUrl ? "replace" : existingUrl && !existingRemoved ? "keep" : "remove");
    /** 既存HTMLの単一画像preview構造を再描画する。 */
    const renderImage = () => {
        preview.replaceChildren();
        const url = draftUrl || (!existingRemoved ? existingUrl : null);
        if (!url) return;
        const li = document.createElement("li");
        li.dataset.imageKind = draftUrl ? "draft" : "existing";
        li.dataset.name = draftUrl ? draftName : existingName;
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "food-menu-image-remove";
        remove.setAttribute("aria-label", "画像を削除");
        const picture = document.createElement("picture");
        const img = document.createElement("img");
        img.src = url;
        img.alt = "メニュー画像";
        picture.append(img);
        li.append(remove, picture);
        preview.append(li);
    };
    /** action共通fieldだけからFormDataを作る。 */
    const makeRequest = (action) => {
        const data = new FormData();
        data.append("noUpDateKey", form.elements.noUpDateKey.value);
        data.append("csrfToken", form.elements.csrfToken.value);
        data.append("action", action);
        return data;
    };
    const controller = initDropZone({
        foodMenu: true,
        dropZone: "#foodMenuDropZone",
        selectFileButton: "#foodMenuSelectImage",
        fileInput: "#foodMenuImageFile",
        endpoint,
        noUpDateKey: form.elements.noUpDateKey.value,
        csrfToken: form.elements.csrfToken.value,
        onBusy: (value) => {
            uploading = value;
            saveButton.disabled = value || saving || uncertainImageState;
        },
        onError: (message) => {
            imageError.hidden = false;
            imageError.style.display = "flex";
            imageError.textContent = message;
        },
        onUncertain: (message) => {
            uncertainImageState = true;
            saveButton.disabled = true;
            imageError.hidden = false;
            imageError.style.display = "flex";
            imageError.textContent = message;
        },
        onUpload: (result) => {
            if (!/^\.\.\/tmp_upload\/food-menu-[0-9]{14}-[0-9a-f]{32}\.(?:jpg|jpeg|png|webp)$/.test(result.file_url) || result.file_name !== result.file_url.slice("../tmp_upload/".length)) {
                uncertainImageState = true;
                saveButton.disabled = true;
                imageError.hidden = false;
                imageError.style.display = "flex";
                imageError.textContent = "画像の表示情報が正しくありません。ページを再読み込みしてください。";
                return;
            }
            imageError.hidden = true;
            imageError.style.display = "none";
            draftUrl = result.file_url;
            draftName = result.file_name;
            renderImage();
        },
        onDiscard: () => {
            draftUrl = null;
            draftName = null;
            imageError.hidden = true;
            imageError.style.display = "none";
            renderImage();
        },
    });
    if (!controller) {
        saveButton.disabled = true;
        imageError.hidden = false;
        imageError.style.display = "flex";
        imageError.textContent = "画像の操作を開始できませんでした。ページを再読み込みしてください。";
        return;
    }
    /** 掲載期間の選択に合わせて日付入力を切り替える。 */
    const syncPeriod = () => {
        const custom = form.querySelector('[name="menuPublicationPeriod"]:checked')?.value === "custom";
        form.elements.menuPublicationStartDay.disabled = !custom;
        form.elements.menuPublicationEndDay.disabled = !custom;
    };
    form.querySelectorAll('[name="menuPublicationPeriod"]').forEach((radio) => radio.addEventListener("change", syncPeriod));
    syncPeriod();
    preview.addEventListener("click", async (event) => {
        if (!event.target.closest(".food-menu-image-remove") || saving || uploading || controller.isBusy() || removalConfirmationPending) return;
        const draftAtOpen = draftUrl;
        const existingRemovedAtOpen = existingRemoved;
        removalConfirmationPending = true;
        try {
            if (!(await showModal("この画像を削除しますか？", true))) return;
            if (saving || uploading || controller.isBusy() || uncertainImageState || draftUrl !== draftAtOpen || existingRemoved !== existingRemovedAtOpen) return;
            if (draftUrl) {
                await controller.discard();
            } else {
                existingRemoved = true;
                renderImage();
            }
        } finally {
            removalConfirmationPending = false;
        }
    });
    /** 保存前のUX検証。server側でも同じ契約を再検証する。 */
    const validate = () => {
        const name = form.elements.menuName.value;
        const price = form.elements.menuPrice.value;
        const description = form.elements.menuDescription.value;
        const period = form.querySelector('[name="menuPublicationPeriod"]:checked')?.value;
        const start = form.elements.menuPublicationStartDay.value;
        const end = form.elements.menuPublicationEndDay.value;
        if (!form.reportValidity()) return false;
        if (!name || /^[\s\p{Z}\uFEFF]*$/u.test(name) || Array.from(name).length > 100) {
            showModal("メニュー名を確認してください。");
            return false;
        }
        if (!/^[1-9][0-9]*$/.test(price) || Number(price) > 2147483647) {
            showModal("料金は1円以上の整数で入力してください。");
            return false;
        }
        if (!description || /^[\s\p{Z}\uFEFF]*$/u.test(description) || new TextEncoder().encode(description).length > 65535) {
            showModal("説明文を確認してください。");
            return false;
        }
        if (period !== "unlimited" && period !== "custom") {
            showModal("掲載期間を選択してください。");
            return false;
        }
        if (period === "custom" && (!start || !end || start > end)) {
            showModal("掲載期間の開始日と終了日を確認してください。");
            return false;
        }
        if (imageMode() === "remove") {
            showModal("メニュー画像を1枚選択してください。");
            return false;
        }
        return true;
    };
    /** 許可済みfieldのみを明示して送信する。 */
    const saveRequest = () => {
        const mode = form.dataset.mode;
        const data = makeRequest(mode);
        if (mode === "update") {
            data.append("menuId", form.elements.menuId.value);
            data.append("menuVersion", form.elements.menuVersion.value);
        }
        data.append("menuName", form.elements.menuName.value);
        data.append("menuPrice", form.elements.menuPrice.value);
        data.append("menuDescription", form.elements.menuDescription.value);
        const period = form.querySelector('[name="menuPublicationPeriod"]:checked').value;
        data.append("menuPublicationPeriod", period);
        data.append("menuPublicationStartDay", period === "custom" ? form.elements.menuPublicationStartDay.value : "");
        data.append("menuPublicationEndDay", period === "custom" ? form.elements.menuPublicationEndDay.value : "");
        data.append("menuIsActive", form.elements.menuIsActive.checked ? "1" : "0");
        data.append("imageMode", imageMode());
        return data;
    };
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving || uploading || uncertainImageState || removalConfirmationPending || !validate()) return;
        saving = true;
        saveButton.disabled = true;
        try {
            const response = await fetch(endpoint, { method: "POST", body: saveRequest(), cache: "no-store" });
            if (!response.ok) throw new Error("http_error");
            const result = await response.json();
            if (!result || typeof result !== "object" || !["success", "warning", "error"].includes(result.status)) {
                throw new Error("response_invalid");
            }
            if (result.status === "success" || result.status === "warning") {
                controller.markFinalized();
                const messages = [];
                if (result.status === "warning") {
                    messages.push(typeof result.msg === "string" && result.msg ? result.msg.replaceAll("<br>", " ") : "保存しました。フロント表示用JSONの更新に失敗しました。");
                }
                if (Array.isArray(result.postCommitWarnings) && result.postCommitWarnings.includes("menu_required_no_active_menu")) {
                    messages.push("食事メニュー選択が必須ですが、現在利用できるメニューがありません。");
                }
                if (messages.length === 0) messages.push("食事メニューを保存しました。");
                await showModal(messages.join(" "));
                const savedMenuId = Number.isInteger(result.menuId) && result.menuId > 0 ? result.menuId : null;
                window.location.href = savedMenuId ? `./client04_03_01.php?menuId=${savedMenuId}` : "./client04_03.php";
                return;
            }
            await showModal(typeof result.msg === "string" && result.msg ? result.msg : "保存できませんでした。");
        } catch (error) {
            await showModal("通信結果を確認できません。保存済みの可能性があります。メニュー一覧で確認してください。");
            window.location.href = "./client04_03.php";
            return;
        } finally {
            saving = false;
            saveButton.disabled = uploading || uncertainImageState;
        }
    });
    document.getElementById("foodMenuCancel").addEventListener("click", async () => {
        if (saving) return;
        await controller.abandon();
        window.location.href = "./client04_03.php";
    });
});
