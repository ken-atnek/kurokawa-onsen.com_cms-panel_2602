import { Editor, Extension, Node } from "https://esm.sh/@tiptap/core";
import StarterKit from "https://esm.sh/@tiptap/starter-kit";
import Image from "https://esm.sh/@tiptap/extension-image";
import { TextStyle, Color } from "https://esm.sh/@tiptap/extension-text-style";
import { Plugin } from "https://esm.sh/prosemirror-state";
import { Decoration, DecorationSet } from "https://esm.sh/prosemirror-view";
//TipTap（編集UI）専用モジュール
// - DB保存 / JSON書き出し / 画像保存は「各ページ側」のAJAXで行う
// - 本モジュールは editor を初期化し、toolbar/色/画像挿入UIを提供する
const $ = (id) => document.getElementById(id);
const $status = $("status");
const $toolbar = $("toolbar");
const $imageInput = $("imageInput");
const $textColor = $("textColor");
const $mount = document.querySelector("#TipTapEditor");
//Dropzone（存在しないページでは null になり得る）
const $dropzoneOverlay = $("dropzoneOverlay");
const $dropzoneCard = $("dropzoneCard");
const $dropzonePick = $("dropzonePick");
const $dropzoneClose = $("dropzoneClose");
const tiptapContext = (() => {
    const ctx = window.TIPTAP_CONTEXT || {};
    return {
        paragraphNo: Number(ctx.paragraphNo || 0) || null,
    };
})();
if (!$mount) {
    //対象ページ以外で読み込まれても落ちないようにする
    console.warn("[tiptap_app] #TipTapEditor が見つからないため初期化をスキップします");
} else {
    function setStatus(message, meta = "") {
        if (!$status) return;
        $status.innerHTML = message + (meta ? ` <small>${meta}</small>` : "");
    }
    function dataUrlToFile(dataUrl, filenameBase = "paste") {
        //data:[<mime>][;base64],<data>
        const m = /^data:([^;,]+)?(;base64)?,(.*)$/i.exec(String(dataUrl || ""));
        if (!m) throw new Error("invalid data url");
        const mime = m[1] || "application/octet-stream";
        const isBase64 = !!m[2];
        const dataPart = m[3] || "";
        let bytes;
        if (isBase64) {
            const bin = atob(dataPart.replace(/\s/g, ""));
            bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        } else {
            const decoded = decodeURIComponent(dataPart);
            bytes = new TextEncoder().encode(decoded);
        }
        const ext = (mime.split("/")[1] || "png").toLowerCase();
        return new File([bytes], `${filenameBase}.${ext}`, { type: mime });
    }
    async function uploadImage(file) {
        const fn = window.tipTapUploadImage;
        if (typeof fn !== "function") {
            throw new Error("画像アップロード関数 window.tipTapUploadImage(file) が未定義です");
        }
        return await fn(file, tiptapContext);
    }
    //画像ノードに「削除（×）ボタン」を付与する（エディタ上から画像を削除するだけ。サーバー上のファイルは残ります）
    const ImageWithDelete = Image.extend({
        addNodeView() {
            return ({ node, getPos, editor }) => {
                const wrapper = document.createElement("span");
                wrapper.className = "image-node";
                wrapper.contentEditable = "false";
                const img = document.createElement("img");
                img.src = node.attrs.src;
                img.alt = node.attrs.alt || "";
                img.title = node.attrs.title || "";
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "image-remove";
                btn.textContent = "×";
                btn.contentEditable = "false";
                btn.setAttribute("tabindex", "-1");
                function removeImageFromEditor(e) {
                    if (e) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    const pos = typeof getPos === "function" ? getPos() : null;
                    if (typeof pos !== "number") return;
                    const state = editor.state;
                    const $pos = state.doc.resolve(pos);
                    //imageGallery の最後の1枚を削除する場合は、ギャラリーごと削除してゴミDOMを残さない
                    for (let d = $pos.depth; d > 0; d--) {
                        const n = $pos.node(d);
                        if (n?.type?.name === "imageGallery") {
                            if (n.childCount <= 1) {
                                const from = $pos.before(d);
                                editor.commands.command(({ tr, dispatch }) => {
                                    if (dispatch) dispatch(tr.delete(from, from + n.nodeSize));
                                    return true;
                                });
                                editor.view.focus();
                                return;
                            }
                            break;
                        }
                    }
                    //通常：画像ノードだけ削除
                    editor.commands.command(({ tr, dispatch }) => {
                        if (dispatch) dispatch(tr.delete(pos, pos + node.nodeSize));
                        return true;
                    });
                    editor.view.focus();
                }
                ["pointerdown", "mousedown", "click"].forEach((eventName) => {
                    wrapper.addEventListener(
                        eventName,
                        (e) => {
                            const target = e.target;
                            if (!target || !target.closest || !target.closest(".image-remove")) {
                                return;
                            }
                            if (eventName === "click") {
                                removeImageFromEditor(e);
                            } else {
                                e.preventDefault();
                                e.stopPropagation();
                            }
                        },
                        true,
                    );
                });
                wrapper.appendChild(img);
                wrapper.appendChild(btn);
                return {
                    dom: wrapper,
                    update(updatedNode) {
                        if (updatedNode.type.name !== "image") return false;
                        img.src = updatedNode.attrs.src;
                        img.alt = updatedNode.attrs.alt || "";
                        img.title = updatedNode.attrs.title || "";
                        return true;
                    },
                    stopEvent(event) {
                        return event.target === btn || btn.contains(event.target);
                    },
                };
            };
        },
    });
    //複数画像をまとめて横並び表示するコンテナ（最大4枚想定）
    const ImageGallery = Node.create({
        name: "imageGallery",
        group: "block",
        content: "image{0,4}",
        isolating: true,
        parseHTML() {
            return [{ tag: 'div[data-type="image-gallery"]' }];
        },
        renderHTML({ HTMLAttributes }) {
            return ["div", { ...HTMLAttributes, "data-type": "image-gallery" }, 0];
        },
        addNodeView() {
            return ({ node }) => {
                const dom = document.createElement("div");
                dom.className = "image-gallery";
                dom.setAttribute("data-type", "image-gallery");
                dom.dataset.count = String(node.childCount);
                const inner = document.createElement("div");
                inner.className = "image-gallery__inner";
                dom.appendChild(inner);
                return {
                    dom,
                    contentDOM: inner,
                    update(updatedNode) {
                        if (updatedNode.type.name !== "imageGallery") return false;
                        dom.dataset.count = String(updatedNode.childCount);
                        return true;
                    },
                };
            };
        },
    });
    //空になった imageGallery を自動削除する（削除ボタン等で最後の画像を消した後の後始末）
    const ImageGalleryCleanup = Extension.create({
        name: "imageGalleryCleanup",
        onTransaction({ editor, transaction }) {
            if (!transaction.docChanged) return;
            const { state } = editor;
            const tr = state.tr;
            let changed = false;
            //後ろから処理（位置ズレ防止）
            const targets = [];
            state.doc.descendants((node, pos) => {
                if (node.type.name === "imageGallery") {
                    targets.push({ pos, node });
                }
            });
            if (targets.length === 0) return;
            for (const t of targets.reverse()) {
                const { pos, node } = t;
                //空ギャラリーは削除
                if (node.childCount === 0) {
                    tr.delete(pos, pos + node.nodeSize);
                    changed = true;
                    continue;
                }
                //1枚だけになったら、単体画像に戻す（1枚だけ登録した時と同じ見た目/JSONに揃える）
                if (node.childCount === 1) {
                    const only = node.content.firstChild;
                    if (only && only.type.name === "image") {
                        tr.replaceWith(pos, pos + node.nodeSize, only);
                        changed = true;
                    }
                }
            }
            if (changed) editor.view.dispatch(tr);
        },
    });
    //現在段落（カーソル位置のブロック）をハイライトする拡張
    const ActiveParagraphHighlight = Extension.create({
        name: "activeParagraphHighlight",
        addProseMirrorPlugins() {
            return [
                new Plugin({
                    props: {
                        decorations(state) {
                            const { selection } = state;
                            const $from = selection.$from;
                            if (!$from) return null;
                            //カーソルの属する「ブロックノード」を探す（paragraph / heading など）
                            let depth = $from.depth;
                            while (depth > 0 && !$from.node(depth).isBlock) depth--;
                            //doc自体は除外
                            if (depth <= 0) return null;
                            const node = $from.node(depth);
                            if (!node || !node.isBlock) return null;
                            //ノードの開始位置（ノード直前）と終了位置（ノード直後）
                            const from = $from.before(depth);
                            const to = from + node.nodeSize;
                            const deco = Decoration.node(from, to, { class: "is-active-paragraph" });
                            return DecorationSet.create(state.doc, [deco]);
                        },
                    },
                }),
            ];
        },
    });
    //初期コンテンツ
    // - ページ側が window.TIPTAP_INITIAL = { json?: object, html?: string } を用意できる
    // - 未指定ならプレースホルダ
    let initialContent = "";
    try {
        const init = window.TIPTAP_INITIAL;
        if (init?.json) {
            initialContent = init.json;
        } else if (typeof init?.html === "string" && init.html.trim() !== "") {
            initialContent = init.html;
        }
    } catch {
        //noop
    }
    const editor = new Editor({
        editorProps: {
            handleDrop: (view, event) => {
                const dt = event.dataTransfer;
                if (!dt) return false;
                const files = [];
                //dataTransfer.files
                for (const f of Array.from(dt.files ?? [])) {
                    if ((f.type || "").startsWith("image/")) files.push(f);
                }
                //dataTransfer.items（ブラウザ/OSによってはこちらが主）
                for (const it of Array.from(dt.items ?? [])) {
                    if (it.kind === "file" && (it.type || "").startsWith("image/")) {
                        const f = it.getAsFile();
                        if (f) files.push(f);
                    }
                }
                //重複排除
                const uniq = [];
                const seen = new Set();
                for (const f of files) {
                    const key = [f.name, f.type, f.size, f.lastModified].join("|");
                    if (seen.has(key)) continue;
                    seen.add(key);
                    uniq.push(f);
                }
                if (uniq.length === 0) return false;
                event.preventDefault();
                event.stopPropagation();
                //ドロップ位置へカーソルを移動してから挿入（ギャラリー追記判定の精度向上）
                const coords = view.posAtCoords({ left: event.clientX, top: event.clientY });
                if (coords?.pos) {
                    editor.commands.setTextSelection(coords.pos);
                } else {
                    editor.commands.focus();
                }
                handleFilesInsert(uniq);
                return true;
            },
            handlePaste: (view, event) => {
                const dt = event.clipboardData;
                if (!dt) return false;
                //ProseMirror内部コピー（data-pm-slice）っぽい場合は、標準貼り付けに任せる
                const htmlData = dt.getData("text/html") || "";
                if (htmlData && htmlData.includes("data-pm-slice")) return false;
                const files = [];
                //(1) clipboardData.files（存在する場合）
                for (const f of Array.from(dt.files ?? [])) {
                    if ((f.type || "").startsWith("image/")) files.push(f);
                }
                //(2) clipboardData.items（多くの環境ではこちらに入る）
                for (const it of Array.from(dt.items ?? [])) {
                    if (it.kind === "file" && (it.type || "").startsWith("image/")) {
                        const f = it.getAsFile();
                        if (f) files.push(f);
                    }
                }
                //重複排除（files と items で同一画像が二重に入るケース対策）
                const uniq = [];
                const seen = new Set();
                for (const f of files) {
                    const key = [f.name, f.type, f.size, f.lastModified].join("|");
                    if (seen.has(key)) continue;
                    seen.add(key);
                    uniq.push(f);
                }
                if (uniq.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    editor.commands.focus();
                    handleFilesInsert(uniq);
                    return true;
                }
                //fileとして取れない場合でも、HTMLに data:image が含まれるケースを救済
                //（例：一部環境のCtrl+Vで画像がHTMLとして入る）
                if (htmlData && htmlData.includes("<img")) {
                    //data:image のsrcだけ抽出（必要最低限）
                    const srcs = [];
                    const reImg = /<img[^>]+src=["']([^"']+)["'][^>]*>/gi;
                    let mm;
                    while ((mm = reImg.exec(htmlData)) !== null) {
                        const src = mm[1] || "";
                        if (src.startsWith("data:image/")) srcs.push(src);
                    }
                    if (srcs.length > 0) {
                        event.preventDefault();
                        event.stopPropagation();
                        editor.commands.focus();
                        (async () => {
                            const files = [];
                            for (const src of srcs.slice(0, 4)) {
                                //最大4枚
                                try {
                                    files.push(dataUrlToFile(src, "paste"));
                                } catch (e) {
                                    console.warn(e);
                                }
                            }
                            if (files.length > 0) handleFilesInsert(files);
                        })();
                        return true;
                    }
                }
                return false;
            },
        },
        element: $mount,
        extensions: [
            StarterKit,
            //★段落ハイライト
            ActiveParagraphHighlight,
            //★複数画像グリッド
            ImageGallery,
            ImageGalleryCleanup,
            //文字色（ColorはTextStyleを前提）
            TextStyle,
            Color,
            //画像
            ImageWithDelete.configure({
                //allowBase64: true, //必要なら有効化
            }),
        ],
        content: initialContent,
        onUpdate: () => updateToolbarState(),
        onSelectionUpdate: () => updateToolbarState(),
    });
    setStatus("編集できます");
    async function handleFilesInsert(files) {
        if (!files || files.length === 0) return;
        //最大4枚まで（1ギャラリーあたり）。必要なら増やせます。
        const picked = Array.from(files).slice(0, 4);
        //まずアップロードしてURLを確定
        const items = [];
        for (const file of picked) {
            try {
                setStatus("画像アップロード中...");
                const url = await uploadImage(file);
                items.push({ url, name: file.name });
            } catch (e) {
                console.error(e);
                setStatus("画像アップロードに失敗しました", String(e?.message ?? e));
            }
        }
        if (items.length === 0) return;
        const getTopLevelBlockPos = () => {
            const { state } = editor;
            const { selection } = state;
            const $from = selection.$from;
            //doc直下のブロック（depth=1）を基準にする（段落の途中に挿入して文章が分割されるのを防ぐ）
            const depth = 1;
            const node = $from.node(depth);
            const from = $from.before(depth);
            const to = $from.after(depth);
            //段落の先頭/末尾情報（カーソルが文章の先頭にある場合は段落前に挿入する）
            const inParagraph = $from.parent?.type?.name === "paragraph";
            const parentOffset = $from.parentOffset ?? 0;
            const atStart = inParagraph && parentOffset === 0;
            const atEnd = inParagraph && parentOffset === ($from.parent?.content?.size ?? 0);
            return { node, from, to, atStart, atEnd };
        };
        const ensureCaretAfterBlock = (afterPos) => {
            try {
                const { state } = editor;
                const $r = state.doc.resolve(afterPos);
                const next = $r.nodeAfter;
                //次が段落なら、その段落の先頭へ移動（余計な空行を増やさない）
                if (next && next.type?.name === "paragraph") {
                    editor
                        .chain()
                        .focus()
                        .setTextSelection(afterPos + 1)
                        .run();
                    return true;
                }
                //次が無い（末尾）または段落以外なら、段落を新規作成してそこへ移動
                editor
                    .chain()
                    .focus()
                    .insertContentAt(afterPos, { type: "paragraph" })
                    .setTextSelection(afterPos + 1)
                    .run();
                return true;
            } catch (e) {
                editor.commands.focus("end");
                return false;
            }
        };
        const getBlockAfterPos = (startPos) => {
            try {
                const n = editor.state.doc.nodeAt(startPos);
                if (!n) return startPos;
                return startPos + n.nodeSize;
            } catch (e) {
                return startPos;
            }
        };
        //既存ギャラリーが近くにある場合は「追記」する（最大4枚）
        const tryAppendToGallery = (items) => {
            const { state } = editor;
            const { selection } = state;
            const $from = selection.$from;
            //(0) 選択中が画像ノード（NodeSelection）の場合：その画像をギャラリー化して追記（最大4枚）
            if (selection.node && selection.node.type.name === "image") {
                const imgNode = selection.node;
                const room = 4 - 1;
                const add = items.slice(0, Math.max(0, room));
                const rest = items.slice(add.length);
                if (add.length > 0) {
                    const gallery = {
                        type: "imageGallery",
                        content: [{ type: "image", attrs: { src: imgNode.attrs.src, alt: imgNode.attrs.alt ?? "" } }, ...add.map((it) => ({ type: "image", attrs: { src: it.url, alt: it.name } }))],
                    };
                    const from = selection.from;
                    editor
                        .chain()
                        .focus()
                        .deleteRange({ from, to: from + imgNode.nodeSize })
                        .insertContentAt(from, gallery)
                        .run();
                    const afterPos = getBlockAfterPos(from);
                    return { appended: true, rest, afterPos };
                }
                return { appended: false, rest, afterPos: selection.to };
            }
            //(A) カーソルが imageGallery の中にある場合：そのギャラリーに追記
            for (let d = $from.depth; d > 0; d--) {
                const n = $from.node(d);
                if (n?.type?.name === "imageGallery") {
                    const posBefore = $from.before(d);
                    const room = 4 - n.childCount;
                    const add = items.slice(0, Math.max(0, room));
                    const rest = items.slice(add.length);
                    if (add.length > 0) {
                        //ギャラリー末尾（閉じ括弧直前）
                        const insertPos = posBefore + n.nodeSize - 1;
                        editor
                            .chain()
                            .focus()
                            .insertContentAt(
                                insertPos,
                                add.map((it) => ({
                                    type: "image",
                                    attrs: { src: it.url, alt: it.name },
                                })),
                            )
                            .run();
                    }
                    const afterPos = getBlockAfterPos(posBefore);
                    return { appended: add.length > 0, rest, afterPos };
                }
            }
            //(B) 直前（空段落はスキップ）のトップレベルノードが imageGallery なら、そのギャラリーに追記
            try {
                //現在トップレベルブロックの開始位置
                const startPos = $from.before(1);
                let pos = startPos;
                while (pos > 0) {
                    const info = state.doc.childBefore(pos);
                    const prevNode = info?.node;
                    if (!prevNode) break;
                    //空段落はスキップ（当エディタが自動で挿入するカーソル保持行を“区切り”とみなさない）
                    if (prevNode.type.name === "paragraph" && prevNode.content.size === 0) {
                        pos = pos - prevNode.nodeSize;
                        continue;
                    }
                    if (prevNode.type.name === "imageGallery") {
                        const prevPos = pos - prevNode.nodeSize;
                        const room = 4 - prevNode.childCount;
                        const add = items.slice(0, Math.max(0, room));
                        const rest = items.slice(add.length);
                        if (add.length > 0) {
                            const insertPos = prevPos + prevNode.nodeSize - 1;
                            editor
                                .chain()
                                .focus()
                                .insertContentAt(
                                    insertPos,
                                    add.map((it) => ({
                                        type: "image",
                                        attrs: { src: it.url, alt: it.name },
                                    })),
                                )
                                .run();
                        }
                        const afterPos = getBlockAfterPos(prevPos);
                        return { appended: add.length > 0, rest, afterPos };
                    }
                    //直前が単独 image の場合は、まとめてギャラリー化（最大4枚）
                    if (prevNode.type.name === "image" && 1 + items.length <= 4) {
                        const prevPos = pos - prevNode.nodeSize;
                        const prevAttrs = prevNode.attrs || {};
                        const gallery = {
                            type: "imageGallery",
                            content: [{ type: "image", attrs: { src: prevAttrs.src, alt: prevAttrs.alt ?? "" } }, ...items.map((it) => ({ type: "image", attrs: { src: it.url, alt: it.name } }))],
                        };
                        editor
                            .chain()
                            .focus()
                            .deleteRange({ from: prevPos, to: prevPos + prevNode.nodeSize })
                            .insertContentAt(prevPos, gallery)
                            .run();
                        const afterPos = getBlockAfterPos(prevPos);
                        return { appended: true, rest: [], afterPos };
                    }
                    break;
                }
            } catch (e) {
                console.warn(e);
            }
            return { appended: false, rest: items, afterPos: null };
        };
        const r = tryAppendToGallery(items);
        //追記できた場合：カーソルをブロックの後ろへ（位置計算は doc.nodeSize を参照するためズレにくい）
        if (r.appended && typeof r.afterPos === "number") {
            ensureCaretAfterBlock(r.afterPos);
        }
        //追記できなかった／追記しきれなかった分
        const rest = r.rest || [];
        if (rest.length === 0) {
            setStatus("画像を挿入しました");
            return;
        }
        //挿入位置：段落の途中に入れて文章を分割しない（トップレベルブロック境界へ）
        const { node: topNode, from: topFrom, to: topTo, atStart } = getTopLevelBlockPos();
        //空段落（カーソル保持行）なら置換
        if (topNode?.type?.name === "paragraph" && topNode.content.size === 0) {
            editor.chain().focus().deleteRange({ from: topFrom, to: topTo }).run();
            const insertPos = topFrom;
            if (rest.length === 1) {
                const it = rest[0];
                editor
                    .chain()
                    .focus()
                    .insertContentAt(insertPos, {
                        type: "image",
                        attrs: { src: it.url, alt: it.name },
                    })
                    .run();
                ensureCaretAfterBlock(getBlockAfterPos(insertPos));
                setStatus("画像を挿入しました");
                return;
            }
            editor
                .chain()
                .focus()
                .insertContentAt(insertPos, {
                    type: "imageGallery",
                    content: rest.map((it) => ({ type: "image", attrs: { src: it.url, alt: it.name } })),
                })
                .run();
            ensureCaretAfterBlock(getBlockAfterPos(insertPos));
            setStatus("画像を挿入しました");
            return;
        }
        //文章段落の先頭にカーソルがある場合は、段落の前に挿入（段落分割を防止しつつ、意図位置に入る）
        const insertPos = topNode?.type?.name === "paragraph" && atStart ? topFrom : topTo;
        if (rest.length === 1) {
            const it = rest[0];
            editor
                .chain()
                .focus()
                .insertContentAt(insertPos, {
                    type: "image",
                    attrs: { src: it.url, alt: it.name },
                })
                .run();
            ensureCaretAfterBlock(getBlockAfterPos(insertPos));
            setStatus("画像を挿入しました");
            return;
        }
        editor
            .chain()
            .focus()
            .insertContentAt(insertPos, {
                type: "imageGallery",
                content: rest.map((it) => ({ type: "image", attrs: { src: it.url, alt: it.name } })),
            })
            .run();
        ensureCaretAfterBlock(getBlockAfterPos(insertPos));
        setStatus("画像を挿入しました");
    }
    function openDropzone() {
        //エディタ選択位置は保持されるので、後で focus() して挿入します
        if (!$dropzoneOverlay || !$dropzoneCard) {
            //ドロップゾーンがないページではファイル選択ダイアログに直接フォールバック
            if ($imageInput) $imageInput.click();
            return;
        }
        $dropzoneOverlay.hidden = false;
        $dropzoneCard.classList.remove("is-dragover");
    }
    function closeDropzone() {
        if (!$dropzoneOverlay || !$dropzoneCard) return;
        $dropzoneOverlay.hidden = true;
        $dropzoneCard.classList.remove("is-dragover");
    }
    if ($dropzoneOverlay && $dropzoneCard && $dropzonePick && $dropzoneClose) {
        //Dropzone: クリックでファイル選択
        $dropzonePick.addEventListener("click", () => $imageInput.click());
        $dropzoneCard.addEventListener("click", (e) => {
            //ボタン押下は除外（ボタン側で処理）
            if (e.target.closest("button")) return;
            $imageInput.click();
        });
        $dropzoneCard.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                $imageInput.click();
            }
        });
        //Dropzone: 閉じる
        $dropzoneClose.addEventListener("click", closeDropzone);
        $dropzoneOverlay.addEventListener("click", (e) => {
            //背景クリックで閉じる（カード内は閉じない）
            if (e.target === $dropzoneOverlay) closeDropzone();
        });
        window.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !$dropzoneOverlay.hidden) closeDropzone();
        });
        //Dropzone: drag & drop
        ["dragenter", "dragover"].forEach((type) => {
            $dropzoneOverlay.addEventListener(type, (e) => {
                e.preventDefault();
                e.stopPropagation();
                $dropzoneCard.classList.add("is-dragover");
            });
        });
        ["dragleave", "dragend"].forEach((type) => {
            $dropzoneOverlay.addEventListener(type, (e) => {
                e.preventDefault();
                e.stopPropagation();
                $dropzoneCard.classList.remove("is-dragover");
            });
        });
        $dropzoneOverlay.addEventListener("drop", async (e) => {
            e.preventDefault();
            e.stopPropagation();
            $dropzoneCard.classList.remove("is-dragover");
            const files = Array.from(e.dataTransfer?.files ?? []).filter((f) => f.type?.startsWith("image/"));
            if (files.length === 0) return;
            closeDropzone();
            await handleFilesInsert(files);
        });
    }
    function cmd(action) {
        const chain = editor.chain().focus();
        switch (action) {
            case "bold":
                return chain.toggleBold().run();
            case "italic":
                return chain.toggleItalic().run();
            case "strike":
                return chain.toggleStrike().run();
            case "h1":
                return chain.toggleHeading({ level: 1 }).run();
            case "h2":
                return chain.toggleHeading({ level: 2 }).run();
            case "h3":
                return chain.toggleHeading({ level: 3 }).run();
            case "h4":
                return chain.toggleHeading({ level: 4 }).run();
            case "h5":
                return chain.toggleHeading({ level: 5 }).run();
            case "h6":
                return chain.toggleHeading({ level: 6 }).run();
            case "bullet":
                return chain.toggleBulletList().run();
            case "ordered":
                return chain.toggleOrderedList().run();
            case "imageUpload":
                openDropzone();
                return true;
            case "unsetColor":
                return chain.unsetColor().run();
            default:
                return false;
        }
    }
    function updateToolbarState() {
        if (!$toolbar) return;
        const map = {
            bold: editor.isActive("bold"),
            italic: editor.isActive("italic"),
            strike: editor.isActive("strike"),
            h1: editor.isActive("heading", { level: 1 }),
            h2: editor.isActive("heading", { level: 2 }),
            h3: editor.isActive("heading", { level: 3 }),
            h4: editor.isActive("heading", { level: 4 }),
            h5: editor.isActive("heading", { level: 5 }),
            h6: editor.isActive("heading", { level: 6 }),
            bullet: editor.isActive("bulletList"),
            ordered: editor.isActive("orderedList"),
        };
        $toolbar.querySelectorAll("button[data-cmd]").forEach((btn) => {
            const k = btn.getAttribute("data-cmd");
            if (k && map[k] !== undefined) {
                btn.classList.toggle("is-active", !!map[k]);
            }
        });
        if ($textColor) {
            const cur = editor.getAttributes("textStyle")?.color;
            $textColor.value = cur || "#000000";
        }
    }
    updateToolbarState();
    if ($toolbar) {
        $toolbar.addEventListener("click", (e) => {
            const btn = e.target.closest("button[data-cmd]");
            if (!btn) return;
            const action = btn.getAttribute("data-cmd");
            if (action) cmd(action);
        });
    }
    if ($textColor) {
        $textColor.addEventListener("input", (e) => {
            const color = e.target.value;
            editor.chain().focus().setColor(color).run();
            updateToolbarState();
        });
    }
    //画像：ファイル選択
    if ($imageInput) {
        $imageInput.addEventListener("change", async () => {
            const files = Array.from($imageInput.files ?? []);
            //同じファイルを連続選択できるようにする
            $imageInput.value = "";
            if (files.length === 0) return;
            closeDropzone();
            await handleFilesInsert(files);
        });
    }
    //ページ側（保存JSなど）から参照できるように公開
    window.TIPTAP_EDITOR = editor;
    setStatus("準備完了");
    window.dispatchEvent(new CustomEvent("tt:tiptap-ready"));
}
