<x-filament-panels::page>
    @php $user = auth()->user(); @endphp

    @if ($freshRecoveryCodes)
        <x-filament::section heading="Wiederherstellungscodes" icon="heroicon-o-key">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Speichere diese Codes sicher ab - jeder ist einmal verwendbar, falls du keinen Zugriff mehr
                auf deine Authenticator-App hast. Sie werden nur dieses eine Mal angezeigt.
            </p>
            <div class="grid gap-2 rounded-lg bg-gray-50 p-4 font-mono text-sm md:grid-cols-2 dark:bg-gray-800">
                @foreach ($freshRecoveryCodes as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($user->hasTwoFactorEnabled())
        <x-filament::section heading="Status">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 tx-ok">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                    Zwei-Faktor-Authentifizierung ist aktiv seit {{ $user->two_factor_confirmed_at->format('d.m.Y H:i') }}.
                </div>
                <x-filament::button color="danger" wire:click="disable" wire:confirm="Zwei-Faktor-Authentifizierung wirklich deaktivieren? Die PIN wird dabei entfernt.">
                    Deaktivieren
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section heading="Entsperr-PIN">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Mit einer PIN kommst du auf bestätigten Geräten wieder hinein, ohne den Code aus der App, etwa wenn
                deine Sitzung abgelaufen ist. Bestätigt ist ein Gerät, auf dem du in den letzten {{ $trustedDeviceDays }}
                Tagen den Code aus der App eingegeben hast. Ohne PIN wirst du nach Ablauf der Sitzung vollständig
                abgemeldet und meldest dich neu an.
            </p>

            @if ($user->hasTwoFactorPin())
                <p class="mb-4 flex items-center gap-2 text-sm tx-ok">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                    PIN festgelegt am {{ $user->two_factor_pin_set_at?->format('d.m.Y H:i') }}.
                </p>
            @endif

            <form wire:submit.prevent="savePin">
                {{ $this->pinForm }}
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-filament::button type="submit">
                        {{ $user->hasTwoFactorPin() ? 'PIN ändern' : 'PIN speichern' }}
                    </x-filament::button>
                    @if ($user->hasTwoFactorPin())
                        <x-filament::button color="gray" wire:click="removePin" wire:confirm="PIN wirklich entfernen? Läuft deine Sitzung ab, wirst du dann vollständig abgemeldet.">
                            PIN entfernen
                        </x-filament::button>
                    @endif
                </div>
            </form>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ $pinMinLength }} bis {{ $pinMaxLength }} Ziffern. Nach {{ $pinMaxAttempts }} falschen Eingaben in Folge
                gilt die PIN erst wieder, wenn du dich mit dem Code aus der App angemeldet hast.
            </p>
        </x-filament::section>

        <x-filament::section heading="Bestätigte Geräte">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Hast du ein Gerät verloren oder nutzt es nicht mehr, verlangt TxWatch nach diesem Klick auf allen
                anderen Geräten wieder den Code aus der App. Dieses Gerät bleibt bestätigt.
            </p>
            <x-filament::button color="gray" wire:click="forgetOtherDevices" wire:confirm="Alle anderen Geräte vergessen? Dort ist beim nächsten Mal wieder der Code aus der App nötig.">
                Andere Geräte vergessen
            </x-filament::button>
        </x-filament::section>
    @elseif ($pendingSecret)
        <x-filament::section heading="Einrichtung abschließen">
            <div class="flex flex-col items-start gap-4 sm:flex-row">
                <div class="shrink-0 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    {!! $qrSvg !!}
                </div>
                <div class="flex-1">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Scanne den QR-Code mit einer Authenticator-App (z. B. Google Authenticator, Authy)
                        oder gib den Schlüssel manuell ein:
                    </p>
                    <code class="mt-1 block break-words rounded bg-gray-50 p-2 text-xs dark:bg-gray-800">{{ $pendingSecret }}</code>

                    <form wire:submit.prevent="confirmSetup" class="mt-3">
                        {{ $this->form }}
                        <x-filament::button type="submit" class="mt-3">Bestätigen und aktivieren</x-filament::button>
                    </form>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="Nicht aktiviert">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Zwei-Faktor-Authentifizierung ist optional, erhöht aber die Sicherheit deines Kontos deutlich.
            </p>
            <x-filament::button wire:click="startSetup">Aktivieren</x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
