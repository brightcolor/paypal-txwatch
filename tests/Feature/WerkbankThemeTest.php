<?php

namespace Tests\Feature;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The workbench theme on the panel: stylesheet and fonts from TxWatch itself,
 * avatars built on the server, nothing fetched from third parties.
 */
class WerkbankThemeTest extends TestCase
{
    use RefreshDatabase;

    private const DATA_URL = 'data:image/svg+xml;base64,';

    public function test_sign_in_page_carries_the_theme_and_the_local_fonts(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('css/werkbank.css', false)
            ->assertSee('css/werkbank-fonts.css', false)
            ->assertSee('wb-login-aside', false)
            ->assertDontSee('fonts.bunny.net', false);
    }

    public function test_panel_pages_carry_the_theme_and_the_local_avatar(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['name' => 'Anna Beispiel']);
        $admin->assignRole(Role::findByName('admin'));

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('css/werkbank.css', false)
            ->assertSee(self::DATA_URL, false)
            ->assertDontSee('ui-avatars.com', false)
            ->assertDontSee('fonts.bunny.net', false);
    }

    public function test_the_avatar_shows_the_initials(): void
    {
        $svg = $this->avatarSvg('Anna Beispiel');

        $this->assertStringContainsString('>AB</text>', $svg);
    }

    public function test_markup_in_a_name_stays_text_in_the_avatar(): void
    {
        $svg = $this->avatarSvg('<b> Test');

        $this->assertStringContainsString('>&lt;T</text>', $svg);
        $this->assertStringNotContainsString('<b>', $svg);
    }

    #[DataProvider('names')]
    public function test_initials(?string $name, string $expected): void
    {
        $this->assertSame($expected, InitialsAvatarProvider::initials($name));
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'two words' => ['Anna Beispiel', 'AB'],
            'three words' => ['Anna Maria Beispiel', 'AM'],
            'one word in lower case' => ['anna', 'A'],
            'umlauts' => ['Özlem Ünal', 'ÖÜ'],
            'e-mail address as name' => ['kasse@beispiel.example', 'KB'],
            'empty' => ['', '?'],
            'spaces only' => ['   ', '?'],
            'null' => [null, '?'],
        ];
    }

    private function avatarSvg(string $name): string
    {
        $url = (new InitialsAvatarProvider())->get(User::factory()->make(['name' => $name]));

        $this->assertStringStartsWith(self::DATA_URL, $url);

        return base64_decode(substr($url, strlen(self::DATA_URL)));
    }
}
