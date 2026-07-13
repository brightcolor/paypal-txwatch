<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report matched bank transfers back to pretix as paid. Per-connection opt-in
 * for full automation; bank rows carry the proposed/reported link to a pending
 * pretix order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pretix_connections', function (Blueprint $table) {
            // When on, a confident bank match is confirmed in pretix automatically
            // during the sync; otherwise it only becomes a one-click proposal.
            $table->boolean('auto_confirm_bank_transfers')->default(false);
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('pretix_connection_id')->nullable()->after('match_method')
                ->constrained('pretix_connections')->nullOnDelete();
            $table->string('pretix_event_slug')->nullable()->after('pretix_connection_id');
            $table->string('pretix_order_code')->nullable()->after('pretix_event_slug');
            // none | proposed | reported | failed | dismissed
            $table->string('pretix_report_status', 12)->default('none')->after('pretix_order_code')->index();
            $table->text('pretix_report_error')->nullable()->after('pretix_report_status');
            $table->timestamp('pretix_reported_at')->nullable()->after('pretix_report_error');
        });
    }

    public function down(): void
    {
        Schema::table('pretix_connections', function (Blueprint $table) {
            $table->dropColumn('auto_confirm_bank_transfers');
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pretix_connection_id');
            $table->dropColumn(['pretix_event_slug', 'pretix_order_code', 'pretix_report_status', 'pretix_report_error', 'pretix_reported_at']);
        });
    }
};
