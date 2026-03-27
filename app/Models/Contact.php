<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'function_id',
        'user_id',
        'name',
        'email',
        'phone',
        'mobile_phone',
        'internal_notes',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get entities linked to the contact.
     */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'contact_entity')
            ->withTimestamps();
    }

    /**
     * Get the contact function.
     */
    public function contactFunction(): BelongsTo
    {
        return $this->belongsTo(ContactFunction::class, 'function_id');
    }

    /**
     * Get the linked user account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all tickets opened by this contact.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get tickets where this contact is the original creator.
     */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'creator_contact_id');
    }

    /**
     * Get all messages created by this contact.
     */
    public function ticketMessages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'author_contact_id');
    }

    /**
     * Get all logs created by this contact.
     */
    public function ticketLogs(): HasMany
    {
        return $this->hasMany(TicketLog::class, 'actor_contact_id');
    }
}
