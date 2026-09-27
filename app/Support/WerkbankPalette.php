<?php

namespace App\Support;

/**
 * Filament's colour scales in the workbench palette of bright color.
 *
 * Filament draws every colour from a 50–950 scale and uses a few shades per
 * role: 600 for text on light surfaces, 400 for text on dark ones, 500 for
 * focus rings and checked boxes in the dark mode, 50 for light tints. Those
 * shades carry the workbench values (bc-tokens.css of the design system);
 * the shades in between only keep the scale continuous. Fills of buttons and
 * pills, the rail and the cards come from public/css/werkbank.css.
 *
 * Primary is ink: TxWatch colours positive amounts "primary" and negative
 * ones "danger", and the workbench link colour equals its error colour.
 * Links get their colour and underline in werkbank.css.
 */
final class WerkbankPalette
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return [
            'primary' => self::PRIMARY,
            'gray' => self::GRAY,
            'success' => self::SUCCESS,
            'warning' => self::WARNING,
            'danger' => self::DANGER,
            'info' => self::INFO,
        ];
    }

    /** Ink on light surfaces, light text on dark ones, cyan for focus. */
    public const PRIMARY = [
        50 => '247, 246, 242',
        100 => '241, 239, 233',
        200 => '227, 224, 216',
        300 => '217, 214, 206',
        400 => '230, 228, 222',
        500 => '10, 134, 173',
        600 => '17, 17, 17',
        700 => '26, 26, 26',
        800 => '31, 31, 34',
        900 => '20, 20, 21',
        950 => '11, 11, 12',
    ];

    /** Warm neutrals: paper and rule light, onyx surfaces dark. */
    public const GRAY = [
        50 => '247, 246, 242',
        100 => '241, 239, 233',
        200 => '227, 224, 216',
        300 => '217, 214, 206',
        400 => '163, 160, 151',
        500 => '94, 91, 85',
        600 => '74, 74, 74',
        700 => '58, 56, 52',
        800 => '31, 31, 34',
        900 => '20, 20, 21',
        950 => '11, 11, 12',
    ];

    /** Lime: running, paid, active. */
    public const SUCCESS = [
        50 => '245, 249, 222',
        100 => '234, 243, 188',
        200 => '221, 236, 148',
        300 => '205, 225, 90',
        400 => '191, 213, 53',
        500 => '170, 196, 32',
        600 => '78, 106, 0',
        700 => '61, 92, 0',
        800 => '47, 70, 0',
        900 => '35, 52, 0',
        950 => '22, 33, 0',
    ];

    /** Yellow: waiting, open, about to expire. */
    public const WARNING = [
        50 => '255, 249, 221',
        100 => '255, 241, 184',
        200 => '255, 231, 128',
        300 => '255, 224, 102',
        400 => '254, 211, 41',
        500 => '233, 188, 12',
        600 => '122, 91, 0',
        700 => '97, 72, 0',
        800 => '74, 55, 0',
        900 => '55, 41, 0',
        950 => '36, 27, 0',
    ];

    /** Pink: urgent, error, negative amounts. */
    public const DANGER = [
        50 => '251, 227, 238',
        100 => '247, 205, 225',
        200 => '240, 163, 199',
        300 => '255, 138, 190',
        400 => '255, 92, 168',
        500 => '214, 31, 122',
        600 => '179, 20, 106',
        700 => '140, 26, 76',
        800 => '110, 16, 62',
        900 => '80, 12, 45',
        950 => '42, 21, 32',
    ];

    /** Cyan: notes and neutral information; text on light surfaces in deep. */
    public const INFO = [
        50 => '227, 247, 253',
        100 => '196, 238, 251',
        200 => '150, 225, 248',
        300 => '90, 210, 246',
        400 => '29, 195, 243',
        500 => '10, 134, 173',
        600 => '5, 32, 42',
        700 => '4, 25, 33',
        800 => '3, 19, 25',
        900 => '2, 13, 17',
        950 => '1, 8, 10',
    ];
}
