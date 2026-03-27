<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketStatus extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_initial',
        'is_final',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'is_final' => 'boolean',
        ];
    }

    /**
     * Get all tickets that use this status.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'status', 'code');
    }
}
