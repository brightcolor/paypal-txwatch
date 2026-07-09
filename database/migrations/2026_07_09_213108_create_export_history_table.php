<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('export_template_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('format'); // pdf|csv|xlsx
            $table->json('filters_snapshot');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_history');
    }
};
