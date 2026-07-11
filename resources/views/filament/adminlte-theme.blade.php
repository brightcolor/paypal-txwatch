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

    /* ===== Page canvas ===== */
    .fi-body { background-color: var(--lte-body) !important; }
    .fi-main { padding-top: 1rem !important; padding-bottom: 1.25rem !important; gap: 1rem !important; }
    .fi-main > * + * { margin-top: 1rem !important; }
    .fi-page { gap: 1rem !important; }

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

    /* ===== Topbar ===== */
    .fi-topbar nav {
        background-color: #fff !important;
        box-shadow: 0 1px 4px rgba(0,0,0,.08) !important;
        ring: none;
    }

    /* ===== Cards / sections: AdminLTE "card-outline" ===== */
    .fi-section, .fi-wi-widget > section, .fi-fo-tabs, .fi-ta-ctn {
        border-top: 3px solid var(--lte-card-border) !important;
        border-radius: .4rem !important;
        box-shadow: 0 1px 3px rgba(0,0,0,.12) !important;
    }
    .fi-section-content { padding: .8rem 1rem !important; }
    .fi-section-header { padding: .7rem 1rem !important; border-bottom: 1px solid #edf0f3; }
    .fi-section-header-heading { font-size: .95rem !important; font-weight: 600 !important; color: #1f2d3d !important; }

    /* ===== Tables: striped, hover, compact, sticky header ===== */
    .fi-ta-header-cell {
        padding-top: .45rem !important;
        padding-bottom: .45rem !important;
        background: #f8fafc !important;
        border-bottom: 2px solid #dee2e6 !important;
    }
    .fi-ta-header-cell-label { font-size: .72rem !important; text-transform: uppercase; letter-spacing: .03em; color: #5f6b7a !important; }
    .fi-ta-cell { padding-top: .32rem !important; padding-bottom: .32rem !important; }
    .fi-ta-row:nth-child(even) { background-color: #fafbfd; }
    .fi-ta-row:hover { background-color: #eef4fb !important; transition: background-color .1s; }
    .fi-ta-record { --min-height: 0 !important; }
    .fi-ta-text-item { line-height: 1.3 !important; }
    .fi-ta-table thead { position: sticky; top: 0; z-index: 5; }
    /* money columns read better with equal-width digits */
    .fi-ta-text-item-label { font-variant-numeric: tabular-nums; }

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
    .fi-wi { gap: .9rem !important; }
    .fi-wi-stats-overview-stat { padding: .85rem 1rem !important; }

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

    /* ===== Forms ===== */
    .fi-fo-component-ctn { gap: .75rem !important; }
    .fi-input-wrp { border-radius: .3rem !important; }

    /* ===== Login card ===== */
    .fi-simple-layout { background: var(--lte-body) !important; }
    .fi-simple-main {
        border-top: 3px solid var(--lte-card-border) !important;
        box-shadow: 0 4px 18px rgba(0,0,0,.12) !important;
        border-radius: .4rem !important;
    }
</style>
