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
        $url = "/organizers/{$organizer}/events/";

        while ($url) {
            $response = $this->http()->get($url, ['page_size' => 50]);
            $response->throw();

            foreach ($response->json('results', []) as $event) {
                $events[] = [
                    'slug' => $event['slug'] ?? '',
                    'name' => is_array($event['name'] ?? null)
                        ? (reset($event['name']) ?: ($event['slug'] ?? ''))
                        : ($event['name'] ?? ($event['slug'] ?? '')),
                ];
            }

            $next = $response->json('next');
            // pretix returns absolute "next" URLs; strip the base so baseUrl() still applies.
            $url = $next ? str_replace($this->connection->apiBaseUrl(), '', $next) : null;
        }

        return $events;
    }
}
