<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // custom_field_contains | custom_field_regex | invoice_id_contains | invoice_id_regex
            // | amount_range | date_range | paypal_account
            $table->string('match_type');

            $table->string('pattern')->nullable();
            $table->boolean('case_sensitive')->default(false);

            $table->decimal('amount_min', 14, 2)->nullable();
            $table->decimal('amount_max', 14, 2)->nullable();

            $table->timestamp('date_from')->nullable();
            $table->timestamp('date_to')->nullable();

            $table->foreignId('paypal_account_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_assignment_rules');
    }
};
