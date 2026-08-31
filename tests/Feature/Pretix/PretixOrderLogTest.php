<?php

namespace Tests\Feature\Pretix;

use App\Filament\Resources\PretixOrderResource\Pages\ListPretixOrders;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\PretixOrderLogEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pretix\PretixTransactionBooker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What the import found per order, and what then happened to it.
 *
 * WHY IT IS NOT IN THE RUN'S LIVE LOG: that column is capped at 300 lines and
 * rewritten on every push. With over a thousand orders the answer to "what became
 * of order X" would be pushed out of the cap inside a single run.
 */
class PretixOrderLogTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): PretixConnection
    {
        return PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);
    }

    private function order(PretixConnection $c, string $code, string $status, float $total, string $provider = 'banktransfer'): PretixOrder
    {
        return PretixOrder::create([
            'pretix_connection_id' => $c->id, 'event_slug' => 'sommerfest', 'order_code' => $code,
            'status' => $status, 'payment_provider' => $provider, 'total' => $total, 'currency' => 'EUR',
            'url' => 'https://pretix.eu/control/order/sommerfest/' . $code . '/',
            'raw_payload' => ['payments' => [
                ['provider' => $provider, 'state' => 'confirmed', 'amount' => (string) $total],
            ]],
        ]);
    }

    /** A booked order says so, with the figures that were booked. */
    public function test_a_booked_order_is_recorded_with_its_figures(): void
    {
        $c = $this->connection();
        $this->order($c, 'BOOKD', 'p', 25.00);

        app(PretixTransactionBooker::class)->book($c);

        $entry = PretixOrderLogEntry::where('order_code', 'BOOKD')->firstOrFail();

        $this->assertSame(PretixOrderLogEntry::ACTION_BOOKED, $entry->action);
        $this->assertSame('verbucht', $entry->actionLabel());
        $this->assertStringContainsString('25,00', $entry->message);
        // The fee is part of what was booked and belongs in the record.
        $this->assertStringContainsString('Gebühr', $entry->message);
        $this->assertSame(1, Transaction::count());
    }

    /**
     * AN ORDER THAT WAS NOT BOOKED SAYS WHY.
     *
     * The half people actually ask about: "the money is there, why is nothing in
     * the books". Without a reason the answer is a shrug.
     */
    public function test_an_unpaid_order_records_why_it_was_skipped(): void
    {
        $c = $this->connection();
        $this->order($c, 'OPENX', 'n', 25.00);

        app(PretixTransactionBooker::class)->book($c);

        $entry = PretixOrderLogEntry::where('order_code', 'OPENX')->firstOrFail();

        $this->assertSame(PretixOrderLogEntry::ACTION_SKIPPED, $entry->action);
        $this->assertStringContainsString('offen', $entry->message);
        $this->assertSame(0, Transaction::count());
    }

    /** A PayPal-paid order is skipped for a different reason, and says which. */
    public function test_a_paypal_order_records_the_duplicate_reason(): void
    {
        $c = $this->connection();
        $this->order($c, 'PPORD', 'p', 25.00, 'paypal');

        app(PretixTransactionBooker::class)->book($c);

        $entry = PretixOrderLogEntry::where('order_code', 'PPORD')->firstOrFail();

        $this->assertSame(PretixOrderLogEntry::ACTION_SKIPPED, $entry->action);
        $this->assertStringContainsString('PayPal', $entry->message);
        $this->assertStringContainsString('Dublette', $entry->message);
    }

    /** Status changes are recorded with both values, not as "something changed". */
    public function test_the_difference_helper_names_both_values(): void
    {
        $changes = \App\Services\Pretix\OrderLog::differences(
            ['status' => 'n', 'total' => 25.0],
            ['status' => 'p', 'total' => 30.0],
        );

        $this->assertContains('Status: offen → bezahlt', $changes);
        $this->assertContains('Betrag: 25 → 30', $changes);
    }

    /** Unchanged snapshots produce no line - four pulls a day must not fill it up. */
    public function test_no_difference_means_no_change_line(): void
    {
        $gleich = ['status' => 'p', 'total' => 25.0, 'payment_provider' => 'banktransfer', 'email' => 'a@b.de'];

        $this->assertSame([], \App\Services\Pretix\OrderLog::differences($gleich, $gleich));
    }

    /**
     * THE LIST SHOWS EVERY STATUS - the reason it was built.
     *
     * An order that is only recorded and has no money against it appeared nowhere at
     * all before, and looked to the user like a failed import.
     */
    public function test_the_order_list_shows_every_status(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        $c = $this->connection();
        $this->order($c, 'OPENA', 'n', 25.00);
        $this->order($c, 'PAIDA', 'p', 25.00);
        $this->order($c, 'CANCA', 'c', 25.00);
        $this->order($c, 'EXPIA', 'e', 25.00);

        Livewire::test(ListPretixOrders::class)
            ->assertOk()
            ->assertSee('OPENA')->assertSee('PAIDA')
            // The two that used to be invisible everywhere.
            ->assertSee('CANCA')->assertSee('EXPIA')
            ->assertSee('storniert')->assertSee('abgelaufen');
    }

    /** The history view renders, including for an order that has none yet. */
    public function test_the_history_view_renders(): void
    {
        $c = $this->connection();
        $order = $this->order($c, 'HISTO', 'p', 25.00);

        // Without any entries - the state of every order imported before this existed.
        $leer = view('filament.pretix.order-history', ['order' => $order, 'entries' => collect()])->render();
        $this->assertStringContainsString('Noch kein Verlauf', $leer);

        app(PretixTransactionBooker::class)->book($c);

        $html = view('filament.pretix.order-history', [
            'order' => $order->refresh(),
            'entries' => PretixOrderLogEntry::where('order_code', 'HISTO')->orderBy('at')->get(),
        ])->render();

        $this->assertStringContainsString('HISTO', $html);
        $this->assertStringContainsString('verbucht', $html);
    }

    /** The record cannot be edited or removed through the UI. */
    public function test_orders_are_read_only(): void
    {
        $resource = \App\Filament\Resources\PretixOrderResource::class;
        $order = $this->order($this->connection(), 'RONLY', 'p', 25.00);

        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canEdit($order));
        $this->assertFalse($resource::canDelete($order));
    }
}
