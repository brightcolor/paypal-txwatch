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
        // templates). Font styles must be set explicitly - the templates
        // inherit nothing from the page. Padding matches the 16mm margins.
        $title = e($data['title'] ?? 'TxWatch');
        $subtitle = e($data['subtitle'] ?? '');
        $generated = ($data['generated_at'] ?? now())->format('d.m.Y H:i');

        $headerHtml = '<div style="font-family: Arial, sans-serif; width:100%; padding:5mm 16mm 1.5mm;'
            . ' display:flex; justify-content:space-between; align-items:flex-end;'
            . ' border-bottom:2px solid #1d4ed8;">'
            . '<span style="font-size:12px; font-weight:bold; color:#1d4ed8;">' . $title
            . ($subtitle !== '' ? ' <span style="font-weight:normal; color:#64748b; font-size:10px;">· ' . $subtitle . '</span>' : '')
            . '</span>'
            . "<span style=\"font-size:9px; color:#94a3b8;\">erstellt am {$generated}</span></div>";

        $footerHtml = '<div style="font-family: Arial, sans-serif; font-size:9px; width:100%; color:#94a3b8;'
            . ' padding:0 16mm 4mm; display:flex; justify-content:space-between;">'
            . "<span>{$title}</span>"
            . '<span>Seite <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>';

        try {
            $browsershot = Browsershot::html($html)
                ->format('A4')
                // DIN A4 with proper print margins - 10mm at the sides sat too
                // close to the paper edge. Top margin leaves room for the
                // repeating header bar.
                ->margins(22, 16, 20, 16)
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
