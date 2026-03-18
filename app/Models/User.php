<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        ];
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
}
