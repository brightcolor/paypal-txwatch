<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixConnection;
use App\Models\PretixPosition;
use App\Services\Pretix\PretixOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The IMPORT writes the positions - not only the writer when called by hand.
 *
 * Without this test the hook in the importer has no caller that checks it: the
 * writer is tested, the rebuild command is tested, and taking the one line out of
 * the importer would leave every test green while production stops filling the
 * table. The second import is the case that matters: a position cancelled in
 * pretix has to leave the audience.
 */
class PretixImportWritesPositionsTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake pretix currently answers with. */
    private array $positions = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ONE FAKE, READING A PROPERTY. Http::fake() adds to the stubs rather than
         * replacing them, and the first matching one wins - a second fake for the
         * second import would never be asked, and that import would see the old
         * state again.
         */
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/events/sportfest/orders/')) {
                return Http::response([
                    'results' => [[
                        'code' => 'ABCDE',
                        'status' => 'p',
                        'total' => '60.00',
                        'currency' => 'EUR',
                        'email' => 'Erika@Example.test',
                        'datetime' => '2026-07-05T12:00:00+02:00',
                        'payments' => [['provider' => 'paypal']],
                        'invoice_address' => ['name' => 'Erika Beispiel', 'zipcode' => '23966', 'city' => 'Wismar'],
                        'positions' => $this->positions,
                    ]],
                    'next' => null,
                ]);
            }

            return Http::response([
                'results' => [['slug' => 'sportfest', 'name' => 'Sportfest']],
                'next' => null,
            ]);
        });
    }

    private function connection(): PretixConnection
    {
        return PretixConnection::create([
            'name' => 'Verein',
            'base_url' => 'https://pretix.eu',
            'organizer_slug' => 'verein',
            'api_token' => 'tok',
        ]);
    }

    public function test_an_imported_order_brings_its_positions(): void
    {
        $this->positions = [
            ['id' => 11, 'item' => 3, 'price' => '40.00', 'canceled' => false],
            ['id' => 12, 'item' => 4, 'price' => '20.00', 'canceled' => false],
        ];

        app(PretixOrderImporter::class)->import($this->connection());

        $this->assertSame(2, PretixPosition::count());
        $this->assertSame('erika@example.test', PretixPosition::first()->buyer_email);
    }

    public function test_a_position_cancelled_in_pretix_leaves_on_the_next_import(): void
    {
        $connection = $this->connection();

        $this->positions = [
            ['id' => 11, 'item' => 3, 'price' => '40.00', 'canceled' => false],
            ['id' => 12, 'item' => 4, 'price' => '20.00', 'canceled' => false],
        ];
        app(PretixOrderImporter::class)->import($connection);
        $this->assertSame(2, PretixPosition::count());

        $this->positions[1]['canceled'] = true;
        app(PretixOrderImporter::class)->import($connection->refresh());

        $this->assertSame(1, PretixPosition::count());
        $this->assertSame(3, PretixPosition::first()->item_id);
    }
}
