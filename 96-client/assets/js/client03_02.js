/**
 * API送信先 共通定数
 *
 */
const requestURL = "./assets/function/proc_client03_02.php";

/**
 * 店舗検索
 *
 */
async function searchConditions(action, pageNumber = 1) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
    //送信用フォーム生成
    const sFd = new FormData(searchForm);
    sFd.set("action", action);
    sFd.set("pageNumber", pageNumber);
    const displayNumberInput = document.querySelector('.select-display-number input[name="displayNumber"][type="hidden"]');
    if (displayNumberInput) {
        sFd.set("displayNumber", displayNumberInput.value);
    }
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
            const currentUl = document.querySelector(".inner_search-list > ul");
            if (currentUl) currentUl.outerHTML = list["tag"];
        }
        const countEl = document.getElementById("search-result-count");
        if (countEl && typeof list["total_items"] !== "undefined") {
            countEl.textContent = `${list["total_items"]}件`;
        }
        if (list && list["pager"]) {
            const currentPager = document.querySelector(".inner_search-list > .box-pager");
            if (currentPager) {
                currentPager.outerHTML = list["pager"];
            } else {
                document.querySelector(".inner_search-list")?.insertAdjacentHTML("beforeend", list["pager"]);
            }
        }
        //input情報クリア
        switch (action) {
            case "reset":
                {
                    const searchProduct = searchForm.querySelector('input[name="searchProduct"]');
                    if (searchProduct) searchProduct.value = "";
                    const hiddenCategory = searchForm.querySelector('input[name="select-search-category"][type="hidden"]');
                    if (hiddenCategory) hiddenCategory.value = "";
                    const categoryRadios = searchForm.querySelectorAll('input[name="select-search-category"][type="radio"]');
                    categoryRadios.forEach((radio) => {
                        radio.checked = false;
                    });
                    const categorySelectBox = searchForm.querySelector(".select-search-category[data-selectbox]");
                    const selectBoxValue = categorySelectBox?.querySelector("[data-selectbox-value]");
                    if (selectBoxValue) selectBoxValue.textContent = "選択してください";
                    //セレクトボックスのCSS状態クラスをリセット
                    if (categorySelectBox) {
                        categorySelectBox.classList.remove("is-selected");
                        categorySelectBox.classList.add("is-empty");
                    }
                    const displayFlgPublic = searchForm.querySelector('input[name="displayFlg"][value="0"]');
                    if (displayFlgPublic) displayFlgPublic.checked = true;
                    const displayNumberHidden = document.querySelector('.select-display-number input[name="displayNumber"][type="hidden"]');
                    const displayNumberRadios = document.querySelectorAll('.select-display-number input[name="displayNumber"][type="radio"]');
                    const displayNumberRadio = document.querySelector('.select-display-number input[name="displayNumber"][value="10"]') || document.querySelector('.select-display-number input[name="displayNumber"][type="radio"]');
                    displayNumberRadios.forEach((radio) => {
                        radio.checked = false;
                    });
                    if (displayNumberRadio) displayNumberRadio.checked = true;
                    if (displayNumberHidden && displayNumberRadio) displayNumberHidden.value = displayNumberRadio.value;
                    const displayNumberSelectBox = document.querySelector(".select-display-number[data-selectbox]");
                    const displayNumberValue = displayNumberSelectBox?.querySelector("[data-selectbox-value]");
                    if (displayNumberValue && displayNumberRadio) displayNumberValue.textContent = displayNumberRadio.value;
                    if (displayNumberSelectBox) {
                        displayNumberSelectBox.classList.remove("is-empty");
                        displayNumberSelectBox.classList.add("is-selected");
                    }
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
function movePage(pageNumber) {
    searchConditions("search", pageNumber);
}
