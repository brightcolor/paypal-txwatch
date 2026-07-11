<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureTwoFactorChallengeIsPassed;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('PayPal TxWatch')
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->favicon(asset('favicon.svg'))
            // AdminLTE-style theme (see filament.adminlte-theme). Dark mode is
            // supported: the light-only surface colors in the theme are scoped
            // to html:not(.dark) so Filament's dark palette shows through.
            // Use the full viewport width for content (the default is a narrow centered
            // column that leaves large unused margins on wide screens, which matters here
            // because the transactions table has many columns).
            ->maxContentWidth(MaxWidth::Full)
            // Livewire-navigate page switches: no full reload per menu click,
            // which is most of the perceived sluggishness when navigating.
            ->spa()
            // Bell-icon notifications (sync/import failures, reconciliation
            // mismatches), polled every 30s. See App\Support\AdminNotifier.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->login()
            ->passwordReset()
            ->profile()
            ->colors([
                'primary' => Color::hex('#007bff'),
                'info' => Color::hex('#17a2b8'),
                'success' => Color::hex('#28a745'),
                'warning' => Color::hex('#ffc107'),
                'danger' => Color::hex('#dc3545'),
                'gray' => Color::Slate,
            ])
            ->navigationGroups([
                'PayPal',
                'pretix',
                'Events & Kunden',
                'Transaktionen',
                'Berichte',
                'Exporte',
                'Einstellungen',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                // Custom dashboard with the Matomo-style period picker.
                \App\Filament\Pages\Dashboard::class,
            ])
            ->widgets([
                \App\Filament\Widgets\DashboardStatsOverview::class,
                \App\Filament\Widgets\ComparisonStatsWidget::class,
                \App\Filament\Widgets\NeedsReviewWidget::class,
                \App\Filament\Widgets\TopEventsWidget::class,
                \App\Filament\Widgets\SyncHealthWidget::class,
                \App\Filament\Widgets\RevenueByDayChart::class,
                \App\Filament\Widgets\RecentSyncRunsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureTwoFactorChallengeIsPassed::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.adminlte-theme'),
            )
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn () => view('filament.version-footer'),
            );
    }
}
