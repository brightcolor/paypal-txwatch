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

        // No Chromium print HEADER: the document header repeats via the
        // page-frame <thead> in the blade (pages 2+ carry the full styled
        // header, the cover stays clean). The FOOTER repeats on every page and
        // carries the operator branding: small logo + claim, page numbers
        // right - "made by us", visible but not pushy.
        $title = e($data['title'] ?? 'TxWatch');

        $brandBits = '';
        try {
            $brand = \App\Models\BrandSetting::current();
            if ($logo = $brand->logoDataUri()) {
                $brandBits .= "<img src=\"{$logo}\" style=\"height:11px; vertical-align:middle; margin-right:4px;\">";
            }
            if (filled($brand->claim)) {
                $brandBits .= '<span>' . e($brand->claim) . '</span>';
            }
        } catch (Throwable) {
            // Branding is optional decoration - never block a PDF on it.
        }

        $footerHtml = '<div style="font-family: Arial, sans-serif; font-size:9px; width:100%; color:#94a3b8;'
            . ' padding:0 16mm 4mm; display:flex; justify-content:space-between; align-items:center;">'
            . '<span style="display:flex; align-items:center; gap:4px;">' . ($brandBits !== '' ? $brandBits : "<span>{$title}</span>") . '</span>'
            . '<span>Seite <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>';

        try {
            $browsershot = Browsershot::html($html)
                ->format('A4')
                // DIN A4 with proper print margins - 10mm at the sides sat too
                // close to the paper edge.
                ->margins(16, 16, 20, 16)
                ->showBackground()
                ->showBrowserHeaderAndFooter()
                ->hideHeader()
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
