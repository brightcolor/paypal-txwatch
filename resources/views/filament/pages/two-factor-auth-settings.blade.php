<x-filament-panels::page>
    @php $user = auth()->user(); @endphp

    @if ($freshRecoveryCodes)
        <x-filament::section heading="Wiederherstellungscodes" icon="heroicon-o-key">
            <p class="mb-3 text-sm text-gray-500">
                Speichere diese Codes sicher ab - jeder ist einmal verwendbar, falls du keinen Zugriff mehr
                auf deine Authenticator-App hast. Sie werden nur dieses eine Mal angezeigt.
            </p>
            <div class="grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-4 font-mono text-sm dark:bg-gray-800">
                @foreach ($freshRecoveryCodes as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($user->hasTwoFactorEnabled())
        <x-filament::section heading="Status">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-success-600">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                    Zwei-Faktor-Authentifizierung ist aktiv seit {{ $user->two_factor_confirmed_at->format('d.m.Y H:i') }}.
                </div>
                <x-filament::button color="danger" wire:click="disable" wire:confirm="Zwei-Faktor-Authentifizierung wirklich deaktivieren?">
                    Deaktivieren
                </x-filament::button>
            </div>
        </x-filament::section>
    @elseif ($pendingSecret)
        <x-filament::section heading="Einrichtung abschließen">
            <div class="flex flex-col items-start gap-4 sm:flex-row">
                <div class="shrink-0 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    {!! $qrSvg !!}
                </div>
                <div class="flex-1">
                    <p class="text-sm text-gray-500">
                        Scanne den QR-Code mit einer Authenticator-App (z. B. Google Authenticator, Authy)
                        oder gib den Schlüssel manuell ein:
                    </p>
                    <code class="mt-1 block break-all rounded bg-gray-50 p-2 text-xs dark:bg-gray-800">{{ $pendingSecret }}</code>

                    <form wire:submit.prevent="confirmSetup" class="mt-4">
                        {{ $this->form }}
                        <x-filament::button type="submit" class="mt-3">Bestätigen und aktivieren</x-filament::button>
                    </form>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="Nicht aktiviert">
            <p class="mb-3 text-sm text-gray-500">
                Zwei-Faktor-Authentifizierung ist optional, erhöht aber die Sicherheit deines Kontos deutlich.
            </p>
            <x-filament::button wire:click="startSetup">Aktivieren</x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
