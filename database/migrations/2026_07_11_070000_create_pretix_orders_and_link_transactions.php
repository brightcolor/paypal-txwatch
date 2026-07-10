<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pretix_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_slug');
            $table->string('order_code');
            $table->string('status')->nullable();        // pretix: n/p/e/c (pending/paid/expired/canceled)
            $table->string('payment_provider')->nullable(); // paypal / banktransfer / ...
            $table->string('email')->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('order_datetime')->nullable();
            $table->string('url');                        // pretix control-panel order URL
            $table->json('raw_payload');
            $table->timestamps();

            $table->unique(['pretix_connection_id', 'event_slug', 'order_code']);
            $table->index('order_code');
            $table->index('event_slug');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('pretix_order_id')->nullable()->after('event_id')
                ->constrained('pretix_orders')->nullOnDelete();
            // null = not a matchable sale / not yet reconciled; otherwise:
            // matched | amount_mismatch | unmatched  (see PretixReconciler)
            $table->string('reconciliation_status')->nullable()->after('pretix_order_id');

            $table->index('reconciliation_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pretix_order_id');
            $table->dropColumn('reconciliation_status');
        });

        Schema::dropIfExists('pretix_orders');
    }
};
