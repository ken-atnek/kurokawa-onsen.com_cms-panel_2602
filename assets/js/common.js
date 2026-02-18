// ----------------------------
// 共通：パネルの開閉（is-active）をトグル
// ----------------------------
function togglePanel(panelId, btnSelector) {
    const panel = document.getElementById(panelId);
    if (!panel) return false;

    const isOpen = panel.classList.toggle("is-active");
    panel.setAttribute("aria-hidden", String(!isOpen));

    const btn = btnSelector ? document.querySelector(btnSelector) : null;
    if (btn) {
        btn.setAttribute("aria-expanded", String(isOpen));
        btn.classList.toggle("is-active", isOpen);
    }

    return isOpen;
}

// ----------------------------
// 初期化：閉じた状態にする
// ----------------------------
function initClosed(panelId, btnId) {
    const panel = document.getElementById(panelId);
    if (panel) {
        panel.classList.remove("is-active");
        panel.setAttribute("aria-hidden", "true");
    }

    const btn = document.getElementById(btnId);
    if (btn) {
        btn.classList.remove("is-active");
        btn.setAttribute("aria-expanded", "false");
    }
}

// ----------------------------
// クリック紐付け
// ----------------------------
function bindToggle(btnId, panelId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;

    btn.addEventListener("click", () => {
        togglePanel(panelId, `#${btnId}`);
    });
}

document.addEventListener("DOMContentLoaded", () => {
    // 初期は閉
    initClosed("innerSearch", "btnSwitchSearch");
    initClosed("innerFilter", "btnSwitchFilter");

    // トグル動作
    bindToggle("btnSwitchSearch", "innerSearch");
    bindToggle("btnSwitchFilter", "innerFilter");
});

// ---------------------------------------------------------------
// セレクトボックス（状態付与 + hidden反映 / 開閉はCSS）
//  - DOM差し替え後に再初期化できるよう関数として公開
// ---------------------------------------------------------------
window.initSelectBoxes = (root = document) => {
    const scope = root && root.querySelectorAll ? root : document;
    const boxes = Array.from(scope.querySelectorAll("[data-selectbox]"));
    if (boxes.length === 0) return;

    if (!window.__rwSelectboxState) {
        window.__rwSelectboxState = { openBox: null, docHandlersInstalled: false };
    }
    const state = window.__rwSelectboxState;

    const getParts = (box) => {
        const head = box.querySelector(".selectbox__head");
        const valueEl = box.querySelector("[data-selectbox-value]");
        const hiddenEl = box.querySelector("[data-selectbox-hidden]");
        return { head, valueEl, hiddenEl };
    };

    // ★追加：labelの status-* クラスを head に同期する
    const syncHeadStatusClass = (head, label) => {
        if (!head) return;

        // headから status-系クラスを削除
        Array.from(head.classList).forEach((c) => {
            if (c.startsWith("status-")) head.classList.remove(c);
        });

        if (!label) return;

        // labelに付いてる status-系クラスを head に付与
        Array.from(label.classList).forEach((c) => {
            if (c.startsWith("status-")) head.classList.add(c);
        });
    };

    // 状態クラス制御
    const setEmptyState = (box, hiddenEl) => {
        box.classList.remove("is-selected");
        box.classList.add("is-empty");
        if (hiddenEl) hiddenEl.value = "";
    };

    const setSelectedState = (box, hiddenEl, value) => {
        box.classList.add("is-selected");
        box.classList.remove("is-empty");
        if (hiddenEl) hiddenEl.value = value;
    };

    const close = (box) => {
        const { head } = getParts(box);
        if (!head) return;

        box.classList.remove("is-open");
        head.setAttribute("aria-expanded", "false");

        if (state.openBox === box) state.openBox = null;
    };

    const open = (box) => {
        const { head } = getParts(box);
        if (!head) return;

        if (state.openBox && state.openBox !== box) close(state.openBox);

        box.classList.add("is-open");
        head.setAttribute("aria-expanded", "true");

        state.openBox = box;
    };

    const toggle = (box) => {
        box.classList.contains("is-open") ? close(box) : open(box);
    };

    // 初期化 & イベント
    boxes.forEach((box) => {
        if (box.dataset.selectboxInitialized === "1") return;
        box.dataset.selectboxInitialized = "1";
        const { head, valueEl, hiddenEl } = getParts(box);
        if (!head || !valueEl) return;

        const radios = box.querySelectorAll('input[type="radio"]');

        // 初期状態：閉
        box.classList.remove("is-open");
        head.setAttribute("aria-expanded", "false");

        // 初期値反映（checkedがあれば）
        const checked = box.querySelector('input[type="radio"]:checked');
        if (checked) {
            const label = box.querySelector(`label[for="${checked.id}"]`);
            if (label) valueEl.textContent = label.textContent.trim();
            syncHeadStatusClass(head, label); // ★追加：初期状態も同期
            setSelectedState(box, hiddenEl, checked.value);
        } else {
            syncHeadStatusClass(head, null); // ★追加：status-* を消す
            setEmptyState(box, hiddenEl);
        }

        // ヘッダクリックで開閉（クラス付与のみ）
        head.addEventListener("click", (e) => {
            e.preventDefault();
            toggle(box);
        });

        // 選択時：表示更新 + headクラス同期 + hidden反映 + 閉じる
        radios.forEach((r) => {
            r.addEventListener("change", () => {
                const label = box.querySelector(`label[for="${r.id}"]`);
                if (label) valueEl.textContent = label.textContent.trim();
                syncHeadStatusClass(head, label); // ★追加：選択時も同期
                setSelectedState(box, hiddenEl, r.value);
                close(box);
            });
        });
    });

    // 外側クリックで閉じる
    if (!state.docHandlersInstalled) {
        state.docHandlersInstalled = true;
        // 外側クリックで閉じる
        document.addEventListener("click", (e) => {
            if (!state.openBox) return;
            if (!state.openBox.contains(e.target)) close(state.openBox);
        });

        // Escで閉じる
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && state.openBox) close(state.openBox);
        });
    }
};

// 初回ロード時も従来通り初期化
document.addEventListener("DOMContentLoaded", () => {
    if (typeof window.initSelectBoxes === "function") {
        window.initSelectBoxes(document);
    }
});
