<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_log_entries', function (Blueprint $table) {
            $table->id();
            // Stable hash of class+file+line+normalized message so repeated
            // occurrences of the same bug collapse into one row (occurrences++)
            // instead of flooding the table.
            $table->string('fingerprint', 64)->unique();
            $table->string('exception_class');
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedSmallInteger('status_code')->default(500)->index();
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('route')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('app_version', 20)->nullable();
            // Sanitized request context (input with secrets redacted, ip, agent).
            $table->json('context')->nullable();
            $table->longText('trace')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->boolean('resolved')->default(false)->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_log_entries');
    }
};
