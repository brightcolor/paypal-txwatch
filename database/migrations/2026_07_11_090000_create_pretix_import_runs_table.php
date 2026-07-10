<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pretix_connection_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('running'); // running|success|failed
            $table->unsignedInteger('events_total')->nullable();
            $table->unsignedInteger('events_done')->default(0);
            $table->unsignedInteger('orders_imported')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('mismatch')->default(0);
            $table->unsignedInteger('unmatched')->default(0);
            $table->json('log')->nullable();          // [{t, m}] progress lines, written live
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['pretix_connection_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_import_runs');
    }
};
