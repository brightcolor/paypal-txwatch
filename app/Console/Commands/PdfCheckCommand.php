<?php

namespace App\Console\Commands;

use App\Services\Export\PdfRenderer;
use App\Services\Export\PdfSampleDocuments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Checks the PDF export end to end: renders the sample documents of
 * PdfSampleDocuments through PdfRenderer (Blade view, Browsershot, Puppeteer,
 * Chromium) and checks that every result is a PDF with the expected pages.
 *
 * CI runs it inside the freshly built image before the image is pushed; on a
 * server it confirms a rollout: `docker compose exec app php artisan pdf:check`.
 * The documents hold fictitious data built in memory; from the database only
 * the branding (logo, claim) comes in, as with every PDF export. Files are
 * written only into the folder given with --output.
 */
#[Signature('pdf:check {--output= : Ordner für die Beispiel-PDFs, etwa zum Ansehen oder als CI-Artefakt}')]
#[Description('Erzeugt Beispiel-PDFs (Transaktionsexport, Abrechnung, Sammelabrechnung) über den PDF-Weg der Anwendung und prüft sie.')]
class PdfCheckCommand extends Command
{
    public function handle(PdfRenderer $renderer, PdfSampleDocuments $samples): int
    {
        $folder = $this->option('output');
        if (filled($folder) && ! $this->prepareFolder($folder)) {
            return self::FAILURE;
        }

        $this->line('PDF-Werkzeuge (config/pdf.php):');
        $this->components->twoColumnDetail('Node.js', $this->node());
        $this->components->twoColumnDetail('Puppeteer', $this->puppeteer());
        $this->components->twoColumnDetail('Chromium', $this->chromium());

        $this->newLine();
        $this->line('Beispiel-PDFs:');

        $documents = $samples->all();
        $failed = 0;
        $browser = null;

        foreach ($documents as $name => $document) {
            try {
                $pdf = $renderer->render($document['data'], $document['view']);
                $problem = $this->problemWith($pdf, $document['min_pages']);
            } catch (Throwable $e) {
                $pdf = null;
                $problem = $e->getMessage();
            }

            if ($problem === null && filled($folder) && File::put("{$folder}/{$name}.pdf", $pdf) === false) {
                $problem = "Die Datei {$folder}/{$name}.pdf ließ sich nicht schreiben. Prüfe den freien Platz und die Rechte des Ordners.";
            }

            if ($problem !== null) {
                $failed++;
                $this->components->twoColumnDetail($document['label'], '<fg=red;options=bold>FEHLER</>');
                $this->error("  {$problem}");

                continue;
            }

            $browser ??= self::browser($pdf);
            $this->components->twoColumnDetail($document['label'], self::describe($pdf));
        }

        if ($browser !== null) {
            $this->components->twoColumnDetail('Browser laut PDF', $browser);
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error(sprintf(
                'PDF-Erzeugung fehlgeschlagen bei %d von %d Beispiel-PDFs. Prüfe die Angaben unter „PDF-Werkzeuge“ und '
                . 'die Einträge CHROMIUM_PATH, NODE_MODULE_PATH und NODE_BINARY in der .env; die Vorgaben stehen in config/pdf.php.',
                $failed,
                count($documents),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'PDF-Erzeugung funktioniert: %d Beispiel-PDFs erzeugt%s.',
            count($documents),
            filled($folder) ? " und in {$folder} abgelegt" : '',
        ));

        return self::SUCCESS;
    }

    private function prepareFolder(string $folder): bool
    {
        if (! File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true, true);
        }

        if (File::isDirectory($folder) && File::isWritable($folder)) {
            return true;
        }

        $this->error("Der Ordner {$folder} für die Beispiel-PDFs lässt sich nicht anlegen oder beschreiben. Gib mit --output einen beschreibbaren Ordner an.");

        return false;
    }

    /** The Node.js PdfRenderer hands to Browsershot: NODE_BINARY if it exists, otherwise "node" from PATH. */
    private function node(): string
    {
        $configured = (string) config('pdf.node_binary');
        $binary = $configured !== '' && file_exists($configured) ? $configured : 'node';

        try {
            $result = Process::run([$binary, '--version']);
            if ($result->successful()) {
                return trim($result->output()) . " ({$binary})";
            }
        } catch (Throwable) {
            // Reported below like a failed call.
        }

        return "nicht aufrufbar ({$binary}); prüfe NODE_BINARY in der .env";
    }

    private function puppeteer(): string
    {
        $modules = (string) config('pdf.node_module_path');
        if ($modules === '') {
            return 'NODE_MODULE_PATH ist leer; Browsershot sucht Puppeteer über „npm root -g“';
        }

        $manifest = rtrim($modules, '/\\') . '/puppeteer/package.json';
        $version = is_readable($manifest)
            ? (json_decode((string) file_get_contents($manifest), true)['version'] ?? null)
            : null;

        return is_string($version)
            ? "{$version} ({$modules})"
            : "nicht gefunden unter {$modules}; prüfe NODE_MODULE_PATH in der .env";
    }

    private function chromium(): string
    {
        $path = (string) config('pdf.chrome_path');
        if ($path === '') {
            return 'CHROMIUM_PATH ist leer; Puppeteer startet seinen eigenen Browser';
        }

        return file_exists($path) ? $path : "nicht gefunden: {$path}; prüfe CHROMIUM_PATH in der .env";
    }

    private function problemWith(string $pdf, int $minPages): ?string
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            return 'Das Ergebnis ist kein PDF. Browsershot lieferte ' . strlen($pdf) . ' Bytes ohne PDF-Kopf.';
        }

        $pages = self::pages($pdf);

        return $pages < $minPages
            ? 'Das PDF hat ' . self::pageLabel($pages) . ", erwartet sind mindestens {$minPages}."
            : null;
    }

    private static function pageLabel(int $pages): string
    {
        return $pages === 1 ? 'eine Seite' : "{$pages} Seiten";
    }

    /** Chromium writes one "/Type /Page" dictionary per page. */
    private static function pages(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $pdf);
    }

    /**
     * Chromium names itself in the document information with its user agent,
     * e.g. "HeadlessChrome/154.0.0.0" (the user agent carries the major version).
     */
    private static function browser(string $pdf): ?string
    {
        return preg_match('#Chrom(?:e|ium)/(\d+(?:\.\d+)*)#', $pdf, $match) === 1
            ? 'Chromium ' . preg_replace('/(?:\.0)+$/', '', $match[1])
            : null;
    }

    private static function describe(string $pdf): string
    {
        return sprintf('%s, %s KB', self::pageLabel(self::pages($pdf)), number_format(strlen($pdf) / 1024, 0, ',', '.'));
    }
}
