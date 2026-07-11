<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\ExportTemplate;
use App\Models\PretixConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Exercises the WRITE side of the admin panel (the route crawl only covers
 * rendering): create + edit-save for every user-manageable resource, so a
 * broken form schema or validation rule fails CI instead of production.
 */
class CrudSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    public function test_customer_can_be_created_and_updated(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\CustomerResource\Pages\CreateCustomer::class)
            ->fillForm(['name' => 'SV Testverein', 'contact_email' => 'kasse@sv-test.de'])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::firstOrFail();
        $this->assertSame('SV Testverein', $customer->name);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\CustomerResource\Pages\EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['name' => 'SV Testverein e.V.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('SV Testverein e.V.', $customer->fresh()->name);
    }

    public function test_export_template_can_be_created_and_updated(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\ExportTemplateResource\Pages\CreateExportTemplate::class)
            // columns stays at the form's default selection (the real-world flow).
            ->fillForm([
                'name' => 'Vereins-Vorlage',
                'mode' => ExportTemplate::MODE_CUSTOMER,
                'vat_rate' => 19,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = ExportTemplate::firstOrFail();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\ExportTemplateResource\Pages\EditExportTemplate::class, ['record' => $template->id])
            ->fillForm(['vat_rate' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('7.00', $template->fresh()->vat_rate);
    }

    public function test_event_can_be_created_updated_and_deactivated(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\EventResource\Pages\CreateEvent::class)
            ->fillForm(['name' => 'Herbstturnier'])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::firstOrFail();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\EventResource\Pages\EditEvent::class, ['record' => $event->id])
            ->fillForm(['venue' => 'Sporthalle Nord'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Sporthalle Nord', $event->fresh()->venue);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\EventResource\Pages\ListEvents::class)
            ->callTableAction('toggleActive', $event);

        $this->assertFalse($event->fresh()->is_active);
    }

    public function test_pretix_connection_can_be_created_and_updated(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\PretixConnectionResource\Pages\CreatePretixConnection::class)
            ->fillForm([
                'name' => 'Zweitinstanz',
                'base_url' => 'https://tickets.example.org',
                'organizer_slug' => 'org',
                'api_token' => 'secret-token',
                'bank_transfer_fee_cents' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $connection = PretixConnection::firstOrFail();
        $this->assertSame('secret-token', $connection->api_token); // encrypted cast round-trips

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\PretixConnectionResource\Pages\EditPretixConnection::class, ['record' => $connection->id])
            ->fillForm(['bank_transfer_fee_cents' => 25])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(25, $connection->fresh()->bank_transfer_fee_cents);
        // Token untouched when the (dehydrated-only-if-filled) field stays empty.
        $this->assertSame('secret-token', $connection->fresh()->api_token);
    }

    public function test_user_can_be_created_with_role(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Kassenwart',
                'email' => 'kasse@example.org',
                'password' => 'super-secret-password',
                'roles' => [Role::findByName('manager')->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'kasse@example.org')->firstOrFail();
        $this->assertTrue($user->hasRole('manager'));
    }
}
