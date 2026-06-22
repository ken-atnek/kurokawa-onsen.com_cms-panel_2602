/**
 * 納品書PDF確認モーダル
 */
async function checkDeliverySlipPdf(shopId, orderId) {
    const formData = new FormData();
    formData.append("mode", "checkDeliverySlipData");
    formData.append("shopId", shopId);
    formData.append("orderId", orderId);
    try {
        const response = await fetch("../assets/lib/jsPDF/delivery_slip.php", {
            method: "POST",
            body: formData,
        });
        if (!response.ok) {
            throw new Error("Network response was not ok");
        }
        const list = await response.json();
        const blockModal = document.getElementById("modalBlock");
        if (!blockModal) {
            return;
        }
        const boxTitleEl = blockModal.querySelector(".box-title p");
        const boxDetailsEl = blockModal.querySelector(".box-details p");
        const buttonBoxEl = blockModal.querySelector(".box-btn");
        if (!buttonBoxEl) {
            return;
        }
        if (boxTitleEl) boxTitleEl.textContent = "納品書PDF";
        if (boxDetailsEl) boxDetailsEl.textContent = "納品書PDFを発行します。よろしいですか？";
        buttonBoxEl.querySelectorAll("button").forEach((buttonEl) => buttonEl.remove());
        if (list.status === "error") {
            if (boxDetailsEl) boxDetailsEl.textContent = list.msg || "納品書PDFを発行できません。";
            const closeButton = document.createElement("button");
            closeButton.type = "button";
            closeButton.className = "btn-cancel";
            closeButton.textContent = "閉じる";
            closeButton.addEventListener("click", closeModalDeliverySlip);
            buttonBoxEl.appendChild(closeButton);
        } else {
            const cancelButton = document.createElement("button");
            cancelButton.type = "button";
            cancelButton.className = "btn-cancel";
            cancelButton.textContent = "キャンセル";
            cancelButton.addEventListener("click", closeModalDeliverySlip);
            buttonBoxEl.appendChild(cancelButton);
            const submitButton = document.createElement("button");
            submitButton.type = "button";
            submitButton.className = "btn-confirm";
            submitButton.textContent = "納品書発行";
            submitButton.addEventListener("click", (event) => {
                makeDeliverySlipPdf(list.shop_id, list.order_id, event.currentTarget);
            });
            buttonBoxEl.appendChild(submitButton);
        }
        blockModal.classList.add("is-active");
        document.documentElement.style.overflow = "hidden";
    } catch (error) {
        console.error("通信エラー:", error);
    }
}
/**
 * 納品書PDF確認モーダルクローズ
 */
function closeModalDeliverySlip() {
    const blockModal = document.getElementById("modalBlock");
    if (!blockModal) return;
    blockModal.classList.remove("is-active");
    document.documentElement.style.overflow = "";
}
(() => {
    const lock = (isLock, triggerEl) => {
        if (triggerEl && triggerEl instanceof HTMLButtonElement) {
            triggerEl.disabled = Boolean(isLock);
            triggerEl.setAttribute("aria-busy", isLock ? "true" : "false");
        }
    };
    const ensureLibs = () => {
        const okCanvas = typeof window.html2canvas === "function";
        const okPdf = window.jspdf && typeof window.jspdf.jsPDF === "function";
        return okCanvas && okPdf;
    };
    const showOverlay = () => {
        const overlay = document.createElement("div");
        overlay.id = "pdfOverlay";
        overlay.textContent = "PDF生成中...";
        overlay.style.position = "fixed";
        overlay.style.inset = "0";
        overlay.style.zIndex = "2147483647";
        overlay.style.display = "grid";
        overlay.style.placeItems = "center";
        overlay.style.background = "rgba(255,255,255,0.92)";
        overlay.style.fontWeight = "800";
        document.body.appendChild(overlay);
        return overlay;
    };
    const waitImages = async (root) => {
        const images = Array.from(root.querySelectorAll("img"));
        await Promise.all(
            images.map((imageEl) => {
                if (imageEl.complete && imageEl.naturalWidth > 0) return Promise.resolve();
                return new Promise((resolve) => {
                    imageEl.addEventListener("load", resolve, { once: true });
                    imageEl.addEventListener("error", resolve, { once: true });
                });
            }),
        );
    };
    const canvasToPdf = (canvas) => {
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({ unit: "mm", format: "a4", orientation: "portrait" });
        const pageWmm = 210;
        const pageHmm = 297;
        const imgHmm = (canvas.height * pageWmm) / canvas.width;
        if (imgHmm <= pageHmm + 0.01) {
            pdf.addImage(canvas.toDataURL("image/jpeg", 0.98), "JPEG", 0, 0, pageWmm, imgHmm);
            return pdf;
        }
        const pageHpx = Math.floor((pageHmm * canvas.width) / pageWmm);
        const c1 = document.createElement("canvas");
        c1.width = canvas.width;
        c1.height = Math.min(pageHpx, canvas.height);
        c1.getContext("2d")?.drawImage(canvas, 0, 0, canvas.width, c1.height, 0, 0, canvas.width, c1.height);
        pdf.addImage(c1.toDataURL("image/jpeg", 0.98), "JPEG", 0, 0, pageWmm, (c1.height * pageWmm) / c1.width);
        const remain = canvas.height - pageHpx;
        if (remain > 0) {
            pdf.addPage();
            const c2 = document.createElement("canvas");
            c2.width = canvas.width;
            c2.height = remain;
            c2.getContext("2d")?.drawImage(canvas, 0, pageHpx, canvas.width, remain, 0, 0, canvas.width, remain);
            pdf.addImage(c2.toDataURL("image/jpeg", 0.98), "JPEG", 0, 0, pageWmm, (c2.height * pageWmm) / c2.width);
        }
        return pdf;
    };
    const mountTempDom = (html) => {
        const wrap = document.createElement("div");
        wrap.id = "pdfMount";
        wrap.innerHTML = html;
        const target = wrap.querySelector("#pdfTarget");
        if (!target) throw new Error("納品書HTMLに #pdfTarget がありません");
        target.classList.add("pdf-mode");
        wrap.style.position = "fixed";
        wrap.style.left = "-10000px";
        wrap.style.top = "0";
        wrap.style.zIndex = "1";
        wrap.style.background = "#fff";
        wrap.style.visibility = "visible";
        wrap.style.opacity = "1";
        document.body.appendChild(wrap);
        return { wrap, target };
    };
    const showResultModal = (title, message) => {
        const blockModal = document.getElementById("modalBlock");
        if (!blockModal) return;
        const boxTitleEl = blockModal.querySelector(".box-title p");
        const boxDetailsEl = blockModal.querySelector(".box-details p");
        const buttonBoxEl = blockModal.querySelector(".box-btn");
        if (!buttonBoxEl) return;
        if (boxTitleEl) boxTitleEl.textContent = title;
        if (boxDetailsEl) boxDetailsEl.textContent = message;
        buttonBoxEl.querySelectorAll("button").forEach((buttonEl) => buttonEl.remove());
        const closeButton = document.createElement("button");
        closeButton.type = "button";
        closeButton.className = "btn-cancel";
        closeButton.textContent = "閉じる";
        closeButton.addEventListener("click", closeModalDeliverySlip);
        buttonBoxEl.appendChild(closeButton);
        blockModal.classList.add("is-active");
        document.documentElement.style.overflow = "hidden";
    };
    window.makeDeliverySlipPdf = async (shopId, orderId, triggerEl) => {
        if (!ensureLibs()) {
            showResultModal("納品書PDF", "必要なライブラリの読み込みに失敗しました。");
            return;
        }
        const trigger = triggerEl ?? document.activeElement;
        if (!shopId || !orderId) {
            showResultModal("納品書PDF", "不正なリクエストです。");
            return;
        }
        lock(true, trigger);
        let overlay;
        let wrap;
        let blobUrl = "";
        try {
            overlay = showOverlay();
            const body = new URLSearchParams({
                mode: "makeDeliverySlipHtml",
                shopId: String(shopId),
                orderId: String(orderId),
            });
            const res = await fetch("../assets/lib/jsPDF/delivery_slip.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
                body,
                credentials: "same-origin",
                cache: "no-store",
            });
            if (!res.ok) {
                throw new Error(`納品書データ取得に失敗しました: ${res.status}`);
            }
            const list = await res.json();
            if (list.status === "error") {
                throw new Error(String(list.msg || "納品書PDFを発行できません。"));
            }
            const html = String(list.html || "");
            if (html === "") {
                throw new Error("納品書PDFのHTMLが空です。");
            }
            const mounted = mountTempDom(html);
            wrap = mounted.wrap;
            const target = mounted.target;
            if (document.fonts?.ready) await document.fonts.ready;
            await waitImages(target);
            await new Promise((resolve) => requestAnimationFrame(resolve));
            const rect = target.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            const scale = Math.min(2, Math.max(1.5, dpr));
            const canvas = await window.html2canvas(target, {
                scale,
                useCORS: true,
                backgroundColor: "#ffffff",
                scrollX: 0,
                scrollY: 0,
                windowWidth: Math.max(1, Math.ceil(rect.width)),
                windowHeight: Math.max(1, Math.ceil(rect.height)),
            });
            const pdf = canvasToPdf(canvas);
            const blob = pdf.output("blob");
            blobUrl = URL.createObjectURL(blob);
            const openedWindow = window.open(blobUrl, "_blank");
            if (!openedWindow) {
                URL.revokeObjectURL(blobUrl);
                blobUrl = "";
                throw new Error("ポップアップがブロックされました。");
            }
            setTimeout(() => {
                if (blobUrl !== "") {
                    URL.revokeObjectURL(blobUrl);
                }
            }, 60000);
            showResultModal("納品書PDF", "納品書を発行いたしました。");
        } catch (error) {
            console.error(error);
            const message = error instanceof Error ? error.message : "納品書PDFの発行に失敗しました。";
            showResultModal("納品書PDF", message);
        } finally {
            if (wrap) wrap.remove();
            if (overlay) overlay.remove();
            lock(false, trigger);
        }
    };
})();
