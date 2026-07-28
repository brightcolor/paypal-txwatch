<?php

namespace Tests\Feature\Bank;

use App\Filament\Pages\FintsConnectionPage;
use App\Models\FintsConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards the FinTS settings form against the recurring Filament trap: closure
 * arguments are injected BY PARAMETER NAME, so `fn (?string $s)` blew up with
 * "[$s] was unresolvable" (500) the moment the form was saved.
 */
class FintsConnectionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);
    }

    public function test_saving_the_form_stores_the_settings(): void
    {
        Livewire::test(FintsConnectionPage::class)
            ->fillForm([
                'bank_code' => '14051000',
                'fints_url' => 'https://banking-mv6.s-fints-pt-mv.de/fints30',
                'product_id' => 'TEST-DK-NUMMER',
                'product_version' => '1.0',
                'username' => 'testuser',
                'pin' => 'geheim',
                'tan_mode' => '921',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $c = FintsConnection::current();
        $this->assertSame('14051000', $c->bank_code);
        $this->assertSame('https://banking-mv6.s-fints-pt-mv.de/fints30', $c->fints_url);
        $this->assertSame('TEST-DK-NUMMER', $c->product_id);
        $this->assertSame('testuser', $c->username);
        $this->assertSame('geheim', $c->pin);
    }

    public function test_saving_without_a_new_pin_keeps_the_stored_one(): void
    {
        FintsConnection::current()->update([
            'bank_code' => '14051000',
            'fints_url' => 'https://banking-mv6.s-fints-pt-mv.de/fints30',
            'product_id' => 'DK',
            'username' => 'testuser',
            'pin' => 'altes-geheimnis',
        ]);

        Livewire::test(FintsConnectionPage::class)
            ->fillForm([
                'bank_code' => '14051000',
                'fints_url' => 'https://banking-mv6.s-fints-pt-mv.de/fints30',
                'product_id' => 'DK',
                'product_version' => '1.0',
                'username' => 'testuser',
                'pin' => null, // left blank on purpose
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('altes-geheimnis', FintsConnection::current()->pin);
    }
}
