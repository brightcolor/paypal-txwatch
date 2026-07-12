<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            // Exactly one template may be the default (enforced in the model);
            // it is preselected in the export dialog.
            $table->boolean('is_default')->default(false);
            // Accent color for the PDF (headings, table header, lines).
            $table->string('accent_color', 9)->nullable();
        });

        // Operator branding shown on exports (small logo + claim).
        Schema::create('brand_settings', function (Blueprint $table) {
            $table->id();
            $table->string('logo_path')->nullable();
            $table->string('claim')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'accent_color']);
        });
        Schema::dropIfExists('brand_settings');
    }
};
