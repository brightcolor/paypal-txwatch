<?php

namespace App\Services\Pretix;

use App\Models\PretixOrder;
use App\Models\PretixPosition;
use Illuminate\Support\Facades\DB;

/**
 * Turns one stored pretix order into the rows of `pretix_positions`.
 *
 * DELETE THEN INSERT, in one transaction: pretix sends the full order on every
 * change, so the payload is the truth and the rows are a projection of it. Updating
 * in place would have to guess which of the stored rows the payload no longer
 * contains - the delete answers that without guessing.
 */
class PositionWriter
{
    /** Rewrites the rows of one order. Returns how many were written. */
    public function write(PretixOrder $order): int
    {
        $rows = $this->rowsFor($order);

        DB::transaction(function () use ($order, $rows) {
            PretixPosition::query()->where('pretix_order_id', $order->id)->delete();

            if ($rows !== []) {
                PretixPosition::query()->insert($rows);
            }
        });

        return count($rows);
    }

    /**
     * The rows one order produces, without touching the database.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsFor(PretixOrder $order): array
    {
        $raw = $order->raw_payload ?? [];
        $addresse = is_array($raw['invoice_address'] ?? null) ? $raw['invoice_address'] : [];
        $jetzt = now();

        $mail = mb_strtolower(trim((string) $order->email));
        $mail = $mail === '' ? null : $mail;

        $rows = [];

        foreach ($raw['positions'] ?? [] as $position) {
            if (($position['canceled'] ?? false) === true) {
                continue;
            }

            $rows[] = [
                'pretix_connection_id' => $order->pretix_connection_id,
                'pretix_order_id' => $order->id,
                'event_slug' => $order->event_slug,
                'order_code' => $order->order_code,
                'order_status' => $order->status,
                'payment_provider' => $order->payment_provider,
                'buyer_email' => $mail,
                'buyer_name' => $this->text($addresse['name'] ?? null),
                'position_id' => (int) ($position['id'] ?? 0),
                'item_id' => (int) ($position['item'] ?? 0),
                'price' => (float) ($position['price'] ?? 0),
                // pretix sends the voucher's id here, not the code that was typed in.
                'voucher' => $this->text($position['voucher'] ?? null),
                'attendee_name' => $this->text($position['attendee_name'] ?? null),
                // The invoice address first: it is the buyer. The position carries an
                // address only when the ticket itself was addressed separately.
                'zipcode' => $this->text($addresse['zipcode'] ?? null) ?? $this->text($position['zipcode'] ?? null),
                'city' => $this->text($addresse['city'] ?? null) ?? $this->text($position['city'] ?? null),
                'country' => $this->text($addresse['country'] ?? null) ?? $this->text($position['country'] ?? null),
                'ordered_at' => $order->order_datetime,
                'created_at' => $jetzt,
                'updated_at' => $jetzt,
            ];
        }

        return $rows;
    }

    /** A trimmed string, or null when there is nothing in it. */
    private function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $getrimmt = trim($value);

        return $getrimmt === '' ? null : $getrimmt;
    }
}
