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
