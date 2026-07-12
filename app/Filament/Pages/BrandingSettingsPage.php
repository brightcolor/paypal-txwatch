<?php

namespace App\Filament\Pages;

use App\Models\BrandSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Operator branding for exports: a small logo and a claim line, shown
 * discreetly in the footer of every PDF page and on the cover.
 */
class BrandingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Einstellungen';

    protected static ?string $navigationLabel = 'Branding (Exporte)';

    protected static ?string $title = 'Branding auf Exporten';

    protected static string $view = 'filament.pages.branding-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $setting = BrandSetting::current();

        $this->form->fill([
            'logo_path' => $setting->logo_path,
            'claim' => $setting->claim,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Euer Auftritt auf den PDFs')
                    ->description('Das Logo erscheint dezent in der Fußzeile jeder Seite und auf dem Deckblatt – sichtbar, aber nicht aufdringlich („Bericht & Ticketing von uns").')
                    ->schema([
                        Forms\Components\FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk('public')
                            ->directory('branding')
                            ->maxSize(2048)
                            ->helperText('PNG/JPG/SVG, am besten mit transparentem Hintergrund.'),
                        Forms\Components\TextInput::make('claim')
                            ->label('Claim (Textzeile neben dem Logo)')
                            ->placeholder('Bericht & Ticketing: HSP Events')
                            ->maxLength(120),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        BrandSetting::current()->update([
            'logo_path' => $state['logo_path'] ?? null,
            'claim' => $state['claim'] ?? null,
        ]);

        Notification::make()->title('Branding gespeichert')->success()->send();
    }
}
