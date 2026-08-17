<?php

namespace Tests\Feature\Bank;

use Tests\TestCase;

/**
 * Every `source_format` the code writes has to fit its column.
 *
 * WHY THIS IS A SOURCE-READING TEST and not a round-trip: the suite runs on
 * SQLite, and SQLite ignores varchar lengths completely. An insert with a
 * 13-character value into varchar(10) passes there and fails only on PostgreSQL -
 * which is exactly how `enablebanking` reached production and dropped a whole
 * bank pull at the last step with "value too long for type character
 * varying(10)".
 *
 * So the column width is read out of the migrations and held against the literals
 * in the code. That works on any driver, including none.
 */
class SourceFormatFitsColumnTest extends TestCase
{
    public function test_every_source_format_fits_the_column(): void
    {
        $width = $this->columnWidth();
        $values = $this->valuesInCode();

        $this->assertNotSame([], $values, 'Der Test findet keine source_format-Werte mehr – dann prüft er nichts.');

        foreach ($values as $value => $files) {
            $this->assertLessThanOrEqual(
                $width,
                strlen($value),
                sprintf(
                    'source_format „%s" ist %d Zeichen lang, die Spalte fasst %d (gesetzt in %s). '
                    . 'Entweder kürzen oder die Spalte verbreitern.',
                    $value,
                    strlen($value),
                    $width,
                    implode(', ', array_unique($files)),
                ),
            );
        }
    }

    /**
     * The width the LAST migration touching this column declares.
     *
     * Sorted by file name, which is the order migrations run in - so a later
     * widening wins over the original definition, exactly as the database sees it.
     */
    private function columnWidth(): int
    {
        $width = null;

        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match_all(
                "/->string\(\s*'source_format'\s*,\s*(\d+)\s*\)/",
                (string) file_get_contents($file),
                $matches,
            )) {
                $width = (int) end($matches[1]);
            }
        }

        $this->assertNotNull($width, 'In keiner Migration ist source_format mit einer Länge deklariert.');

        return $width;
    }

    /**
     * Every literal the application assigns to source_format.
     *
     * @return array<string, array<int, string>> Wert => Dateien
     */
    private function valuesInCode(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path()),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match_all(
                "/'source_format'\s*=>\s*'([^']+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
            )) {
                foreach ($matches[1] as $value) {
                    $found[$value][] = $file->getBasename();
                }
            }
        }

        return $found;
    }
}
