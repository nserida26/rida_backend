<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptainSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptainController extends Controller
{
    /**
     * Mettre à jour la position GPS
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $request->user()->captainProfile->updateLocation(
            $request->lat,
            $request->lng
        );

        return response()->json(['message' => 'Position mise à jour.']);
    }

    /**
     * Changer son statut (online/offline)
     */
    public function setStatus(Request $request): JsonResponse
    {
        $request->validate(['online' => 'required|boolean']);

        $profile = $request->user()->captainProfile;

        if ($request->online) {
            if (!$profile->hasActiveSubscription()) {
                return response()->json([
                    'message' => 'Abonnement requis pour être en ligne.',
                ], 403);
            }
            $profile->setAvailable();
        } else {
            $profile->setOffline();
        }

        return response()->json([
            'message' => $request->online ? 'Vous êtes en ligne.' : 'Vous êtes hors ligne.',
            'status'  => $profile->fresh()->status,
        ]);
    }

    /**
     * Ancien endpoint points garde pour compatibilite mobile.
     */
    public function points(Request $request): JsonResponse
    {
        $captain = $request->user();
        $profile = $captain->captainProfile;

        return response()->json([
            'total_points' => 0,
            'balance'      => $profile->balance,
            'history'      => ['data' => []],
        ]);
    }

    /**
     * Mes abonnements
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $subs = CaptainSubscription::where('captain_id', $request->user()->id)
            ->latest()
            ->get();

        $active = $subs->first(fn($s) => $s->isValid());

        return response()->json([
            'active_subscription' => $active,
            'all_subscriptions'   => $subs,
        ]);
    }

    /**
     * Dashboard captain : résumé du jour
     */
    public function dashboard(Request $request): JsonResponse
    {
        $captain = $request->user();
        $profile = $captain->captainProfile;

        $today = $captain->ridesAsCaptain()
            ->whereDate('completed_at', today())
            ->where('status', 'completed');

        $week = $captain->ridesAsCaptain()
            ->whereBetween('completed_at', [now()->startOfWeek(), now()])
            ->where('status', 'completed');

        return response()->json([
            'profile'              => $profile,
            'has_active_sub'       => $profile->hasActiveSubscription(),
            'is_online'            => $profile->is_online,
            'status'               => $profile->status,
            'today' => [
                'rides'    => (clone $today)->count(),
                'earnings' => (clone $today)->sum('final_price'),
                'commission_balance' => $profile->balance,
            ],
            'week' => [
                'rides'    => (clone $week)->count(),
                'earnings' => (clone $week)->sum('final_price'),
                'commission_balance' => $profile->balance,
            ],
            'total_points' => 0,
            'commission_amount' => (float) config('services.masar.ride_commission_amount', 10),
        ]);
    }

    /**
     * Liste des captains disponibles (pour admin/broker)
     */
    public function availableCaptains(Request $request): JsonResponse
    {
        $captains = $this->availableCaptainsQuery()
            ->get()
            ->map(fn($u) => $this->formatNearbyCaptain($u));

        return response()->json(['captains' => $captains]);
    }

    /**
     * Client : drivers disponibles autour d'un point de depart.
     */
    public function nearbyCaptains(Request $request): JsonResponse
    {
        $request->validate([
            'lat'       => 'required|numeric',
            'lng'       => 'required|numeric',
            'radius_km' => 'nullable|numeric|min:0.1|max:25',
        ]);

        $lat = (float) $request->lat;
        $lng = (float) $request->lng;
        $radiusKm = (float) ($request->radius_km ?? 3.5);

        $captains = $this->availableCaptainsQuery()
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(captain_profiles.current_lat)) * cos(radians(captain_profiles.current_lng) - radians(?)) + sin(radians(?)) * sin(radians(captain_profiles.current_lat)))) as distance_km',
                [$lat, $lng, $lat]
            )
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km')
            ->limit(20)
            ->get()
            ->map(fn($u) => $this->formatNearbyCaptain($u));

        return response()->json(['drivers' => $captains]);
    }

    private function availableCaptainsQuery()
    {
        return User::query()
            ->join('captain_profiles', 'captain_profiles.user_id', '=', 'users.id')
            ->where('users.role', 'captain')
            ->where('users.is_active', true)
            ->where('captain_profiles.status', 'available')
            ->whereNotNull('captain_profiles.current_lat')
            ->whereNotNull('captain_profiles.current_lng')
            ->select([
                'users.id',
                'users.name',
                'users.phone',
                'captain_profiles.current_lat as lat',
                'captain_profiles.current_lng as lng',
                'captain_profiles.vehicle_brand',
                'captain_profiles.vehicle_model',
                'captain_profiles.vehicle_plate',
                'captain_profiles.balance',
            ]);
    }

    private function formatNearbyCaptain($captain): array
    {
        return [
            'id'          => $captain->id,
            'name'        => $captain->name,
            'phone'       => $captain->phone,
            'lat'         => $captain->lat,
            'lng'         => $captain->lng,
            'vehicle'     => trim($captain->vehicle_brand . ' ' . $captain->vehicle_model),
            'plate'       => $captain->vehicle_plate,
            'balance'     => $captain->balance,
            'distance_km' => isset($captain->distance_km) ? round((float) $captain->distance_km, 2) : null,
        ];
    }
}
