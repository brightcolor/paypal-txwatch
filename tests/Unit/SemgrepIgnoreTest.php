<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * .semgrepignore leaves the JavaScript bundles out of the Semgrep scan that
 * Filament publishes into public/js/filament/. The folder therefore holds
 * nothing but those bundles, byte for byte as Filament ships them in
 * vendor/filament/<package>/dist; anything else there would drop out of the
 * scan as well.
 */
class SemgrepIgnoreTest extends TestCase
{
    private const BUNDLES = 'public/js/filament';

    public function test_semgrep_skips_the_filament_bundles_and_nothing_else_below_public(): void
    {
        $entries = self::entries((string) file_get_contents(self::root() . '/.semgrepignore'));

        $this->assertSame(
            ['/' . self::BUNDLES . '/'],
            array_values(array_filter($entries, fn (string $entry) => str_contains($entry, 'public'))),
            'Unter public/ nimmt .semgrepignore genau den Ordner der Filament-Bündel aus: /' . self::BUNDLES . '/',
        );
    }

    public function test_the_bundle_folder_holds_only_unchanged_filament_files(): void
    {
        $root = self::root();

        $shipped = [];
        foreach (glob("{$root}/vendor/filament/*/dist", GLOB_ONLYDIR) ?: [] as $dist) {
            foreach (self::files($dist) as $file) {
                $shipped[hash_file('sha256', $file)] = true;
            }
        }
        $this->assertNotSame([], $shipped, 'Unter vendor/filament/*/dist liegt keine Datei. Vorher composer install ausführen.');

        $foreign = [];
        foreach (self::files("{$root}/" . self::BUNDLES) as $file) {
            if (! isset($shipped[hash_file('sha256', $file)])) {
                $foreign[] = substr(str_replace('\\', '/', $file), strlen($root) + 1);
            }
        }

        $this->assertSame([], $foreign, 'Diese Dateien stimmen mit keiner Datei aus vendor/filament/*/dist überein, '
            . "und Semgrep ließe sie aus:\n" . implode("\n", $foreign)
            . "\nEigenen Code an einen anderen Ort legen, etwa resources/js; Filament-Bündel mit php artisan filament:upgrade neu veröffentlichen.");
    }

    public function test_entries_skip_comments_and_blank_lines(): void
    {
        $this->assertSame(['/a/', 'b/', '*.min.js'], self::entries("# Kommentar\n/a/\n\n  \nb/   \r\n*.min.js\n"));
    }

    /** @return list<string> */
    private static function entries(string $ignoreFile): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $ignoreFile) as $line) {
            $line = rtrim($line);
            if (trim($line) !== '' && ! str_starts_with($line, '#')) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private static function files(string $folder): array
    {
        if (! is_dir($folder)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }
}
