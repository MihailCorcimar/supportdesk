<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'inbox_id',
        'entity_id',
        'contact_id',
        'creator_contact_id',
        'created_by_user_id',
        'assigned_operator_id',
        'subject',
        'description',
        'status',
        'priority',
        'type',
        'cc_emails',
        'last_activity_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cc_emails' => 'array',
            'last_activity_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the inbox for this ticket.
     */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /**
     * Get the entity for this ticket.
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Get the requester contact.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the contact that opened this ticket.
     */
    public function creatorContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'creator_contact_id');
    }

    /**
     * Get the operator that created the ticket.
     */
    public function creatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the assigned operator.
     */
    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    /**
     * Get all messages for this ticket.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * Get all logs for this ticket.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class);
    }

    /**
     * Get all attachments for this ticket.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Get in-app notifications linked to this ticket.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /**
     * Get users following this ticket.
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_followers')
            ->withTimestamps();
    }

    /**
     * Get the ticket type option.
     */
    public function typeOption(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'type', 'code');
    }

    /**
     * Get the ticket status option.
     */
    public function statusOption(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status', 'code');
    }
}
