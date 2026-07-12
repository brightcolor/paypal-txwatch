<?php

namespace App\Services\Export;

use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Renders the export data array (see ExportDataBuilder) to a PDF using a
 * headless Chromium via Browsershot. Kept separate from ExportDataBuilder
 * so the data-preparation logic (grouping, sums, masking) stays unit
 * testable without needing Chromium installed.
 */
class PdfRenderer
{
    public function render(array $data, string $view = 'exports.pdf'): string
    {
        $html = view($view, $data)->render();

        // Repeating print header/footer on EVERY page (Chromium print
        // templates). Font-size must be set explicitly - Chromium's default
        // is unreadably small. Padding matches the 16mm page margins.
        $title = e($data['title'] ?? config('app.name'));
        $generated = ($data['generated_at'] ?? now())->format('d.m.Y H:i');

        $headerHtml = '<div style="font-size:9px; width:100%; color:#94a3b8; padding:6mm 16mm 0;'
            . ' display:flex; justify-content:space-between; border-bottom:0.5px solid #e2e8f0;">'
            . "<span>{$title}</span><span>erstellt am {$generated}</span></div>";

        $footerHtml = '<div style="font-size:9px; width:100%; color:#94a3b8; padding:0 16mm 4mm;'
            . ' display:flex; justify-content:space-between;">'
            . "<span>{$title}</span>"
            . '<span>Seite <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>';

        try {
            $browsershot = Browsershot::html($html)
                ->format('A4')
                // DIN A4 with proper print margins - 10mm at the sides sat too
                // close to the paper edge. Top margin leaves room for the
                // repeating header line.
                ->margins(20, 16, 20, 16)
                ->showBackground()
                ->showBrowserHeaderAndFooter()
                ->headerHtml($headerHtml)
                ->footerHtml($footerHtml)
                ->noSandbox() // required: Chromium's sandbox needs privileges containers don't grant
                // Without this, the distro Chromium package fails to launch entirely in this
                // container with "chrome_crashpad_handler: --database is required" (its crash
                // reporter expects a writable database path we don't provide and don't need).
                ->addChromiumArguments(['disable-crash-reporter', 'disable-dev-shm-usage'])
                ->waitUntilNetworkIdle();

            if ($chromePath = config('pdf.chrome_path')) {
                $browsershot->setChromePath($chromePath);
            }

            if ($nodeModulePath = config('pdf.node_module_path')) {
                $browsershot->setNodeModulePath($nodeModulePath);
            }

            if (($nodeBinary = config('pdf.node_binary')) && file_exists($nodeBinary)) {
                $browsershot->setNodeBinary($nodeBinary);
            }

            if (($npmBinary = config('pdf.npm_binary')) && file_exists($npmBinary)) {
                $browsershot->setNpmBinary($npmBinary);
            }

            return $browsershot->pdf();
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'PDF-Erzeugung fehlgeschlagen. Ist Node.js/Chromium (Puppeteer) auf dem Server installiert? '
                . 'Details: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
