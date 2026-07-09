<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paypal_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('transaction_id')->nullable();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();

            // api_error|validation|resultset_too_large|rate_limit|auth|unknown
            $table->string('error_type');
            $table->text('message');
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index('error_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
    }
};
