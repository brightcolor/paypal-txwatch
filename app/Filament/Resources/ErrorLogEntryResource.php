<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ErrorLogEntryResource\Pages;
use App\Models\ErrorLogEntry;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Read-only inbox of captured 5xx errors (see App\Support\ErrorLogger). Admin
 * only. Rows can be marked resolved or deleted - these are diagnostics, not
 * audit data, so deletion is allowed (unlike transactions/audit entries).
 */
class ErrorLogEntryResource extends Resource
{
    protected static ?string $model = ErrorLogEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Fehler-Log';

    protected static ?string $modelLabel = 'Fehler';

    protected static ?string $pluralModelLabel = 'Fehler-Log';

    protected static ?int $navigationSort = 90;

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
        $count = ErrorLogEntry::where('resolved', false)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('last_seen_at')->label('Zuletzt')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('occurrences')->label('×')->badge()
                    ->color(fn (int $state) => $state > 5 ? 'danger' : 'gray')->sortable(),
                Tables\Columns\TextColumn::make('exception_class')->label('Typ')
                    ->formatStateUsing(fn (ErrorLogEntry $r) => $r->shortClass())->badge()->color('danger'),
                Tables\Columns\TextColumn::make('message')->label('Nachricht')->limit(60)->wrap()
                    ->tooltip(fn (ErrorLogEntry $r) => $r->message)->searchable(),
                Tables\Columns\TextColumn::make('location')->label('Ort')
                    ->state(fn (ErrorLogEntry $r) => $r->shortLocation()),
                Tables\Columns\TextColumn::make('route')->label('Route/URL')
                    ->state(fn (ErrorLogEntry $r) => $r->route ?: Str::limit($r->url, 30))->wrap(),
                Tables\Columns\IconColumn::make('resolved')->label('Erledigt')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('resolved')->label('Erledigt')
                    ->default(false),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('toggleResolved')
                    ->label(fn (ErrorLogEntry $r) => $r->resolved ? 'Wieder öffnen' : 'Als erledigt')
                    ->icon(fn (ErrorLogEntry $r) => $r->resolved ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check')
                    ->action(fn (ErrorLogEntry $r) => $r->update(['resolved' => ! $r->resolved])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('resolve')->label('Als erledigt')->icon('heroicon-o-check')
                    ->action(fn ($records) => $records->each->update(['resolved' => true]))->deselectRecordsAfterCompletion(),
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('Keine Fehler protokolliert')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Fehler')->schema([
                Infolists\Components\TextEntry::make('exception_class')->label('Typ'),
                Infolists\Components\TextEntry::make('message')->label('Nachricht')->columnSpanFull(),
                Infolists\Components\TextEntry::make('location')->label('Ort')
                    ->state(fn (ErrorLogEntry $r) => $r->file . ':' . $r->line),
                Infolists\Components\TextEntry::make('status_code')->label('Status')->badge()->color('danger'),
                Infolists\Components\TextEntry::make('occurrences')->label('Vorkommen')->badge(),
                Infolists\Components\TextEntry::make('app_version')->label('Version'),
                Infolists\Components\TextEntry::make('first_seen_at')->label('Zuerst')->dateTime('d.m.Y H:i:s'),
                Infolists\Components\TextEntry::make('last_seen_at')->label('Zuletzt')->dateTime('d.m.Y H:i:s'),
            ])->columns(3),
            Infolists\Components\Section::make('Request')->schema([
                Infolists\Components\TextEntry::make('method')->label('Methode'),
                Infolists\Components\TextEntry::make('url')->label('URL')->columnSpan(2),
                Infolists\Components\TextEntry::make('route')->label('Route'),
                Infolists\Components\TextEntry::make('user_id')->label('User-ID')->placeholder('–'),
                Infolists\Components\KeyValueEntry::make('context')->label('Kontext')->columnSpanFull(),
            ])->columns(3),
            Infolists\Components\Section::make('Stacktrace')->collapsed()->schema([
                Infolists\Components\TextEntry::make('trace')->label('')->fontFamily('mono')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListErrorLogEntries::route('/'),
            'view' => Pages\ViewErrorLogEntry::route('/{record}'),
        ];
    }
}
