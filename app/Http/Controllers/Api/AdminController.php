<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptainSubscription;
use App\Models\Ride;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    // ===== TABLEAU DE BORD =====

    public function dashboard(): JsonResponse
    {
        $today = today();

        $totalDrivers  = User::where('role', 'captain')->count();
        $activeDrivers = User::where('role', 'captain')->where('is_active', true)->count();
        $onlineDrivers = User::where('role', 'captain')
            ->whereHas('captainProfile', fn($q) => $q->where('is_online', true))->count();
        $totalClients  = User::where('role', 'client')->count();

        return response()->json([
            'users' => [
                // Flutter reads *_drivers keys; keep *_captains as aliases
                'total_drivers'   => $totalDrivers,
                'active_drivers'  => $activeDrivers,
                'online_drivers'  => $onlineDrivers,
                'total_clients'   => $totalClients,
                'total_captains'  => $totalDrivers,
                'active_captains' => $activeDrivers,
                'online_captains' => $onlineDrivers,
            ],
            'rides' => [
                'today_total'     => Ride::whereDate('created_at', $today)->count(),
                'today_completed' => Ride::whereDate('completed_at', $today)->where('status', 'completed')->count(),
                'today_cancelled' => Ride::whereDate('cancelled_at', $today)->where('status', 'cancelled')->count(),
                'today_pending'   => Ride::pending()->count(),
                'today_revenue'   => (float) Ride::whereDate('completed_at', $today)->where('status', 'completed')->sum('final_price'),
                'week_revenue'    => (float) Ride::whereBetween('completed_at', [now()->startOfWeek(), now()])->where('status', 'completed')->sum('final_price'),
                'month_revenue'   => (float) Ride::whereMonth('completed_at', now()->month)->where('status', 'completed')->sum('final_price'),
            ],
        ]);
    }

    // ===== GESTION UTILISATEURS =====

    public function users(Request $request): JsonResponse
    {
        $request->validate(['role' => 'nullable|in:admin,captain,client']);

        $query = User::with(['captainProfile'])->latest();

        if ($request->role) {
            $query->where('role', $request->role);
        }

        return response()->json($query->paginate(20));
    }

    public function approveUser(Request $request, User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

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
            'captain_id'  => 'nullable|exists:users,id',
            'driver_id'   => 'nullable|exists:users,id',
            'amount_paid' => 'required|numeric|min:0',
            'period'      => 'required|in:weekly,monthly',
            'valid_from'  => 'required|date',
            'note'        => 'nullable|string',
        ]);

        $captainId = $request->captain_id ?? $request->driver_id;
        if (!$captainId) {
            return response()->json([
                'message' => 'captain_id ou driver_id est requis.',
            ], 422);
        }

        $captain = User::findOrFail($captainId);
        if (!$captain->isCaptain()) {
            return response()->json(['message' => 'Utilisateur non captain.'], 422);
        }

        $validFrom  = now()->parse($request->valid_from);
        $validUntil = $request->period === 'weekly'
            ? $validFrom->addWeek()
            : $validFrom->addMonth();

        // Désactiver l'ancien abonnement
        CaptainSubscription::where('captain_id', $captainId)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $sub = CaptainSubscription::create([
            'captain_id'  => $captainId,
            'amount_paid' => $request->amount_paid,
            'reference'   => 'SUB-' . strtoupper(Str::random(8)),
            'period'      => $request->period,
            'valid_from'  => $validFrom->toDateString(),
            'valid_until' => $validUntil->toDateString(),
            'approved_by' => $request->user()->id,
            'note'        => $request->note,
        ]);

        $captain->captainProfile?->increment('balance', $request->amount_paid);

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

    // ===== CRÉER UNE COURSE (admin) =====

    public function createRide(Request $request): JsonResponse
    {
        $request->validate([
            'customer_phone'    => 'required|string',
            'customer_name'     => 'nullable|string|max:100',
            'third_party_phone' => 'nullable|string',
            'pickup_address'    => 'required|string',
            'pickup_lat'        => 'required|numeric',
            'pickup_lng'        => 'required|numeric',
            'dropoff_address'   => 'required|string',
            'dropoff_lat'       => 'required|numeric',
            'dropoff_lng'       => 'required|numeric',
            'estimated_price'   => 'nullable|numeric|min:0',
            'vehicle_type'      => 'nullable|string',
            'notes'             => 'nullable|string|max:500',
            'scheduled_at'      => 'nullable|date',
        ]);

        // Find or create the client by phone
        $phone = preg_replace('/\s+/', '', trim($request->customer_phone));
        $client = User::firstOrCreate(
            ['phone' => $phone, 'role' => 'client'],
            [
                'name'      => $request->customer_name ?? 'Client ' . $phone,
                'password'  => bcrypt(Str::random(16)),
                'is_active' => true,
            ]
        );

        $thirdPartyPhone = $request->third_party_phone
            ? preg_replace('/\s+/', '', trim($request->third_party_phone))
            : null;

        DB::beginTransaction();
        try {
            $ride = Ride::create([
                'client_id'         => $client->id,
                'broker_id'         => $request->user()->id, // admin is the broker/dispatcher
                'pickup_address'    => $request->pickup_address,
                'pickup_lat'        => $request->pickup_lat,
                'pickup_lng'        => $request->pickup_lng,
                'dropoff_address'   => $request->dropoff_address,
                'dropoff_lat'       => $request->dropoff_lat,
                'dropoff_lng'       => $request->dropoff_lng,
                'estimated_price'   => $request->estimated_price,
                'payment_method'    => 'cash',
                'cancel_reason'     => $request->notes, // repurposed for dispatcher notes
                'third_party_phone' => $thirdPartyPhone,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erreur lors de la création: ' . $e->getMessage()], 500);
        }

        // Dispatch to available captains
        app(FcmService::class)->sendNewRideToAvailableCaptains($ride->fresh(['client']));

        $displayPhone = $thirdPartyPhone ?? $client->phone;

        return response()->json([
            'message' => 'Course créée et envoyée aux chauffeurs disponibles.',
            'ride'    => [
                'id'                => $ride->id,
                'reference'         => $ride->reference,
                'status'            => $ride->status,
                'pickup_address'    => $ride->pickup_address,
                'dropoff_address'   => $ride->dropoff_address,
                'estimated_price'   => $ride->estimated_price,
                'third_party_phone' => $thirdPartyPhone,
                'display_phone'     => $displayPhone,
                'client'            => [
                    'id'    => $client->id,
                    'name'  => $client->name,
                    'phone' => $client->phone,
                ],
                'created_at'        => $ride->created_at->toIso8601String(),
            ],
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
        ]);

        $query = Ride::with(['client:id,name,phone', 'captain:id,name,phone'])->latest();

        if ($request->status)     $query->where('status', $request->status);
        if ($request->date_from)  $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)    $query->whereDate('created_at', '<=', $request->date_to);
        if ($request->captain_id) $query->where('captain_id', $request->captain_id);

        return response()->json($query->paginate(25));
    }

    // ===== POINTS CAPTAIN (ajustement manuel) =====

    public function adjustPoints(Request $request): JsonResponse
    {
        $request->validate([
            'captain_id' => 'nullable|exists:users,id',
            'driver_id'  => 'nullable|exists:users,id',
        ]);

        $captainId = $request->captain_id ?? $request->driver_id;
        if (!$captainId) {
            return response()->json([
                'message' => 'captain_id ou driver_id est requis.',
            ], 422);
        }

        $captain = User::findOrFail($captainId);
        if (!$captain->isCaptain()) {
            return response()->json(['message' => 'Utilisateur non captain.'], 422);
        }

        return response()->json([
            'message' => 'Le système de points est désactivé.',
            'balance' => $captain->captainProfile->fresh()->balance,
        ]);
    }
    // ===== NOTIFICATIONS =====

    public function sendNotification(Request $request): JsonResponse
    {
        $request->validate([
            'title'    => 'required|string|max:200',
            'body'     => 'required|string|max:1000',
            'target'   => 'required|in:all_drivers,all_clients,all,selected',
            'user_ids' => 'array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $role = match ($request->target) {
            'all_drivers' => 'captain',
            'all_clients' => 'client',
            default       => 'all',
        };

        $userIds = $request->target === 'selected'
            ? ($request->user_ids ?? [])
            : [];

        try {
            $sent = app(FcmService::class)->sendCustomToUsers(
                $userIds,
                $request->title,
                $request->body,
                $role,
            );
        } catch (\Throwable $e) {
            logger()->error('Admin notification send failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erreur FCM : ' . $e->getMessage(),
                'sent'    => 0,
            ], 500);
        }

        return response()->json([
            'message' => "Notification envoyée à {$sent} appareil(s).",
            'sent'    => $sent,
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
