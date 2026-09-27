<?php

namespace Tests\Unit;

use App\Services\Auth\TwoFactorPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The PINs refused as too easy to guess, and ordinary ones that pass. */
class TwoFactorPinGuessableTest extends TestCase
{
    #[DataProvider('pins')]
    public function test_guessable_pins(string $pin, bool $guessable): void
    {
        $this->assertSame($guessable, TwoFactorPin::isGuessable($pin));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pins(): array
    {
        return [
            'gleiche Ziffern' => ['000000', true],
            'aufwärts' => ['123456', true],
            'aufwärts ab 4' => ['456789', true],
            'abwärts' => ['987654', true],
            'lang aufwärts' => ['0123456789', true],
            'gewöhnlich' => ['482913', false],
            'fast eine Folge' => ['123457', false],
            'Folge über die 9 hinaus' => ['789012', false],
            'Paare' => ['112233', false],
        ];
    }
}
