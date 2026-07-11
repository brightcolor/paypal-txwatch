<?php

namespace App\Providers;

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
