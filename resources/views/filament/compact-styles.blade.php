{{-- Compact density overrides for Filament's default (fairly generous) spacing.
     Injected via a render hook so no Tailwind/theme build step is needed. --}}
<style>
    /* Page gutters and vertical rhythm */
    .fi-main {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
        gap: 1rem !important;
    }
    .fi-main > * + * { margin-top: 1rem !important; }
    .fi-page { gap: 1rem !important; }

    /* Sections */
    .fi-section-content { padding: 0.75rem 1rem !important; }
    .fi-section-header { padding: 0.75rem 1rem !important; }

    /* Widgets / dashboard grid + stat cards */
    .fi-wi { gap: 0.75rem !important; }
    .fi-wi-stats-overview-stat { padding: 0.85rem 1rem !important; }

    /* Tables: tighter rows + headers */
    .fi-ta-header-cell { padding-top: 0.4rem !important; padding-bottom: 0.4rem !important; }
    .fi-ta-cell { padding-top: 0.3rem !important; padding-bottom: 0.3rem !important; }
    .fi-ta-record { --min-height: 0 !important; }
    .fi-ta-text-item { line-height: 1.25 !important; }

    /* Forms: a little tighter */
    .fi-fo-component-ctn { gap: 0.75rem !important; }
</style>
