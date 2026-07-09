<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('event_date')->nullable();
            $table->string('venue')->nullable();
            $table->string('display_name')->nullable(); // shown on PDF instead of internal name
            $table->text('short_description')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('pdf_footer')->nullable();
            $table->text('legal_notice')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('event_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
