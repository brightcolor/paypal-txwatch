<?php

namespace App\Services\PayPal;

use Carbon\CarbonInterface;
use Closure;

/**
 * Wraps GET /v1/reporting/transactions: builds the request for a single
 * time window, and drives pagination across all result pages.
 *
 * PayPal hard limits (see config/paypal.php): max 31 days per window,
 * max page_size 500, max ~10,000 records per window (RESULTSET_TOO_LARGE
 * beyond that - callers must shrink the window and retry).
 */
class TransactionSearchClient
{
    public function __construct(private readonly PayPalClient $client)
    {
    }

    /**
     * Fetch a single page of transactions for the given window.
     *
     * @param  array<string,mixed>  $extraParams  e.g. transaction_status, transaction_currency
     * @return array{transaction_details: array, total_items: int, total_pages: int, page: int}
     */
    public function searchPage(
        CarbonInterface $start,
        CarbonInterface $end,
        int $page = 1,
        array $extraParams = [],
    ): array {
        $query = array_merge([
            'start_date' => $this->formatDate($start),
            'end_date' => $this->formatDate($end),
            'fields' => 'all',
            'page_size' => config('paypal.max_page_size', 500),
            'page' => $page,
        ], $extraParams);

        $response = $this->client->get('/v1/reporting/transactions', $query);
        $body = $response->json();

        return [
            'transaction_details' => $body['transaction_details'] ?? [],
            'total_items' => (int) ($body['total_items'] ?? 0),
            'total_pages' => (int) ($body['total_pages'] ?? 1),
            'page' => $page,
        ];
    }

    /**
     * Iterate every transaction in the window, across all pages, invoking
     * $onTransaction for each raw record. Returns run statistics.
     *
     * @param  Closure(array): void  $onTransaction
     * @return array{total_items: int, pages_fetched: int, api_requests: int}
     */
    public function searchAll(
        CarbonInterface $start,
        CarbonInterface $end,
        Closure $onTransaction,
        array $extraParams = [],
    ): array {
        $page = 1;
        $apiRequests = 0;
        $totalItems = 0;
        $totalPages = 1;

        do {
            $result = $this->searchPage($start, $end, $page, $extraParams);
            $apiRequests++;
            $totalItems = $result['total_items'];
            $totalPages = max(1, $result['total_pages']);

            foreach ($result['transaction_details'] as $record) {
                $onTransaction($record);
            }

            $page++;
        } while ($page <= $totalPages);

        return [
            'total_items' => $totalItems,
            'pages_fetched' => $page - 1,
            'api_requests' => $apiRequests,
        ];
    }

    /**
     * PayPal expects ISO8601 with a numeric UTC offset, e.g. 2026-01-01T00:00:00+0000.
     */
    private function formatDate(CarbonInterface $date): string
    {
        return $date->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
