<?php

namespace App\Services\Pretix;

use App\Models\PretixItem;
use App\Models\PretixOrder;
use App\Models\PretixOrderLogEntry;

/**
 * Who bought which ticket type of an event - as a list you can act on.
 *
 * BUILT FROM THE STORED ORDERS, not from a live pretix query: the positions are
 * already in `raw_payload`, and an export that needs the API to be up would fail at
 * exactly the moment someone needs the addresses.
 *
 * TWO SHAPES, because two different questions are asked of the same data:
 *
 *  - ADDRESSES: one row per person, de-duplicated by e-mail. What a mailing needs;
 *    an order with three VIP tickets is one recipient, not three.
 *  - PURCHASES: one row per position. What counting and checking needs - three VIP
 *    tickets are three tickets.
 *
 * De-duplication happens on the LOWERCASED address, because pretix stores what the
 * buyer typed and "A.Mueller@…" and "a.mueller@…" are one mailbox.
 *
 * THE COLUMNS ARE CHOSEN, not fixed - same as the transaction export. Rows are built
 * complete and projected afterwards, so choosing columns can never change WHICH rows
 * come out: a narrower selection means less per person, never fewer people.
 */
class ParticipantExporter
{
    public const MODE_ADDRESSES = 'addresses';
    public const MODE_PURCHASES = 'purchases';

    /**
     * What can be exported per shape, in the order it is offered.
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        self::MODE_ADDRESSES => [
            'email' => 'E-Mail',
            'name' => 'Name',
            'items' => 'Ticketarten',
            'tickets' => 'Anzahl Tickets',
            'orders' => 'Bestellnummern',
        ],
        self::MODE_PURCHASES => [
            'order_code' => 'Bestellnummer',
            'status' => 'Status',
            'email' => 'E-Mail',
            'buyer' => 'Käufer',
            'attendee' => 'Name auf dem Ticket',
            'item' => 'Ticketart',
            'price' => 'Preis',
            'ordered_at' => 'Bestellt am',
        ],
    ];

    /** The columns of a shape, or all of them when nothing was chosen. */
    public static function columnsFor(string $mode, array $chosen = []): array
    {
        $katalog = self::COLUMNS[$mode] ?? self::COLUMNS[self::MODE_ADDRESSES];

        // Unknown keys are dropped rather than exported as an empty column: the
        // selection is client-controllable state, and a stale one must not produce a
        // list with a nameless column in it.
        $gefiltert = array_values(array_filter(
            array_map(fn ($c) => is_array($c) ? ($c['column'] ?? null) : $c, $chosen),
            fn ($key) => is_string($key) && array_key_exists($key, $katalog),
        ));

        return $gefiltert !== [] ? $gefiltert : array_keys($katalog);
    }

    /**
     * @param  array<int, int|string>  $itemIds  ticket types; empty means all of them
     * @param  array<int, string>  $statuses  pretix statuses; empty means all
     * @param  array<int, mixed>  $columns  chosen columns; empty means all of the shape
     * @return array{headings: array<int, string>, rows: array<int, array<int, mixed>>, count: int, columns: array<int, string>}
     */
    public function build(
        string $eventSlug,
        array $itemIds = [],
        array $statuses = ['p'],
        string $mode = self::MODE_ADDRESSES,
        array $columns = [],
    ): array {
        $namen = PretixItem::optionsFor($eventSlug);
        $gewaehlt = array_map('intval', $itemIds);

        $orders = PretixOrder::query()
            ->where('event_slug', $eventSlug)
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->orderBy('order_datetime')
            ->get();

        $voll = $mode === self::MODE_PURCHASES
            ? $this->purchaseRows($orders, $gewaehlt, $namen)
            : $this->addressRows($orders, $gewaehlt, $namen);

        return $this->project($voll, $mode, $columns);
    }

    /**
     * The built list as plain text: tab-separated, with a header line.
     *
     * WRITTEN HERE rather than through the spreadsheet writer, because plain text is
     * the point of the format. Unquoted, so with a single column the file is one
     * value per line - which is what gets pasted into a mail client, and what quoting
     * would ruin.
     *
     * @param  array{headings: array<int, string>, rows: array<int, array<int, mixed>>}  $built
     */
    public static function toText(array $built): string
    {
        $trenner = chr(9);
        $umbruch = chr(10);

        $zeilen = [implode($trenner, $built['headings'])];

        foreach ($built['rows'] as $row) {
            $zeilen[] = implode($trenner, array_map(
                // A tab or newline inside a value would forge an extra column or row;
                // a space keeps the shape honest.
                fn ($wert) => str_replace([chr(9), chr(13), chr(10)], " ", (string) $wert),
                $row,
            ));
        }

        return implode($umbruch, $zeilen) . $umbruch;
    }

    /**
     * Reduces the complete rows to the chosen columns, in the chosen order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{headings: array<int, string>, rows: array<int, array<int, mixed>>, count: int, columns: array<int, string>}
     */
    private function project(array $rows, string $mode, array $columns): array
    {
        $katalog = self::COLUMNS[$mode] ?? self::COLUMNS[self::MODE_ADDRESSES];
        $keys = self::columnsFor($mode, $columns);

        return [
            'columns' => $keys,
            'headings' => array_map(fn (string $k) => $katalog[$k], $keys),
            'rows' => array_map(
                fn (array $row) => array_map(fn (string $k) => $row[$k] ?? '', $keys),
                $rows,
            ),
            'count' => count($rows),
        ];
    }

    /**
     * One row per person, de-duplicated by e-mail.
     *
     * The ticket types they hold are collected into one cell rather than spread over
     * rows: someone with a VIP and a Meet & Greet ticket is still one person to write
     * to, and two rows would mean two mails.
     *
     * @param  \Illuminate\Support\Collection<int, PretixOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function addressRows($orders, array $gewaehlt, array $namen): array
    {
        $personen = [];

        foreach ($orders as $order) {
            $treffer = $this->matchingPositions($order, $gewaehlt);

            if ($treffer === []) {
                continue;
            }

            $mail = trim((string) $order->email);

            if ($mail === '') {
                continue;
            }

            $schluessel = mb_strtolower($mail);

            $personen[$schluessel] ??= [
                'email' => $mail,
                'name' => $this->buyerName($order),
                'orders' => [],
                'itemNames' => [],
                'tickets' => 0,
            ];

            $personen[$schluessel]['orders'][] = $order->order_code;
            $personen[$schluessel]['tickets'] += count($treffer);

            foreach ($treffer as $position) {
                $id = (int) ($position['item'] ?? 0);
                $personen[$schluessel]['itemNames'][$id] = $namen[$id] ?? ('Ticketart #' . $id);
            }
        }

        return array_map(fn (array $p) => [
            'email' => $p['email'],
            'name' => $p['name'],
            'items' => implode(', ', array_values($p['itemNames'])),
            'tickets' => $p['tickets'],
            'orders' => implode(', ', array_unique($p['orders'])),
        ], array_values($personen));
    }

    /**
     * One row per position - three tickets are three rows.
     *
     * @param  \Illuminate\Support\Collection<int, PretixOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function purchaseRows($orders, array $gewaehlt, array $namen): array
    {
        $rows = [];

        foreach ($orders as $order) {
            foreach ($this->matchingPositions($order, $gewaehlt) as $position) {
                $id = (int) ($position['item'] ?? 0);

                $rows[] = [
                    'order_code' => $order->order_code,
                    'status' => PretixOrderLogEntry::statusLabel($order->status),
                    'email' => (string) $order->email,
                    'buyer' => $this->buyerName($order),
                    // The name on the ticket, where the buyer entered one - it can
                    // differ from the buyer, and for a guest list that difference is
                    // the whole point.
                    'attendee' => trim((string) ($position['attendee_name'] ?? '')),
                    'item' => $namen[$id] ?? ('Ticketart #' . $id),
                    'price' => (float) ($position['price'] ?? 0),
                    'ordered_at' => $order->order_datetime?->format('d.m.Y H:i'),
                ];
            }
        }

        return $rows;
    }

    /**
     * The positions of an order that match the chosen ticket types.
     *
     * CANCELLED POSITIONS ARE LEFT OUT even when the order still counts: pretix keeps
     * them in the payload with `canceled: true`, and a cancelled ticket in a guest
     * list is a person who will not be at the door.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchingPositions(PretixOrder $order, array $gewaehlt): array
    {
        $treffer = [];

        foreach ($order->raw_payload['positions'] ?? [] as $position) {
            if (($position['canceled'] ?? false) === true) {
                continue;
            }

            if ($gewaehlt !== [] && ! in_array((int) ($position['item'] ?? 0), $gewaehlt, true)) {
                continue;
            }

            $treffer[] = $position;
        }

        return $treffer;
    }

    /** The buyer's name from the invoice address, or empty. */
    private function buyerName(PretixOrder $order): string
    {
        return trim((string) ($order->raw_payload['invoice_address']['name'] ?? ''));
    }
}
