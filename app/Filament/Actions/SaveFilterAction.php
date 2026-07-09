<?php

namespace App\Filament\Actions;

use App\Models\SavedFilter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Persists the Transactions table's current filter state (Filament's
 * ->persistFiltersInSession() already keeps it across requests; this makes
 * it reusable/shareable via SavedFilter records).
 */
class SaveFilterAction
{
    public static function make(): Action
    {
        return Action::make('saveFilter')
            ->label('Filter speichern')
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->form([
                Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
                Forms\Components\Textarea::make('description')->label('Beschreibung')->rows(2),
                Forms\Components\Toggle::make('is_shared')->label('Über Link teilbar machen'),
            ])
            ->action(function (array $data, $livewire) {
                $filter = SavedFilter::create([
                    'user_id' => auth()->id(),
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'filters' => $livewire->tableFilters ?? [],
                    'is_shared' => (bool) ($data['is_shared'] ?? false),
                ]);

                Notification::make()
                    ->title('Filter gespeichert')
                    ->body($filter->is_shared ? route('filters.shared', $filter->share_token) : null)
                    ->success()
                    ->send();
            });
    }
}
