<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paypal_account_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type'); // scheduled|manual|backfill|csv_import
            $table->string('status')->default('running'); // running|success|partial|failed

            $table->timestamp('window_start');
            $table->timestamp('window_end');

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('api_requests_count')->default(0);

            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['paypal_account_id', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
