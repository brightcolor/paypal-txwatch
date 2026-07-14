<?php

namespace App\Providers;

use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

/*
 * NOTE on login listeners: App\Listeners\RecordLoginEvent lives in app/Listeners
 * and its handle* methods type-hint the auth events, so Laravel's event
 * auto-discovery already registers them. We do NOT also register them manually
 * here or every login would be logged twice.
 */

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        $this->applyMailSettings();

        // Pagination policy for every table (list pages + relation managers):
        // NO "Alle" option - loading the full table (tens of thousands of rows)
        // kills the server. Cap at 500 rows; the pagination guard warns before a
        // 500-row load and the ClampsRecordsPerPageOnReload trait resets a
        // remembered large size back to 200 on reload so we don't loop on a slow
        // query. Default 50.
        Table::configureUsing(function (Table $table): void {
            $table
                ->paginationPageOptions([25, 50, 100, 200, 500])
                ->defaultPaginationPageOption(50);
        });
    }

    /**
     * Push the operator's stored SMTP config (Einstellungen → E-Mail-Versand)
     * into the live mail config. Guarded so it can't break console commands
     * that run before the table exists (migrate) or when the DB is unreachable.
     */
    private function applyMailSettings(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('mail_settings')) {
                return;
            }

            \App\Models\MailSetting::current()->apply();
        } catch (\Throwable) {
            // Fall back to the env/log mailer; never block boot on this.
        }
    }
}
