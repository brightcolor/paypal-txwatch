<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailedJobResource\Pages;
use App\Models\FailedJob;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

/**
 * Ops view onto Laravel's failed_jobs table: see what failed and why, retry
 * it (queue:retry) or clear it. Admin-only - this is infrastructure, not
 * business data.
 */
class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Fehlgeschlagene Jobs';

    protected static ?string $modelLabel = 'Fehlgeschlagener Job';

    protected static ?string $pluralModelLabel = 'Fehlgeschlagene Jobs';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('failed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('failed_at')->label('Fehlgeschlagen am')->dateTime('d.m.Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('job')
                    ->label('Job')
                    ->badge()
                    ->state(fn (FailedJob $record) => $record->jobName()),
                Tables\Columns\TextColumn::make('queue')->label('Queue'),
                Tables\Columns\TextColumn::make('error')
                    ->label('Fehler')
                    ->state(fn (FailedJob $record) => $record->errorSummary())
                    ->wrap()
                    ->tooltip(fn (FailedJob $record) => (string) str($record->exception)->limit(800)),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('Erneut versuchen')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);

                        Notification::make()
                            ->title('Job erneut eingereiht')
                            ->body('Der Job wurde zurück in die Queue gestellt und wird vom Worker erneut verarbeitet.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make()->label('Entfernen'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Entfernen'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailedJobs::route('/'),
        ];
    }
}
