<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\User;

class TicketActivityLogger
{
    /**
     * Build actor payload based on user role.
     *
     * @return array<string, int|string|null>
     */
    public static function actorFor(User $user, ?Contact $contact = null): array
    {
        if ($user->isClient() && $contact) {
            return [
                'actor_type' => 'contact',
                'actor_user_id' => null,
                'actor_contact_id' => $contact->id,
            ];
        }

        return [
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'actor_contact_id' => null,
        ];
    }

    /**
     * Persist a ticket activity log entry.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function log(
        Ticket $ticket,
        string $action,
        ?string $field = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        string $actorType = 'system',
        ?int $actorUserId = null,
        ?int $actorContactId = null,
        ?array $metadata = null
    ): void {
        TicketLog::query()->create([
            'ticket_id' => $ticket->id,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'actor_contact_id' => $actorContactId,
            'action' => $action,
            'field' => $field,
            'old_value' => self::normalizeValue($oldValue),
            'new_value' => self::normalizeValue($newValue),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Normalize log values to strings.
     */
    private static function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
