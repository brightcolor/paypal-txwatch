<?php

namespace App\Filament\Pages;

use App\Models\MailSetting;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP configuration editable in the panel so the operator can enable real
 * email without a redeploy. Admin only. Password is write-only (never sent
 * back to the browser) and stored encrypted via the MailSetting cast.
 */
class MailSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Einstellungen';

    protected static ?string $navigationLabel = 'E-Mail-Versand';

    protected static ?string $title = 'E-Mail-Versand (SMTP)';

    protected static string $view = 'filament.pages.mail-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $setting = MailSetting::current();

        // Never hydrate the password into the browser; blank means "unchanged".
        $this->form->fill([
            'enabled' => $setting->enabled,
            'host' => $setting->host,
            'port' => $setting->port,
            'encryption' => $setting->encryption,
            'username' => $setting->username,
            'password' => null,
            'from_address' => $setting->from_address,
            'from_name' => $setting->from_name,
            'alert_recipients' => $setting->alert_recipients,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('SMTP-Zugang')
                    ->description('Wenn aktiv, verschickt die Anwendung E-Mails (Fehler-/Backup-Warnungen, Abrechnungen) über diesen Server. Ohne Konfiguration bleibt die Glocke der einzige Kanal.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('enabled')->label('E-Mail-Versand aktiv')->columnSpanFull(),
                        Forms\Components\TextInput::make('host')->label('SMTP-Host')->placeholder('smtp.example.com')
                            ->requiredIf('enabled', true),
                        Forms\Components\TextInput::make('port')->label('Port')->numeric()->default(587)
                            ->requiredIf('enabled', true),
                        Forms\Components\Select::make('encryption')->label('Verschlüsselung')
                            ->options(['tls' => 'TLS', 'ssl' => 'SSL', '' => 'keine'])->default('tls'),
                        Forms\Components\TextInput::make('username')->label('Benutzername')->autocomplete(false),
                        Forms\Components\TextInput::make('password')->label('Passwort')
                            ->password()->revealable()->autocomplete('new-password')
                            ->placeholder('unverändert lassen zum Beibehalten')
                            // Only overwrite the stored (encrypted) password when a new one is typed.
                            ->dehydrated(fn (?string $state) => filled($state)),
                    ]),
                Forms\Components\Section::make('Absender & Empfänger')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('from_address')->label('Absender-Adresse')->email()
                            ->requiredIf('enabled', true),
                        Forms\Components\TextInput::make('from_name')->label('Absender-Name')->placeholder('TxWatch'),
                        Forms\Components\Textarea::make('alert_recipients')->label('Empfänger für System-Warnungen')
                            ->helperText('Komma-getrennt. Leer = alle aktiven Admins.')->rows(2)->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $setting = MailSetting::current();
        // password absent from $state (dehydrated:false) when left blank -> keep old.
        $setting->fill($state)->save();
        $setting->apply();

        Notification::make()->title('E-Mail-Einstellungen gespeichert')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Testmail senden')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Speichert die Einstellungen und sendet eine Testmail an die konfigurierten Empfänger (bzw. alle Admins).')
                ->action(function () {
                    $this->save();
                    $setting = MailSetting::current();

                    if (! $setting->isConfigured()) {
                        Notification::make()->title('Bitte zuerst aktivieren und Host/Absender ausfüllen.')->warning()->send();

                        return;
                    }

                    $recipients = $setting->alertRecipientList();
                    if (empty($recipients)) {
                        Notification::make()->title('Keine Empfänger gefunden.')->warning()->send();

                        return;
                    }

                    try {
                        $setting->apply();
                        Mail::raw('Dies ist eine Testmail von TxWatch. Der E-Mail-Versand funktioniert.', function ($m) use ($recipients) {
                            $m->to($recipients)->subject('TxWatch – Testmail');
                        });

                        Notification::make()->title('Testmail gesendet an: ' . implode(', ', $recipients))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Versand fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
