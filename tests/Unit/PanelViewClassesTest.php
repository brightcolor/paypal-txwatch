<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Classes in the panel's own views. TxWatch has no Tailwind build: a class
 * works only if one of the panel's stylesheets already contains it, the
 * compiled CSS of Filament or werkbank.css. And a text colour for the light
 * mode needs its dark partner on the same element, or the text disappears on
 * the dark surface.
 */
class PanelViewClassesTest extends TestCase
{
    private const VIEWS = 'resources/views/filament';

    private const STYLESHEETS = [
        'public/css/filament/filament/app.css',
        'public/css/filament/forms/forms.css',
        'public/css/filament/support/support.css',
        'public/css/werkbank.css',
    ];

    private const LIGHT_TEXT_COLOUR = '/^text-(?:gray|primary|danger|warning|success|info)-\d{2,3}$/';

    public function test_classes_in_the_panel_views_exist_and_work_in_both_modes(): void
    {
        $root = dirname(__DIR__, 2);
        $css = implode("\n", array_map(fn (string $path): string => file_get_contents("{$root}/{$path}"), self::STYLESHEETS));

        $problems = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/" . self::VIEWS));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            foreach (self::problems(file_get_contents($file->getPathname()), $css) as $problem) {
                $problems[] = "{$relative}: {$problem}";
            }
        }

        $this->assertSame([], $problems, "Klassen, die im Panel nicht wirken:\n" . implode("\n", $problems));
    }

    public function test_the_check_finds_unknown_classes_and_missing_dark_partners(): void
    {
        $css = '.text-sm{font-size:.875rem}.text-gray-500{color:#5e5b55}:is(.dark .dark\:text-gray-400){color:#a3a097}'
            . '.hover\:text-gray-500:hover{color:#5e5b55}.py-1\.5{padding:.375rem 0}.neg{color:#b3146a}';
        $blade = <<<'BLADE'
            <p class="text-sm text-gray-500 dark:text-gray-400">passt</p>
            <a class="hover:text-gray-500 py-1.5 {{ $active ? 'font-bold' : '' }}">Ausdruck bleibt außen vor</a>
            <td class="text-sm @if($amount < 0) neg @endif">Blade-Anweisung bleibt außen vor</td>
            <span class="lte-bg-{{ $colour }}">angehängter Ausdruck bleibt außen vor</span>
            <p class="text-sm text-gray-500">ohne Partner</p>
            <span class="mt-4 text-sm">unbekannt</span>
            <div class="text-gray-50 dark:text-gray-400">unbekannt, obwohl text-gray-500 existiert</div>
            BLADE;

        $this->assertSame([
            'text-gray-500 ohne dark:text-… im selben class-Attribut',
            'mt-4 steht in keinem Stylesheet des Panels',
            'text-gray-50 steht in keinem Stylesheet des Panels',
        ], self::problems($blade, $css));
    }

    /**
     * @return list<string>
     */
    private static function problems(string $blade, string $css): array
    {
        preg_match_all('/\bclass="([^"]*)"/', $blade, $attributes);

        $problems = [];
        foreach ($attributes[1] as $attribute) {
            $tokens = preg_split('/\s+/', preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $attribute), -1, PREG_SPLIT_NO_EMPTY);
            $hasDarkText = (bool) preg_grep('/^dark:text-/', $tokens);

            foreach ($tokens as $token) {
                // Pieces of Blade (@if(...), comparisons, a class glued to an
                // expression) are no classes of their own.
                if (preg_match('/[@$(){}<>=\'"]|^-|-$/', $token) || ! preg_match('/[a-z]/', $token)) {
                    continue;
                }
                if (! self::defined($token, $css)) {
                    $problems[] = "{$token} steht in keinem Stylesheet des Panels";
                } elseif (preg_match(self::LIGHT_TEXT_COLOUR, $token) && ! $hasDarkText) {
                    $problems[] = "{$token} ohne dark:text-… im selben class-Attribut";
                }
            }
        }

        return $problems;
    }

    /** Whether a stylesheet has a selector for the class, as Tailwind escapes it. */
    private static function defined(string $class, string $css): bool
    {
        $selector = '.' . str_replace([':', '/', '.', '[', ']'], ['\\:', '\\/', '\\.', '\\[', '\\]'], $class);

        return (bool) preg_match('/' . preg_quote($selector, '/') . '(?![\w\\\\-])/', $css);
    }
}
