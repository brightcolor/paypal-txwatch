<?php

namespace Tests\Feature;

use App\Filament\Pages\PaypalCsvImport;
use App\Filament\Pages\Reports;
use App\Filament\Pages\TwoFactorAuthSettings;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\EventResource;
use App\Filament\Resources\ExportHistoryResource;
use App\Filament\Resources\ExportTemplateResource;
use App\Filament\Resources\FailedJobResource;
use App\Filament\Resources\PaypalAccountResource;
use App\Filament\Resources\PretixConnectionResource;
use App\Filament\Resources\PretixImportRunResource;
use App\Filament\Resources\SavedFilterResource;
use App\Filament\Resources\SettlementResource;
use App\Filament\Resources\SyncRunResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Broad "does any admin page 500 on render" net. It doesn't exercise
 * form submissions or row actions, but it catches the class of render-time
 * 500s (broken tables/infolists/forms) that repeatedly bit us in production.
 */
class AdminPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const RESOURCES = [
        PaypalAccountResource::class,
        PretixConnectionResource::class,
        PretixImportRunResource::class,
        TransactionResource::class,
        SyncRunResource::class,
        EventResource::class,
        CustomerResource::class,
        ExportTemplateResource::class,
        SavedFilterResource::class,
        UserResource::class,
        ExportHistoryResource::class,
        AuditLogResource::class,
        FailedJobResource::class,
        SettlementResource::class,
        \App\Filament\Resources\ErrorLogEntryResource::class,
        \App\Filament\Resources\LoginEventResource::class,
        \App\Filament\Resources\BankTransactionResource::class,
    ];

    private const PAGES = [
        Reports::class,
        PaypalCsvImport::class,
        TwoFactorAuthSettings::class,
        \App\Filament\Pages\MailSettingsPage::class,
        \App\Filament\Pages\FinancialCloseReport::class,
        \App\Filament\Pages\TicketStatsPage::class,
        \App\Filament\Pages\DisputesPage::class,
        \App\Filament\Pages\BrandingSettingsPage::class,
        \App\Filament\Pages\FintsConnectionPage::class,
    ];

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    public function test_no_admin_resource_page_returns_a_500(): void
    {
        $admin = $this->admin();

        // Dashboard
        $this->assertLessThan(500, $this->actingAs($admin)->get('/admin')->status(), 'Dashboard 500');

        foreach (self::RESOURCES as $resource) {
            foreach (['index', 'create'] as $page) {
                if (! array_key_exists($page, $resource::getPages())) {
                    continue;
                }

                $status = $this->actingAs($admin)->get($resource::getUrl($page))->status();
                $this->assertLessThan(500, $status, "{$resource} [{$page}] returned {$status}");
            }
        }
    }

    public function test_error_log_view_page_renders(): void
    {
        $admin = $this->admin();

        $entry = \App\Models\ErrorLogEntry::create([
            'fingerprint' => 'abc', 'exception_class' => 'RuntimeException', 'message' => 'Boom',
            'file' => '/app/Foo.php', 'line' => 12, 'status_code' => 500, 'method' => 'GET',
            'url' => 'https://x/admin', 'context' => ['ip' => '127.0.0.1'], 'trace' => '#0 ...',
            'occurrences' => 2, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $status = $this->actingAs($admin)
            ->get(\App\Filament\Resources\ErrorLogEntryResource::getUrl('view', ['record' => $entry]))
            ->status();

        $this->assertLessThan(500, $status, "Error-log view returned {$status}");
    }

    public function test_no_custom_admin_page_returns_a_500(): void
    {
        $admin = $this->admin();

        foreach (self::PAGES as $page) {
            $status = $this->actingAs($admin)->get($page::getUrl())->status();
            $this->assertLessThan(500, $status, "{$page} returned {$status}");
        }
    }
}
