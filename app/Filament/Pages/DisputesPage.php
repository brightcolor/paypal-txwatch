<?php

namespace App\Filament\Pages;

use App\Models\PaypalAccount;
use App\Services\PayPal\DisputesOverview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Open PayPal buyer disputes across all active accounts - the early-warning
 * list before disputes turn into chargebacks. Live from the PayPal Disputes
 * API (cached), operator-facing.
 */
class DisputesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'PayPal';

    protected static ?string $navigationLabel = 'Käuferkonflikte';

    protected static ?string $title = 'Offene Käuferkonflikte (Disputes)';

    protected static string $view = 'filament.pages.disputes';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-paypal-accounts') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return PaypalAccount::query()->where('is_active', true)->exists()
            && (auth()->user()?->can('manage-paypal-accounts') ?? false);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getDisputesProperty(): Collection
    {
        return app(DisputesOverview::class)->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    app(DisputesOverview::class)->all(fresh: true);
                    Notification::make()->title('Aktualisiert')->success()->send();
                }),
        ];
    }
}
