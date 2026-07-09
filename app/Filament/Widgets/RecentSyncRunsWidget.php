<?php

namespace App\Filament\Widgets;

use App\Models\SyncRun;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentSyncRunsWidget extends BaseWidget
{
    protected static ?string $heading = 'Letzte Sync-Läufe';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(SyncRun::query()->latest('started_at')->limit(10))
            ->columns([
                Tables\Columns\TextColumn::make('paypalAccount.name')->label('Konto'),
                Tables\Columns\TextColumn::make('type')->label('Typ'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => SyncRun::STATUS_SUCCESS,
                        'warning' => SyncRun::STATUS_PARTIAL,
                        'danger' => SyncRun::STATUS_FAILED,
                        'gray' => SyncRun::STATUS_RUNNING,
                    ]),
                Tables\Columns\TextColumn::make('started_at')->label('Gestartet')->since(),
                Tables\Columns\TextColumn::make('imported_count')->label('Neu'),
                Tables\Columns\TextColumn::make('error_count')->label('Fehler')->color('danger'),
            ])
            ->paginated(false);
    }
}
