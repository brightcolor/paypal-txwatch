<?php

namespace Tests\Feature;

use App\Services\Export\PdfRenderer;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/**
 * `php artisan pdf:check` renders the sample documents through PdfRenderer and
 * checks every result. Chromium itself runs in the CI job "docker" against the
 * built image. Here a stand-in renderer turns each Blade view into HTML, so the
 * samples have to fit the real views, and answers with a minimal PDF.
 */
class PdfCheckCommandTest extends TestCase
{
    private string $folder;

    /** Stand-in for PdfRenderer; collects each rendered view in $renderer->rendered. */
    private ?PdfRenderer $renderer = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Paths other than the defaults in config/pdf.php.
        $this->folder = sys_get_temp_dir() . '/txwatch-pdf-check-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists("{$this->folder}/modules/puppeteer");
        File::put("{$this->folder}/modules/puppeteer/package.json", json_encode(['name' => 'puppeteer', 'version' => '25.12.0']));
        File::put("{$this->folder}/node", '');
        File::put("{$this->folder}/chromium", '');

        config([
            'pdf.node_binary' => "{$this->folder}/node",
            'pdf.node_module_path' => "{$this->folder}/modules",
            'pdf.chrome_path' => "{$this->folder}/chromium",
        ]);

        Process::fake(['*' => Process::result("v24.21.0\n")]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_renders_all_samples_and_stores_them_in_the_output_folder(): void
    {
        $this->renderWith(fn (string $view) => self::pdf($view === 'exports.pdf' ? 3 : 1));
        $output = "{$this->folder}/pdfs";

        $this->artisan('pdf:check', ['--output' => $output])
            ->expectsOutputToContain("v24.21.0 ({$this->folder}/node)")
            ->expectsOutputToContain("25.12.0 ({$this->folder}/modules)")
            ->expectsOutputToContain('3 Seiten')
            ->expectsOutputToContain('Chromium 154')
            ->expectsOutputToContain("PDF-Erzeugung funktioniert: 3 Beispiel-PDFs erzeugt und in {$output} abgelegt.")
            ->assertSuccessful();

        foreach (['transaktionsexport', 'abrechnung', 'sammelabrechnung'] as $name) {
            $this->assertStringStartsWith('%PDF-', (string) file_get_contents("{$output}/{$name}.pdf"), "{$name}.pdf fehlt oder ist kein PDF.");
        }

        Process::assertRan(fn (PendingProcess $process) => $process->command === ["{$this->folder}/node", '--version']);

        [$export, $settlement, $customerSettlement] = $this->renderer->rendered;
        $this->assertSame('exports.pdf', $export['view']);
        $this->assertStringContainsString('Probelauf Sommerfest', $export['html']);
        $this->assertStringContainsString('Lieschen Müller', $export['html']);
        $this->assertStringContainsString('Abrechnung: Probelauf Sommerfest', $settlement['html']);
        $this->assertStringContainsString('Auszahlungsbetrag', $settlement['html']);
        // The customer settlement has no event and lists every event of the customer.
        $this->assertStringContainsString('Sammelabrechnung: Beispielverein e. V.', $customerSettlement['html']);
        $this->assertStringContainsString('Probelauf Herbstmarkt', $customerSettlement['html']);
    }

    public function test_a_failing_renderer_fails_the_check_with_cause_and_next_step(): void
    {
        $this->renderWith(fn () => throw new RuntimeException('PDF-Erzeugung fehlgeschlagen. Details: Chromium beendete sich mit Code 127.'));

        $this->artisan('pdf:check')
            ->expectsOutputToContain('Chromium beendete sich mit Code 127.')
            ->expectsOutputToContain('PDF-Erzeugung fehlgeschlagen bei 3 von 3 Beispiel-PDFs. Prüfe die Angaben unter „PDF-Werkzeuge“ '
                . 'und die Einträge CHROMIUM_PATH, NODE_MODULE_PATH und NODE_BINARY in der .env')
            ->assertFailed();
    }

    public function test_a_result_without_pdf_header_or_with_too_few_pages_fails(): void
    {
        $this->renderWith(fn (string $view) => $view === 'exports.pdf' ? self::pdf(1) : '<html>Fehlerseite</html>');

        $this->artisan('pdf:check')
            ->expectsOutputToContain('Das PDF hat eine Seite, erwartet sind mindestens 2.')
            ->expectsOutputToContain('Das Ergebnis ist kein PDF.')
            ->expectsOutputToContain('PDF-Erzeugung fehlgeschlagen bei 3 von 3 Beispiel-PDFs.')
            ->assertFailed();
    }

    public function test_names_the_setting_to_check_when_a_tool_is_missing(): void
    {
        $this->renderWith(fn () => self::pdf(2));
        config([
            'pdf.node_binary' => "{$this->folder}/fehlt/node",
            'pdf.node_module_path' => "{$this->folder}/leer",
            'pdf.chrome_path' => "{$this->folder}/fehlt/chromium",
        ]);
        Process::fake(['*' => Process::result('', 'command not found', 127)]);

        $this->artisan('pdf:check')
            ->expectsOutputToContain('nicht aufrufbar (node); prüfe NODE_BINARY in der .env')
            ->expectsOutputToContain("nicht gefunden unter {$this->folder}/leer; prüfe NODE_MODULE_PATH in der .env")
            ->expectsOutputToContain("nicht gefunden: {$this->folder}/fehlt/chromium; prüfe CHROMIUM_PATH in der .env")
            ->assertSuccessful();
    }

    public function test_an_unusable_output_folder_stops_before_rendering(): void
    {
        $this->renderWith(fn () => self::pdf(2));
        $blocked = "{$this->folder}/datei";
        File::put($blocked, 'belegt');

        $this->artisan('pdf:check', ['--output' => $blocked])
            ->expectsOutputToContain("Der Ordner {$blocked} für die Beispiel-PDFs lässt sich nicht anlegen oder beschreiben.")
            ->assertFailed();

        $this->assertSame([], $this->renderer->rendered);
    }

    /** @param  Closure(string, string): string  $result  view name and rendered HTML to PDF bytes */
    private function renderWith(Closure $result): void
    {
        $this->renderer = new class($result) extends PdfRenderer
        {
            /** @var list<array{view: string, html: string}> */
            public array $rendered = [];

            public function __construct(private Closure $result) {}

            public function render(array $data, string $view = 'exports.pdf'): string
            {
                $html = view($view, $data)->render();
                $this->rendered[] = ['view' => $view, 'html' => $html];

                return ($this->result)($view, $html);
            }
        };

        $this->app->instance(PdfRenderer::class, $this->renderer);
    }

    /** The parts of a Chromium PDF the check reads: header, page dictionaries, creator. */
    private static function pdf(int $pages): string
    {
        return "%PDF-1.4\n"
            . str_repeat("<</Type /Page /Parent 2 0 R>>\n", $pages)
            . "<</Type /Pages /Count {$pages}>>\n"
            . "<</Creator (Mozilla/5.0 \\(X11; Linux x86_64\\) AppleWebKit/537.36 \\(KHTML, like Gecko\\) HeadlessChrome/154.0.0.0 Safari/537.36)>>\n"
            . "%%EOF\n";
    }
}
