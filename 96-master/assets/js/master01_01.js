/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_master01_01.php";

/**
 * 店舗検索
 *
 */
async function searchConditions(action) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
    //送信用フォーム生成
    const sFd = new FormData(searchForm);
    sFd.set("action", action);
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const list = (await response.json()) || {};
        if (list && list["noUpDateKey"]) {
            const noUpDateKeyInput = searchForm.querySelector('input[name="noUpDateKey"]');
            if (noUpDateKeyInput) noUpDateKeyInput.value = list["noUpDateKey"];
        }
        if (list && list["status"] === "error") {
            alert(list["msg"] || "エラーが発生しました。ページを再読み込みしてください。");
            return;
        }
        //表示中の情報入替
        if (list && list["tag"]) {
            const currentUl = document.querySelector(".inner_search-list ul");
            if (currentUl) currentUl.remove();
            //ページ表示
            document.querySelector(".inner_search-list").insertAdjacentHTML("beforeend", list["tag"]);
        }
        //input情報クリア
        switch (action) {
            case "reset":
                {
                    //店舗選択セレクトボックス
                    const hiddenShopId = searchForm.querySelector('input[name="searchShopId"][type="hidden"]');
                    if (hiddenShopId) hiddenShopId.value = "";
                    const shopRadios = searchForm.querySelectorAll('input[name="searchShopId"][type="radio"]');
                    shopRadios.forEach((radio) => {
                        radio.checked = false;
                    });
                    //公開設定ラジオボタン
                    const selectBoxValue = searchForm.querySelector("[data-selectbox-value]");
                    if (selectBoxValue) selectBoxValue.textContent = "選択してください";
                    //セレクトボックスのCSS状態クラスをリセット
                    const selectBox = searchForm.querySelector("[data-selectbox]");
                    if (selectBox) {
                        selectBox.classList.remove("is-selected");
                        selectBox.classList.add("is-empty");
                    }
                    const displayModePublic = searchForm.querySelector('input[name="displayMode"][value="1"]');
                    if (displayModePublic) displayModePublic.checked = true;
                }
                break;
        }
        //ページの上端までスクロール
        const areaMaster = document.querySelector("main");
        if (areaMaster) areaMaster.scrollIntoView(true);
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * 公開設定変更（店舗一覧のボタン用）
 *
 */
async function changeStatus(shopId, shopName, status) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
    //送信用フォーム生成
    const sFd = new FormData(searchForm);
    sFd.set("action", "changeStatus");
    sFd.set("changeShopId", String(shopId));
    sFd.set("shopName", String(shopName));
    sFd.set("status", String(status));
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");
        const list = await response.json();
        if (list && list["noUpDateKey"]) {
            const noUpDateKeyInput = searchForm.querySelector('input[name="noUpDateKey"]');
            if (noUpDateKeyInput) noUpDateKeyInput.value = list["noUpDateKey"];
        }
        //表示中の情報入替
        if (list && list["tag"]) {
            const currentUl = document.querySelector(".inner_search-list ul");
            if (currentUl) currentUl.remove();
            //ページ表示
            document.querySelector(".inner_search-list").insertAdjacentHTML("beforeend", list["tag"]);
        }
        //店舗選択セレクトボックス
        const returnedShopId = list && typeof list["searchShopId"] !== "undefined" ? String(list["searchShopId"]) : null;
        if (returnedShopId === "") {
            const hiddenShopId = searchForm.querySelector('input[name="searchShopId"][type="hidden"]');
            if (hiddenShopId) hiddenShopId.value = "";
            const shopRadios = searchForm.querySelectorAll('input[name="searchShopId"][type="radio"]');
            shopRadios.forEach((radio) => {
                radio.checked = false;
            });
            const selectBoxValue = searchForm.querySelector("[data-selectbox-value]");
            if (selectBoxValue) selectBoxValue.textContent = "選択してください";
            //セレクトボックスのCSS状態クラスをリセット
            const selectBox = searchForm.querySelector("[data-selectbox]");
            if (selectBox) {
                selectBox.classList.remove("is-selected");
                selectBox.classList.add("is-empty");
            }
        }
        //公開設定ラジオボタン
        const displayModePrivate = searchForm.querySelector('input[name="displayMode"][value="0"]');
        const displayModePublic = searchForm.querySelector('input[name="displayMode"][value="1"]');
        const mode = list && typeof list["displayMode"] !== "undefined" ? String(list["displayMode"]) : null;
        if (mode === "0") {
            if (displayModePrivate) displayModePrivate.checked = true;
        } else if (mode === "1") {
            if (displayModePublic) displayModePublic.checked = true;
        }
        //モーダルボックス
        const blockModal = document.getElementById("modalBlock");
        const title = list["title"] || (list["status"] === "success" ? "公開設定変更" : "公開設定変更エラー");
        const msg = String(list["msg"] || "処理が完了しました。").replace(/\n/g, "<br>");
        if (!blockModal) {
            alert(String(list["msg"] || "処理が完了しました。"));
            return;
        }
        const titleEl = blockModal.querySelector(".box-title p");
        if (titleEl) titleEl.innerHTML = title;
        const msgEl = blockModal.querySelector(".box-details p");
        if (msgEl) msgEl.innerHTML = msg;
        const btnArea = blockModal.querySelector(".box-btn");
        if (btnArea) {
            //ボタン再生成
            btnArea.querySelectorAll("button").forEach((btn) => btn.remove());
            //ボタン生成
            btnArea.insertAdjacentHTML("beforeend", '<button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>');
        }
        blockModal.classList.add("is-active");
        document.documentElement.style.overflow = "hidden";
        //ページの上端までスクロール
        const areaMaster = document.querySelector("main");
        if (areaMaster) areaMaster.scrollIntoView(true);
    } catch (error) {
        console.error("送信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
