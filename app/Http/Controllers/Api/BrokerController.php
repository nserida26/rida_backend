<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrokerController extends Controller
{
    /**
     * Dashboard broker : solde + stats
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->brokerProfile;

        if (!$profile) {
            return response()->json(['message' => 'Profil broker introuvable.'], 404);
        }

        $today = $user->ridesAsBroker()->whereDate('created_at', today());
        $month = $user->ridesAsBroker()->whereMonth('created_at', now()->month);

        return response()->json([
            'profile'     => $profile,
            'is_approved' => $profile->is_approved,
            'credit'      => [
                'balance'        => $profile->credit_balance,
                'total_recharged'=> $profile->total_recharged,
                'total_spent'    => $profile->total_spent,
            ],
            'rides' => [
                'today_count'   => (clone $today)->count(),
                'today_spent'   => (clone $today)->where('status', 'completed')->sum('final_price'),
                'month_count'   => (clone $month)->count(),
                'month_spent'   => (clone $month)->where('status', 'completed')->sum('final_price'),
                'total_count'   => $user->ridesAsBroker()->count(),
            ],
            'recharges' => $profile->recharges()->latest()->take(5)->get(),
        ]);
    }

    /**
     * Historique des recharges
     */
    public function recharges(Request $request): JsonResponse
    {
        $recharges = $request->user()->brokerProfile
            ->recharges()
            ->latest()
            ->paginate(20);

        return response()->json($recharges);
    }
}
