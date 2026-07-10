<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedFilterLinkTest extends TestCase
{
    use RefreshDatabase;

    private function sharedFilter(array $filters = ['is_relevant' => ['value' => true]]): SavedFilter
    {
        return SavedFilter::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Geteilt',
            'filters' => $filters,
            'is_shared' => true,
        ]);
    }

    public function test_guest_is_redirected_to_the_filament_login_not_a_500(): void
    {
        $filter = $this->sharedFilter();

        $response = $this->get('/f/' . $filter->share_token);

        $response->assertredirect(route('filament.admin.auth.login'));
    }

    public function test_authenticated_user_gets_the_filter_loaded_and_is_redirected_to_transactions(): void
    {
        $filter = $this->sharedFilter(['date_range' => ['from' => '2026-01-01']]);

        $response = $this->actingAs(User::factory()->create())
            ->get('/f/' . $filter->share_token);

        $response->assertredirect(\App\Filament\Resources\TransactionResource::getUrl('index'));

        $this->assertSame(
            ['date_range' => ['from' => '2026-01-01']],
            session('tables.' . md5(ListTransactions::class) . '_filters'),
        );
    }

    public function test_unknown_token_is_a_404_not_a_500(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/f/does-not-exist');

        $response->assertNotFound();
    }

    public function test_non_shared_filter_token_is_a_404(): void
    {
        $filter = SavedFilter::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Privat',
            'filters' => [],
            'is_shared' => false,
        ]);

        // A non-shared filter has no share_token at all; a crafted/guessed token
        // must not resolve.
        $response = $this->actingAs(User::factory()->create())
            ->get('/f/' . ($filter->share_token ?? 'x'));

        $response->assertNotFound();
    }
}
