<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One ticket type of one event - the name behind the numeric id in an order position.
 *
 * Reference data owned by pretix: refreshed on every order import, never edited here.
 */
class PretixItem extends Model
{
    protected $fillable = ['pretix_connection_id', 'event_slug', 'item_id', 'name'];

    protected function casts(): array
    {
        return ['item_id' => 'integer'];
    }

    /**
     * The ticket types of an event as id => name, for a picker.
     *
     * @return array<int, string>
     */
    public static function optionsFor(?string $eventSlug): array
    {
        if (blank($eventSlug)) {
            return [];
        }

        return static::query()
            ->where('event_slug', $eventSlug)
            ->orderBy('name')
            ->pluck('name', 'item_id')
            ->all();
    }
}
