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
    public function render(array $data): string
    {
        $html = view('exports.pdf', $data)->render();

        $footerHtml = '<div style="font-size:9px; width:100%; text-align:center; color:#888; padding:0 10mm;">'
            . 'Seite <span class="pageNumber"></span> / <span class="totalPages"></span></div>';

        try {
            $browsershot = Browsershot::html($html)
                ->format('A4')
                ->margins(15, 10, 20, 10)
                ->showBackground()
                ->showBrowserHeaderAndFooter()
                ->hideHeader()
                ->footerHtml($footerHtml)
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
