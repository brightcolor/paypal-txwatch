<?php

namespace Tests\Support;

use App\Models\PretixConnection;
use App\Models\PretixItem;
use App\Models\PretixOrder;
use App\Services\Pretix\PositionWriter;

/**
 * Builds pretix orders the way the importer stores them, so tests exercise the
 * real payload shape. All names and addresses are invented.
 */
trait MakesPretixData
{
    private ?PretixConnection $pretixConnection = null;

    protected function pretixConnection(): PretixConnection
    {
        return $this->pretixConnection ??= PretixConnection::create([
            'name' => 'Verein',
            'base_url' => 'https://pretix.eu',
            'organizer_slug' => 'verein',
            'api_token' => 'tok',
            'is_active' => true,
        ]);
    }

    protected function ticketType(string $eventSlug, int $itemId, string $name): PretixItem
    {
        return PretixItem::create([
            'pretix_connection_id' => $this->pretixConnection()->id,
            'event_slug' => $eventSlug,
            'item_id' => $itemId,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<int, array{item: int, price?: float, attendee?: string, voucher?: string, canceled?: bool}>  $positions
     * @param  array<string, string>  $address
     */
    protected function pretixOrder(
        string $eventSlug,
        string $code,
        ?string $email,
        array $positions,
        string $status = 'p',
        string $orderedAt = '2026-07-05 12:00:00',
        array $address = ['name' => 'Erika Beispiel', 'zipcode' => '23966', 'city' => 'Wismar', 'country' => 'DE'],
        string $provider = 'paypal',
        bool $writePositions = true,
    ): PretixOrder {
        $nummer = 0;

        $order = PretixOrder::create([
            'pretix_connection_id' => $this->pretixConnection()->id,
            'event_slug' => $eventSlug,
            'order_code' => $code,
            'status' => $status,
            'payment_provider' => $provider,
            'email' => $email,
            'total' => array_sum(array_map(fn (array $p) => $p['price'] ?? 50.0, $positions)),
            'currency' => 'EUR',
            'order_datetime' => $orderedAt,
            'url' => 'https://pretix.eu/x/' . $code,
            'raw_payload' => [
                'code' => $code,
                'invoice_address' => $address,
                'positions' => array_map(function (array $p) use (&$nummer) {
                    $nummer++;

                    return [
                        'id' => 1000 + $nummer,
                        'positionid' => $nummer,
                        'item' => $p['item'],
                        'price' => $p['price'] ?? 50.0,
                        'attendee_name' => $p['attendee'] ?? null,
                        'voucher' => $p['voucher'] ?? null,
                        'canceled' => $p['canceled'] ?? false,
                    ];
                }, $positions),
            ],
        ]);

        if ($writePositions) {
            app(PositionWriter::class)->write($order);
        }

        return $order;
    }
}
