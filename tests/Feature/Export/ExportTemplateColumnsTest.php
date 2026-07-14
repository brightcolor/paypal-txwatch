<?php

namespace Tests\Feature\Export;

use App\Filament\Actions\ExportFilterAction;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the fix for "selecting an export template leaves the columns empty":
 * a `->simple()` Repeater must be set programmatically in its internal wrapped
 * shape ([uuid => ['column' => value]]), otherwise the rows render with empty
 * selects. This locks that shape against Filament's dehydration contract
 * (pluck by the inner field name -> flat list), used both to render and submit.
 */
class ExportTemplateColumnsTest extends TestCase
{
    /** @param array<int, string> $cols */
    private function wrap(array $cols): array
    {
        $m = new ReflectionMethod(ExportFilterAction::class, 'wrapColumns');
        $m->setAccessible(true);

        return $m->invoke(null, $cols);
    }

    public function test_wrap_produces_simple_repeater_item_shape(): void
    {
        $cols = ['gross_amount', 'net_amount', 'event'];
        $wrapped = $this->wrap($cols);

        $this->assertCount(3, $wrapped);

        foreach ($wrapped as $key => $item) {
            $this->assertIsString($key);          // uuid-keyed items
            $this->assertSame(['column'], array_keys($item));
        }

        // Filament dehydrates a simple repeater via pluck(fieldName); that must
        // reproduce the original flat column list (order preserved).
        $this->assertSame($cols, collect($wrapped)->pluck('column')->values()->all());
    }

    public function test_keys_are_unique_even_with_duplicate_columns(): void
    {
        $wrapped = $this->wrap(['a', 'a', 'b']);

        $this->assertCount(3, $wrapped);
        $this->assertCount(3, array_unique(array_keys($wrapped)));
    }

    public function test_empty_list_wraps_to_empty(): void
    {
        $this->assertSame([], $this->wrap([]));
    }
}
