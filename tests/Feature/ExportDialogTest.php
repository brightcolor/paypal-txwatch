<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\ExportHistory;
use App\Models\ExportTemplate;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Export\ExportPlaceholders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_are_scoped_by_export_kind(): void
    {
        $all = ExportTemplate::create(['name' => 'Alle', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'applies_to' => 'all']);
        $pdf = ExportTemplate::create(['name' => 'PDF', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'applies_to' => 'pdf']);
        $csv = ExportTemplate::create(['name' => 'CSV', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'applies_to' => 'csv']);

        $pdfIds = ExportTemplate::query()->forFormat('pdf')->pluck('id');
        $this->assertTrue($pdfIds->contains($all->id));
        $this->assertTrue($pdfIds->contains($pdf->id));
        $this->assertFalse($pdfIds->contains($csv->id));

        $csvIds = ExportTemplate::query()->forFormat('xlsx')->pluck('id'); // xlsx maps to csv kind
        $this->assertTrue($csvIds->contains($all->id));
        $this->assertTrue($csvIds->contains($csv->id));
        $this->assertFalse($csvIds->contains($pdf->id));
    }

    public function test_default_for_format_respects_scope(): void
    {
        ExportTemplate::create(['name' => 'PDF-Std', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'applies_to' => 'pdf', 'is_default' => true]);

        $this->assertNotNull(ExportTemplate::defaultForFormat('pdf'));
        // The single default applies only to PDF -> no CSV default.
        $this->assertNull(ExportTemplate::defaultForFormat('csv'));
    }

    public function test_timestamp_placeholder_is_filename_safe(): void
    {
        Carbon::setTestNow('2026-07-12 14:05:00');
        $ctx = ExportPlaceholders::context(null, null, 0, Carbon::now(), 19.0);

        $this->assertSame('2026-07-12_14-05', $ctx['timestamp']);
        $this->assertSame('14:05', $ctx['time']);

        $name = ExportPlaceholders::filename('Export {{ timestamp }}', $ctx, 'csv', 'x');
        $this->assertSame('Export 2026-07-12_14-05.csv', $name);
        Carbon::setTestNow();
    }

    public function test_csv_export_produces_a_file_and_history_entry(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'gross_amount' => 10, 'net_amount' => 10, 'currency' => 'EUR', 'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListTransactions::class)
            ->callAction('exportFilter', data: [
                'csv_columns' => ['date', 'gross', 'net'],
                'csv_vat_rate' => 19,
                'csv_filename_pattern' => 'Daten {{ date }}',
            ], arguments: ['format' => 'csv'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, ExportHistory::where('format', 'csv')->count());
    }
}
