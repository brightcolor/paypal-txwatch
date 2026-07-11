<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(587);
            $table->string('encryption', 10)->nullable()->default('tls'); // tls | ssl | null
            $table->string('username')->nullable();
            // Encrypted at rest (Laravel encrypted cast) - never stored in plain text.
            $table->text('password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            // Comma-separated extra recipients for admin alerts (optional).
            $table->text('alert_recipients')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
