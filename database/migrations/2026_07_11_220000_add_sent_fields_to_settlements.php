<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('paid_reference');
            $table->string('sent_to')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
