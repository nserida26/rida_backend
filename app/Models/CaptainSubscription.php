<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptainSubscription extends Model
{
    protected $fillable = [
        'captain_id', 'amount_paid', 'reference', 'period',
        'valid_from', 'valid_until', 'is_active', 'approved_by', 'note',
    ];

    protected $casts = [
        'amount_paid' => 'float',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function isValid(): bool
    {
        return $this->is_active && $this->valid_until->isFuture();
    }
}
