{{-- AdminLTE-style theme for Filament: classic light admin panel with a dark
     sidebar, colored stat boxes, cards with an accent top border, striped
     compact tables and strong badges. Injected via render hook so no
     Tailwind/theme build step is needed. Replaces the earlier compact-styles
     (its density rules are folded in here). --}}
<style>
    :root {
        --lte-sidebar: #343a40;
        --lte-sidebar-hover: #494e53;
        --lte-sidebar-active: #007bff;
        --lte-body: #f4f6f9;
        --lte-card-border: #007bff;
    }

    /* ===== Page canvas (light-only surfaces; dark mode keeps Filament's) ===== */
    html:not(.dark) .fi-body { background-color: var(--lte-body) !important; }
    /* Tight top: push the page heading ("Dashboard" & co.) right up under the
       topbar - almost no empty space above it. */
    .fi-main { padding: .1rem 1rem .7rem !important; gap: .6rem !important; }
    .fi-main > * + * { margin-top: .7rem !important; }
    .fi-page { gap: .6rem !important; }
    /* tighter page header (title + actions) */
    .fi-header { margin-bottom: 0 !important; padding-top: 0 !important; }
    .fi-header-heading { font-size: 1.25rem !important; }
    /* Breadcrumbs only for real multi-level drill-down (Edit/View/Create record
       pages). On Dashboard, list/index pages ("Transaktionen > Übersicht") and
       settings pages the trail is redundant - hide by default, show only on the
       record pages you actually navigated down into. */
    .fi-breadcrumbs { display: none !important; }
    .fi-resource-edit-record-page .fi-breadcrumbs,
    .fi-resource-view-record-page .fi-breadcrumbs,
    .fi-resource-create-record-page .fi-breadcrumbs { display: flex !important; }

    /* Page heading on the SAME ROW as the topbar search (desktop only). The
       whole header is lifted ~84px into the empty left part of the topbar so the
       title/breadcrumb sits at search height and the page content moves up.
       Verified live (heading center 30px ≈ search center 32px). Guards:
       - pointer-events:none on the header + auto on its children: the lifted
         header spans full width on top of the topbar, so without this it would
         SWALLOW clicks on the search/avatar (confirmed). Now only the heading &
         buttons are clickable, empty header area passes clicks through.
       - the 2nd header child (actions bar) is pushed back down by the same 84px,
         but ONLY when it actually contains buttons/links (`:has(button, a)`):
         list/edit action bars then land below the topbar (no collision), while
         the Dashboard's empty actions container stays collapsed (no gap). */
    @media (min-width: 1024px) {
        .fi-main { padding-top: 0 !important; }
        .fi-header {
            margin-top: -84px !important;
            position: relative; z-index: 30;
            align-items: flex-start !important;
            pointer-events: none !important;
        }
        .fi-header > * { pointer-events: auto !important; }
        .fi-header > *:nth-child(2):has(button, a) { margin-top: 84px !important; }
    }

    /* ===== Dark sidebar (the AdminLTE signature) ===== */
    .fi-sidebar { background-color: var(--lte-sidebar) !important; }
    .fi-sidebar-header {
        background-color: var(--lte-sidebar) !important;
        box-shadow: inset 0 -1px 0 rgba(255,255,255,.1);
        ring: none;
    }
    .fi-sidebar-header .fi-logo,
    .fi-sidebar-header * { color: #fff !important; }
    .fi-sidebar-nav { padding-top: .5rem !important; }
    .fi-sidebar-group-label {
        color: #8a9199 !important;
        text-transform: uppercase;
        font-size: .68rem !important;
        letter-spacing: .06em;
        padding-top: .55rem;
    }
    .fi-sidebar-group-collapse-button { color: #8a9199 !important; }
    .fi-sidebar-item-button, .fi-sidebar-item a { border-radius: .375rem; }
    .fi-sidebar-item-label { color: #c2c7d0 !important; font-size: .82rem !important; }
    .fi-sidebar-item-icon { color: #c2c7d0 !important; }
    .fi-sidebar-item a:hover { background-color: var(--lte-sidebar-hover) !important; }
    .fi-sidebar-item a:hover .fi-sidebar-item-label,
    .fi-sidebar-item a:hover .fi-sidebar-item-icon { color: #fff !important; }
    .fi-sidebar-item-active a { background-color: var(--lte-sidebar-active) !important; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
    .fi-sidebar-item-active .fi-sidebar-item-label,
    .fi-sidebar-item-active .fi-sidebar-item-icon { color: #fff !important; font-weight: 600; }
    .fi-sidebar-item-badge .fi-badge { box-shadow: none; }
    /* Tighter nav rows: less vertical padding per item and smaller gaps between
       items/groups, so the whole menu is more compact. */
    /* The clickable row is .fi-sidebar-item-button (ships with py-2); target it
       directly so the padding override actually lands. */
    .fi-sidebar-item-button, .fi-sidebar-item a, .fi-sidebar-item button { padding-top: .3rem !important; padding-bottom: .3rem !important; }
    .fi-sidebar-nav-groups { gap: .15rem !important; }
    .fi-sidebar-group-items { gap: .05rem !important; }
    .fi-sidebar-group-items > * + * { margin-top: .05rem !important; }

    /* ===== Mobile sidebar: reach the last menu item =====
       Filament's off-canvas sidebar is 100vh, which counts the area *behind*
       the phone's browser/system bars - so the bottom entries (System group:
       Fehler-Log etc.) land under the chrome and can't be scrolled to. Pin it
       to the dynamic viewport height and make the nav list scroll, with a bit
       of bottom padding so the last item clears the edge. */
    @media (max-width: 1023px) {
        .fi-sidebar {
            height: 100dvh !important;
            max-height: 100dvh !important;
        }
        .fi-sidebar-nav {
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 2rem !important;
        }
    }

    /* ===== Topbar (light-only white) ===== */
    html:not(.dark) .fi-topbar nav {
        background-color: #fff !important;
        box-shadow: 0 1px 4px rgba(0,0,0,.08) !important;
    }

    /* ===== Cards / sections: AdminLTE "card-outline" (accent + shadow work in both modes) ===== */
    .fi-section, .fi-wi-widget > section, .fi-fo-tabs, .fi-ta-ctn {
        border-top: 3px solid var(--lte-card-border) !important;
        border-radius: .4rem !important;
        box-shadow: 0 1px 3px rgba(0,0,0,.12) !important;
    }
    .fi-section-content { padding: .65rem .9rem !important; }
    .fi-section-header { padding: .55rem .9rem !important; }
    html:not(.dark) .fi-section-header { border-bottom: 1px solid #edf0f3; }
    .fi-section-header-heading { font-size: .95rem !important; font-weight: 600 !important; }
    html:not(.dark) .fi-section-header-heading { color: #1f2d3d !important; }

    /* ===== Tables: striped, hover, compact, sticky header ===== */
    .fi-ta-header-cell { padding-top: .45rem !important; padding-bottom: .45rem !important; }
    html:not(.dark) .fi-ta-header-cell { background: #f8fafc !important; border-bottom: 2px solid #dee2e6 !important; }
    .fi-ta-header-cell-label { font-size: .72rem !important; text-transform: uppercase; letter-spacing: .03em; }
    html:not(.dark) .fi-ta-header-cell-label { color: #5f6b7a !important; }
    /* Rows about half as tall (66px -> ~34px). The height comes from the column
       content wrapper (.fi-ta-text and the actions wrapper) which ships with
       `py-4` (1rem top+bottom) - several levels BELOW .fi-ta-cell, not on the
       cell (p-0) or .fi-ta-col-wrp. So target every `py-4` wrapper inside a cell
       directly (verified live: 901 matches, row 66px -> 34px). */
    .fi-ta-cell [class~="py-4"] { padding-top: .3rem !important; padding-bottom: .3rem !important; }
    .fi-ta-row, .fi-ta-record { --min-height: 0 !important; min-height: 0 !important; }
    /* tighter toolbar above tables (search + filters) */
    .fi-ta-header-toolbar { padding: .5rem .75rem !important; }
    .fi-ta-header-ctn { gap: .4rem !important; }
    html:not(.dark) .fi-ta-row:nth-child(even) { background-color: #fafbfd; }
    html:not(.dark) .fi-ta-row:hover { background-color: #eef4fb !important; transition: background-color .1s; }
    .dark .fi-ta-row:hover { background-color: rgba(255,255,255,.04) !important; }
    .fi-ta-text-item { line-height: 1.2 !important; }
    .fi-ta-table thead { position: sticky; top: 0; z-index: 5; }
    /* money columns read better with equal-width digits */
    .fi-ta-text-item-label { font-variant-numeric: tabular-nums; }
    /* Same behavior as the report tables: cells and headers never wrap
       mid-value ("1.436,46 €" stays on one line, "Nach Gebühren" stays one
       line) - on small screens the table scrolls horizontally instead of
       squeezing. Columns that opt into wrapping via ->wrap() get Filament's
       whitespace-normal class and are excluded. */
    .fi-ta-header-cell-label { white-space: nowrap; }
    .fi-ta-text-item-label:not(.whitespace-normal) { white-space: nowrap; }
    .fi-ta-text:not(.whitespace-normal) { white-space: nowrap; }
    .fi-ta-content { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* ===== Badges: solid AdminLTE colors instead of pale pills ===== */
    .fi-badge { font-weight: 600 !important; border: none !important; }
    .fi-badge.fi-color-success { background: #28a745 !important; color: #fff !important; }
    .fi-badge.fi-color-danger { background: #dc3545 !important; color: #fff !important; }
    .fi-badge.fi-color-warning { background: #ffc107 !important; color: #1f2d3d !important; }
    .fi-badge.fi-color-info { background: #17a2b8 !important; color: #fff !important; }
    .fi-badge.fi-color-primary { background: #007bff !important; color: #fff !important; }
    .fi-badge.fi-color-gray { background: #6c757d !important; color: #fff !important; }

    /* ===== Buttons ===== */
    .fi-btn { border-radius: .3rem !important; box-shadow: 0 1px 2px rgba(0,0,0,.1) !important; }

    /* ===== Widgets grid ===== */
    .fi-wi { gap: .5rem !important; }
    .fi-wi-stats-overview-stat { padding: .5rem .8rem !important; }
    .fi-wi-stats-overview-stat-value { font-size: 1.15rem !important; }
    /* Compact widget section headers (e.g. "Umsatz (Letzte 30 Tage)"). */
    .fi-wi .fi-section-header { padding: .4rem .8rem !important; }

    /* ===== AdminLTE small boxes (dashboard) ===== */
    .lte-small-boxes { display: grid; gap: .9rem; grid-template-columns: repeat(1, 1fr); }
    @media (min-width: 640px) { .lte-small-boxes { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 1280px) { .lte-small-boxes { grid-template-columns: repeat(4, 1fr); } }
    .lte-small-box {
        position: relative; overflow: hidden;
        border-radius: .4rem; color: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,.18);
        display: flex; flex-direction: column;
    }
    .lte-small-box .inner { padding: .8rem 1rem .55rem; }
    .lte-small-box .value { font-size: 1.65rem; font-weight: 700; line-height: 1.15; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .lte-small-box .label { font-size: .82rem; opacity: .95; margin-top: .1rem; }
    .lte-small-box .icon {
        position: absolute; top: .4rem; right: .6rem;
        width: 3.6rem; height: 3.6rem; color: rgba(0,0,0,.15);
        transition: transform .25s ease;
    }
    .lte-small-box:hover .icon { transform: scale(1.12); }
    .lte-small-box .more {
        display: block; text-align: center; font-size: .74rem; padding: .28rem 0;
        background: rgba(0,0,0,.12); color: #fff; text-decoration: none; margin-top: auto;
    }
    .lte-small-box .more:hover { background: rgba(0,0,0,.22); }
    .lte-bg-primary { background: linear-gradient(180deg, #1a88ff, #007bff); }
    .lte-bg-info { background: linear-gradient(180deg, #1fb5cd, #17a2b8); }
    .lte-bg-success { background: linear-gradient(180deg, #2fbf50, #28a745); }
    .lte-bg-warning { background: linear-gradient(180deg, #ffcb2b, #ffc107); color: #1f2d3d; }
    .lte-bg-warning .more { color: #1f2d3d; }
    .lte-bg-danger { background: linear-gradient(180deg, #e6505f, #dc3545); }
    .lte-bg-secondary { background: linear-gradient(180deg, #78828b, #6c757d); }

    /* ===== Forms: tighter field stacks + polished inputs ===== */
    .fi-fo-component-ctn { gap: .5rem !important; }
    .fi-fo-field-wrp-label { font-size: .78rem !important; font-weight: 600 !important; }
    .fi-fo-field-wrp-label { margin-bottom: .1rem !important; }
    .fi-input-wrp { border-radius: .3rem !important; }
    .fi-input-wrp:focus-within { box-shadow: 0 0 0 3px rgba(0,123,255,.15) !important; }
    /* Compact control height globally - inputs/selects were too tall. */
    .fi-input, .fi-select-input, .fi-fo-field-wrp .fi-input { font-size: .85rem !important; }
    .fi-input-wrp .fi-input { padding-top: .35rem !important; padding-bottom: .35rem !important; }
    /* modals a touch tighter */
    .fi-modal-content { gap: .7rem !important; }

    /* ===== Dashboard period picker: a slim inline bar, not a big card ===== */
    .fi-page > form.fi-form,
    .fi-page > .fi-form { margin-bottom: .1rem !important; }
    .fi-page > form .fi-section,
    .fi-page > .fi-form .fi-section { border-top-width: 2px !important; box-shadow: none !important; }
    .fi-page > form .fi-section-content,
    .fi-page > .fi-form .fi-section-content { padding: .5rem .75rem !important; }

    /* ===== Chart widget: cap the height so it stays fully on screen ===== */
    .fi-wi-chart canvas { max-height: 230px !important; }

    /* small-boxes: compact - what matters must be visible at a glance without
       scrolling, so keep the tiles short. */
    .lte-small-boxes { gap: .5rem; }
    .lte-small-box .inner { padding: .5rem .75rem .3rem; }
    .lte-small-box .value { font-size: 1.3rem; line-height: 1.1; }
    .lte-small-box .label { font-size: .75rem; margin-top: .05rem; }
    .lte-small-box .icon { width: 2.6rem; height: 2.6rem; top: .3rem; right: .45rem; }
    .lte-small-box .more { font-size: .68rem; padding: .18rem 0; }

    /* ===== Report tables (Berichte-Seite) =====
       Plain CSS (no Tailwind build here). The wrapper scrolls horizontally on
       mobile; numeric cells never wrap ("1.436,46 €" stays on one line) and use
       tabular figures so columns line up. */
    .rpt-wrap { overflow-x: auto; margin: 0 -.25rem; padding: 0 .25rem; -webkit-overflow-scrolling: touch; }
    .rpt { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .rpt th, .rpt td { padding: .4rem .55rem; }
    .rpt thead th {
        text-align: left; font-weight: 600; white-space: nowrap;
        color: #5f6b7a; border-bottom: 2px solid #dee2e6;
        font-size: .72rem; text-transform: uppercase; letter-spacing: .03em;
    }
    .dark .rpt thead th { color: #9aa4b2; border-bottom-color: rgba(255,255,255,.12); }
    .rpt tbody tr { border-top: 1px solid #edf0f3; }
    .dark .rpt tbody tr { border-top-color: rgba(255,255,255,.06); }
    html:not(.dark) .rpt tbody tr:nth-child(even) { background: #fafbfd; }
    .rpt td.num, .rpt th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .rpt td.lbl { font-weight: 500; white-space: nowrap; }
    .rpt td.strong { font-weight: 600; }
    .rpt td.muted { color: #6c757d; }
    /* App-wide money colors: Betrag/Umsatz blue, Nach Gebühren green,
       charged fees / negatives red (.neg declared last so it wins). */
    .rpt td.amt { color: #007bff; }
    .dark .rpt td.amt { color: #64b1ff; }
    .rpt td.net { color: #28a745; font-weight: 600; }
    .dark .rpt td.net { color: #63d385; }
    .rpt td.neg { color: #dc3545; }
    .dark .rpt td.neg { color: #f28b95; }
    .rpt-empty { padding: .75rem .55rem; color: #9aa0a6; }

    /* ===== Login card ===== */
    html:not(.dark) .fi-simple-layout { background: var(--lte-body) !important; }
    .fi-simple-main {
        border-top: 3px solid var(--lte-card-border) !important;
        box-shadow: 0 4px 18px rgba(0,0,0,.12) !important;
        border-radius: .4rem !important;
    }

    /* ===== Global loading bar =====
       A thin indeterminate top bar shown during ANY Livewire request that runs
       longer than a moment (actions, "Vorlage übernehmen", filters, saves, …),
       so slow "loading/applying" is never a silent freeze. Complements
       Filament's own button spinners + table overlays and Livewire's navigate
       bar. Driven by #ak-progress in the theme script below. */
    #ak-progress {
        position: fixed; top: 0; left: 0; right: 0; height: 3px;
        z-index: 99999; pointer-events: none; overflow: hidden;
        opacity: 0; transition: opacity .15s ease;
    }
    #ak-progress.ak-progress-active { opacity: 1; }
    #ak-progress::before {
        content: ""; position: absolute; top: 0; height: 100%; width: 35%;
        border-radius: 0 3px 3px 0;
        background: linear-gradient(90deg, rgba(0,123,255,0), #1a88ff 60%, #007bff);
        box-shadow: 0 0 8px rgba(0,123,255,.6);
        animation: ak-progress-slide 1.1s ease-in-out infinite;
    }
    @keyframes ak-progress-slide {
        0% { left: -35%; } 100% { left: 100%; }
    }

    /* ===== Long-running action overlay =====
       For actions that run longer than a few seconds (esp. PDF export), a
       centred, styled "this may take a moment" card with a spinner appears on
       top of everything, so the user knows it's still working. Driven by
       #ak-longload in the theme script. */
    #ak-longload {
        position: fixed; inset: 0; z-index: 100000;
        display: flex; align-items: center; justify-content: center;
        background: rgba(15,23,42,.55); backdrop-filter: blur(2px);
        opacity: 0; visibility: hidden; transition: opacity .2s ease, visibility .2s;
    }
    #ak-longload.ak-longload-active { opacity: 1; visibility: visible; }
    .ak-longload-card {
        background: #fff; color: #1f2d3d;
        border-top: 3px solid var(--lte-card-border);
        border-radius: .55rem; box-shadow: 0 12px 44px rgba(0,0,0,.38);
        padding: 1.7rem 2rem; max-width: 22rem; text-align: center;
        display: flex; flex-direction: column; align-items: center; gap: .45rem;
        animation: ak-longload-pop .2s ease;
    }
    .dark .ak-longload-card { background: #1f2937; color: #e5e7eb; }
    @keyframes ak-longload-pop { from { transform: translateY(8px) scale(.97); opacity: 0; } to { transform: none; opacity: 1; } }
    .ak-longload-title { font-weight: 700; font-size: 1.02rem; }
    .ak-longload-text { font-size: .82rem; opacity: .8; line-height: 1.45; }
    .ak-spinner {
        width: 2.5rem; height: 2.5rem; border-radius: 50%;
        border: 3px solid rgba(0,123,255,.18); border-top-color: #007bff;
        animation: ak-spin .8s linear infinite; margin-bottom: .35rem;
    }
    @keyframes ak-spin { to { transform: rotate(360deg); } }

    /* ===== Drag-to-scroll for wide (horizontally scrollable) tables =====
       Grab anywhere on a row and drag to pan a wide table sideways. A plain
       click still follows the record link; only a real drag pans (and then the
       click is suppressed). Driven by the theme script. */
    .fi-ta-content.ak-grabbable { cursor: grab; }
    body.ak-drag-scrolling, body.ak-drag-scrolling * { cursor: grabbing !important; user-select: none !important; }
</style>

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
    var NODRAG = 'input, select, textarea, [contenteditable]';
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
