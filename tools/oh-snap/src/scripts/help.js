/**
 * OH SNAP! — Offline Help modal (? button + F1).
 *
 * SNAPSMACK_EOF_HEADER: preserve canonical EOF marker.
 */
(function () {
    "use strict";

    const TOPICS = [
        [
            "What OH SNAP does",
            "Designs and checks skins, the look of a SnapSmack site. Inspect a skin, check it is valid, render a preview, and export it."
        ],
        [
            "First run",
            "Open a skin project, or start a new one. Render a preview to see how it looks before you export."
        ],
        [
            "Main actions",
            "Inspect, validate, render, export. Export still needs the normal review and signing before a skin can be used."
        ],
        [
            "Where your data lives",
            "Skin projects live on your computer. Exported skins go through the normal review and signing step."
        ]
    ];

    function buildHelpUi() {
        if (document.getElementById("ohsnap-help-btn")) return;

        const btn = document.createElement("button");
        btn.type = "button";
        btn.id = "ohsnap-help-btn";
        btn.className = "btn";
        btn.textContent = "Help (F1)";
        btn.title = "Open OH SNAP Help (F1)";
        btn.style.cssText = "position:fixed;top:12px;right:14px;z-index:9000;";
        document.body.appendChild(btn);

        const overlay = document.createElement("div");
        overlay.id = "ohsnap-help-modal";
        overlay.hidden = true;
        overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:none;align-items:center;justify-content:center;";

        const card = document.createElement("div");
        card.style.cssText = "width:min(720px,92vw);max-height:80vh;background:#121512;color:#eaeaea;border:1px solid #3a4;border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:10px;";

        const header = document.createElement("div");
        header.style.cssText = "display:flex;justify-content:space-between;align-items:center;";
        const title = document.createElement("strong");
        title.textContent = "OH SNAP — Help";
        title.style.color = "#9f3";
        const closeBtn = document.createElement("button");
        closeBtn.type = "button";
        closeBtn.className = "btn";
        closeBtn.textContent = "Close";
        header.appendChild(title);
        header.appendChild(closeBtn);

        const search = document.createElement("input");
        search.type = "search";
        search.placeholder = "Search help…";
        search.style.cssText = "padding:8px;background:#1a1e1a;color:#eee;border:1px solid #333;border-radius:6px;";

        const list = document.createElement("div");
        list.style.cssText = "overflow:auto;display:flex;flex-direction:column;gap:10px;";

        card.appendChild(header);
        card.appendChild(search);
        card.appendChild(list);
        overlay.appendChild(card);
        document.body.appendChild(overlay);

        function render(q) {
            const query = (q || "").trim().toLowerCase();
            list.innerHTML = "";
            const hits = TOPICS.filter(([t, b]) => !query || t.toLowerCase().includes(query) || b.toLowerCase().includes(query));
            if (!hits.length) {
                const empty = document.createElement("p");
                empty.textContent = "No help topics match that search.";
                empty.style.color = "#999";
                list.appendChild(empty);
                return;
            }
            for (const [t, b] of hits) {
                const item = document.createElement("div");
                item.style.cssText = "padding:10px;background:#181c18;border-radius:6px;";
                const h = document.createElement("div");
                h.textContent = t;
                h.style.cssText = "font-weight:700;color:#9f3;margin-bottom:4px;";
                const p = document.createElement("div");
                p.textContent = b;
                p.style.cssText = "white-space:pre-wrap;line-height:1.45;";
                item.appendChild(h);
                item.appendChild(p);
                list.appendChild(item);
            }
        }

        function openHelp() {
            render(search.value);
            overlay.style.display = "flex";
            overlay.hidden = false;
            search.focus();
        }

        function closeHelp() {
            overlay.style.display = "none";
            overlay.hidden = true;
        }

        btn.addEventListener("click", openHelp);
        closeBtn.addEventListener("click", closeHelp);
        search.addEventListener("input", () => render(search.value));
        document.addEventListener("keydown", (e) => {
            if (e.key === "F1") {
                e.preventDefault();
                openHelp();
            } else if (e.key === "Escape" && !overlay.hidden) {
                closeHelp();
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", buildHelpUi);
    } else {
        buildHelpUi();
    }
})();
// ===== SNAPSMACK EOF =====
