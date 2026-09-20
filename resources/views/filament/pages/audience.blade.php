<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php
        $stats = $this->stats;
    @endphp

    <div class="aud-kacheln">
        <x-filament::section>
            <div class="aud-klein">Käufer</div>
            <div class="aud-gross">{{ number_format($stats['buyers'], 0, ',', '.') }}</div>
            <div class="aud-fein">{{ number_format($stats['multi_event_buyers'], 0, ',', '.') }} bei mehreren Veranstaltungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Tickets</div>
            <div class="aud-gross">{{ number_format($stats['tickets'], 0, ',', '.') }}</div>
            <div class="aud-fein">aus {{ number_format($stats['orders'], 0, ',', '.') }} Bestellungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Umsatz</div>
            <div class="aud-gross">{{ number_format($stats['revenue'], 2, ',', '.') }} €</div>
            <div class="aud-fein">Ticketpreise aus pretix</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Anteil wiederkehrend</div>
            <div class="aud-gross">{{ number_format($stats['returning_share'], 1, ',', '.') }}&nbsp;%</div>
            <div class="aud-fein">{{ number_format($stats['tickets_without_buyer'], 0, ',', '.') }} Tickets ohne Käuferadresse</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
