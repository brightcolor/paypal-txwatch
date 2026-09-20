<?php

namespace App\Services\Audience;

use App\Models\Event;
use App\Models\PretixItem;
use Illuminate\Support\Carbon;

/**
 * The patterns beyond the headline figures.
 *
 * Everything here is aggregated in SQL and shaped in PHP. At this size - a few
 * thousand rows - that stays fast, and the classification rules stay readable
 * instead of hiding in a CASE expression per database.
 */
class AudienceDimensions
{
    /**
     * Tickets and revenue per ticket type, with the name behind the number.
     *
     * @return array<int, array{event: string, item_id: int, name: string, tickets: int, revenue: float}>
     */
    public function ticketTypes(AudienceQuery $query): array
    {
        $zeilen = $query->positions()
            ->selectRaw('event_slug, item_id, COUNT(*) as tickets, SUM(price) as revenue')
            ->groupBy('event_slug', 'item_id')
            ->get();

        $namen = $this->itemNames();

        $arten = $zeilen->map(fn ($zeile) => [
            'event' => $zeile->event_slug,
            'item_id' => (int) $zeile->item_id,
            'name' => $namen[$zeile->event_slug][(int) $zeile->item_id] ?? ('Ticketart #' . (int) $zeile->item_id),
            'tickets' => (int) $zeile->tickets,
            'revenue' => (float) $zeile->revenue,
        ])->all();

        usort($arten, fn (array $a, array $b) => [$a['event'], $a['name']] <=> [$b['event'], $b['name']]);

        return $arten;
    }

    /**
     * Buyers who pick the same kind of ticket at every event they attend.
     *
     * COMPARED BY NAME, never by item_id: every event has its own ticket type
     * numbers, so the same "VIP" is a different number at each of them and an id
     * comparison would find loyalty nowhere.
     *
     * @return array{buyers: int, loyal: int, share: float}
     */
    public function sameTypeAcrossEvents(AudienceQuery $query): array
    {
        $zeilen = $query->buyers()
            ->select('buyer_email', 'event_slug', 'item_id')
            ->distinct()
            ->get();

        $namen = $this->itemNames();
        $proKaeufer = [];

        foreach ($zeilen as $zeile) {
            $name = $namen[$zeile->event_slug][(int) $zeile->item_id] ?? ('#' . (int) $zeile->item_id);
            $proKaeufer[$zeile->buyer_email]['events'][$zeile->event_slug] = true;
            $proKaeufer[$zeile->buyer_email]['types'][mb_strtolower(trim($name))] = true;
        }

        $mehrfach = 0;
        $treu = 0;

        foreach ($proKaeufer as $kaeufer) {
            if (count($kaeufer['events']) < 2) {
                continue;
            }

            $mehrfach++;

            if (count($kaeufer['types']) === 1) {
                $treu++;
            }
        }

        return [
            'buyers' => $mehrfach,
            'loyal' => $treu,
            'share' => $mehrfach > 0 ? round($treu * 100 / $mehrfach, 1) : 0.0,
        ];
    }

    /**
     * How long before the event people buy.
     *
     * Needs a maintained event date; tickets for events without one are reported as
     * uncovered rather than dropped silently.
     *
     * @return array{classes: array<string, int>, covered: int, uncovered: int}
     */
    public function leadTime(AudienceQuery $query): array
    {
        $termine = $this->eventDates();

        $klassen = [
            'am Tag selbst' => 0,
            '1 bis 3 Tage' => 0,
            '4 bis 7 Tage' => 0,
            '8 bis 14 Tage' => 0,
            '15 bis 30 Tage' => 0,
            '31 bis 90 Tage' => 0,
            'über 90 Tage' => 0,
        ];

        $erfasst = 0;
        $offen = 0;

        foreach ($query->positions()->select('event_slug', 'ordered_at')->get() as $zeile) {
            $termin = $termine[$zeile->event_slug] ?? null;

            if ($termin === null || $zeile->ordered_at === null) {
                $offen++;

                continue;
            }

            $erfasst++;
            $klassen[$this->leadTimeClass($this->daysBefore($zeile->ordered_at, $termin))]++;
        }

        return ['classes' => $klassen, 'covered' => $erfasst, 'uncovered' => $offen];
    }

    /**
     * Tickets per order, and how often someone books for other people.
     *
     * @return array{classes: array<string, int>, average: float, with_other_attendee: int, comparable: int}
     */
    public function groupSize(AudienceQuery $query): array
    {
        $bestellungen = $query->positions()
            ->selectRaw('event_slug, order_code, COUNT(*) as tickets')
            ->groupBy('event_slug', 'order_code')
            ->get();

        $klassen = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5 bis 9' => 0, 'ab 10' => 0];
        $summe = 0;

        foreach ($bestellungen as $bestellung) {
            $anzahl = (int) $bestellung->tickets;
            $summe += $anzahl;
            $klassen[$this->groupSizeClass($anzahl)]++;
        }

        $mitNamen = $query->positions()
            ->whereNotNull('attendee_name')
            ->whereNotNull('buyer_name')
            ->select('attendee_name', 'buyer_name')
            ->get();

        $abweichend = $mitNamen->filter(
            fn ($zeile) => mb_strtolower(trim($zeile->attendee_name)) !== mb_strtolower(trim($zeile->buyer_name)),
        )->count();

        return [
            'classes' => $klassen,
            'average' => $bestellungen->count() > 0 ? round($summe / $bestellungen->count(), 2) : 0.0,
            'with_other_attendee' => $abweichend,
            'comparable' => $mitNamen->count(),
        ];
    }

    /**
     * When people buy: per calendar day, per day before the event, per weekday, per
     * four-hour block.
     *
     * THE SECOND AXIS EARNS ITS PLACE: on a calendar the curves of two events sit
     * side by side and say nothing about each other. Counted in days before the
     * event they lie on top of each other, and that is where "this one sold late"
     * becomes visible. It needs a maintained event date, so orders without one are
     * reported rather than dropped.
     *
     * @return array{by_day: array<string, int>, by_days_before: array<int, int>, by_weekday: array<string, int>, by_hour_block: array<string, int>, without_event_date: int}
     */
    public function salesCurve(AudienceQuery $query): array
    {
        $wochentage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $bloecke = ['00–03 Uhr', '04–07 Uhr', '08–11 Uhr', '12–15 Uhr', '16–19 Uhr', '20–23 Uhr'];

        $termine = $this->eventDates();

        $proTag = [];
        $proVorlauf = [];
        $ohneTermin = 0;
        $proWochentag = array_fill_keys($wochentage, 0);
        $proBlock = array_fill_keys($bloecke, 0);

        $bestellungen = $query->positions()
            ->selectRaw('event_slug, order_code, MIN(ordered_at) as bestellt_am')
            ->groupBy('event_slug', 'order_code')
            ->get();

        foreach ($bestellungen as $bestellung) {
            if ($bestellung->bestellt_am === null) {
                continue;
            }

            $zeitpunkt = Carbon::parse($bestellung->bestellt_am);
            $tag = $zeitpunkt->format('Y-m-d');

            $proTag[$tag] = ($proTag[$tag] ?? 0) + 1;
            $proWochentag[$wochentage[$zeitpunkt->dayOfWeekIso - 1]]++;
            $proBlock[$bloecke[intdiv($zeitpunkt->hour, 4)]]++;

            $termin = $termine[$bestellung->event_slug] ?? null;

            if ($termin === null) {
                $ohneTermin++;

                continue;
            }

            $tageVorher = $this->daysBefore($bestellung->bestellt_am, $termin);
            $proVorlauf[$tageVorher] = ($proVorlauf[$tageVorher] ?? 0) + 1;
        }

        ksort($proTag);
        krsort($proVorlauf);

        return [
            'by_day' => $proTag,
            'by_days_before' => $proVorlauf,
            'by_weekday' => $proWochentag,
            'by_hour_block' => $proBlock,
            'without_event_date' => $ohneTermin,
        ];
    }

    /**
     * What an order is worth.
     *
     * THE DISTRIBUTION COMES FIRST and the median beside the average, because a
     * handful of large orders move an average far enough to describe nobody.
     *
     * @return array{classes: array<string, int>, average: float, median: float}
     */
    public function orderValue(AudienceQuery $query): array
    {
        $summen = $query->positions()
            ->selectRaw('event_slug, order_code, SUM(price) as summe')
            ->groupBy('event_slug', 'order_code')
            ->pluck('summe')
            ->map(fn ($wert) => (float) $wert)
            ->sort()
            ->values()
            ->all();

        $klassen = ['bis 10 €' => 0, '10 bis 25 €' => 0, '25 bis 50 €' => 0, '50 bis 100 €' => 0, 'über 100 €' => 0];

        foreach ($summen as $summe) {
            $klassen[$this->orderValueClass($summe)]++;
        }

        $anzahl = count($summen);

        return [
            'classes' => $klassen,
            'average' => $anzahl > 0 ? round(array_sum($summen) / $anzahl, 2) : 0.0,
            'median' => $anzahl > 0 ? $this->median($summen) : 0.0,
        ];
    }

    /**
     * How much of the audience came in on a voucher.
     *
     * pretix hands over the voucher's IDENTIFIER at the position, not the code that
     * was typed in - the code sits behind its own endpoint. The list therefore groups
     * by that identifier.
     *
     * @return array{with: int, total: int, share: float, top: array<int, array{voucher: string, tickets: int}>}
     */
    public function vouchers(AudienceQuery $query): array
    {
        $gesamt = (int) $query->positions()->count();
        $mit = (int) $query->positions()->whereNotNull('voucher')->count();

        $top = $query->positions()
            ->whereNotNull('voucher')
            ->selectRaw('voucher, COUNT(*) as tickets')
            ->groupBy('voucher')
            ->orderByDesc('tickets')
            ->orderBy('voucher')
            ->limit(15)
            ->get()
            ->map(fn ($zeile) => ['voucher' => (string) $zeile->voucher, 'tickets' => (int) $zeile->tickets])
            ->all();

        return [
            'with' => $mit,
            'total' => $gesamt,
            'share' => $gesamt > 0 ? round($mit * 100 / $gesamt, 1) : 0.0,
            'top' => $top,
        ];
    }

    /**
     * How people paid, counted per order.
     *
     * @return array<string, int>
     */
    public function paymentProviders(AudienceQuery $query): array
    {
        $zeilen = $query->positions()
            ->selectRaw("payment_provider, COUNT(DISTINCT event_slug || '/' || order_code) as bestellungen")
            ->groupBy('payment_provider')
            ->orderByDesc('bestellungen')
            ->get();

        $arten = [];

        foreach ($zeilen as $zeile) {
            $arten[(string) ($zeile->payment_provider ?: 'unbekannt')] = (int) $zeile->bestellungen;
        }

        return $arten;
    }

    /**
     * Where the audience comes from - and how much of it we actually know.
     *
     * THE COVERAGE IS PART OF THE ANSWER: the invoice address is filled on well under
     * half the orders, and a regional chart without that number reads as if it
     * described everybody.
     *
     * @return array{regions: array<int, array{region: string, tickets: int}>, cities: array<int, array{city: string, tickets: int}>, countries: array<string, int>, covered: int, total: int, coverage: float}
     */
    public function origin(AudienceQuery $query): array
    {
        $gesamt = (int) $query->positions()->count();
        $erfasst = (int) $query->positions()->whereNotNull('zipcode')->count();

        $regionen = [];

        foreach ($query->positions()->whereNotNull('zipcode')->select('zipcode')->get() as $zeile) {
            $region = mb_substr((string) preg_replace('/\s+/', '', (string) $zeile->zipcode), 0, 2);

            if ($region === '') {
                continue;
            }

            $regionen[$region] = ($regionen[$region] ?? 0) + 1;
        }

        arsort($regionen);

        $orte = $query->positions()
            ->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as tickets')
            ->groupBy('city')
            ->orderByDesc('tickets')
            ->orderBy('city')
            ->limit(15)
            ->get()
            ->map(fn ($zeile) => ['city' => (string) $zeile->city, 'tickets' => (int) $zeile->tickets])
            ->all();

        $laender = [];

        $proLand = $query->positions()
            ->whereNotNull('country')
            ->selectRaw('country, COUNT(*) as tickets')
            ->groupBy('country')
            ->get();

        foreach ($proLand as $zeile) {
            $laender[(string) $zeile->country] = (int) $zeile->tickets;
        }

        arsort($laender);

        return [
            'regions' => array_map(
                fn ($region, $anzahl) => ['region' => (string) $region, 'tickets' => $anzahl],
                array_keys($regionen),
                $regionen,
            ),
            'cities' => $orte,
            'countries' => $laender,
            'covered' => $erfasst,
            'total' => $gesamt,
            'coverage' => $gesamt > 0 ? round($erfasst * 100 / $gesamt, 1) : 0.0,
        ];
    }

    /**
     * Orders that were cancelled or expired.
     *
     * COUNTED ON THE ORDER LEVEL, and deliberately past the status filter: a fully
     * cancelled order has no active position at all, so in the position table it
     * simply does not exist - and with the default filter on "paid" it would be
     * removed before it could be counted as cancelled.
     *
     * @return array{by_event: array<string, array{cancelled: int, total: int, share: float}>, buyers_only_cancelled: int}
     */
    public function cancellations(AudienceQuery $query): array
    {
        $zeilen = $query->ordersAnyStatus()
            ->selectRaw('event_slug, status, COUNT(*) as anzahl')
            ->groupBy('event_slug', 'status')
            ->get();

        $abgebrochen = ['c', 'e'];
        $proEvent = [];

        foreach ($zeilen as $zeile) {
            $slug = (string) $zeile->event_slug;
            $proEvent[$slug] ??= ['cancelled' => 0, 'total' => 0, 'share' => 0.0];
            $proEvent[$slug]['total'] += (int) $zeile->anzahl;

            if (in_array((string) $zeile->status, $abgebrochen, true)) {
                $proEvent[$slug]['cancelled'] += (int) $zeile->anzahl;
            }
        }

        foreach ($proEvent as $slug => $werte) {
            $proEvent[$slug]['share'] = $werte['total'] > 0
                ? round($werte['cancelled'] * 100 / $werte['total'], 1)
                : 0.0;
        }

        ksort($proEvent);

        $mitAbbruch = $query->ordersAnyStatus()
            ->whereIn('status', $abgebrochen)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($mail) => mb_strtolower(trim((string) $mail)))
            ->filter(fn ($mail) => $mail !== '')
            ->unique()
            ->values();

        // Checked against the SELECTION, not the whole stock: this block sits under
        // the selection, and whoever has only cancellations here is missing from the
        // buyer list here - whether or not they hold a ticket somewhere else.
        $mitTicket = $query->positions()
            ->whereIn('buyer_email', $mitAbbruch->all())
            ->distinct()
            ->pluck('buyer_email');

        return [
            'by_event' => $proEvent,
            'buyers_only_cancelled' => $mitAbbruch->diff($mitTicket)->count(),
        ];
    }

    /**
     * Whatever questions pretix asks at a position, with the answers given.
     *
     * READ FROM THE PAYLOAD because the questions differ per event and change over
     * time; a column per question would need a migration for every new one.
     *
     * @return array<string, array{question: string, answers: array<string, int>}>
     */
    public function questions(AudienceQuery $query): array
    {
        $fragen = [];

        $query->orders()->select('id', 'raw_payload')->orderBy('id')->chunk(200, function ($seite) use (&$fragen) {
            foreach ($seite as $order) {
                foreach ($order->raw_payload['positions'] ?? [] as $position) {
                    if (($position['canceled'] ?? false) === true) {
                        continue;
                    }

                    foreach ($position['answers'] ?? [] as $antwort) {
                        $schluessel = (string) ($antwort['question_identifier'] ?? $antwort['question'] ?? '?');
                        $wert = trim((string) ($antwort['answer'] ?? ''));

                        if ($wert === '') {
                            continue;
                        }

                        $fragen[$schluessel]['question'] ??= $schluessel;
                        $fragen[$schluessel]['answers'][$wert] = ($fragen[$schluessel]['answers'][$wert] ?? 0) + 1;
                    }
                }
            }
        });

        foreach (array_keys($fragen) as $schluessel) {
            arsort($fragen[$schluessel]['answers']);
        }

        ksort($fragen);

        return $fragen;
    }

    private function orderValueClass(float $summe): string
    {
        return match (true) {
            $summe <= 10.0 => 'bis 10 €',
            $summe <= 25.0 => '10 bis 25 €',
            $summe <= 50.0 => '25 bis 50 €',
            $summe <= 100.0 => '50 bis 100 €',
            default => 'über 100 €',
        };
    }

    /** @param  array<int, float>  $sortiert */
    private function median(array $sortiert): float
    {
        $anzahl = count($sortiert);
        $mitte = intdiv($anzahl, 2);

        return $anzahl % 2 === 1
            ? round($sortiert[$mitte], 2)
            : round(($sortiert[$mitte - 1] + $sortiert[$mitte]) / 2, 2);
    }

    /**
     * Whole days between an order and its event, never negative.
     *
     * Counted from midnight to midnight, so an order on the evening before the
     * event is one day ahead and an order on the day itself is zero.
     */
    private function daysBefore(mixed $orderedAt, mixed $eventDate): int
    {
        $tage = (int) Carbon::parse($orderedAt)->startOfDay()
            ->diffInDays(Carbon::parse($eventDate)->startOfDay(), false);

        return max(0, $tage);
    }

    /** @return array<string, mixed> pretix slug => event date */
    private function eventDates(): array
    {
        return Event::query()
            ->whereNotNull('pretix_event_slug')
            ->whereNotNull('event_date')
            ->pluck('event_date', 'pretix_event_slug')
            ->all();
    }

    private function leadTimeClass(int $tage): string
    {
        return match (true) {
            $tage <= 0 => 'am Tag selbst',
            $tage <= 3 => '1 bis 3 Tage',
            $tage <= 7 => '4 bis 7 Tage',
            $tage <= 14 => '8 bis 14 Tage',
            $tage <= 30 => '15 bis 30 Tage',
            $tage <= 90 => '31 bis 90 Tage',
            default => 'über 90 Tage',
        };
    }

    private function groupSizeClass(int $anzahl): string
    {
        return match (true) {
            $anzahl <= 1 => '1',
            $anzahl === 2 => '2',
            $anzahl === 3 => '3',
            $anzahl === 4 => '4',
            $anzahl <= 9 => '5 bis 9',
            default => 'ab 10',
        };
    }

    /**
     * Ticket type names as slug => [item_id => name].
     *
     * @return array<string, array<int, string>>
     */
    private function itemNames(): array
    {
        $namen = [];

        foreach (PretixItem::query()->get(['event_slug', 'item_id', 'name']) as $art) {
            $namen[$art->event_slug][(int) $art->item_id] = (string) $art->name;
        }

        return $namen;
    }
}
