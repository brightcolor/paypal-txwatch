<x-filament-panels::page>
    {{--
        Der Hinweis steht ÜBER dem Formular, nicht darunter: wer Adressen
        exportiert, trifft damit eine Entscheidung über personenbezogene Daten,
        und die gehört vor die Auswahl und nicht hinter den Knopf.
    --}}
    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950/40">
        <strong>Personenbezogene Daten.</strong>
        Die Liste enthält Namen und E-Mail-Adressen von Gästen. Sie darf nur für den Zweck verwendet
        werden, für den die Daten erhoben wurden – eine Werbemail an alle Ticketkäufer ist davon in
        aller Regel <em>nicht</em> gedeckt. Die Datei wird nicht dauerhaft gespeichert, sondern direkt
        heruntergeladen.
    </div>

    {{ $this->form }}
</x-filament-panels::page>
