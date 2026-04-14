<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrokerRecharge extends Model
{
    protected $fillable = [
        'broker_profile_id', 'amount', 'reference', 'method', 'note', 'approved_by',
    ];

    protected $casts = ['amount' => 'float'];

    public function brokerProfile()
    {
        return $this->belongsTo(BrokerProfile::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
