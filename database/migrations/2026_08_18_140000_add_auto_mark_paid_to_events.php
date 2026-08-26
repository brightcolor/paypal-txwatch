<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The switch that decides whether TxWatch marks orders paid on its own.
 *
 * PER EVENT, not per connection. Automatic confirmation used to hang on
 * `pretix_connections.auto_confirm_bank_transfers` - all or nothing for an entire
 * organizer. An event that is over, or one where payments are handled elsewhere,
 * could not be excluded without switching off every other event as well.
 *
 * DEFAULT OFF, and deliberately so: confirming is a WRITE into a third-party system
 * that marks an order paid and sends the customer their tickets. Nothing may start
 * doing that because a column appeared.
 *
 * The existing connection setting is carried over so behaviour does not change on
 * deploy. On this installation that is a no-op - the one connection has it off and
 * not a single order was ever confirmed automatically - but a migration that
 * silently changes what an installation does is not one worth writing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('auto_mark_paid')->default(false)->index();
        });

        if (! Schema::hasTable('pretix_connections') || ! Schema::hasTable('pretix_orders')) {
            return;
        }

        /*
         * Events belong to a connection only through their orders - `events` is keyed
         * by the pretix slug alone. So the carry-over asks: does this event's slug
         * appear in orders of a connection that had auto-confirm on?
         */
        $slugs = DB::table('pretix_orders')
            ->join('pretix_connections', 'pretix_connections.id', '=', 'pretix_orders.pretix_connection_id')
            ->where('pretix_connections.auto_confirm_bank_transfers', true)
            ->distinct()
            ->pluck('pretix_orders.event_slug')
            ->filter()
            ->all();

        if ($slugs !== []) {
            DB::table('events')->whereIn('pretix_event_slug', $slugs)->update(['auto_mark_paid' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('auto_mark_paid');
        });
    }
};
