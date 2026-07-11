<?php

use App\Models\PretixConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pretix_connections', function (Blueprint $table) {
            // Opaque token embedded in the pretix webhook URL; identifies the
            // connection and authorizes the (otherwise public) webhook call.
            $table->string('webhook_secret', 64)->nullable()->unique()->after('api_token');
        });

        PretixConnection::query()->whereNull('webhook_secret')->get()
            ->each(fn (PretixConnection $c) => $c->forceFill(['webhook_secret' => Str::random(48)])->save());
    }

    public function down(): void
    {
        Schema::table('pretix_connections', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
