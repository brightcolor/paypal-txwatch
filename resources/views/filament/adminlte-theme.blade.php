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
    /* Tight top: trim the empty space above the page heading ("Dashboard"). */
    .fi-main { padding: .3rem 1rem .8rem !important; gap: .6rem !important; }
    .fi-main > * + * { margin-top: .7rem !important; }
    .fi-page { gap: .6rem !important; }
    /* tighter page header (title + actions) */
    .fi-header { margin-bottom: 0 !important; padding-top: 0 !important; }
    .fi-header-heading { font-size: 1.25rem !important; }

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
    .fi-ta-cell { padding-top: .28rem !important; padding-bottom: .28rem !important; }
    /* tighter toolbar above tables (search + filters) */
    .fi-ta-header-toolbar { padding: .5rem .75rem !important; }
    .fi-ta-header-ctn { gap: .4rem !important; }
    html:not(.dark) .fi-ta-row:nth-child(even) { background-color: #fafbfd; }
    html:not(.dark) .fi-ta-row:hover { background-color: #eef4fb !important; transition: background-color .1s; }
    .dark .fi-ta-row:hover { background-color: rgba(255,255,255,.04) !important; }
    .fi-ta-record { --min-height: 0 !important; }
    .fi-ta-text-item { line-height: 1.3 !important; }
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
    .fi-wi { gap: .7rem !important; }
    .fi-wi-stats-overview-stat { padding: .7rem .9rem !important; }

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
    .fi-fo-component-ctn { gap: .6rem !important; }
    .fi-fo-field-wrp-label { font-size: .8rem !important; font-weight: 600 !important; }
    .fi-input-wrp { border-radius: .3rem !important; }
    .fi-input-wrp:focus-within { box-shadow: 0 0 0 3px rgba(0,123,255,.15) !important; }
    /* modals a touch tighter */
    .fi-modal-content { gap: .7rem !important; }

    /* small-boxes: a little more compact */
    .lte-small-boxes { gap: .7rem; }
    .lte-small-box .inner { padding: .7rem .9rem .5rem; }
    .lte-small-box .value { font-size: 1.5rem; }

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
</style>
