{{-- TxWatch im Werkbank-Stil von bright color. The stylesheet public/css/werkbank.css
     loads after Filament's own (render hook HEAD_END); the fonts come from
     public/css/werkbank-fonts.css via the panel's local font provider. The
     scripts below are the page size guard, the loading indicators, drag to
     scroll and column reordering. --}}
<link rel="stylesheet" href="{{ asset('css/werkbank.css') }}?v={{ config('version.number') }}">

<script>
/* Pagination guard: warn before very large page sizes (>= 400 rows). A native
   confirm() popup must be accepted; if declined the select is reverted and the
   change never reaches Livewire. Together with the ClampsRecordsPerPageOnReload
   trait (server side) a confirmed 500 applies only to the current view and is
   reset to 200 on the next reload/revisit - so we never loop on a slow query.
   Only ever intervenes at >= 400; 25-200 are completely untouched. */
(function () {
    if (window.__ppGuardInstalled) return;
    window.__ppGuardInstalled = true;

    var THRESHOLD = 400;
    var prev = new WeakMap();

    function perPageSelect(t) {
        return (t && t.tagName === 'SELECT' && t.closest('.fi-pagination-records-per-page-select')) ? t : null;
    }

    document.addEventListener('focusin', function (e) {
        var s = perPageSelect(e.target);
        if (s) prev.set(s, s.value);
    }, true);

    function revert(e, s) {
        e.stopImmediatePropagation();
        e.preventDefault();
        var p = prev.get(s);
        s.value = (p !== undefined ? p : '200');
    }

    function guard(e) {
        var s = perPageSelect(e.target);
        if (!s) return;

        var v = parseInt(s.value, 10);
        if (isNaN(v) || v < THRESHOLD) { prev.set(s, s.value); return; }

        if (s.dataset.ppOk === s.value) return;               // already confirmed this size
        if (s.dataset.ppNo === s.value) { revert(e, s); return; } // declined within this event pair

        var ok = window.confirm(v + ' Zeilen pro Seite zu laden dauert deutlich länger und '
            + 'belastet den Server stark.\n\nBeim nächsten Neuladen der Seite wird automatisch '
            + 'wieder auf 200 begrenzt.\n\nTrotzdem laden?');

        if (ok) {
            s.dataset.ppOk = s.value;
            delete s.dataset.ppNo;
            prev.set(s, s.value);
        } else {
            s.dataset.ppNo = s.value;
            revert(e, s);
            setTimeout(function () { if (s.dataset.ppNo === String(v)) delete s.dataset.ppNo; }, 0);
        }
    }

    document.addEventListener('input', guard, true);
    document.addEventListener('change', guard, true);
})();
</script>

<script>
/* Global loading bar: shows the thin top bar (#ak-progress) during any Livewire
   request that lasts longer than a short threshold, so "loading / applying"
   never looks like a frozen UI. Uses a 180ms delay so instant requests don't
   flicker, and a counter so overlapping requests keep it visible until the last
   one finishes. Livewire's own bar still handles wire:navigate page loads. */
(function () {
    if (window.__akProgressInstalled) return;
    window.__akProgressInstalled = true;

    var bar = null, overlay = null, active = 0, barTimer = null, longTimer = null;
    var LONG_MS = 4000; // after this long, escalate to the centred "one moment" card

    function ensureBar() {
        if (bar && document.body.contains(bar)) return bar;
        bar = document.createElement('div');
        bar.id = 'ak-progress';
        document.body.appendChild(bar);
        return bar;
    }
    function show() { ensureBar().classList.add('ak-progress-active'); }
    function hide() { if (bar) bar.classList.remove('ak-progress-active'); }

    function ensureOverlay() {
        if (overlay && document.body.contains(overlay)) return overlay;
        overlay = document.createElement('div');
        overlay.id = 'ak-longload';
        overlay.innerHTML =
            '<div class="ak-longload-card" role="status" aria-live="polite">'
            + '<div class="ak-spinner"></div>'
            + '<div class="ak-longload-title">Einen Moment noch …</div>'
            + '<div class="ak-longload-text">Die Aktion wird verarbeitet. Gerade Exporte können ein paar Sekunden dauern – bitte nicht schließen.</div>'
            + '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }
    function showOverlay() { ensureOverlay().classList.add('ak-longload-active'); }
    function hideOverlay() { if (overlay) overlay.classList.remove('ak-longload-active'); }

    function start() {
        active++;
        if (active === 1) {
            clearTimeout(barTimer);
            barTimer = setTimeout(show, 180);
            clearTimeout(longTimer);
            longTimer = setTimeout(showOverlay, LONG_MS);
        }
    }
    function stop() {
        active = Math.max(0, active - 1);
        if (active === 0) {
            clearTimeout(barTimer); barTimer = null; hide();
            clearTimeout(longTimer); longTimer = null; hideOverlay();
        }
    }

    document.addEventListener('livewire:init', function () {
        if (typeof Livewire === 'undefined' || ! Livewire.hook) return;

        Livewire.hook('commit', function (payload) {
            start();
            var done = false;
            var finish = function () { if (done) return; done = true; stop(); };
            // Register on every completion callback the payload offers; the
            // done-guard means only the first one counts. Wrapped so an
            // unexpected payload shape can never break the Livewire commit.
            try {
                ['respond', 'succeed', 'fail'].forEach(function (k) {
                    if (payload && typeof payload[k] === 'function') payload[k](finish);
                });
            } catch (e) { /* ignore */ }
            // Fallback so the indicators can never get stuck if no callback
            // fires. Generous (60s) so genuinely long exports keep the overlay
            // the whole time, while a real hang still clears eventually.
            setTimeout(finish, 60000);
        });
    });

    // Safety net: if a full page navigation happens, reset all loading state.
    document.addEventListener('livewire:navigated', function () {
        active = 0;
        clearTimeout(barTimer); barTimer = null; hide();
        clearTimeout(longTimer); longTimer = null; hideOverlay();
    });
})();
</script>

<script>
/* Drag-to-scroll for wide tables: grab ANYWHERE on a row (incl. over links and
   action buttons) and drag to pan a horizontally scrollable table. A precise
   click still follows the record link / triggers the button; only a real drag
   (moved > a few px) pans - and then the click that would follow is swallowed,
   so accidentally starting on a link/button and dragging never opens/triggers
   it. Only text-entry controls (input/select/textarea) are left untouched so
   you can still select/type in them. */
(function () {
    if (window.__akDragScrollInstalled) return;
    window.__akDragScrollInstalled = true;

    var SCROLLER = '.fi-ta-content';
    // Only real text-entry controls block a drag; links & buttons are grabbable.
    // `thead` is excluded so column-header dragging (reordering) works there.
    var NODRAG = 'input, select, textarea, [contenteditable], thead';
    var drag = null;
    var suppressClick = false, suppressTimer = null;

    // Persistent capture-phase guard: eats the single click that follows a real
    // drag (before it reaches the link's/button's own handler), then steps
    // aside. A safety timeout clears the flag if no click ever fires. This is
    // robust regardless of exact event timing (the old setTimeout(0) cleanup
    // could miss the click and let the link open).
    document.addEventListener('click', function (e) {
        if (suppressClick) {
            suppressClick = false;
            clearTimeout(suppressTimer);
            e.stopPropagation();
            e.preventDefault();
        }
    }, true);

    // Stop the browser's native link/text drag-ghost while we pan.
    document.addEventListener('dragstart', function (e) { if (drag) e.preventDefault(); }, true);

    document.addEventListener('mousedown', function (e) {
        if (e.button !== 0) return; // left button only
        suppressClick = false;      // fresh interaction
        var el = e.target.closest && e.target.closest(SCROLLER);
        if (! el) return;
        if (el.scrollWidth <= el.clientWidth && el.scrollHeight <= el.clientHeight) return; // nothing to pan
        if (e.target.closest && e.target.closest(NODRAG)) return;
        drag = { el: el, x: e.clientX, y: e.clientY, left: el.scrollLeft, top: el.scrollTop, moved: false };
    });

    document.addEventListener('mousemove', function (e) {
        if (! drag) return;
        var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
        if (! drag.moved) {
            if (Math.abs(dx) < 5 && Math.abs(dy) < 5) return; // threshold: below this it's a click
            drag.moved = true;
            document.body.classList.add('ak-drag-scrolling');
        }
        e.preventDefault();
        drag.el.scrollLeft = drag.left - dx;
        drag.el.scrollTop = drag.top - dy;
    });

    function end() {
        if (! drag) return;
        if (drag.moved) {
            document.body.classList.remove('ak-drag-scrolling');
            suppressClick = true; // swallow the click that immediately follows
            clearTimeout(suppressTimer);
            suppressTimer = setTimeout(function () { suppressClick = false; }, 400);
        }
        drag = null;
    }
    document.addEventListener('mouseup', end);
    window.addEventListener('blur', end);

    // Grab cursor hint on tables that actually overflow horizontally.
    function markGrabbable() {
        document.querySelectorAll(SCROLLER).forEach(function (el) {
            el.classList.toggle('ak-grabbable', el.scrollWidth > el.clientWidth);
        });
    }
    window.addEventListener('resize', markGrabbable);
    document.addEventListener('livewire:navigated', function () { setTimeout(markGrabbable, 60); });
    document.addEventListener('livewire:init', function () {
        if (window.Livewire && Livewire.hook) {
            Livewire.hook('commit', function (p) {
                try { if (typeof p.succeed === 'function') p.succeed(function () { setTimeout(markGrabbable, 60); }); } catch (e) {}
            });
        }
    });
    setTimeout(markGrabbable, 400);
})();
</script>

<script>
/* Column reordering for tables (Filament 3.3 has no native support): drag a
   column HEADER onto another column to move it there. Order is stored per user
   in localStorage and re-applied after every table re-render (Livewire morphs
   the cells back into source order positionally, so we re-apply AFTER each
   commit - and because whole <td>s are moved, cell contents always travel with
   their column). The selection checkbox + row-actions columns stay pinned. */
(function () {
    if (window.__akColReorderInstalled) return;
    window.__akColReorderInstalled = true;

    function allTables() { return [].slice.call(document.querySelectorAll('.fi-ta-table')); }
    function tableIndex(table) { return allTables().indexOf(table); }
    function storeKey(idx) { return 'akColOrder:' + location.pathname + '#' + idx; }
    function loadOrder(idx) { try { return JSON.parse(localStorage.getItem(storeKey(idx)) || 'null'); } catch (e) { return null; } }
    function saveOrder(idx, order) { try { localStorage.setItem(storeKey(idx), JSON.stringify(order)); } catch (e) {} }

    function cellKey(cell) {
        var m = (cell.className || '').match(/fi-table-cell-([A-Za-z0-9._-]+)/);
        return m ? m[1] : null;
    }
    function isSelection(c) { return c.classList.contains('fi-ta-selection-cell'); }
    function isActions(c) { return c.classList.contains('fi-ta-actions-cell'); }

    // Tag header + body cells with data-akcol (key from a body cell's
    // fi-table-cell-<name> class) and make data headers draggable.
    function tag(table) {
        var headRow = table.querySelector('thead tr');
        if (! headRow) return null;
        var headCells = [].slice.call(headRow.children);
        var refRow = table.querySelector('tbody tr');
        var keys = [];
        for (var i = 0; i < headCells.length; i++) {
            var key = (refRow && refRow.children[i]) ? cellKey(refRow.children[i]) : null;
            if (! key) key = 'col' + i;
            keys.push(key);
            var th = headCells[i];
            th.dataset.akcol = key;
            if (! isSelection(th) && ! isActions(th)) {
                th.setAttribute('draggable', 'true');
                th.classList.add('ak-col-draggable');
            }
        }
        [].slice.call(table.querySelectorAll('tbody tr')).forEach(function (row) {
            [].slice.call(row.children).forEach(function (c, i) { c.dataset.akcol = keys[i] || ('col' + i); });
        });
        return keys;
    }

    function reorderRow(row, order) {
        if (! row) return;
        var cells = [].slice.call(row.children);
        var selection = cells.filter(isSelection)[0];
        var actions = cells.filter(isActions)[0];
        var byKey = {};
        cells.forEach(function (c) { if (c !== selection && c !== actions) byKey[c.dataset.akcol] = c; });
        var ordered = order.map(function (k) { return byKey[k]; }).filter(Boolean);
        cells.forEach(function (c) { if (c !== selection && c !== actions && ordered.indexOf(c) === -1) ordered.push(c); });
        var seq = [];
        if (selection) seq.push(selection);
        seq = seq.concat(ordered);
        if (actions) seq.push(actions);
        seq.forEach(function (c) { row.appendChild(c); });
    }

    function currentOrder(table) {
        var headRow = table.querySelector('thead tr');
        if (! headRow) return [];
        return [].slice.call(headRow.children)
            .filter(function (c) { return c.classList.contains('ak-col-draggable'); })
            .map(function (c) { return c.dataset.akcol; });
    }
    function sameOrder(a, b) { return a.length === b.length && a.every(function (k, i) { return k === b[i]; }); }

    function applyOrder(table, order) {
        if (sameOrder(currentOrder(table), order)) return;
        reorderRow(table.querySelector('thead tr'), order);
        [].slice.call(table.querySelectorAll('tbody tr')).forEach(function (row) { reorderRow(row, order); });
    }

    function moveColumn(table, from, targetKey, after) {
        if (from === targetKey) return;
        var order = currentOrder(table);
        var f = order.indexOf(from);
        if (f === -1) return;
        order.splice(f, 1);
        var to = order.indexOf(targetKey);
        if (to === -1) order.push(from);
        else order.splice(after ? to + 1 : to, 0, from);
        saveOrder(tableIndex(table), order);
        applyOrder(table, order);
    }

    var refreshTimer = null;
    function refresh() {
        allTables().forEach(function (table, idx) {
            if (! tag(table)) return;
            var saved = loadOrder(idx);
            if (saved && saved.length) applyOrder(table, saved);
        });
    }
    function scheduleRefresh() { clearTimeout(refreshTimer); refreshTimer = setTimeout(refresh, 60); }

    // ----- drag interaction on the (draggable) header cells -----
    var srcKey = null, srcTable = null;
    function clearIndicators() {
        [].slice.call(document.querySelectorAll('.ak-col-dropbefore, .ak-col-dropafter'))
            .forEach(function (e) { e.classList.remove('ak-col-dropbefore', 'ak-col-dropafter'); });
    }
    document.addEventListener('dragstart', function (e) {
        var th = e.target.closest && e.target.closest('.ak-col-draggable');
        if (! th) return;
        srcKey = th.dataset.akcol;
        srcTable = th.closest('.fi-ta-table');
        th.classList.add('ak-col-dragging');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', srcKey); } catch (x) {}
    }, true);
    document.addEventListener('dragover', function (e) {
        if (srcKey === null) return;
        var th = e.target.closest && e.target.closest('.ak-col-draggable');
        if (! th || th.closest('.fi-ta-table') !== srcTable) return;
        e.preventDefault();
        try { e.dataTransfer.dropEffect = 'move'; } catch (x) {}
        clearIndicators();
        var r = th.getBoundingClientRect();
        th.classList.add((e.clientX > r.left + r.width / 2) ? 'ak-col-dropafter' : 'ak-col-dropbefore');
    });
    document.addEventListener('drop', function (e) {
        if (srcKey === null) return;
        var th = e.target.closest && e.target.closest('.ak-col-draggable');
        if (th && th.closest('.fi-ta-table') === srcTable) {
            e.preventDefault();
            var r = th.getBoundingClientRect();
            moveColumn(srcTable, srcKey, th.dataset.akcol, e.clientX > r.left + r.width / 2);
        }
        srcKey = null; srcTable = null; clearIndicators();
    });
    document.addEventListener('dragend', function () {
        srcKey = null; srcTable = null; clearIndicators();
        [].slice.call(document.querySelectorAll('.ak-col-dragging')).forEach(function (e) { e.classList.remove('ak-col-dragging'); });
    });

    document.addEventListener('livewire:navigated', scheduleRefresh);
    document.addEventListener('livewire:init', function () {
        if (window.Livewire && Livewire.hook) {
            Livewire.hook('commit', function (p) {
                try { if (typeof p.succeed === 'function') p.succeed(scheduleRefresh); } catch (e) {}
            });
        }
    });
    setTimeout(refresh, 500);
})();
</script>
