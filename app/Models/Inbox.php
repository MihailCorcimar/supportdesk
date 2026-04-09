<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inbox extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'image_path',
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
     * Get all tickets for the inbox.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get operators linked to this inbox.
     */
    public function operators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'inbox_user')
            ->withPivot('can_manage_users')
            ->withTimestamps();
    }

    /**
     * Get notification templates scoped to this inbox.
     */
    public function notificationTemplates(): HasMany
    {
        return $this->hasMany(InboxNotificationTemplate::class);
    }
}
