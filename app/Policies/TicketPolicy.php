<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can create tickets.
     */
    public function create(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isOperator()) {
            return $user->accessibleInboxes()->exists();
        }

        return $user->entities()->exists();
    }

    /**
     * Determine whether the user can view the ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isOperator()) {
            return $user->hasInboxAccess($ticket->inbox_id);
        }

        return $user->entities()->whereKey($ticket->entity_id)->exists();
    }

    /**
     * Determine whether the user can update ticket metadata.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->is_active
            && $user->isOperator()
            && $user->hasInboxAccess($ticket->inbox_id);
    }

    /**
     * Determine whether the user can add messages to the ticket.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /**
     * Determine whether the user can assign operators.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }
}
