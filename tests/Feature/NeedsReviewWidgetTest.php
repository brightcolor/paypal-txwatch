<?php

namespace Tests\Feature;

use App\Filament\Widgets\NeedsReviewWidget;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards against render-time closure-resolution bugs in the dashboard "Zu
 * prüfen" widget. The columns only render when there is at least one
 * mismatch/unmatched row, so an empty DB (as in the smoke test) would not
 * exercise them - this seeds one on purpose.
 */
class NeedsReviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_table_renders_with_a_mismatch_row(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'custom_field' => 'Order SOMMERFEST-ABCDE', 'gross_amount' => 50.00, 'currency' => 'EUR',
            'reconciliation_status' => Transaction::RECONCILIATION_MISMATCH,
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $this->assertTrue(NeedsReviewWidget::canView());

        Livewire::actingAs($admin)
            ->test(NeedsReviewWidget::class)
            ->assertOk()
            ->assertSee('Betrag weicht ab');
    }
}
