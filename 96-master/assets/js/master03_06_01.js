/**
 * API送信共通定数
 *
 */
const requestURL = "./assets/function/proc_master03_06_01.php";

/**
 * 表示検索処理
 * フォーム値をAjaxで送信し、注文一覧・ページャー・月合計を差し替える
 */
async function searchConditions(action, pageNumber = 1) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
    const sFd = new FormData(searchForm);
    sFd.set("action", action);
    sFd.set("pageNumber", String(pageNumber));
    try {
        const response = await fetch(requestURL, {
            method: "POST",
            body: sFd,
        });
        if (!response.ok) {
            throw new Error("Network response was not ok");
        }
        const list = (await response.json()) || {};
        if (list["noUpDateKey"]) {
            const noUpDateKeyInput = searchForm.querySelector('input[name="noUpDateKey"]');
            if (noUpDateKeyInput) {
                noUpDateKeyInput.value = list["noUpDateKey"];
            }
        }
        if (list["status"] === "error") {
            alert(list["msg"] || "エラーが発生しました。ページを再読み込みしてください。");
            return;
        }
        if (list["tag"]) {
            const currentUl = document.getElementById("setMasterShopsOrder");
            if (currentUl) {
                currentUl.outerHTML = list["tag"];
            }
        }
        if (list["pager"]) {
            const currentPager = document.querySelector(".inner-summary-list > .box-pager");
            if (currentPager) {
                currentPager.outerHTML = list["pager"];
            } else {
                document.getElementById("setMasterShopsOrder")?.insertAdjacentHTML("afterend", list["pager"]);
            }
        }
        if (list["summary_tag"]) {
            const currentSummary = document.getElementById("setShopMonthlySummary");
            if (currentSummary) {
                currentSummary.outerHTML = list["summary_tag"];
            }
        }
        if (list["target_month_label"]) {
            const targetMonthLabel = document.getElementById("aggregateTargetMonthLabel");
            if (targetMonthLabel) {
                targetMonthLabel.textContent = list["target_month_label"];
            }
        }
        if (typeof window.initSelectBoxes === "function") {
            window.initSelectBoxes(document);
        }
    } catch (error) {
        console.error("通信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
/**
 * ページャー遷移
 * ページ番号を指定して検索処理を再実行する
 */
function movePage(pageNumber) {
    searchConditions("search", pageNumber);
}
