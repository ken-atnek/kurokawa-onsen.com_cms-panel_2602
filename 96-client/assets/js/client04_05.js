/**
 * 予約一覧read API送信先
 *  一覧検索・reset・page移動で共通利用する
 */
const requestURL = "./assets/function/proc_client04_05.php";

let reservationListRequestSequence = 0;
let isReservationListLoading = false;

/**
 * 予約一覧loading状態切替
 *  最新request処理中の操作対象を安全側へ抑制する
 */
function setReservationListLoading(isLoading) {
    const searchButton = document.getElementById("reservationListSearchButton");
    const resetButton = document.getElementById("reservationListResetButton");
    const searchForm = document.querySelector('form[name="reservationListSearchForm"]');
    const searchFields = searchForm?.querySelectorAll(
        'input[name="searchVisitStartDay"], input[name="searchVisitEndDay"], input[name="searchReceptionStartDay"], input[name="searchReceptionEndDay"], input[name="searchCustomerName"], input[name="searchCustomerTel"], input[name="searchReservationRoute"], input[name="searchReservationStatus"]',
    );
    const listBlock = document.querySelector(".block-search-list");
    const pager = listBlock?.querySelector(".box-pager");

    if (searchButton) searchButton.disabled = isLoading;
    if (resetButton) resetButton.disabled = isLoading;
    searchFields?.forEach((field) => {
        field.disabled = isLoading;
    });
    if (listBlock) listBlock.setAttribute("aria-busy", isLoading ? "true" : "false");
    if (pager) {
        pager.style.pointerEvents = isLoading ? "none" : "";
        pager.style.opacity = isLoading ? "0.6" : "";
    }
}

/**
 * 予約一覧fragment検証
 *  APIから返されたHTMLが期待する単一rootかを確認する
 */
function parseReservationListFragment(tag, expectedSelector) {
    if (typeof tag !== "string" || tag.trim() === "") return null;

    const template = document.createElement("template");
    template.innerHTML = tag.trim();
    if (template.content.children.length !== 1) return null;

    const root = template.content.firstElementChild;
    if (!root || !root.matches(expectedSelector)) return null;
    return root;
}

/**
 * 予約一覧成功response適用
 *  list・pager・画面instance keyを同一responseから更新する
 */
function applyReservationListResponse(list, searchForm) {
    const totalItems = list["total_items"];
    const totalPages = list["total_pages"];
    const pageNumber = list["page_number"];
    const noUpDateKey = list["noUpDateKey"];

    if (!Number.isInteger(totalItems) || totalItems < 0 || !Number.isInteger(totalPages) || totalPages < 0 || !Number.isInteger(pageNumber) || pageNumber < 1) {
        return false;
    }
    if ((totalItems === 0 && (totalPages !== 0 || pageNumber !== 1)) || (totalItems > 0 && (totalPages < 1 || pageNumber > totalPages))) {
        return false;
    }
    if (typeof noUpDateKey !== "string" || noUpDateKey === "") {
        return false;
    }

    const newList = parseReservationListFragment(list["tag"], "ul.search-list#reservationList");
    const newPager = parseReservationListFragment(list["pager"], "div.box-pager");
    const currentList = document.getElementById("reservationList");
    const currentPager = document.querySelector(".block-search-list .box-pager");
    const noUpDateKeyInput = searchForm.querySelector('input[name="noUpDateKey"]');
    if (!newList || !newPager || !currentList || !currentPager || !noUpDateKeyInput) {
        return false;
    }

    currentList.replaceWith(newList);
    currentPager.replaceWith(newPager);
    noUpDateKeyInput.value = noUpDateKey;
    return true;
}

/**
 * 予約一覧取得
 *  現在の検索条件と指定pageをPOSTし、最新responseだけを画面へ反映する
 */
async function readReservationList(pageNumber) {
    const searchForm = document.querySelector('form[name="reservationListSearchForm"]');
    if (!searchForm || isReservationListLoading) return;

    const sFd = new FormData(searchForm);
    sFd.set("pageNumber", String(pageNumber));
    isReservationListLoading = true;
    const requestSequence = ++reservationListRequestSequence;
    setReservationListLoading(true);

    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) throw new Error("Network response was not ok");

        const list = (await response.json()) || {};
        if (requestSequence !== reservationListRequestSequence) return;

        if (list["status"] === "error") {
            alert(list["msg"] || "予約一覧を取得できませんでした。ページを再読み込みしてください。");
            return;
        }
        if (list["status"] !== "success" || applyReservationListResponse(list, searchForm) === false) {
            alert("予約一覧の応答が不正です。ページを再読み込みしてください。");
        }
    } catch (error) {
        if (requestSequence !== reservationListRequestSequence) return;
        console.error("予約一覧取得エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    } finally {
        if (requestSequence === reservationListRequestSequence) {
            setReservationListLoading(false);
            isReservationListLoading = false;
        }
    }
}

/**
 * 予約一覧検索条件reset
 *  noUpDateKeyを保持して全filterを初期値へ戻す
 */
function resetReservationListSearchConditions() {
    const searchForm = document.querySelector('form[name="reservationListSearchForm"]');
    if (!searchForm) return;

    [
        "searchVisitStartDay",
        "searchVisitEndDay",
        "searchReceptionStartDay",
        "searchReceptionEndDay",
        "searchCustomerName",
        "searchCustomerTel",
    ].forEach((fieldName) => {
        const field = searchForm.elements.namedItem(fieldName);
        if (field instanceof HTMLInputElement) field.value = "";
    });

    const routeAll = document.getElementById("searchReservationRouteAll");
    const statusAll = document.getElementById("searchReservationStatusAll");
    if (routeAll instanceof HTMLInputElement) routeAll.checked = true;
    if (statusAll instanceof HTMLInputElement) statusAll.checked = true;
}

/**
 * 予約一覧page移動
 *  現在の検索条件を維持して指定pageを取得する
 */
function movePage(pageNumber) {
    if (!Number.isInteger(pageNumber) || pageNumber < 1) return;
    readReservationList(pageNumber);
}

document.addEventListener("DOMContentLoaded", () => {
    const searchForm = document.querySelector('form[name="reservationListSearchForm"]');
    const resetButton = document.getElementById("reservationListResetButton");
    if (!searchForm || !resetButton) return;

    searchForm.addEventListener("submit", (event) => {
        event.preventDefault();
        readReservationList(1);
    });
    resetButton.addEventListener("click", () => {
        resetReservationListSearchConditions();
        readReservationList(1);
    });
});
