<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference table for the bank picker in the FinTS setup: BLZ -> name + PIN/TAN
 * FinTS URL. Filled from the official FinTS institute list (fints:import-banks);
 * the list itself is NOT shipped in the (public) repo - it's imported on the
 * server, since the Deutsche Kreditwirtschaft provides it to registered
 * manufacturers only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fints_banks', function (Blueprint $table) {
            $table->string('blz', 12)->primary();
            $table->string('name');
            $table->string('ort')->nullable();
            $table->string('bic', 16)->nullable();
            $table->string('url', 512);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fints_banks');
    }
};
