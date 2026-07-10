<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixConnection;
use App\Services\Pretix\PretixClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PretixClientTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): PretixConnection
    {
        return PretixConnection::create([
            'name' => 'Test',
            'base_url' => 'https://pretix.eu',
            'organizer_slug' => 'sportverein',
            'api_token' => 'secret-token',
        ]);
    }

    public function test_successful_connection_reports_event_count(): void
    {
        Http::fake([
            'pretix.eu/api/v1/organizers/sportverein/' => Http::response(['name' => 'Sportverein e.V.'], 200),
            'pretix.eu/api/v1/organizers/sportverein/events/*' => Http::response(['count' => 3, 'results' => []], 200),
        ]);

        $result = (new PretixClient($this->connection()))->testConnection();

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['events']);
        $this->assertStringContainsString('Sportverein e.V.', $result['message']);
    }

    public function test_invalid_token_is_reported(): void
    {
        Http::fake([
            'pretix.eu/api/v1/organizers/sportverein/' => Http::response(['detail' => 'Invalid token.'], 401),
        ]);

        $result = (new PretixClient($this->connection()))->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Token', $result['message']);
    }

    public function test_unknown_organizer_is_reported(): void
    {
        Http::fake([
            'pretix.eu/api/v1/organizers/sportverein/' => Http::response(['detail' => 'Not found.'], 404),
        ]);

        $result = (new PretixClient($this->connection()))->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nicht gefunden', $result['message']);
    }

    public function test_bank_transfer_fee_helper_converts_cents_to_euro(): void
    {
        $connection = $this->connection();
        $connection->bank_transfer_fee_cents = 20;

        $this->assertSame(0.20, $connection->bankTransferFee());
        $this->assertSame('https://pretix.eu/api/v1', $connection->apiBaseUrl());
    }
}
