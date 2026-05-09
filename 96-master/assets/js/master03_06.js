/**
 * API送信先共通変数
 *
 */
const requestURL = "./assets/function/proc_master03_06.php";

/**
 * 表示検索処理
 *
 */
async function searchConditions(action) {
    const searchForm = document.querySelector('form[name="searchForm"]');
    if (!searchForm) {
        alert("フォームが見つかりません。ページを再読み込みしてください。");
        return;
    }
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
        if (list && list["tag"]) {
            const currentUl = document.getElementById("setMasterShopsOrder");
            if (currentUl) currentUl.remove();
            document.querySelector(".inner-head").insertAdjacentHTML("afterend", list["tag"]);
        }
        if (list && list["target_month_label"]) {
            const targetMonthLabel = document.getElementById("aggregateTargetMonthLabel")
                || document.querySelector('form[name="searchForm"] h3 span');
            if (targetMonthLabel) targetMonthLabel.textContent = list["target_month_label"];
        }
        const areaMaster = document.querySelector("main");
        if (areaMaster) areaMaster.scrollIntoView(true);
    } catch (error) {
        console.error("通信エラー:", error);
        alert("通信エラーが発生しました。ページを再読み込みしてください。");
    }
}
