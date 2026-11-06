<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Browser side of the plugin's GLPI tables (AuditTable): the filter row, the request state behind
 * GLPI's reloadTab() calls, and the Columns menu (kept per table in this browser). Shared by the
 * audit evidence and backup objects pages. GLPI defines reloadTab() only on item tabs, so each page
 * defines its own and uses step() to turn the call into its next request.
 */
final class TableUi
{
    /** window.backupinfoTable = {step, query, applyCols, colMenu, bindFilters} */
    public static function script(): string
    {
        return <<<'JS'
window.backupinfoTable = window.backupinfoTable || (() => {
    const hidden = (store) => { try { return new Set(JSON.parse(localStorage.getItem(store) || "[]")); } catch (e) { return new Set(); } };
    const save = (store, set) => { try { localStorage.setItem(store, JSON.stringify([...set])); } catch (e) {} };
    const labels = (root) => {
        const head = root.querySelector("table thead tr:not(.filter_row)");
        if (!head) { return []; }
        return [...head.children].filter((th) => !th.querySelector(".show_filters")).map((th) => th.textContent.trim());
    };
    const applyCols = (store, root) => {
        const names = labels(root), off = hidden(store);
        root.querySelectorAll("table tr").forEach((tr) => {
            const shift = tr.classList.contains("filter_row") ? 1 : 0;
            if (tr.children.length < names.length) { return; }
            names.forEach((name, i) => { const cell = tr.children[i + shift]; if (cell) { cell.style.display = off.has(name) ? "none" : ""; } });
        });
    };
    const colMenu = (store, root, menu, showAllText) => {
        const names = labels(root), off = hidden(store);
        menu.textContent = "";
        names.forEach((name) => {
            const label = document.createElement("label"), box = document.createElement("input");
            label.className = "dropdown-item d-flex align-items-center gap-2"; // no .form-check: its negative margin pushes the box out of the menu
            box.type = "checkbox"; box.className = "form-check-input m-0 flex-shrink-0"; box.checked = !off.has(name);
            box.addEventListener("change", () => { const h = hidden(store); box.checked ? h.delete(name) : h.add(name); save(store, h); applyCols(store, root); });
            label.append(box, document.createTextNode(name));
            menu.appendChild(label);
        });
        if (names.length) {
            const all = document.createElement("button");
            all.type = "button"; all.className = "btn btn-sm btn-link w-100 mt-1"; all.textContent = showAllText;
            all.addEventListener("click", () => { save(store, new Set()); colMenu(store, root, menu, showAllText); applyCols(store, root); });
            menu.appendChild(all);
        }
    };
    // One reloadTab() call (sort, pages, page size, filter row open/closed, filters) -> next state.
    const step = (st, add) => {
        const p = new URLSearchParams(add || ""), next = Object.assign({}, st);
        if (!add) { next.filters = ""; next.start = 0; }
        const f = new URLSearchParams();
        p.forEach((v, k) => { if (k.startsWith("filters[")) { f.append(k, v); } });
        if ([...f.keys()].length) { next.filters = f.toString(); next.start = 0; }
        if (p.has("sort")) { next.sort = p.get("sort"); next.order = p.get("order") || "ASC"; }
        if (p.has("start")) { next.start = parseInt(p.get("start"), 10) || 0; }
        if (p.has("glpilist_limit")) { next.limit = p.get("glpilist_limit"); next.start = 0; }
        return next;
    };
    const query = (st) => {
        const q = new URLSearchParams(st.filters || "");
        if (st.sort) { q.set("sort", st.sort); q.set("order", st.order || "ASC"); }
        if (st.start) { q.set("start", String(st.start)); }
        if (st.limit) { q.set("glpilist_limit", st.limit); }
        return q.toString();
    };
    // The filter row, as GLPI's log_filters.js does it for item tabs, scoped to one container.
    const bindFilters = ($root, reload) => {
        let timer = null;
        const changed = () => { clearTimeout(timer); reload($root.find(".filter_row [name^=\"filters[\"]").serialize()); };
        $root.on("click", ".show_filters", (ev) => { ev.preventDefault(); reload($(ev.currentTarget).hasClass("active") ? "" : "filters[active]=1"); });
        $root.on("select2:unselecting", ".filter_row .select2-hidden-accessible", (ev) => {
            if (ev.params && ev.params.args && ev.params.args.originalEvent) { ev.params.args.originalEvent.stopPropagation(); }
        });
        $root.on("input", ".filter_row input[name^=\"filters[\"]", () => { clearTimeout(timer); timer = setTimeout(changed, 800); });
        $root.on("change", ".filter_row select[name^=\"filters[\"]", changed);
        $root.on("keypress", ".filter_row [name^=\"filters[\"]", (ev) => { if (ev.key === "Enter") { ev.preventDefault(); changed(); } });
    };
    return {step, query, applyCols, colMenu, bindFilters};
})();
JS;
    }

    /** The Columns button and its (filled by colMenu) menu. */
    public static function columnsButton(string $menuId): string
    {
        return '<div class="dropdown"><button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">'
            . '<i class="ti ti-columns"></i> ' . __s('Columns', 'backupinfo') . '</button>'
            . '<div class="dropdown-menu dropdown-menu-end p-2" style="max-height: 60vh; overflow-y: auto;" id="' . htmlescape($menuId) . '"></div></div>';
    }
}
