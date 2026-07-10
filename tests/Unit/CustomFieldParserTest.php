<?php

namespace Tests\Unit;

use App\Services\CustomFieldParser;
use PHPUnit\Framework\TestCase;

class CustomFieldParserTest extends TestCase
{
    public function test_it_splits_the_pretix_order_scheme(): void
    {
        $this->assertSame('GAG-WISMAR-2026', CustomFieldParser::eventReference('Order GAG-WISMAR-2026-SC3HR'));
        $this->assertSame('SC3HR', CustomFieldParser::orderNumber('Order GAG-WISMAR-2026-SC3HR'));
    }

    public function test_it_is_case_insensitive_about_the_order_label_and_ignores_extra_spacing(): void
    {
        $this->assertSame('SOMMERFEST-2026', CustomFieldParser::eventReference('order  SOMMERFEST-2026-A1B2'));
        $this->assertSame('A1B2', CustomFieldParser::orderNumber('order  SOMMERFEST-2026-A1B2'));
    }

    public function test_it_works_without_the_order_label(): void
    {
        $this->assertSame('FOO-2026', CustomFieldParser::eventReference('FOO-2026-XYZ'));
        $this->assertSame('XYZ', CustomFieldParser::orderNumber('FOO-2026-XYZ'));
    }

    public function test_null_and_empty_yield_null(): void
    {
        $this->assertNull(CustomFieldParser::eventReference(null));
        $this->assertNull(CustomFieldParser::orderNumber(null));
        $this->assertNull(CustomFieldParser::eventReference('   '));
        $this->assertNull(CustomFieldParser::orderNumber('Order '));
    }

    public function test_single_segment_falls_back_to_the_whole_value(): void
    {
        // No separable order number - the whole value stands for both parts.
        $this->assertSame('ABC', CustomFieldParser::eventReference('ABC'));
        $this->assertSame('ABC', CustomFieldParser::orderNumber('ABC'));
    }
}
