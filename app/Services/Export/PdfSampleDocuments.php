<?php

namespace App\Services\Export;

use App\Models\Customer;
use App\Models\Event;
use App\Models\ExportTemplate;
use App\Models\Settlement;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The documents `php artisan pdf:check` renders: a transaction export with an
 * event cover page, an event settlement and a customer settlement. Each entry
 * names the Blade view and carries data in the shape the PDF actions hand to
 * PdfRenderer (ExportFilterAction, SettlementResource; the settlements go
 * through Settlement::pdfData() itself).
 *
 * Everything is built in memory from fictitious data: the samples read no
 * transactions and save nothing, so the check is safe on the live system.
 */
class PdfSampleDocuments
{
    private const VAT_RATE = 19.0;

    /**
     * Keyed by a short name that also serves as the file name.
     *
     * @return array<string, array{label: string, view: string, data: array<string, mixed>, min_pages: int}>
     */
    public function all(): array
    {
        $customer = new Customer(['name' => 'Beispielverein e. V.']);
        $summer = $this->event($customer, 'Probelauf Sommerfest', '2026-07-18');
        $autumn = $this->event($customer, 'Probelauf Herbstmarkt', '2026-10-10');
        $summerPayments = $this->payments($summer, 'PROBE-SOMMER-2026', 48);
        $autumnPayments = $this->payments($autumn, 'PROBE-HERBST-2026', 12);

        return [
            'transaktionsexport' => [
                'label' => 'Transaktionsexport',
                'view' => 'exports.pdf',
                'data' => $this->export($summer, $summerPayments),
                // The event cover fills page 1, the table starts on page 2.
                'min_pages' => 2,
            ],
            'abrechnung' => [
                'label' => 'Abrechnung',
                'view' => 'exports.settlement',
                'data' => $this->settlement('Abrechnung: ' . $summer->displayName(), $summer, $customer, $summerPayments)->pdfData(),
                'min_pages' => 1,
            ],
            'sammelabrechnung' => [
                'label' => 'Sammelabrechnung',
                'view' => 'exports.settlement',
                'data' => $this->settlement(
                    'Sammelabrechnung: ' . $customer->name,
                    null,
                    $customer,
                    [...$summerPayments, ...$autumnPayments],
                    [$summer->displayName() => $summerPayments, $autumn->displayName() => $autumnPayments],
                )->pdfData(),
                'min_pages' => 1,
            ],
        ];
    }

    private function event(Customer $customer, string $name, string $date): Event
    {
        $event = new Event([
            'name' => $name,
            'event_date' => $date,
            'venue' => 'Marktplatz',
            'contact_person' => 'Erika Mustermann',
            'short_description' => 'Erfundene Beispieldaten für die Prüfung der PDF-Erzeugung.',
        ]);
        $event->setRelation('customer', $customer);

        return $event;
    }

    /**
     * PayPal payments with fee, a pretix bank transfer every fifth and a
     * PayPal refund every twelfth row.
     *
     * @return list<Transaction>
     */
    private function payments(Event $event, string $reference, int $count): array
    {
        $names = ['Erika Mustermann', 'Max Mustermann', 'Lieschen Müller', 'Jürgen Schäfer'];
        $prices = [24.00, 49.00, 79.00, 19.50];
        $first = Carbon::parse($event->event_date)->subDays($count + 7);

        $payments = [];
        for ($i = 1; $i <= $count; $i++) {
            $name = $names[$i % count($names)];
            $price = $prices[$i % count($prices)];
            $kind = match (true) {
                $i % 12 === 0 => 'refund',
                $i % 5 === 0 => 'transfer',
                default => 'paypal',
            };
            $gross = $kind === 'refund' ? -$price : $price;
            $fee = match ($kind) {
                'paypal' => -round($price * 0.0249 + 0.35, 2),
                'transfer' => -0.20,
                'refund' => 0.0,
            };

            $payment = new Transaction([
                'transaction_id' => sprintf('PROBE%012d', $i),
                'transaction_event_code' => match ($kind) {
                    'paypal' => 'T0006',
                    'refund' => 'T1107',
                    'transfer' => null,
                },
                'transaction_status' => 'S',
                'transaction_initiation_date' => $first->copy()->addDays($i)->setTime(8 + $i % 12, ($i * 7) % 60),
                'gross_amount' => $gross,
                'fee_amount' => $fee,
                'net_amount' => round($gross + $fee, 2),
                'currency' => 'EUR',
                'payer_name' => $name,
                'payer_email' => Str::slug($name, '.', 'de') . '@example.org',
                'custom_field' => sprintf('Order %s-%05X', $reference, $i * 4099),
                'instrument_type' => $kind === 'transfer' ? 'pretix' : null,
            ]);
            $payment->setRelation('event', $event);
            $payment->setRelation('pretixOrder', null);
            $payments[] = $payment;
        }

        return $payments;
    }

    /**
     * Same keys as ExportDataBuilder::build(), grouped by month with sums.
     *
     * @param  list<Transaction>  $payments
     * @return array<string, mixed>
     */
    private function export(Event $event, array $payments): array
    {
        $columns = array_values(array_diff(ExportTemplate::DEFAULT_COLUMNS, ExportColumns::INTERNAL_ONLY));

        $groups = [];
        foreach (collect($payments)->groupBy(fn (Transaction $t) => $t->transaction_initiation_date->translatedFormat('F Y')) as $label => $rows) {
            $groups[] = [
                'label' => $label,
                'rows' => $rows->map(fn (Transaction $t) => array_combine(
                    $columns,
                    array_map(fn (string $column) => ExportColumns::value($t, $column, false, self::VAT_RATE), $columns),
                ))->all(),
                'sum' => $this->sums($rows->all()),
            ];
        }

        return [
            'title' => 'Probelauf PDF-Erzeugung',
            'subtitle' => $event->displayName(),
            'description' => 'Erfundene Beispieldaten, erzeugt von php artisan pdf:check.',
            'mode' => ExportTemplate::MODE_CUSTOMER,
            'mask_pii' => false,
            'footer_note' => 'Die Zahlen in diesem Dokument sind erfunden.',
            'vat_rate' => self::VAT_RATE,
            'columns' => $columns,
            'column_labels' => array_map(fn (string $column) => ExportColumns::label($column), $columns),
            'event' => $event,
            'pretix_cover' => null,
            'placeholder_context' => [],
            'filename_pattern' => null,
            'period' => $this->period($payments),
            'generated_at' => Carbon::now(),
            'groups' => $groups,
            'grand_total' => $this->sums($payments),
        ];
    }

    /**
     * An unsaved settlement with the blocks SettlementBuilder would freeze.
     *
     * @param  list<Transaction>  $payments
     * @param  array<string, list<Transaction>>  $perEvent  breakdown of a customer settlement
     */
    private function settlement(string $title, ?Event $event, Customer $customer, array $payments, array $perEvent = []): Settlement
    {
        $isPretix = fn (Transaction $t) => $t->instrument_type === 'pretix';
        $blocks = collect([
            'PayPal-Zahlungen' => array_filter($payments, fn (Transaction $t) => ! $isPretix($t) && ! $t->isRefundOrReversal()),
            'PayPal-Erstattungen' => array_filter($payments, fn (Transaction $t) => ! $isPretix($t) && $t->isRefundOrReversal()),
            'Überweisungen & weitere Zahlarten (pretix)' => array_filter($payments, $isPretix),
        ])->filter()->map(function (array $rows, string $label) {
            $sums = $this->sums($rows);

            return ['label' => $label, 'count' => $sums['count'], 'amount' => $sums['gross'], 'fees' => $sums['fee'], 'net' => $sums['net']];
        });
        $events = collect($perEvent)->map(function (array $rows, string $label) {
            $sums = $this->sums($rows);

            return ['label' => $label, 'count' => $sums['count'], 'amount' => $sums['gross'], 'payout' => $sums['net']];
        });

        $sums = $this->sums($payments);
        $period = $this->period($payments);

        $settlement = new Settlement([
            'title' => $title,
            'period_from' => $period['from'],
            'period_to' => $period['to'],
            'vat_rate' => self::VAT_RATE,
            'tx_count' => $sums['count'],
            'gross' => $sums['gross'],
            'fees' => $sums['fee'],
            'payout' => $sums['net'],
            'vat' => $sums['vat'],
            'net_excl_vat' => $sums['net_excl_vat'],
            'blocks' => $blocks->values()->all(),
            'events' => $events->values()->all(),
        ]);
        $settlement->created_at = Carbon::now();
        $settlement->setRelation('event', $event);
        $settlement->setRelation('customer', $customer);

        return $settlement;
    }

    /**
     * @param  array<int, Transaction>  $payments
     * @return array{count: int, gross: float, vat: float, net_excl_vat: float, fee: float, net: float}
     */
    private function sums(array $payments): array
    {
        $total = fn (string $attribute) => round(array_sum(array_map(fn (Transaction $t) => (float) $t->{$attribute}, $payments)), 2);
        $vat = round(array_sum(array_map(fn (Transaction $t) => $t->vatAmount(self::VAT_RATE), $payments)), 2);

        return [
            'count' => count($payments),
            'gross' => $total('gross_amount'),
            'vat' => $vat,
            'net_excl_vat' => round($total('gross_amount') - $vat, 2),
            'fee' => $total('fee_amount'),
            'net' => $total('net_amount'),
        ];
    }

    /**
     * @param  list<Transaction>  $payments
     * @return array{from: Carbon, to: Carbon}
     */
    private function period(array $payments): array
    {
        $dates = collect($payments)->pluck('transaction_initiation_date');

        return ['from' => $dates->min(), 'to' => $dates->max()];
    }
}
