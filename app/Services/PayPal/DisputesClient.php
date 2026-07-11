<?php

namespace App\Services\PayPal;

use Throwable;

/**
 * Reads the PayPal Disputes API (/v1/customer/disputes) for one account. Open
 * buyer disputes are the early-warning signal *before* money is pulled back as
 * a chargeback, so surfacing them lets the operator respond in time.
 */
class DisputesClient
{
    /** Dispute states that are NOT yet closed - the ones worth acting on. */
    private const OPEN_STATES = [
        'REQUIRED_ACTION', 'REQUIRED_OTHER_PARTY_ACTION', 'UNDER_PAYPAL_REVIEW',
        'OPEN_INQUIRIES', 'APPEALABLE', 'WAITING_FOR_BUYER_RESPONSE', 'WAITING_FOR_SELLER_RESPONSE',
    ];

    public function __construct(private readonly PayPalClient $client)
    {
    }

    /**
     * Open disputes, newest first. Returns a normalized shape; never throws
     * (returns []) so a single failing account can't break the whole page.
     *
     * @return array<int, array{id: string, status: string, reason: ?string, amount: float, currency: ?string, created: ?string, response_due: ?string, transaction_id: ?string}>
     */
    public function openDisputes(): array
    {
        $out = [];

        try {
            // dispute_state accepts a comma-separated filter; ask only for open ones.
            $path = '/v1/customer/disputes';
            $query = ['dispute_state' => implode(',', self::OPEN_STATES), 'page_size' => 50];

            for ($page = 0; $page < 100; $page++) {
                $response = $this->client->get($path, $query);
                $body = (array) $response->json();

                foreach ($body['items'] ?? [] as $d) {
                    $out[] = $this->normalize($d);
                }

                $next = $this->nextLink($body['links'] ?? []);
                if (! $next) {
                    break;
                }

                // The next link is absolute; strip the base URL to reuse get().
                $path = str_replace($this->client->baseUrl(), '', $next);
                $query = [];
            }
        } catch (Throwable) {
            return [];
        }

        // Newest first.
        usort($out, fn ($a, $b) => strcmp((string) $b['created'], (string) $a['created']));

        return $out;
    }

    /** @param array<string,mixed> $d */
    private function normalize(array $d): array
    {
        $txnId = $d['disputed_transactions'][0]['seller_transaction_id']
            ?? $d['disputed_transactions'][0]['buyer_transaction_id']
            ?? null;

        return [
            'id' => (string) ($d['dispute_id'] ?? ''),
            'status' => (string) ($d['status'] ?? $d['dispute_state'] ?? 'UNKNOWN'),
            'reason' => $d['reason'] ?? null,
            'amount' => (float) ($d['dispute_amount']['value'] ?? 0),
            'currency' => $d['dispute_amount']['currency_code'] ?? null,
            'created' => $d['create_time'] ?? null,
            'response_due' => $d['seller_response_due_date'] ?? null,
            'transaction_id' => $txnId,
        ];
    }

    /** @param array<int,array<string,mixed>> $links */
    private function nextLink(array $links): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === 'next' && ! empty($link['href'])) {
                return $link['href'];
            }
        }

        return null;
    }
}
