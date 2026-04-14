<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptainProfile extends Model
{
    protected $fillable = [
        'user_id', 'license_number', 'vehicle_brand', 'vehicle_model',
        'vehicle_color', 'vehicle_plate', 'vehicle_year',
        'points', 'balance', 'is_online', 'current_lat', 'current_lng',
        'last_location_at', 'status',
    ];

    protected $casts = [
        'is_online'        => 'boolean',
        'current_lat'      => 'float',
        'current_lng'      => 'float',
        'points'           => 'integer',
        'balance'          => 'float',
        'last_location_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasActiveSubscription(): bool
    {
        return CaptainSubscription::where('captain_id', $this->user_id)
            ->where('is_active', true)
            ->where('valid_until', '>=', now()->toDateString())
            ->exists();
    }

    public function addPoints(int $points, ?int $rideId = null, string $note = ''): void
    {
        $this->increment('points', $points);

        CaptainPointsHistory::create([
            'captain_id' => $this->user_id,
            'ride_id'    => $rideId,
            'points'     => $points,
            'type'       => 'earned',
            'note'       => $note,
        ]);
    }

    public function updateLocation(float $lat, float $lng): void
    {
        $this->update([
            'current_lat'      => $lat,
            'current_lng'      => $lng,
            'last_location_at' => now(),
        ]);
    }

    public function setAvailable(): void
    {
        $this->update(['status' => 'available', 'is_online' => true]);
    }

    public function setOffline(): void
    {
        $this->update(['status' => 'offline', 'is_online' => false]);
    }
}
