<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password',
        'role', 'is_active', 'avatar', 'fcm_token',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
    ];

    // ===== Relations =====

    public function captainProfile()
    {
        return $this->hasOne(CaptainProfile::class);
    }

    public function brokerProfile()
    {
        return $this->hasOne(BrokerProfile::class);
    }

    public function ridesAsClient()
    {
        return $this->hasMany(Ride::class, 'client_id');
    }

    public function ridesAsCaptain()
    {
        return $this->hasMany(Ride::class, 'captain_id');
    }

    public function ridesAsBroker()
    {
        return $this->hasMany(Ride::class, 'broker_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(CaptainSubscription::class, 'captain_id');
    }

    public function pointsHistory()
    {
        return $this->hasMany(CaptainPointsHistory::class, 'captain_id');
    }

    // ===== Helpers rôles =====

    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isCaptain(): bool  { return $this->role === 'captain'; }
    public function isClient(): bool   { return $this->role === 'client'; }
    public function isBroker(): bool   { return $this->role === 'broker'; }

    // ===== Accessors =====

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? asset('storage/' . $this->avatar) : null;
    }
}
