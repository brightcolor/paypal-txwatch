<?php

namespace App\Services\Audience;

/**
 * Which events share an audience.
 *
 * BUILT IN PHP from a distinct list of (buyer, event) pairs rather than as a
 * self-join per pair: the pair count grows with the square of the events, and at
 * this size the whole list is a few thousand rows. One query, portable across
 * SQLite and PostgreSQL, and the intersection logic stays readable.
 */
class AudienceOverlap
{
    /**
     * @return array{events: array<int, string>, rows: array<string, array{buyers: int, shared: array<string, array{count: int, share: float}>}>}
     */
    public function matrix(AudienceQuery $query): array
    {
        $proEvent = $this->buyersByEvent($query);
        $events = array_keys($proEvent);
        sort($events);

        $rows = [];

        foreach ($events as $a) {
            $anzahlA = count($proEvent[$a]);
            $geteilt = [];

            foreach ($events as $b) {
                $gemeinsam = count(array_intersect_key($proEvent[$a], $proEvent[$b]));

                $geteilt[$b] = [
                    'count' => $gemeinsam,
                    'share' => $anzahlA > 0 ? round($gemeinsam * 100 / $anzahlA, 1) : 0.0,
                ];
            }

            $rows[$a] = ['buyers' => $anzahlA, 'shared' => $geteilt];
        }

        return ['events' => $events, 'rows' => $rows];
    }

    /**
     * New against returning buyers per event.
     *
     * A buyer counts as FIRST TIME at the event their earliest purchase in the whole
     * VISIBLE stock belongs to. Measured against the visible stock rather than the
     * current selection, so the verdict does not flip when someone unticks an event.
     *
     * @return array<string, array{first_time: int, returning: int, share: float}>
     */
    public function firstTimeByEvent(AudienceQuery $query): array
    {
        $erstes = $this->firstEventPerBuyer($query);
        $proEvent = $this->buyersByEvent($query);
        $zahlen = [];

        foreach ($proEvent as $slug => $kaeufer) {
            $neu = 0;

            foreach (array_keys($kaeufer) as $mail) {
                if (($erstes[$mail] ?? null) === $slug) {
                    $neu++;
                }
            }

            $gesamt = count($kaeufer);

            $zahlen[$slug] = [
                'first_time' => $neu,
                'returning' => $gesamt - $neu,
                'share' => $gesamt > 0 ? round(($gesamt - $neu) * 100 / $gesamt, 1) : 0.0,
            ];
        }

        ksort($zahlen);

        return $zahlen;
    }

    /**
     * The buyers of each selected event, as slug => [email => true].
     *
     * @return array<string, array<string, true>>
     */
    private function buyersByEvent(AudienceQuery $query): array
    {
        $proEvent = [];

        $paare = $query->buyers()
            ->select('event_slug', 'buyer_email')
            ->distinct()
            ->get();

        foreach ($paare as $paar) {
            $proEvent[$paar->event_slug][$paar->buyer_email] = true;
        }

        ksort($proEvent);

        return $proEvent;
    }

    /**
     * For every buyer, the event of their earliest purchase in the visible stock.
     *
     * Ties - the same timestamp at two events - go to the alphabetically first slug,
     * so the figure does not depend on the order rows come back in.
     *
     * @return array<string, string>
     */
    private function firstEventPerBuyer(AudienceQuery $query): array
    {
        $zeilen = $query->visiblePositions()
            ->whereNotNull('buyer_email')
            ->selectRaw('buyer_email, event_slug, MIN(ordered_at) as frueheste')
            ->groupBy('buyer_email', 'event_slug')
            ->get();

        $bestes = [];

        foreach ($zeilen as $zeile) {
            $mail = $zeile->buyer_email;
            $zeitpunkt = (string) $zeile->frueheste;
            $vorhanden = $bestes[$mail] ?? null;

            if ($vorhanden === null
                || $zeitpunkt < $vorhanden['at']
                || ($zeitpunkt === $vorhanden['at'] && $zeile->event_slug < $vorhanden['slug'])) {
                $bestes[$mail] = ['at' => $zeitpunkt, 'slug' => $zeile->event_slug];
            }
        }

        return array_map(fn (array $b) => $b['slug'], $bestes);
    }
}
