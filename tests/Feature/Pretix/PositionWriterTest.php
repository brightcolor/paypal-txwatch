<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixPosition;
use App\Services\Pretix\PositionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The derived table has to say the same thing as the payload it came from.
 *
 * The case that matters most is the SECOND import: pretix marks a position as
 * cancelled, and the row has to disappear. Writing only additively would leave a
 * ticket in the audience that nobody holds any more, and every count above it would
 * stay quietly wrong.
 */
class PositionWriterTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    public function test_it_writes_one_row_per_active_position(): void
    {
        $order = $this->pretixOrder('sommerfest', 'ABCDE', 'A.Mueller@example.test', [
            ['item' => 3, 'price' => 49.5, 'attendee' => 'Lena Beispiel'],
            ['item' => 4, 'price' => 20.0],
            ['item' => 4, 'price' => 20.0, 'canceled' => true],
        ], writePositions: false);

        $geschrieben = app(PositionWriter::class)->write($order);

        $this->assertSame(2, $geschrieben);
        $this->assertSame(2, PretixPosition::count());

        $erste = PretixPosition::orderBy('position_id')->first();
        $this->assertSame('sommerfest', $erste->event_slug);
        $this->assertSame('ABCDE', $erste->order_code);
        $this->assertSame('p', $erste->order_status);
        $this->assertSame('a.mueller@example.test', $erste->buyer_email);
        $this->assertSame('Erika Beispiel', $erste->buyer_name);
        $this->assertSame('Lena Beispiel', $erste->attendee_name);
        $this->assertSame(3, $erste->item_id);
        $this->assertSame('49.50', $erste->price);
        $this->assertSame('23966', $erste->zipcode);
        $this->assertSame('Wismar', $erste->city);
    }

    public function test_a_cancelled_position_disappears_on_the_next_write(): void
    {
        $order = $this->pretixOrder('sommerfest', 'ABCDE', 'k@example.test', [
            ['item' => 3],
            ['item' => 4],
        ]);

        $this->assertSame(2, PretixPosition::count());

        $payload = $order->raw_payload;
        $payload['positions'][1]['canceled'] = true;
        $order->update(['raw_payload' => $payload]);

        app(PositionWriter::class)->write($order->refresh());

        $this->assertSame(1, PretixPosition::count());
        $this->assertSame(3, PretixPosition::first()->item_id);
    }

    public function test_an_order_without_an_address_gets_no_buyer(): void
    {
        $order = $this->pretixOrder('sommerfest', 'LEER1', '  ', [['item' => 3]], writePositions: false);

        app(PositionWriter::class)->write($order);

        $this->assertNull(PretixPosition::first()->buyer_email);
    }

    public function test_writing_twice_leaves_the_same_rows(): void
    {
        $order = $this->pretixOrder('sommerfest', 'ABCDE', 'k@example.test', [['item' => 3], ['item' => 4]]);

        app(PositionWriter::class)->write($order);

        $this->assertSame(2, PretixPosition::count());
    }
}
