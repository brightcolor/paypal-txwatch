<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Http\Middleware\EnsureTwoFactorChallengeIsPassed;
use App\Support\WerkbankPalette;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
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
            // Just "TxWatch": the tool tracks PayPal AND pretix transactions.
            ->brandName('TxWatch')
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->favicon(asset('favicon.svg'))
            // Workbench design of bright color: colour scales in WerkbankPalette,
            // everything else in public/css/werkbank.css (see
            // filament.werkbank-theme). Fonts and avatars come from this server.
            ->font('Atkinson Hyperlegible', url: asset('css/werkbank-fonts.css') . '?v=' . config('version.number'), provider: LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
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
            ->colors(WerkbankPalette::colors())
            ->navigationGroups([
                'PayPal',
                'pretix',
                'Bank',
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
                fn () => view('filament.werkbank-theme'),
            )
            // Onyx half with the greeting beside the sign-in form.
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn () => view('filament.login-aside'),
            )
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn () => view('filament.version-footer'),
            );
    }
}
