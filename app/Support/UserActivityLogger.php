<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserLog;

class UserActivityLogger
{
    /**
     * Persist a user activity log entry.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function log(
        ?User $actor,
        ?User $target,
        string $action,
        ?array $metadata = null
    ): void {
        UserLog::query()->create([
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}