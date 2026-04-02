<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Determine if the user is an operator.
     */
    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    /**
     * Determine if the user is an admin operator.
     */
    public function isAdmin(): bool
    {
        return $this->isOperator() && (bool) $this->is_admin;
    }

    /**
     * Determine if the user is a client.
     */
    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    /**
     * Get the contacts linked to this user.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Get entities linked to this user through contacts.
     */
    public function entities(): Builder
    {
        return Entity::query()
            ->whereHas('contacts', fn (Builder $query) => $query->where('contacts.user_id', $this->id))
            ->distinct();
    }

    /**
     * Get inboxes accessible to this operator.
     */
    public function accessibleInboxes(): BelongsToMany
    {
        return $this->belongsToMany(Inbox::class, 'inbox_user')
            ->withPivot('can_manage_users')
            ->withTimestamps();
    }

    /**
     * Check if the user can access an inbox.
     */
    public function hasInboxAccess(int $inboxId): bool
    {
        if (! $this->isOperator()) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return $this->accessibleInboxes()->whereKey($inboxId)->exists();
    }

    /**
     * Get inboxes where the operator can manage users.
     */
    public function manageableInboxes(): BelongsToMany
    {
        return $this->accessibleInboxes()->wherePivot('can_manage_users', true);
    }

    /**
     * Check if the user can manage users in any inbox.
     */
    public function canManageUsers(): bool
    {
        return $this->isAdmin()
            || ($this->isOperator() && $this->manageableInboxes()->exists());
    }

    /**
     * Check if the user can manage users in a specific inbox.
     */
    public function hasInboxManagementAccess(int $inboxId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->isOperator()
            && $this->manageableInboxes()->where('inboxes.id', $inboxId)->exists();
    }

    /**
     * Get tickets created by this operator.
     */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by_user_id');
    }

    /**
     * Get tickets assigned to this operator.
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_operator_id');
    }

    /**
     * Get ticket messages authored by this operator.
     */
    public function ticketMessages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'author_user_id');
    }

    /**
     * Get ticket logs created by this operator.
     */
    public function ticketLogs(): HasMany
    {
        return $this->hasMany(TicketLog::class, 'actor_user_id');
    }

    /**
     * Get in-app notifications for this user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /**
     * Get tickets pinned by this user in sidebar.
     */
    public function pinnedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'user_pinned_tickets')
            ->withPivot(['position'])
            ->withTimestamps();
    }

    /**
     * Get tickets this user follows for updates.
     */
    public function followedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_followers')
            ->withTimestamps();
    }
}
