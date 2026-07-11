<?php

namespace App\Services\Export;

use App\Models\Event;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Builds the per-event settlement ("Vereinsabrechnung"): every revenue source
 * of one event - PayPal payments/refunds and pretix-booked bank transfers
 * incl. their handling fee - condensed into blocks plus totals, so the final
 * payout amount towards the club is a single number. Ledger events and
 * irrelevant-marked transactions are excluded like everywhere else.
 */
class SettlementBuilder
{
    public function build(Event $event, float $vatRate = 19.0): array
    {
        $transactions = Transaction::query()
            ->where('event_id', $event->id)
            ->excludingLedgerEvents()
            ->excludingIrrelevant()
            ->with('pretixOrder')
            ->get();

        $isPretix = fn (Transaction $t) => $t->instrument_type === 'pretix';
        $isRefund = fn (Transaction $t) => $t->isRefundOrReversal();

        $blocks = collect([
            ['label' => 'PayPal-Zahlungen', 'rows' => $transactions->filter(fn ($t) => ! $isPretix($t) && ! $isRefund($t))],
            ['label' => 'PayPal-Erstattungen', 'rows' => $transactions->filter(fn ($t) => ! $isPretix($t) && $isRefund($t))],
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
        $fees = round($transactions->sum(fn (Transaction $t) => (float) $t->fee_amount), 2);
        $vat = round($transactions->sum(fn (Transaction $t) => $t->vatAmount($vatRate)), 2);
        $dates = $transactions->pluck('transaction_initiation_date')->filter();

        return [
            'event' => $event,
            'generated_at' => now(),
            'vat_rate' => $vatRate,
            'period' => ['from' => $dates->min(), 'to' => $dates->max()],
            'blocks' => $blocks,
            'totals' => [
                'count' => $transactions->count(),
                'amount' => $amount,
                'fees' => $fees,
                'payout' => round($transactions->sum(fn (Transaction $t) => (float) $t->net_amount), 2),
                'vat' => $vat,
                'net_excl_vat' => round($amount - $vat, 2),
            ],
        ];
    }
}
