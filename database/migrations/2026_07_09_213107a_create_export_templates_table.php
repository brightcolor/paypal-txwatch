<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');

            // Ordered list of column keys, e.g. ["date","transaction_id","name",...]
            $table->json('columns');

            $table->string('group_by')->nullable(); // event|day|week|month|status|currency
            $table->boolean('show_group_sums')->default(true);
            $table->boolean('show_grand_total')->default(true);

            $table->string('mode')->default('customer'); // customer|internal
            $table->boolean('mask_pii')->default(false);

            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->boolean('show_event_info')->default(true);
            $table->text('footer_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};
