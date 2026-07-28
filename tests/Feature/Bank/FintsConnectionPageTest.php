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

    /**
     * The registration number and the login name are shown masked (all but the
     * last 4 characters). Mounting must therefore never expose them in full.
     */
    public function test_stored_secrets_are_shown_masked_with_last_four_visible(): void
    {
        FintsConnection::current()->update([
            'product_id' => 'E67DD14597C6086A4634C7DB4',
            'username' => '1234567890',
        ]);

        // 25 characters -> 21 masked + the last 4 ("7DB4") visible.
        Livewire::test(FintsConnectionPage::class)
            ->assertFormSet([
                'product_id' => str_repeat('•', 21) . '7DB4',
                'username' => str_repeat('•', 6) . '7890',
            ]);
    }

    /**
     * The critical case: saving while the fields still show the mask must keep
     * the stored values - writing the mask would destroy the credentials.
     */
    public function test_saving_while_masked_keeps_the_stored_values(): void
    {
        FintsConnection::current()->update([
            'bank_code' => '14051000',
            'fints_url' => 'https://banking-mv6.s-fints-pt-mv.de/fints30',
            'product_id' => 'E67DD14597C6086A4634C7DB4',
            'username' => '1234567890',
            'pin' => 'geheim',
        ]);

        Livewire::test(FintsConnectionPage::class)
            ->fillForm(['tan_mode' => '923']) // change something unrelated
            ->call('save')
            ->assertHasNoFormErrors();

        $c = FintsConnection::current();
        $this->assertSame('E67DD14597C6086A4634C7DB4', $c->product_id);
        $this->assertSame('1234567890', $c->username);
        $this->assertSame('923', $c->tan_mode);
    }

    public function test_retyping_a_masked_field_overwrites_it(): void
    {
        FintsConnection::current()->update([
            'bank_code' => '14051000',
            'fints_url' => 'https://banking-mv6.s-fints-pt-mv.de/fints30',
            'product_id' => 'ALTE-NUMMER',
            'username' => 'alt',
            'pin' => 'geheim',
        ]);

        Livewire::test(FintsConnectionPage::class)
            ->fillForm(['product_id' => 'NEUE-NUMMER', 'username' => 'neu'])
            ->call('save')
            ->assertHasNoFormErrors();

        $c = FintsConnection::current();
        $this->assertSame('NEUE-NUMMER', $c->product_id);
        $this->assertSame('neu', $c->username);
    }

    /**
     * Pasted credentials often carry stray spaces; the bank then answers 9931
     * ("Anmeldename oder PIN ist falsch") which looks like wrong credentials.
     */
    public function test_credentials_are_trimmed_on_save(): void
    {
        Livewire::test(FintsConnectionPage::class)
            ->fillForm([
                'bank_code' => ' 14051000 ',
                'fints_url' => 'https://banking-mv6.s-fints-pt-mv.de/fints30',
                'product_id' => "  E67DD14597C6086A4634C7DB4\t",
                'product_version' => '1.0',
                'username' => "  testuser \n",
                'pin' => '  geheim  ',
                'tan_mode' => ' 923 ',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $c = FintsConnection::current();
        $this->assertSame('14051000', $c->bank_code);
        $this->assertSame('E67DD14597C6086A4634C7DB4', $c->product_id);
        $this->assertSame('testuser', $c->username);
        $this->assertSame('geheim', $c->pin);
        $this->assertSame('923', $c->tan_mode);
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
