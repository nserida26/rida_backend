<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ride extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'client_id', 'captain_id', 'broker_id',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'dropoff_address', 'dropoff_lat', 'dropoff_lng',
        'status', 'estimated_price', 'final_price',
        'distance_km', 'duration_minutes', 'captain_commission',
        'commission_debited_at', 'commission_refunded_at',
        'payment_method', 'is_paid', 'points_earned',
        'accepted_at', 'arrived_at', 'started_at', 'completed_at',
        'cancelled_at', 'cancel_reason', 'third_party_phone', 'rating', 'comment',
    ];

    protected $casts = [
        'pickup_lat'        => 'float',
        'pickup_lng'        => 'float',
        'dropoff_lat'       => 'float',
        'dropoff_lng'       => 'float',
        'estimated_price'   => 'float',
        'final_price'       => 'float',
        'distance_km'       => 'float',
        'captain_commission' => 'float',
        'is_paid'           => 'boolean',
        'commission_debited_at' => 'datetime',
        'commission_refunded_at' => 'datetime',
        'accepted_at'       => 'datetime',
        'arrived_at'        => 'datetime',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'cancelled_at'      => 'datetime',
    ];

    // ===== Boot =====

    protected static function booted(): void
    {
        static::creating(function (Ride $ride) {
            $ride->reference = self::generateReference();
        });
    }

    private static function generateReference(): string
    {
        $count = self::withTrashed()->count() + 1;
        return 'ETX-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    // ===== Relations =====

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function broker()
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    // ===== Scopes =====

    public function scopePending($q)    { return $q->where('status', 'pending'); }
    public function scopeActive($q)     { return $q->whereIn('status', ['accepted', 'arrived', 'in_progress']); }
    public function scopeCompleted($q)  { return $q->where('status', 'completed'); }

    // ===== Status transitions =====

    public function accept(User $captain): void
    {
        $commission = (float) config('services.masar.ride_commission_amount', 10);
        $profile = $captain->captainProfile;

        if (!$profile || !$profile->debitRideCommission($commission)) {
            throw new \RuntimeException('Solde abonnement insuffisant.');
        }

        $this->update([
            'captain_id'            => $captain->id,
            'status'                => 'accepted',
            'captain_commission'    => $commission,
            'commission_debited_at' => now(),
            'accepted_at'           => now(),
        ]);
        $profile->update(['status' => 'busy']);
    }

    public function markArrived(): void
    {
        $this->update(['status' => 'arrived', 'arrived_at' => now()]);
    }

    public function start(): void
    {
        $this->update(['status' => 'in_progress', 'started_at' => now()]);
    }

    public function complete(float $finalPrice): void
    {
        $this->update([
            'status'        => 'completed',
            'final_price'   => $finalPrice,
            'is_paid'       => true,
            'points_earned' => 0,
            'completed_at'  => now(),
        ]);

        if ($this->captain) {
            $profile = $this->captain->captainProfile?->fresh();
            $profile?->hasActiveSubscription()
                ? $profile->setAvailable()
                : $profile?->setOffline();
        }

        // Débiter le crédit client si course pour tiers
        if ($this->payment_method === 'broker_credit' && $this->broker_id) {
            $this->broker?->decrement('broker_credit_balance', $finalPrice);
        }
    }

    public function cancel(string $reason = ''): void
    {
        $refundedAt = $this->commission_refunded_at;
        if (
            $this->captain
            && $this->commission_debited_at
            && !$this->commission_refunded_at
            && $this->captain_commission > 0
        ) {
            $this->captain->captainProfile?->refundRideCommission($this->captain_commission);
            $refundedAt = now();
        }

        $this->update([
            'status'        => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_at'  => now(),
            'commission_refunded_at' => $refundedAt,
        ]);

        if ($this->captain) {
            $profile = $this->captain->captainProfile?->fresh();
            $profile?->hasActiveSubscription()
                ? $profile->setAvailable()
                : $profile?->setOffline();
        }
    }

    public function isByBroker(): bool
    {
        return !is_null($this->broker_id);
    }
}
