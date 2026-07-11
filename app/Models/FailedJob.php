<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The job class name, extracted from the queue payload.
     */
    public function jobName(): string
    {
        $payload = json_decode($this->payload, true);

        return class_basename($payload['displayName'] ?? 'unbekannt');
    }

    /**
     * First line of the exception (the actual message, without the trace).
     */
    public function errorSummary(): string
    {
        return (string) str($this->exception)->before("\n")->limit(200);
    }
}
