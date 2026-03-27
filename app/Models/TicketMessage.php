<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketMessage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'author_type',
        'author_user_id',
        'author_contact_id',
        'body',
        'body_format',
        'attachments',
        'is_internal',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_internal' => 'boolean',
        ];
    }

    /**
     * Get the ticket that owns this message.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the author operator.
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Get the author contact.
     */
    public function authorContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'author_contact_id');
    }

    /**
     * Get all attachments for this message.
     */
    public function attachmentsList(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_message_id');
    }
}
