<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrokerProfile;
use App\Models\CaptainSubscription;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    // ===== TABLEAU DE BORD =====

    public function dashboard(): JsonResponse
    {
        $today = today();

        return response()->json([
            'users' => [
                'total_captains' => User::where('role', 'captain')->count(),
                'active_captains' => User::where('role', 'captain')->where('is_active', true)->count(),
                'online_captains' => User::where('role', 'captain')
                    ->whereHas('captainProfile', fn($q) => $q->where('is_online', true))->count(),
                'total_clients'  => User::where('role', 'client')->count(),
                'total_brokers'  => User::where('role', 'broker')->count(),
            ],
            'rides' => [
                'today_total'     => Ride::whereDate('created_at', $today)->count(),
                'today_completed' => Ride::whereDate('completed_at', $today)->where('status', 'completed')->count(),
                'today_cancelled' => Ride::whereDate('cancelled_at', $today)->where('status', 'cancelled')->count(),
                'today_pending'   => Ride::pending()->count(),
                'today_revenue'   => Ride::whereDate('completed_at', $today)->where('status', 'completed')->sum('final_price'),
                'week_revenue'    => Ride::whereBetween('completed_at', [now()->startOfWeek(), now()])->where('status', 'completed')->sum('final_price'),
                'month_revenue'   => Ride::whereMonth('completed_at', now()->month)->where('status', 'completed')->sum('final_price'),
            ],
        ]);
    }

    // ===== GESTION UTILISATEURS =====

    public function users(Request $request): JsonResponse
    {
        $request->validate(['role' => 'nullable|in:admin,captain,client,broker']);

        $query = User::with(['captainProfile', 'brokerProfile'])->latest();

        if ($request->role) {
            $query->where('role', $request->role);
        }

        return response()->json($query->paginate(20));
    }

    public function approveUser(Request $request, User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        if ($user->isBroker()) {
            $user->brokerProfile?->update(['is_approved' => true]);
        }

        return response()->json(['message' => "{$user->name} approuvé(e) avec succès."]);
    }

    public function suspendUser(Request $request, User $user): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);
        $user->update(['is_active' => false]);

        return response()->json(['message' => "{$user->name} suspendu(e)."]);
    }

    // ===== ABONNEMENTS CAPTAINS =====

    public function storeSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'captain_id'  => 'required|exists:users,id',
            'amount_paid' => 'required|numeric|min:0',
            'period'      => 'required|in:weekly,monthly',
            'valid_from'  => 'required|date',
            'note'        => 'nullable|string',
        ]);

        $captain = User::findOrFail($request->captain_id);
        if (!$captain->isCaptain()) {
            return response()->json(['message' => 'Utilisateur non captain.'], 422);
        }

        $validFrom  = now()->parse($request->valid_from);
        $validUntil = $request->period === 'weekly'
            ? $validFrom->addWeek()
            : $validFrom->addMonth();

        // Désactiver l'ancien abonnement
        CaptainSubscription::where('captain_id', $request->captain_id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $sub = CaptainSubscription::create([
            'captain_id'  => $request->captain_id,
            'amount_paid' => $request->amount_paid,
            'reference'   => 'SUB-' . strtoupper(Str::random(8)),
            'period'      => $request->period,
            'valid_from'  => $validFrom->toDateString(),
            'valid_until' => $validUntil->toDateString(),
            'approved_by' => $request->user()->id,
            'note'        => $request->note,
        ]);

        // Activer le captain si pas encore actif
        $captain->update(['is_active' => true]);

        return response()->json([
            'message'      => 'Abonnement créé.',
            'subscription' => $sub,
        ], 201);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $subs = CaptainSubscription::with('captain:id,name,phone')
            ->latest()
            ->paginate(20);

        return response()->json($subs);
    }

    // ===== RECHARGES BROKER =====

    public function brokerRecharge(Request $request): JsonResponse
    {
        $request->validate([
            'broker_id' => 'required|exists:users,id',
            'amount'    => 'required|numeric|min:1',
            'method'    => 'required|in:cash,transfer,other',
            'note'      => 'nullable|string',
        ]);

        $broker = User::findOrFail($request->broker_id);
        if (!$broker->isBroker()) {
            return response()->json(['message' => 'Utilisateur non broker.'], 422);
        }

        $profile = $broker->brokerProfile;
        if (!$profile) {
            return response()->json(['message' => 'Profil broker introuvable.'], 404);
        }

        $recharge = $profile->addCredit(
            $request->amount,
            'RCH-' . strtoupper(Str::random(8)),
            $request->method,
            $request->user()->id,
        );

        return response()->json([
            'message'       => "Crédit de {$request->amount} MRU ajouté.",
            'new_balance'   => $profile->fresh()->credit_balance,
            'recharge'      => $recharge,
        ], 201);
    }

    // ===== TOUTES LES COURSES =====

    public function allRides(Request $request): JsonResponse
    {
        $request->validate([
            'status'     => 'nullable|in:pending,accepted,arrived,in_progress,completed,cancelled',
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date',
            'captain_id' => 'nullable|exists:users,id',
            'broker_id'  => 'nullable|exists:users,id',
        ]);

        $query = Ride::with(['client:id,name,phone', 'captain:id,name,phone', 'broker:id,name'])->latest();

        if ($request->status)     $query->where('status', $request->status);
        if ($request->date_from)  $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)    $query->whereDate('created_at', '<=', $request->date_to);
        if ($request->captain_id) $query->where('captain_id', $request->captain_id);
        if ($request->broker_id)  $query->where('broker_id', $request->broker_id);

        return response()->json($query->paginate(25));
    }

    // ===== POINTS CAPTAIN (ajustement manuel) =====

    public function adjustPoints(Request $request): JsonResponse
    {
        $request->validate([
            'captain_id' => 'required|exists:users,id',
            'points'     => 'required|integer',
            'note'       => 'required|string',
        ]);

        $captain = User::findOrFail($request->captain_id);
        if (!$captain->isCaptain()) {
            return response()->json(['message' => 'Utilisateur non captain.'], 422);
        }

        $captain->captainProfile->increment('points', $request->points);

        \App\Models\CaptainPointsHistory::create([
            'captain_id' => $request->captain_id,
            'points'     => $request->points,
            'type'       => 'adjusted',
            'note'       => $request->note . ' (par admin)',
        ]);

        return response()->json([
            'message'      => 'Points ajustés.',
            'total_points' => $captain->captainProfile->fresh()->points,
        ]);
    }
    public function enableBroker(\Illuminate\Http\Request $request, \App\Models\User $user): \Illuminate\Http\JsonResponse
    {
        if (!$user->isClient()) {
            return response()->json(['message' => 'Seul un client peut être broker.'], 422);
        }

        $request->validate(['initial_credit' => 'required|numeric|min:0']);

        $user->update([
            'is_broker_enabled'     => true,
            'broker_credit_balance' => $request->initial_credit,
            'broker_total_recharged' => $request->initial_credit,
        ]);

        return response()->json([
            'message'        => "Fonctionnalité broker activée pour {$user->name}.",
            'credit_balance' => $user->fresh()->broker_credit_balance,
        ]);
    }

    // Recharge du crédit broker (admin)
    public function rechargeBroker(\Illuminate\Http\Request $request, \App\Models\User $user): \Illuminate\Http\JsonResponse
    {
        if (!$user->is_broker_enabled) {
            return response()->json(['message' => 'Broker non activé.'], 422);
        }

        $request->validate(['amount' => 'required|numeric|min:1']);

        $user->increment('broker_credit_balance', $request->amount);
        $user->increment('broker_total_recharged', $request->amount);

        return response()->json([
            'message'        => "Crédit rechargé.",
            'new_balance'    => $user->fresh()->broker_credit_balance,
        ]);
    }
}
