<?php

namespace App\Services\Sync;

use App\Models\EventAssignmentRule;
use Illuminate\Support\Collection;

/**
 * Matches a normalized transaction against active EventAssignmentRule
 * records (highest priority first) and returns assignment attributes to
 * merge onto the Transaction, so the UI can always show whether/how a
 * transaction was linked to an event.
 */
class EventAssigner
{
    private ?Collection $rules = null;

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{event_id: ?int, assignment_method: ?string, assignment_rule_id: ?int, assigned_at: ?\Illuminate\Support\Carbon}
     */
    public function assign(array $normalized): array
    {
        foreach ($this->rules() as $rule) {
            if ($rule->matches($normalized)) {
                return [
                    'event_id' => $rule->event_id,
                    'assignment_method' => 'rule',
                    'assignment_rule_id' => $rule->id,
                    'assigned_at' => now(),
                ];
            }
        }

        return [
            'event_id' => null,
            'assignment_method' => null,
            'assignment_rule_id' => null,
            'assigned_at' => null,
        ];
    }

    /**
     * Rules are cached for the lifetime of this instance (one sync run),
     * since they rarely change mid-run and this avoids N+1 queries per record.
     */
    private function rules(): Collection
    {
        return $this->rules ??= EventAssignmentRule::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();
    }
}
