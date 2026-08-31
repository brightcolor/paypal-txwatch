<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the import found, per order - and what then happened to it.
 *
 * NOT IN THE RUN'S LIVE LOG, and that is the point. `pretix_import_runs.log` is a
 * JSON column capped at 300 lines and rewritten in full on every push: it answers
 * "what is the import doing right now" and cannot answer "what became of order
 * JDCBU" - with over a thousand orders the answer would be pushed out of the cap
 * within one run, and writing it there would cost O(n²) bytes.
 *
 * One row per order per thing that happened to it: found (new, changed or
 * unchanged), booked into the transactions, reconciled against PayPal, or
 * deliberately skipped with a reason. Bounded in practice because the import is
 * incremental - pretix only returns orders it changed since the last run.
 *
 * APPEND-ONLY. Nothing updates or deletes these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_order_log', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('pretix_import_run_id')->nullable()->index();
            $table->unsignedBigInteger('pretix_connection_id')->nullable();

            /*
             * Order identity as PLAIN VALUES, not only as a foreign key: the history
             * of an order has to survive the order being deleted from pretix, which
             * is exactly when someone asks what happened to it.
             */
            $table->string('event_slug')->nullable();
            $table->string('order_code')->nullable();
            $table->index(['order_code', 'event_slug'], 'pol_code_idx');
            $table->unsignedBigInteger('pretix_order_id')->nullable()->index();

            $table->string('action', 24)->index();

            // Before and after, because "changed" without the two values is a claim
            // rather than a record.
            $table->string('status_before', 2)->nullable();
            $table->string('status_after', 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();

            $table->text('message')->nullable();
            $table->json('context')->nullable();

            $table->timestamp('at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_order_log');
    }
};
