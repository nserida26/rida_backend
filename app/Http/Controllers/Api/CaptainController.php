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
     * Mes points et historique
     */
    public function points(Request $request): JsonResponse
    {
        $captain = $request->user();
        $profile = $captain->captainProfile;

        $history = $captain->pointsHistory()
            ->with('ride:id,reference,pickup_address,dropoff_address')
            ->latest()
            ->paginate(20);

        return response()->json([
            'total_points' => $profile->points,
            'balance'      => $profile->balance,
            'history'      => $history,
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
                'points'   => (clone $today)->sum('points_earned'),
            ],
            'week' => [
                'rides'    => (clone $week)->count(),
                'earnings' => (clone $week)->sum('final_price'),
                'points'   => (clone $week)->sum('points_earned'),
            ],
            'total_points' => $profile->points,
        ]);
    }

    /**
     * Liste des captains disponibles (pour admin/broker)
     */
    public function availableCaptains(Request $request): JsonResponse
    {
        $captains = User::where('role', 'captain')
            ->where('is_active', true)
            ->whereHas('captainProfile', fn($q) => $q->where('status', 'available'))
            ->with('captainProfile')
            ->get()
            ->map(fn($u) => [
                'id'          => $u->id,
                'name'        => $u->name,
                'phone'       => $u->phone,
                'lat'         => $u->captainProfile->current_lat,
                'lng'         => $u->captainProfile->current_lng,
                'vehicle'     => $u->captainProfile->vehicle_brand . ' ' . $u->captainProfile->vehicle_model,
                'plate'       => $u->captainProfile->vehicle_plate,
                'points'      => $u->captainProfile->points,
            ]);

        return response()->json(['captains' => $captains]);
    }
}
