<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            // event_id for a single-event settlement, customer_id for a
            // multi-event customer settlement; exactly one is set.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(19);

            // Frozen snapshot at creation time (accounting document must not
            // drift when transactions change afterwards).
            $table->unsignedInteger('tx_count')->default(0);
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('fees', 14, 2)->default(0);
            $table->decimal('payout', 14, 2)->default(0);
            $table->decimal('vat', 14, 2)->default(0);
            $table->decimal('net_excl_vat', 14, 2)->default(0);
            $table->json('blocks');
            $table->json('events')->nullable(); // per-event breakdown (customer settlements)

            $table->string('status')->default('open'); // open | paid
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
