<?php

namespace App\Services\Export;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the settlement ("Abrechnung"): every revenue source of an event (or,
 * for a customer, all their events) - PayPal payments/refunds/chargebacks and
 * pretix-booked transfers incl. their handling fee - condensed into blocks
 * plus totals, so the final payout amount is a single number. Ledger events
 * and irrelevant-marked transactions are excluded like everywhere else.
 */
class SettlementBuilder
{
    public function build(Event $event, float $vatRate = 19.0): array
    {
        $query = Transaction::query()->where('event_id', $event->id);

        return $this->fromQuery($query, $vatRate, [
            'title' => 'Abrechnung: ' . $event->displayName(),
            'event' => $event,
            'customer' => $event->customer,
        ]);
    }

    public function buildForCustomer(Customer $customer, float $vatRate = 19.0): array
    {
        $query = Transaction::query()->whereHas('event', fn (Builder $q) => $q->where('customer_id', $customer->id));

        $data = $this->fromQuery($query, $vatRate, [
            'title' => 'Sammelabrechnung: ' . $customer->name,
            'event' => null,
            'customer' => $customer,
        ]);

        // Per-event breakdown on top of the payment-source blocks.
        $data['events'] = Transaction::query()
            ->whereHas('event', fn (Builder $q) => $q->where('customer_id', $customer->id))
            ->excludingLedgerEvents()->excludingIrrelevant()->currentRevision()
            ->where(fn (Builder $q) => $q->whereNull('transaction_status')->orWhereNotIn('transaction_status', ['D', 'P']))
            ->leftJoin('events', 'events.id', '=', 'transactions.event_id')
            ->selectRaw("COALESCE(NULLIF(events.display_name, ''), events.name, 'Ohne Event') as label")
            ->selectRaw('count(*) as cnt, COALESCE(sum(gross_amount),0) as amount, COALESCE(sum(net_amount),0) as payout')
            ->groupBy('label')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->cnt, 'amount' => (float) $r->amount, 'payout' => (float) $r->payout])
            ->all();

        return $data;
    }

    /**
     * @param  array{title: string, event: ?Event, customer: ?Customer}  $meta
     */
    private function fromQuery(Builder $query, float $vatRate, array $meta): array
    {
        $transactions = (clone $query)
            ->excludingLedgerEvents()
            ->excludingIrrelevant()
            // Only the latest revision per PayPal transaction - a status update
            // creates a new row sharing the transaction_id, and summing both
            // would pay the customer twice (audit 2026-07-12).
            ->currentRevision()
            // Money that never arrived must not be settled: Denied (D) and
            // still-Pending (P) payments are excluded. V (reversed) stays in -
            // its reversal is a separate T11xx row that nets it out.
            ->where(fn (Builder $q) => $q->whereNull('transaction_status')->orWhereNotIn('transaction_status', ['D', 'P']))
            ->with('pretixOrder')
            ->get();

        $isPretix = fn (Transaction $t) => $t->instrument_type === 'pretix';
        $isRefund = fn (Transaction $t) => $t->isRefundOrReversal();
        $isChargeback = fn (Transaction $t) => $t->isChargeback();

        $blocks = collect([
            ['label' => 'PayPal-Zahlungen', 'rows' => $transactions->filter(fn ($t) => ! $isPretix($t) && ! $isRefund($t) && ! $isChargeback($t))],
            ['label' => 'PayPal-Erstattungen', 'rows' => $transactions->filter(fn ($t) => ! $isPretix($t) && $isRefund($t) && ! $isChargeback($t))],
            ['label' => 'Rückbuchungen/Chargebacks', 'rows' => $transactions->filter(fn ($t) => $isChargeback($t))],
            ['label' => 'Überweisungen & weitere Zahlarten (pretix)', 'rows' => $transactions->filter(fn ($t) => $isPretix($t) && ! $isRefund($t))],
            ['label' => 'Erstattungen (pretix)', 'rows' => $transactions->filter(fn ($t) => $isPretix($t) && $isRefund($t))],
        ])->map(fn (array $block) => [
            'label' => $block['label'],
            'count' => $block['rows']->count(),
            'amount' => round($block['rows']->sum(fn (Transaction $t) => (float) $t->gross_amount), 2),
            'fees' => round($block['rows']->sum(fn (Transaction $t) => (float) $t->fee_amount), 2),
            'net' => round($block['rows']->sum(fn (Transaction $t) => (float) $t->net_amount), 2),
        ])->filter(fn (array $block) => $block['count'] > 0)->values()->all();

        $amount = round($transactions->sum(fn (Transaction $t) => (float) $t->gross_amount), 2);
        $vat = round($transactions->sum(fn (Transaction $t) => $t->vatAmount($vatRate)), 2);
        $dates = $transactions->pluck('transaction_initiation_date')->filter();

        return [
            'title' => $meta['title'],
            'event' => $meta['event'],
            'customer' => $meta['customer'],
            'generated_at' => now(),
            'vat_rate' => $vatRate,
            'period' => ['from' => $dates->min(), 'to' => $dates->max()],
            'blocks' => $blocks,
            'events' => [],
            'totals' => [
                'count' => $transactions->count(),
                'amount' => $amount,
                'fees' => round($transactions->sum(fn (Transaction $t) => (float) $t->fee_amount), 2),
                'payout' => round($transactions->sum(fn (Transaction $t) => (float) $t->net_amount), 2),
                'vat' => $vat,
                'net_excl_vat' => round($amount - $vat, 2),
            ],
        ];
    }

    /**
     * Freezes the built data into a Settlement record (accounting snapshot).
     */
    public function persist(array $data, User $user): Settlement
    {
        return Settlement::create([
            'event_id' => $data['event']?->id,
            'customer_id' => $data['customer']?->id,
            'title' => $data['title'],
            'period_from' => $data['period']['from'],
            'period_to' => $data['period']['to'],
            'vat_rate' => $data['vat_rate'],
            'tx_count' => $data['totals']['count'],
            'gross' => $data['totals']['amount'],
            'fees' => $data['totals']['fees'],
            'payout' => $data['totals']['payout'],
            'vat' => $data['totals']['vat'],
            'net_excl_vat' => $data['totals']['net_excl_vat'],
            'blocks' => $data['blocks'],
            'events' => $data['events'] ?? [],
            'status' => Settlement::STATUS_OPEN,
            'created_by_user_id' => $user->id,
        ]);
    }
}
