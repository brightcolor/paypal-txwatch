<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail of authentication attempts (successful and failed). Diagnostic /
 * security data - append-only in spirit but prunable (not accounting data).
 * Written by App\Listeners\RecordLoginEvent.
 */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'successful', 'ip', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
