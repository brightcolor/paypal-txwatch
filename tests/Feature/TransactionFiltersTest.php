<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Activates every table filter once and renders the list. Guards against the
 * Filament closure-injection trap: filter closures get their arguments BY NAME
 * ($query, $data) - a param named e.g. $q silently receives a model-less
 * builder from the container and 500s on the first scope call. The plain page
 * smoke test never activates filters, so it can't catch this.
 */
class TransactionFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_filter_can_be_activated_without_a_500(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'custom_field' => 'Order X-1', 'gross_amount' => 50.00, 'fee_amount' => -1.50, 'currency' => 'EUR',
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $component = Livewire::actingAs($admin)->test(ListTransactions::class);

        $filters = [
            ['date_range', ['from' => '2026-01-01', 'until' => '2026-12-31']],
            ['amount_range', ['min' => 1, 'max' => 100]],
            ['art', ['value' => 'Zahlung']],
            ['refunds_only', ['isActive' => true]],
            ['duplicates_only', ['isActive' => true]],
            ['has_fee', ['value' => true]],
            ['has_fee', ['value' => false]],
            ['amount_sign', ['value' => true]],
            ['amount_sign', ['value' => false]],
            ['has_custom_field', ['value' => true]],
            ['has_custom_field', ['value' => false]],
            ['is_relevant', ['value' => true]],
            ['is_relevant', ['value' => false]],
            ['is_assigned', ['value' => true]],
            ['is_assigned', ['value' => false]],
        ];

        foreach ($filters as [$name, $state]) {
            $component
                ->set("tableFilters.{$name}", $state)
                ->assertOk();

            $component->call('resetTableFiltersForm')->assertOk();
        }
    }
}
