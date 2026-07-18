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
        'role', 'is_active', 'avatar', 'fcm_token', 'last_login_at',
        'is_broker_enabled', 'broker_credit_balance', 'broker_total_recharged',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'      => 'datetime',
        'password'               => 'hashed',
        'is_active'              => 'boolean',
        'last_login_at'          => 'datetime',
        'is_broker_enabled'      => 'boolean',
        'broker_credit_balance'  => 'float',
        'broker_total_recharged' => 'float',
    ];

    // ===== Relations =====

    public function captainProfile()
    {
        return $this->hasOne(CaptainProfile::class);
    }

    public function ridesAsClient()
    {
        return $this->hasMany(Ride::class, 'client_id');
    }

    public function ridesAsCaptain()
    {
        return $this->hasMany(Ride::class, 'captain_id');
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

    // ===== Accessors =====

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? asset('storage/' . $this->avatar) : null;
    }
}
