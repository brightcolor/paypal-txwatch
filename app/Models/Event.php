<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'name',
        'pretix_event_slug',
        'event_date',
        'venue',
        'display_name',
        'short_description',
        'contact_person',
        'logo_path',
        'pdf_footer',
        'legal_notice',
        'internal_notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function assignmentRules(): HasMany
    {
        return $this->hasMany(EventAssignmentRule::class)->orderByDesc('priority');
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->name;
    }
}
