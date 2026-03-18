<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'entity_id',
        'user_id',
        'name',
        'email',
        'phone',
        'job_title',
        'is_primary',
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
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the entity that owns the contact.
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
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
