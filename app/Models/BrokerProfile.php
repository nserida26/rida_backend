<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrokerProfile extends Model
{
    protected $fillable = [
        'user_id', 'company_name', 'address',
        'credit_balance', 'total_recharged', 'total_spent', 'is_approved',
    ];

    protected $casts = [
        'credit_balance' => 'float',
        'total_recharged' => 'float',
        'total_spent' => 'float',
        'is_approved' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recharges()
    {
        return $this->hasMany(BrokerRecharge::class);
    }

    public function addCredit(float $amount, string $reference, string $method = 'cash', ?int $approvedBy = null): BrokerRecharge
    {
        $recharge = BrokerRecharge::create([
            'broker_profile_id' => $this->id,
            'amount' => $amount,
            'reference' => $reference,
            'method' => $method,
            'approved_by' => $approvedBy,
        ]);

        $this->increment('credit_balance', $amount);
        $this->increment('total_recharged', $amount);

        return $recharge;
    }

    public function hasEnoughCredit(float $amount): bool
    {
        return $this->credit_balance >= $amount;
    }
}
