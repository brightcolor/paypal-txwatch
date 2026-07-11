<?php

namespace App\Services\Pretix;

use App\Models\PretixConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the pretix REST API (https://docs.pretix.eu/en/latest/api/).
 * Authentication is a per-connection API token sent as "Authorization: Token …".
 */
class PretixClient
{
    public function __construct(private readonly PretixConnection $connection)
    {
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->connection->api_token, 'Token')
            ->acceptJson()
            ->timeout(20)
            ->baseUrl($this->connection->apiBaseUrl());
    }

    /**
     * Verifies base URL, token and organizer slug by fetching the organizer and
     * counting its events.
     *
     * @return array{success: bool, message: string, events?: int}
     */
    public function testConnection(): array
    {
        $organizer = $this->connection->organizer_slug;

        try {
            $org = $this->http()->get("/organizers/{$organizer}/");

            if ($org->status() === 401 || $org->status() === 403) {
                return ['success' => false, 'message' => 'Authentifizierung fehlgeschlagen – API-Token prüfen.'];
            }

            if ($org->status() === 404) {
                return ['success' => false, 'message' => "Organizer \"{$organizer}\" nicht gefunden – Slug/Basis-URL prüfen."];
            }

            if (! $org->successful()) {
                return ['success' => false, 'message' => "Unerwartete Antwort (HTTP {$org->status()})."];
            }

            $events = $this->http()->get("/organizers/{$organizer}/events/", ['page_size' => 1]);
            $count = (int) ($events->json('count') ?? 0);

            return [
                'success' => true,
                'message' => "Verbunden mit \"" . ($org->json('name') ?? $organizer) . "\" – {$count} Event(s).",
                'events' => $count,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    /**
     * All event slugs of the organizer (paged), for auto-matching against local events.
     *
     * @return array<int, array{slug: string, name: string}>
     */
    public function events(): array
    {
        $organizer = $this->connection->organizer_slug;
        $events = [];

        $this->paginate("/organizers/{$organizer}/events/", function (array $results) use (&$events) {
            foreach ($results as $event) {
                $events[] = [
                    'slug' => $event['slug'] ?? '',
                    'name' => self::localized($event['name'] ?? null) ?: ($event['slug'] ?? ''),
                    'date_from' => $event['date_from'] ?? null,
                    'date_to' => $event['date_to'] ?? null,
                    'location' => self::localized($event['location'] ?? null),
                ];
            }
        });

        return $events;
    }

    /**
     * The event's logo image URL from its settings (or null). pretix exposes
     * uploaded event images (logo/header) as absolute media URLs in the event
     * settings endpoint.
     */
    public function eventLogoUrl(string $eventSlug): ?string
    {
        $organizer = $this->connection->organizer_slug;

        try {
            $settings = $this->http()->get("/organizers/{$organizer}/events/{$eventSlug}/settings/");

            if (! $settings->successful()) {
                return null;
            }

            $logo = $settings->json('logo_image') ?: $settings->json('og_image') ?: null;

            if (! $logo) {
                return null;
            }

            // Media paths may be relative to the instance host, not the API base.
            return str_starts_with($logo, 'http')
                ? $logo
                : rtrim($this->connection->base_url, '/') . '/' . ltrim($logo, '/');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Downloads a pretix media URL (token-authenticated) and returns the raw
     * bytes, or null on any failure. Used to cache the event image locally.
     * Uses an absolute URL directly so the API base URL is not prepended.
     */
    public function download(string $url): ?string
    {
        try {
            $response = Http::withToken($this->connection->api_token, 'Token')->timeout(30)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * pretix returns many text fields as {locale: value} maps; pick a sensible
     * single string (German first, then whatever is there).
     */
    private static function localized(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value['de'] ?? $value['de-informal'] ?? $value['en'] ?? (reset($value) ?: null);
        }

        return $value !== '' ? $value : null;
    }

    /**
     * Follows pretix' cursor pagination. page_size is sent only on the first
     * request; subsequent pages use the absolute "next" URL as-is (Guzzle treats
     * it as absolute, overriding the base URL). Crucially, the "next" request is
     * made WITHOUT a query argument at all - passing even an empty array clears
     * the URL's own query string (dropping its page=… parameter) and loops
     * forever on page 1, which is exactly the bug this replaced.
     *
     * @param  callable(array<int, array<string, mixed>>): void  $handlePage
     * @param  array<string, string>  $query  extra first-request query params
     */
    private function paginate(string $path, callable $handlePage, array $query = []): void
    {
        $response = $this->http()->get($path, $query + ['page_size' => 50]);

        // Hard safety cap (250k rows at 50/page) so a misbehaving "next" can never
        // hammer the pretix API in an unbounded loop again.
        for ($page = 0; $page < 5000; $page++) {
            $response->throw();
            $handlePage($response->json('results', []));

            $next = $response->json('next');

            if (! $next) {
                return;
            }

            $response = $this->http()->get($next);
        }

        throw new \RuntimeException('pretix-Pagination hat das Seitenlimit überschritten – Abbruch.');
    }

    /**
     * All orders of one event (paged). When $onPage is given it is invoked with
     * each page's orders as they arrive, so callers can report live progress.
     * With $modifiedSince only orders changed after that moment are fetched
     * (pretix' modified_since filter) - the basis for incremental imports.
     *
     * @param  callable(array<int, array<string, mixed>>): void|null  $onPage
     * @return array<int, array<string, mixed>>
     */
    public function ordersForEvent(string $eventSlug, ?callable $onPage = null, ?\DateTimeInterface $modifiedSince = null): array
    {
        $organizer = $this->connection->organizer_slug;
        $orders = [];

        $query = $modifiedSince ? ['modified_since' => $modifiedSince->format(DATE_ATOM)] : [];

        $this->paginate("/organizers/{$organizer}/events/{$eventSlug}/orders/", function (array $page) use (&$orders, $onPage) {
            $orders = array_merge($orders, $page);

            if ($onPage) {
                $onPage($page);
            }
        }, $query);

        return $orders;
    }

    /**
     * Ticket capacity/availability for an event, aggregated across its quotas.
     * pretix' quota endpoint with ?with_availability=true returns each quota's
     * total size and remaining available_number; sold/blocked = size - available.
     * A quota with size=null is unlimited and marks the event as uncapped.
     *
     * @return array{capacity: ?int, available: int, sold: int, unlimited: bool, quotas: int}
     */
    public function ticketAvailability(string $eventSlug): array
    {
        $organizer = $this->connection->organizer_slug;

        $capacity = 0;
        $available = 0;
        $unlimited = false;
        $quotaCount = 0;

        try {
            $this->paginate(
                "/organizers/{$organizer}/events/{$eventSlug}/quotas/",
                function (array $page) use (&$capacity, &$available, &$unlimited, &$quotaCount) {
                    foreach ($page as $quota) {
                        $quotaCount++;
                        $size = $quota['size'] ?? null; // null = unlimited
                        $avail = $quota['available_number'] ?? null;

                        if ($size === null) {
                            $unlimited = true;

                            continue;
                        }

                        $capacity += (int) $size;
                        $available += (int) ($avail ?? 0);
                    }
                },
                ['with_availability' => 'true'],
            );
        } catch (Throwable) {
            return ['capacity' => null, 'available' => 0, 'sold' => 0, 'unlimited' => false, 'quotas' => 0];
        }

        return [
            'capacity' => $unlimited && $capacity === 0 ? null : $capacity,
            'available' => $available,
            'sold' => max(0, $capacity - $available),
            'unlimited' => $unlimited,
            'quotas' => $quotaCount,
        ];
    }

    /**
     * Control-panel deep link for an order, e.g.
     * https://pretix.eu/control/event/{organizer}/{event}/orders/{code}/
     */
    public function orderControlUrl(string $eventSlug, string $orderCode): string
    {
        return rtrim($this->connection->base_url, '/')
            . "/control/event/{$this->connection->organizer_slug}/{$eventSlug}/orders/{$orderCode}/";
    }
}
