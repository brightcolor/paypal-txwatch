<?php

namespace Tests\Feature\Pretix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The column widths have to fit what pretix can send.
 *
 * READ FROM THE MIGRATION, not written and read back: SQLite ignores a varchar
 * length, so a round trip would stay green no matter how narrow the column is and
 * PostgreSQL would reject the insert in production. That is the same class of
 * failure as the `source_format` column on 17.08.2026.
 */
class PretixPositionsSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** The shortest width each column may have, from pretix' own field lengths. */
    private const MINIMUM_WIDTHS = [
        'event_slug' => 255,
        'order_code' => 64,
        'payment_provider' => 64,
        'buyer_email' => 255,
        'buyer_name' => 255,
        'voucher' => 255,
        'attendee_name' => 255,
        'zipcode' => 32,
        'city' => 128,
    ];

    public function test_every_string_column_is_wide_enough(): void
    {
        $migration = File::get(database_path('migrations/2026_09_21_100000_create_pretix_positions_table.php'));

        foreach (self::MINIMUM_WIDTHS as $column => $minimum) {
            $found = preg_match(
                sprintf("/->string\('%s',\s*(\d+)\)/", preg_quote($column, '/')),
                $migration,
                $treffer,
            );

            $this->assertSame(1, $found, "Spalte {$column} wird in der Migration ohne ausdrückliche Länge angelegt.");
            $this->assertGreaterThanOrEqual(
                $minimum,
                (int) $treffer[1],
                "Spalte {$column} ist zu schmal für das, was pretix liefern kann.",
            );
        }
    }

    public function test_table_and_model_agree_on_the_fillable_columns(): void
    {
        $model = new \App\Models\PretixPosition();

        foreach ($model->getFillable() as $column) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('pretix_positions', $column),
                "Das Model nennt {$column}, die Tabelle hat diese Spalte nicht.",
            );
        }
    }
}
