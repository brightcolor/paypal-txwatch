<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar as an inline SVG: onyx circle with the initials in yellow, the
 * workbench avatar. Built on the server, so no name leaves TxWatch (Filament's
 * default asks ui-avatars.com for every user).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model | Authenticatable $record): string
    {
        $initials = self::initials(Filament::getNameForDefaultAvatar($record));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<circle cx="32" cy="32" r="32" fill="#111111"/>'
            . '<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#fed329" '
            . 'font-family="Arial, Helvetica, sans-serif" font-size="26" font-weight="700">'
            . htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * First letters of the first two words, upper case; "?" without a name.
     */
    public static function initials(?string $name): string
    {
        $words = preg_split('/[\s._@-]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice($words, 0, 2));

        return $letters === [] ? '?' : implode('', $letters);
    }
}
