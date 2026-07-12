<?php

namespace App\Services\Export;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Resolves {{ placeholders }} in export template texts (title, subtitle,
 * description, footer note) and in the download filename pattern. All event
 * data - local fields plus live pretix figures where a cover was loaded - is
 * exposed as placeholders so operators can write things like:
 *
 *   "Abrechnung {{ event.name }} · Spieltag {{ event.date }}"
 *   filename: "Abrechnung {{ event.name }} {{ period.to }}"
 *
 * Unknown placeholders resolve to an empty string (never a literal "{{...}}").
 */
class ExportPlaceholders
{
    /**
     * Every available placeholder key mapped to a short human description,
     * for the on-screen help. Keep in sync with context().
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        return [
            'event.name' => 'Event-Name',
            'event.display_name' => 'Anzeigename des Events',
            'event.date' => 'Veranstaltungsdatum (TT.MM.JJJJ)',
            'event.date_long' => 'Veranstaltungsdatum ausgeschrieben',
            'event.venue' => 'Veranstaltungsort (lokal)',
            'event.location' => 'Ort laut pretix (falls Event gewählt)',
            'event.slug' => 'pretix-Event-Kürzel',
            'event.contact' => 'Ansprechpartner',
            'event.description' => 'Event-Kurzbeschreibung',
            'event.begins' => 'Beginn mit Uhrzeit (aus pretix)',
            'event.begins_time' => 'Beginn-Uhrzeit (aus pretix)',
            'event.admission' => 'Einlasszeit (aus pretix)',
            'event.capacity' => 'Kapazität (aus pretix)',
            'event.sold' => 'Verkaufte/geblockte Plätze (aus pretix)',
            'event.attended' => 'Erschienene Gäste (aus pretix)',
            'customer.name' => 'Kundenname (Verein)',
            'customer.contact' => 'Kunden-Ansprechpartner',
            'customer.email' => 'Kunden-E-Mail',
            'period.from' => 'Zahlungszeitraum von',
            'period.to' => 'Zahlungszeitraum bis',
            'period.year' => 'Jahr des Zeitraum-Endes',
            'count' => 'Anzahl Transaktionen',
            'date' => 'Erstellungsdatum',
            'time' => 'Erstellungs-Uhrzeit',
            'datetime' => 'Erstellungsdatum mit Uhrzeit',
            'timestamp' => 'Zeitstempel für Dateinamen (JJJJ-MM-TT_HH-MM)',
            'vat_rate' => 'MwSt-Satz',
        ];
    }

    /**
     * Build the flat placeholder context for one export.
     *
     * @param  array{from: ?Carbon, to: ?Carbon}|null  $period
     * @param  array<string, mixed>|null  $pretixCover  see PretixEventCover
     * @return array<string, string>
     */
    public static function context(?Event $event, ?array $period, int $count, Carbon $generatedAt, float $vatRate, ?array $pretixCover = null): array
    {
        $customer = $event?->customer;
        $details = $pretixCover['details'] ?? [];
        $tz = config('app.timezone');

        $ctx = [
            'event.name' => (string) ($event?->name ?? ''),
            'event.display_name' => (string) ($event?->displayName() ?? ''),
            'event.date' => $event?->event_date?->format('d.m.Y') ?? '',
            'event.date_long' => $event?->event_date?->translatedFormat('l, d. F Y') ?? '',
            'event.venue' => (string) ($event?->venue ?? ''),
            'event.location' => (string) ($details['location'] ?? $event?->venue ?? ''),
            'event.slug' => (string) ($event?->pretix_event_slug ?? ''),
            'event.contact' => (string) ($event?->contact_person ?? ''),
            'event.description' => (string) ($event?->short_description ?? ''),
            'event.begins' => isset($details['date_from']) && $details['date_from']
                ? Carbon::parse($details['date_from'])->timezone($tz)->format('d.m.Y H:i') : '',
            'event.begins_time' => isset($details['date_from']) && $details['date_from']
                ? Carbon::parse($details['date_from'])->timezone($tz)->format('H:i') : '',
            'event.admission' => isset($details['date_admission']) && $details['date_admission']
                ? Carbon::parse($details['date_admission'])->timezone($tz)->format('H:i') : '',
            'event.capacity' => isset($pretixCover['capacity']['capacity']) && $pretixCover['capacity']['capacity'] !== null
                ? (string) $pretixCover['capacity']['capacity'] : '',
            'event.sold' => isset($pretixCover['totals']['booked']) ? (string) $pretixCover['totals']['booked'] : '',
            'event.attended' => isset($pretixCover['totals']['attended']) ? (string) $pretixCover['totals']['attended'] : '',
            'customer.name' => (string) ($customer?->name ?? ''),
            'customer.contact' => (string) ($customer?->contact_name ?? ''),
            'customer.email' => (string) ($customer?->contact_email ?? ''),
            'period.from' => ($period['from'] ?? null)?->format('d.m.Y') ?? '',
            'period.to' => ($period['to'] ?? null)?->format('d.m.Y') ?? '',
            'period.year' => ($period['to'] ?? null)?->format('Y') ?? $generatedAt->format('Y'),
            'count' => (string) $count,
            'date' => $generatedAt->format('d.m.Y'),
            'time' => $generatedAt->format('H:i'),
            'datetime' => $generatedAt->format('d.m.Y H:i'),
            'timestamp' => $generatedAt->format('Y-m-d_H-i'),
            'vat_rate' => rtrim(rtrim(number_format($vatRate, 2, ',', '.'), '0'), ',') . ' %',
        ];

        return $ctx;
    }

    /**
     * Replace {{ key }} tokens (whitespace optional, case-insensitive keys) in
     * a text. Unknown keys become empty strings.
     *
     * @param  array<string, string>  $context
     */
    public static function resolve(?string $text, array $context): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($context) {
            return $context[strtolower($m[1])] ?? '';
        }, $text);
    }

    /**
     * Build a safe download filename from a placeholder pattern. Resolves the
     * pattern, strips filesystem-unsafe characters, collapses whitespace, and
     * falls back to $fallbackBase when the result is empty. The extension is
     * always appended (never taken from user input).
     *
     * @param  array<string, string>  $context
     */
    public static function filename(?string $pattern, array $context, string $extension, string $fallbackBase): string
    {
        $base = filled($pattern) ? (string) self::resolve($pattern, $context) : '';

        // Drop filesystem-unsafe characters (turn them into spaces so words
        // don't glue together), then collapse whitespace.
        $base = preg_replace('/[^\p{L}\p{N} _\-.()]+/u', ' ', $base);
        $base = trim(preg_replace('/\s+/', ' ', $base));
        $base = trim($base, " .-");

        if ($base === '') {
            $base = $fallbackBase;
        }

        // Avoid runaway lengths.
        $base = Str::limit($base, 120, '');

        return $base . '.' . $extension;
    }
}
