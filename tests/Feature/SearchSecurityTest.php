<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SearchSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function search(string $field, string $value, string $mode = 'contains'): \Illuminate\Database\Eloquent\Builder
    {
        $query = Transaction::query();
        $m = new ReflectionMethod(TransactionResource::class, 'applyStringSearch');
        $m->setAccessible(true);
        $m->invoke(null, $query, $field, $value, $mode, false);

        return $query;
    }

    public function test_search_field_is_restricted_to_an_allow_list(): void
    {
        // A crafted (non-whitelisted) field name must add NO condition, so it
        // can never be interpolated into raw SQL.
        $injected = $this->search('transactions.id) = 1 OR (1=1', 'x', 'exact');
        $this->assertCount(0, $injected->getQuery()->wheres);

        // A legitimate field does add a condition.
        $legit = $this->search('custom_field', 'ABC');
        $this->assertCount(1, $legit->getQuery()->wheres);
    }

    public function test_injection_payload_never_reaches_the_sql(): void
    {
        // The crafted field is rejected, so the payload is never interpolated
        // into the query - the SQL stays clean (and no OR-1=1 condition exists).
        $query = $this->search("custom_field') OR ('1'='1", 'nope', 'exact');

        $this->assertStringNotContainsString("1'='1", $query->toSql());
        $this->assertCount(0, $query->getQuery()->wheres);
    }

    public function test_malformed_regex_is_ignored_instead_of_erroring(): void
    {
        $query = $this->search('custom_field', '[invalid(', 'regex');

        // No condition added for an un-compilable pattern -> no DB error.
        $this->assertCount(0, $query->getQuery()->wheres);
    }
}
