<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptainPointsHistory extends Model
{
    protected $fillable = ['captain_id', 'ride_id', 'points', 'type', 'note'];

    protected $casts = ['points' => 'integer'];

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function ride()
    {
        return $this->belongsTo(Ride::class);
    }
}
