<?php

namespace App\Filament\Pages;

use App\Models\EnableBankingConnection;
use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\EnableBankingException;
use App\Services\EnableBanking\KeyVault;
use App\Services\EnableBanking\Sync;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

/**
 * Bank auto-fetch via Enable Banking (PSD2) - the path that needs no
 * registration with the Deutsche Kreditwirtschaft, unlike FinTS.
 *
 * The admin uploads the application key from the Enable Banking control panel,
 * picks a bank, and is sent to that bank to authorise. TxWatch never sees the
 * banking credentials; it receives a read-only session. Admin only: the key is
 * the banking access of the entire installation.
 *
 * WHY KEY SETUP AND BANK CONNECTION SHARE ONE PAGE. They are two steps of one
 * errand, and the first is done exactly once. Splitting them across two nav
 * entries would leave a permanent menu item for a task nobody performs again -
 * and would hide, on the day it matters, that the second step fails because the
 * first was never done.
 */
class EnableBankingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Bank';

    protected static ?string $navigationLabel = 'Bank verbinden';

    protected static ?string $title = 'Bank verbinden (Enable Banking)';

    /** Above the stillgelegt FinTS entry (20): this is the path that works. */
    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.enable-banking';

    protected static ?string $slug = 'bank-verbinden';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $c = EnableBankingConnection::current();

        $this->form->fill([
            'country' => $c->aspsp_country ?: 'DE',
            'aspsp_name' => $c->aspsp_name,
            'iban' => $c->iban,
            'key' => null,
            'application_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Zugang der Installation')
                ->description('Die Anwendungskennung und der private Schlüssel stammen aus dem Control Panel von Enable Banking. Sie gelten für diese Installation, nicht für ein einzelnes Konto. Der Schlüssel wird nur hinterlegt – angezeigt wird er nie wieder.')
                ->collapsed(fn () => $this->vault()->isReady())
                ->collapsible()
                ->schema([
                    Forms\Components\FileUpload::make('key')
                        ->label(fn () => $this->vault()->hasKey() ? 'Neuen Schlüssel hochladen (.pem)' : 'Schlüssel hochladen (.pem)')
                        /*
                         * A private key has no business in the public disk (which
                         * is symlinked into the document root) nor in a permanent
                         * upload folder. `storeFiles(false)` keeps it as a
                         * TemporaryUploadedFile, so the only copy that survives is
                         * the one the vault writes with mode 0600.
                         */
                        ->storeFiles(false)
                        /*
                         * NO MIME FILTER, and that is deliberate after it broke
                         * the upload outright: `.pem` has no dependable media
                         * type. Windows reports application/octet-stream, some
                         * browsers send an empty type, and a filter listing
                         * x-pem-file rejects the very file the control panel
                         * hands out - "must be a file of type…" on a perfectly
                         * good key.
                         *
                         * The media type is the wrong gate anyway: it comes from
                         * the client and says nothing about the content. What
                         * decides is KeyVault::validate(), which parses the key,
                         * insists on RSA and checks the length - and names the
                         * two usual mix-ups (certificate, public half) by name.
                         * A size cap stays, because that is cheap and catches the
                         * "wrong file entirely" case before it is read.
                         */
                        ->maxSize(128)
                        ->helperText('Die Datei aus dem Control Panel heisst z. B. txwatch_prod_<Kennung>.pem oder prod_<Kennung>.pem – die Kennung liest TxWatch aus dem Namen mit, umbenennen ist nicht nötig.'),

                    Forms\Components\TextInput::make('application_id')
                        ->label('Anwendungskennung (nur nötig, wenn die Datei umbenannt wurde)')
                        ->placeholder('00000000-0000-0000-0000-000000000000')
                        ->autocomplete(false),
                ]),

            Forms\Components\Section::make('Bank')
                ->description('Nach dem Speichern führt „Zur Bank und freigeben" oben zu Ihrer Bank. Dort melden Sie sich an und erlauben den Lesezugriff.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('country')
                        ->label('Land')
                        ->options(['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'])
                        ->default('DE')
                        ->live()
                        ->required(),

                    Forms\Components\Select::make('aspsp_name')
                        ->label('Bank')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => $this->searchBanks($search))
                        ->getOptionLabelUsing(fn ($value) => $value)
                        /*
                         * DISABLED WITH A REASON while no key is stored.
                         *
                         * The first version left it enabled and simply returned
                         * an empty result set - so someone typed, nothing
                         * appeared, and the field gave no hint that the missing
                         * piece was the key two sections above. A control that
                         * silently does nothing is worse than one that is
                         * visibly unavailable.
                         */
                        ->disabled(fn () => ! $this->vault()->isReady())
                        ->helperText(fn () => $this->vault()->isReady()
                            ? 'Mindestens zwei Buchstaben tippen, dann erscheinen Vorschläge. Die Liste kommt live von Enable Banking und enthält nur Banken, die dort angebunden sind.'
                            : 'Erst den Schlüssel oben hinterlegen und speichern – ohne ihn lässt sich die Bankenliste nicht abrufen.')
                        /*
                         * ONLY MANDATORY ONCE A BANK CAN ACTUALLY BE PICKED.
                         *
                         * An unconditional required() deadlocked the whole page:
                         * the field cannot be filled without a key, the key is
                         * stored by saving the form, and saving failed on this
                         * very field being empty. "The bank field is required"
                         * next to a disabled dropdown, with no way out.
                         *
                         * The consent action guards the same condition anyway
                         * (`filled(aspsp_name)`), so nothing is lost by letting
                         * the first save carry only the key.
                         */
                        ->required(fn () => $this->vault()->isReady()),

                    Forms\Components\TextInput::make('iban')
                        ->label('Konto (IBAN, optional)')
                        ->helperText('Nur nötig, wenn die Freigabe mehrere Konten umfasst – dann entscheidet sie, welches abgerufen wird.')
                        ->columnSpanFull(),
                ]),
        ])->statePath('data');
    }

    /**
     * Bank names for the picker.
     *
     * Filtered here rather than in the request, because the ASPSP list of a
     * country is a few hundred entries and Enable Banking has no search
     * parameter. Failures return an empty list instead of throwing: a broken
     * dropdown is bad, a white screen on the setup page is worse.
     *
     * @return array<string, string>
     */
    private function searchBanks(string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 2 || ! $this->vault()->isReady()) {
            return [];
        }

        try {
            $banks = app(Client::class)->banks($this->data['country'] ?? 'DE');
        } catch (EnableBankingException $e) {
            Log::warning('Enable Banking: Bankenliste nicht abrufbar', ['error' => $e->getMessage()]);

            /*
             * SAID OUT LOUD, not only written to the log.
             *
             * A failed list looks exactly like "no bank matches your search",
             * and the two call for completely different actions. This is where
             * an inactive application or a key that does not match its id first
             * becomes noticeable, so the reason has to reach the screen.
             */
            Notification::make()
                ->title('Bankenliste nicht abrufbar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return [];
        }

        $out = [];

        foreach ($banks as $bank) {
            $name = (string) ($bank['name'] ?? '');

            if ($name === '' || mb_stripos($name, $search) === false) {
                continue;
            }

            // The beta marker is part of the label, not swallowed: those
            // connections do fail more often, and knowing that beforehand is
            // worth one word.
            $out[$name] = ! empty($bank['beta']) ? $name . ' (Beta)' : $name;

            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $c = EnableBankingConnection::current();

        // The key first: without it the bank selection cannot be verified anyway.
        if (filled($state['key'] ?? null)) {
            if (! $this->storeKey($state['key'], $state['application_id'] ?? null)) {
                return;
            }
        }

        $c->forceFill([
            'aspsp_country' => $state['country'] ?? 'DE',
            'aspsp_name' => $state['aspsp_name'] ?? null,
            'iban' => filled($state['iban'] ?? null) ? trim((string) $state['iban']) : null,
        ])->save();

        // Clear the upload field so a second save does not re-store the same file.
        $this->form->fill([...$state, 'key' => null, 'application_id' => null]);

        Notification::make()->title('Gespeichert')->success()->send();
    }

    /** Returns false when the key was refused (a notification was already sent). */
    private function storeKey(mixed $upload, ?string $applicationId): bool
    {
        $file = is_array($upload) ? reset($upload) : $upload;

        if (! $file || ! method_exists($file, 'getRealPath')) {
            Notification::make()->title('Die Datei kam nicht an')->danger()->send();

            return false;
        }

        try {
            $status = $this->vault()->store(
                (string) file_get_contents($file->getRealPath()),
                method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'key.pem',
                $applicationId,
            );
        } catch (\Throwable $e) {
            Notification::make()->title('Schlüssel abgewiesen')->body($e->getMessage())->danger()->persistent()->send();

            return false;
        }

        /*
         * Into the application log, with the fingerprint: whoever swaps this key
         * redirects every future bank consent of this installation to a
         * different Enable Banking application, and that has to be traceable
         * afterwards.
         */
        Log::warning('Enable-Banking-Schlüssel hinterlegt', [
            'application_id' => $status['application_id'],
            'environment' => $status['environment'],
            'fingerprint' => $status['fingerprint'],
            'bits' => $status['bits'],
        ]);

        Notification::make()->title('Schlüssel hinterlegt')
            ->body('Prüfe ihn gleich mit „Selbsttest" oben.')->success()->send();

        return true;
    }

    public function getConnectionProperty(): EnableBankingConnection
    {
        return EnableBankingConnection::current();
    }

    /** @return array<string, mixed> */
    public function getVaultStatusProperty(): array
    {
        return $this->vault()->status();
    }

    public function getSetupReasonProperty(): ?string
    {
        return $this->vault()->missingReason();
    }

    public function getConfiguredByEnvProperty(): bool
    {
        return $this->vault()->configuredByEnv();
    }

    /**
     * The return address to register in the control panel.
     *
     * Built from the route name, so it cannot drift apart from the route the
     * bank actually lands on - and printed in the UI, because a missing or
     * mistyped entry here is the most common setup failure with this API and the
     * only one that cannot be guessed from the error message.
     */
    public function getCallbackUrlProperty(): string
    {
        return route('enablebanking.callback');
    }

    private function vault(): KeyVault
    {
        return app(KeyVault::class);
    }

    /**
     * How long the consent will actually last, as a phrase for the dialog.
     *
     * Falls back to a vague wording rather than a wrong number: if the list is
     * momentarily unreachable, "so lange wie Ihre Bank es erlaubt" is true, while
     * "höchstens 90 Tage" might not be.
     */
    private function consentDurationLabel(): string
    {
        $c = EnableBankingConnection::current();

        try {
            $allowed = app(Client::class)->maxConsentDays(
                (string) $c->aspsp_name,
                (string) ($c->aspsp_country ?: 'DE'),
            );
        } catch (EnableBankingException) {
            return 'so lange wie Ihre Bank es erlaubt';
        }

        $days = $allowed !== null
            ? min((int) config('bank.enablebanking.consent_days'), $allowed)
            : null;

        return $days !== null
            ? sprintf('höchstens %d Tage', $days)
            : 'so lange wie Ihre Bank es erlaubt';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('selfTest')
                ->label('Selbsttest')
                ->icon('heroicon-o-shield-check')->color('gray')
                ->visible(fn () => $this->vault()->isReady())
                ->action(fn () => $this->selfTest()),

            Action::make('connect')
                ->label('Zur Bank und freigeben')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn () => $this->vault()->isReady() && filled(EnableBankingConnection::current()->aspsp_name))
                ->requiresConfirmation()
                ->modalHeading('Weiter zu Ihrer Bank')
                /*
                 * The number in this sentence is the bank's own, not the wish
                 * from the config. Naming a duration the bank does not grant
                 * would be a promise broken on the next screen.
                 */
                ->modalDescription(fn () => sprintf(
                    'Sie werden zu „%s" geleitet und melden sich dort an. TxWatch bekommt Ihre Zugangsdaten '
                    . 'nicht zu sehen – nur einen Leseschlüssel für Umsätze und Salden, und den %s.',
                    EnableBankingConnection::current()->aspsp_name,
                    $this->consentDurationLabel(),
                ))
                ->modalSubmitActionLabel('Zur Bank')
                ->action(fn () => $this->beginConsent()),

            Action::make('sync')
                ->label('Jetzt abrufen')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => EnableBankingConnection::current()->isActive())
                ->action(function () {
                    $result = app(Sync::class)->syncSafely(EnableBankingConnection::current());
                    $this->reportSync($result);
                }),

            Action::make('disconnect')
                ->label('Trennen')
                ->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn () => EnableBankingConnection::current()->status !== EnableBankingConnection::STATUS_NEW)
                ->requiresConfirmation()
                ->modalDescription('Die Verbindung wird hier entfernt und die Zustimmung bei der Bank widerrufen. Bereits importierte Umsätze bleiben erhalten.')
                ->action(fn () => $this->disconnect()),

            Action::make('removeKey')
                ->label('Schlüssel entfernen')
                ->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => $this->vault()->hasKey() && ! $this->vault()->configuredByEnv())
                ->requiresConfirmation()
                ->modalHeading('Schlüssel der Installation entfernen')
                ->modalDescription('Danach kann keine Bank mehr verbunden oder abgerufen werden, bis ein Schlüssel hinterlegt ist. Bereits erteilte Zustimmungen bei den Banken bleiben bestehen und laufen mit ihrer Frist ab – widerrufen werden sie damit nicht.')
                ->action(function () {
                    try {
                        $this->vault()->forget();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Entfernen fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Log::warning('Enable-Banking-Schlüssel entfernt');
                    Notification::make()->title('Schlüssel entfernt')->success()->send();
                }),
        ];
    }

    private function selfTest(): void
    {
        try {
            $application = app(Client::class)->application();
        } catch (EnableBankingException $e) {
            Notification::make()->title('Selbsttest fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $urls = is_array($application['redirect_urls'] ?? null) ? $application['redirect_urls'] : [];
        $expected = $this->getCallbackUrlProperty();

        $lines = [
            'Anwendung: ' . ($application['name'] ?? 'ohne Namen'),
            'Umgebung: ' . ($application['environment'] ?? 'unbekannt'),
            'Aktiv: ' . (($application['active'] ?? null) === false ? 'NEIN' : 'ja'),
        ];

        /*
         * THE REDIRECT CHECK IS THE POINT OF THIS TEST. Everything above merely
         * proves key and id match; this line predicts whether a consent can
         * succeed at all - and it is the failure nobody can diagnose from the
         * error message, because the bank never gets reached.
         */
        if (! in_array($expected, $urls, true)) {
            $lines[] = '';
            $lines[] = 'ACHTUNG: Die Rückkehr-Adresse dieser Installation ist im Control Panel NICHT eingetragen.';
            $lines[] = 'Dort ergänzen: ' . $expected;
            $lines[] = $urls === []
                ? 'Bisher ist keine Adresse hinterlegt.'
                : 'Hinterlegt ist bisher: ' . implode(', ', $urls);

            Notification::make()->title('Zugang erkannt, aber nicht benutzbar')
                ->body(implode("\n", $lines))->warning()->persistent()->send();

            return;
        }

        $lines[] = 'Rückkehr-Adresse ist eingetragen.';

        Notification::make()->title('Selbsttest erfolgreich')
            ->body(implode("\n", $lines))->success()->persistent()->send();
    }

    private function beginConsent(): void
    {
        $c = EnableBankingConnection::current();

        /*
         * ASK THE BANK, don't assume 90.
         *
         * The config value is only a ceiling for the wish; what actually decides
         * is `maximum_consent_validity` of the chosen bank. Measured on
         * 2026-08-17: German banks grant 180 days, not the 90 that every older
         * PSD2 guide names. Sending 90 anyway would halve the validity and make
         * the account holder re-authorise twice as often - and `valid_until`
         * ABOVE the bank's limit is rejected outright, so guessing in either
         * direction costs something.
         */
        $wish = (int) config('bank.enablebanking.consent_days');
        $allowed = app(Client::class)->maxConsentDays((string) $c->aspsp_name, (string) ($c->aspsp_country ?: 'DE'));

        $days = max(1, $allowed !== null ? min($wish, $allowed) : min($wish, 90));
        $validUntil = now()->addDays($days);

        try {
            $start = app(Client::class)->startAuthorization(
                bank: (string) $c->aspsp_name,
                country: (string) ($c->aspsp_country ?: 'DE'),
                redirectUrl: $this->getCallbackUrlProperty(),
                state: $c->issueState(),
                validUntil: $validUntil,
            );
        } catch (EnableBankingException $e) {
            Notification::make()->title('Freigabe liess sich nicht starten')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        if ($start['url'] === '') {
            Notification::make()->title('Der Dienst hat keine Adresse für die Freigabe geschickt')->danger()->send();

            return;
        }

        // Announced, not surprising: the confirmation dialog said where this goes.
        $this->redirect($start['url']);
    }

    private function disconnect(): void
    {
        $c = EnableBankingConnection::current();
        $revoked = true;
        $note = null;

        if (filled($c->session_id)) {
            try {
                app(Client::class)->closeSession($c->session_id);
            } catch (\Throwable $e) {
                /*
                 * The local record is cleared even when the revocation fails -
                 * otherwise a connection nobody can get rid of stays stuck. That
                 * the revocation is still open at the bank IS said, not
                 * swallowed.
                 */
                $revoked = false;
                $note = 'Die Verbindung wurde hier entfernt, der Widerruf beim Dienst ist aber '
                    . 'fehlgeschlagen: ' . $e->getMessage();
            }
        }

        $c->forceFill([
            'session_id' => null,
            'accounts' => null,
            'access_valid_until' => null,
            'status' => EnableBankingConnection::STATUS_NEW,
            'last_error' => null,
        ])->save();

        $revoked
            ? Notification::make()->title('Verbindung getrennt')->success()->send()
            : Notification::make()->title('Verbindung getrennt')->body($note)->warning()->persistent()->send();
    }

    /** @param array<string, mixed> $result */
    private function reportSync(array $result): void
    {
        if (isset($result['error'])) {
            Notification::make()->title('Abruf fehlgeschlagen')->body($result['error'])->danger()->persistent()->send();

            return;
        }

        /*
         * In journal mode "0 neu importiert" would read like a failure, when it is
         * exactly what was configured. So the mode is named, and what the journal
         * did is reported instead.
         */
        $body = ($result['mode'] ?? 'journal') === 'journal'
            ? sprintf(
                'Nur aufgezeichnet, es wurde nichts gebucht: %d neu im Journal, %d schon bekannt, '
                . '%d davon mit erkannter pretix-Bestellnummer.',
                $result['recorded'] ?? 0,
                $result['known'] ?? 0,
                $result['with_order'] ?? 0,
            )
            : "{$result['imported']} neu importiert, {$result['matched']} zugeordnet.";

        if (($result['skipped_pending'] ?? 0) > 0) {
            // Named rather than hidden: a vorgemerkte Buchung is not an error,
            // but "3 fewer than at the bank" needs an explanation.
            $body .= " {$result['skipped_pending']} noch nicht gebuchte Umsätze übersprungen.";
        }

        if (filled($result['truncation_notice'] ?? null)) {
            Notification::make()->title('Abruf unvollständig')
                ->body($body . ' ' . $result['truncation_notice'])->warning()->persistent()->send();

            return;
        }

        Notification::make()->title('Abruf fertig')->body($body)->success()->send();
    }
}
