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
                    'name' => is_array($event['name'] ?? null)
                        ? (reset($event['name']) ?: ($event['slug'] ?? ''))
                        : ($event['name'] ?? ($event['slug'] ?? '')),
                ];
            }
        });

        return $events;
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
     * Control-panel deep link for an order, e.g.
     * https://pretix.eu/control/event/{organizer}/{event}/orders/{code}/
     */
    public function orderControlUrl(string $eventSlug, string $orderCode): string
    {
        return rtrim($this->connection->base_url, '/')
            . "/control/event/{$this->connection->organizer_slug}/{$eventSlug}/orders/{$orderCode}/";
    }
}
