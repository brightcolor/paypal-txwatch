<?php

namespace App\Jobs;

use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\PayPal\Exceptions\PayPalAuthException;
use App\Services\PayPal\Exceptions\PayPalPermissionException;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class SyncPaypalAccountJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 300, 900, 1800];

    public function __construct(
        public readonly int $paypalAccountId,
        public readonly string $start,
        public readonly string $end,
        public readonly string $type = SyncRun::TYPE_SCHEDULED,
        public readonly ?int $triggeredByUserId = null,
    ) {
    }

    public function handle(SyncService $syncService): void
    {
        $account = PaypalAccount::findOrFail($this->paypalAccountId);
        $triggeredBy = $this->triggeredByUserId ? User::find($this->triggeredByUserId) : null;

        try {
            $syncService->run(
                $account,
                Carbon::parse($this->start),
                Carbon::parse($this->end),
                $this->type,
                $triggeredBy,
            );
        } catch (PayPalAuthException|PayPalPermissionException $e) {
            // Never succeeds on retry - stop immediately rather than burning
            // through the backoff schedule. Already recorded on the SyncRun.
            $this->fail($e);
        }
    }

    public function failed(Throwable $exception): void
    {
        // Recorded on the SyncRun/ImportError by SyncService; also alert admins.
        $name = \App\Models\PaypalAccount::find($this->paypalAccountId)?->name ?? "#{$this->paypalAccountId}";

        \App\Support\AdminNotifier::warn(
            'PayPal-Sync fehlgeschlagen',
            "Konto „{$name}“: " . $exception->getMessage(),
            \App\Filament\Resources\SyncRunResource::getUrl('index'),
        );
    }
}
