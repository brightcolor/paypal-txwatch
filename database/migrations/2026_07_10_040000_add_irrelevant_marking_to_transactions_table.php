<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Null = relevant (the default for every existing/new transaction). Non-null marks
            // it excluded from revenue/report figures without ever deleting the row itself.
            $table->timestamp('marked_irrelevant_at')->nullable()->after('last_seen_at');
            $table->text('irrelevant_reason')->nullable()->after('marked_irrelevant_at');
            $table->foreignId('irrelevant_marked_by_user_id')->nullable()
                ->after('irrelevant_reason')->constrained('users')->nullOnDelete();

            $table->index('marked_irrelevant_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('irrelevant_marked_by_user_id');
            $table->dropColumn(['marked_irrelevant_at', 'irrelevant_reason']);
        });
    }
};
