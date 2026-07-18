<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RideController extends Controller
{
    private const DRIVER_NEIGHBORHOOD_RADIUS_KM = 3.5;

    /**
     * Client : Créer une nouvelle course
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'pickup_address'  => 'required|string',
            'pickup_lat'      => 'required|numeric',
            'pickup_lng'      => 'required|numeric',
            'dropoff_address' => 'required|string',
            'dropoff_lat'     => 'required|numeric',
            'dropoff_lng'     => 'required|numeric',
            'estimated_price' => 'nullable|numeric|min:0',
            'distance_km'     => 'nullable|numeric|min:0',
            'duration_minutes' => 'nullable|integer|min:0',
            // Fonctionnalité client "course pour tiers"
            'client_name'     => 'nullable|string',
            'client_phone'    => 'nullable|string',
        ]);

        $user = $request->user();

        DB::beginTransaction();
        try {
            // Si la fonctionnalité est activée : créer/retrouver le client tiers
            $clientId = $user->id;
            if ($user->isClient() && $user->is_broker_enabled) {
                if ($request->client_phone) {
                    $clientUser = User::firstOrCreate(
                        ['phone' => $request->client_phone],
                        [
                            'name'      => $request->client_name ?? 'Client ' . $request->client_phone,
                            'password'  => bcrypt($request->client_phone),
                            'role'      => 'client',
                        ]
                    );
                    $clientId = $clientUser->id;
                }
            }

            $ride = Ride::create([
                'client_id'       => $clientId,
                'broker_id'       => ($user->isClient() && $user->is_broker_enabled) ? $user->id : null,
                'pickup_address'  => $request->pickup_address,
                'pickup_lat'      => $request->pickup_lat,
                'pickup_lng'      => $request->pickup_lng,
                'dropoff_address' => $request->dropoff_address,
                'dropoff_lat'     => $request->dropoff_lat,
                'dropoff_lng'     => $request->dropoff_lng,
                'estimated_price' => $request->estimated_price ?? $this->priceForKm($request->distance_km),
                'distance_km'     => $request->distance_km,
                'duration_minutes' => $request->duration_minutes,
                'payment_method'  => ($user->isClient() && $user->is_broker_enabled) ? 'broker_credit' : 'cash',
            ]);

            DB::commit();

            app(FcmService::class)->sendNewRideToAvailableCaptains($ride);
            app(FcmService::class)->sendRideUpdateToCustomer($ride->fresh(['client']), 'ride_created');

            return response()->json([
                'message' => 'Course créée avec succès.',
                'ride'    => $this->formatRide($ride),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erreur lors de la création: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Captain : Accepter une course
     */
    public function accept(Request $request, Ride $ride): JsonResponse
    {
        $captain = $request->user();

        if (!$captain->isCaptain()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($ride->status !== 'pending') {
            return response()->json(['message' => 'Cette course n\'est plus disponible.'], 409);
        }

        $profile = $captain->captainProfile;
        if (!$profile || $profile->status !== 'available') {
            return response()->json(['message' => 'Vous n\'êtes pas disponible.'], 409);
        }

        if (!$profile->hasActiveSubscription()) {
            return response()->json(['message' => 'Abonnement requis pour accepter des courses.'], 403);
        }

        DB::beginTransaction();
        try {
            $ride->accept($captain);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(FcmService::class)->sendRideUpdateToCustomer($ride->fresh(['client', 'captain']), 'ride_accepted');

        return response()->json([
            'message' => 'Course acceptée.',
            'ride'    => $this->formatRide($ride->fresh()),
        ]);
    }

    /**
     * Captain : Marquer comme arrivé
     */
    public function markArrived(Request $request, Ride $ride): JsonResponse
    {
        if ($ride->captain_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($ride->status !== 'accepted') {
            return response()->json(['message' => 'Statut incorrect.'], 409);
        }

        $ride->markArrived();
        app(FcmService::class)->sendRideUpdateToCustomer($ride->fresh(['client', 'captain']), 'driver_arrived');

        return response()->json(['message' => 'Arrivée confirmée.', 'ride' => $this->formatRide($ride)]);
    }

    /**
     * Captain : Démarrer la course
     */
    public function start(Request $request, Ride $ride): JsonResponse
    {
        if ($ride->captain_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($ride->status !== 'arrived') {
            return response()->json(['message' => 'Statut incorrect.'], 409);
        }

        $ride->start();
        app(FcmService::class)->sendRideUpdateToCustomer($ride->fresh(['client', 'captain']), 'ride_started');

        return response()->json(['message' => 'Course démarrée.', 'ride' => $this->formatRide($ride)]);
    }

    /**
     * Captain : Terminer la course
     */
    public function complete(Request $request, Ride $ride): JsonResponse
    {
        $request->validate([
            'final_price'   => 'required|numeric|min:0',
            'distance_km'   => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer',
        ]);

        if ($ride->captain_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($ride->status !== 'in_progress') {
            return response()->json(['message' => 'Statut incorrect.'], 409);
        }

        $ride->update([
            'distance_km'      => $request->distance_km,
            'duration_minutes' => $request->duration_minutes,
        ]);
        $ride->complete($request->final_price);
        app(FcmService::class)->sendRideUpdateToCustomer($ride->fresh(['client', 'captain']), 'ride_completed');

        return response()->json([
            'message'      => 'Course terminée.',
            'ride'         => $this->formatRide($ride->fresh()),
        ]);
    }

    /**
     * Annuler une course
     */
    public function cancel(Request $request, Ride $ride): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        $user = $request->user();
        $canCancel = $ride->client_id === $user->id
            || $ride->captain_id === $user->id
            || $ride->broker_id === $user->id
            || $user->isAdmin();

        if (!$canCancel) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (in_array($ride->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Course déjà terminée ou annulée.'], 409);
        }

        $ride->cancel($request->reason ?? '');
        $freshRide = $ride->fresh(['client', 'captain']);
        app(FcmService::class)->sendRideUpdateToCustomer($freshRide, 'ride_cancelled');
        app(FcmService::class)->sendRideUpdateToDriver($freshRide, 'ride_cancelled');

        return response()->json(['message' => 'Course annulée.', 'ride' => $this->formatRide($ride)]);
    }

    /**
     * Lister les courses disponibles (pour captains)
     */
    public function available(Request $request): JsonResponse
    {
        if (!$request->user()->isCaptain()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $profile = $request->user()->captainProfile;
        if (!$profile || $profile->current_lat === null || $profile->current_lng === null) {
            return response()->json(['rides' => []]);
        }

        $lat = (float) $profile->current_lat;
        $lng = (float) $profile->current_lng;

        $rides = Ride::pending()
            ->with(['client:id,name,phone'])
            ->select('rides.*')
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(pickup_lat)) * cos(radians(pickup_lng) - radians(?)) + sin(radians(?)) * sin(radians(pickup_lat)))) as distance_to_pickup_km',
                [$lat, $lng, $lat]
            )
            ->having('distance_to_pickup_km', '<=', self::DRIVER_NEIGHBORHOOD_RADIUS_KM)
            ->orderBy('distance_to_pickup_km')
            ->latest()
            ->get()
            ->map(fn($r) => $this->formatRide($r));

        return response()->json(['rides' => $rides]);
    }

    /**
     * Mes courses (client, captain)
     */
    public function myRides(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Ride::with(['client:id,name,phone', 'captain:id,name,phone', 'broker:id,name']);

        if ($user->isClient()) {
            $query->where('client_id', $user->id);
        } elseif ($user->isCaptain()) {
            $query->where('captain_id', $user->id);
        }

        $rides = $query->latest()->paginate(20);

        return response()->json($rides);
    }

    /**
     * Détail d'une course
     */
    public function show(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $canView = $ride->client_id === $user->id
            || $ride->captain_id === $user->id
            || $ride->broker_id === $user->id
            || $user->isAdmin();

        if (!$canView) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return response()->json($this->formatRide($ride->load(['client', 'captain.captainProfile', 'broker'])));
    }

    /**
     * Client : noter la course
     */
    public function rate(Request $request, Ride $ride): JsonResponse
    {
        $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        if ($ride->client_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($ride->status !== 'completed') {
            return response()->json(['message' => 'Course non terminée.'], 409);
        }

        $ride->update(['rating' => $request->rating, 'comment' => $request->comment]);

        return response()->json(['message' => 'Note enregistrée.']);
    }

    private function formatRide(Ride $ride): array
    {
        $captainProfile = $ride->captain?->captainProfile;

        return [
            'id'               => $ride->id,
            'reference'        => $ride->reference,
            'status'           => $ride->status,
            'pickup_address'   => $ride->pickup_address,
            'pickup_lat'       => $ride->pickup_lat,
            'pickup_lng'       => $ride->pickup_lng,
            'dropoff_address'  => $ride->dropoff_address,
            'dropoff_lat'      => $ride->dropoff_lat,
            'dropoff_lng'      => $ride->dropoff_lng,
            'estimated_price'  => $ride->estimated_price,
            'final_price'      => $ride->final_price,
            'distance_km'      => $ride->distance_km,
            'duration_minutes' => $ride->duration_minutes,
            'captain_commission' => $ride->captain_commission,
            'commission_debited_at' => $ride->commission_debited_at?->toIso8601String(),
            'commission_refunded_at' => $ride->commission_refunded_at?->toIso8601String(),
            'payment_method'   => $ride->payment_method,
            'is_paid'          => $ride->is_paid,
            'points_earned'    => $ride->points_earned,
            'is_broker_ride'   => $ride->isByBroker(),
            'is_third_party'   => $ride->isByBroker() || !is_null($ride->third_party_phone),
            'third_party_name' => $ride->isByBroker() ? $ride->client?->name : null,
            'third_party_phone' => $ride->third_party_phone ?? ($ride->isByBroker() ? $ride->client?->phone : null),
            // Passenger contact resolved on the backend: third_party_phone takes priority
            'contact_phone'    => $ride->third_party_phone ?? ($ride->client?->phone ?: null),
            'contact_name'     => ($ride->client?->name ?: null),
            // Captain real-time GPS for customer map car marker
            'captain_lat'      => $captainProfile?->current_lat,
            'captain_lng'      => $captainProfile?->current_lng,
            'rating'           => $ride->rating,
            'comment'          => $ride->comment,
            'accepted_at'      => $ride->accepted_at?->toIso8601String(),
            'started_at'       => $ride->started_at?->toIso8601String(),
            'completed_at'     => $ride->completed_at?->toIso8601String(),
            'created_at'       => $ride->created_at->toIso8601String(),
            'client'           => $ride->relationLoaded('client') ? [
                'id'    => $ride->client?->id,
                'name'  => $ride->client?->name,
                'phone' => $ride->client?->phone,
            ] : null,
            'captain'          => $ride->relationLoaded('captain') ? [
                'id'      => $ride->captain?->id,
                'name'    => $ride->captain?->name,
                'phone'   => $ride->captain?->phone,
                'profile' => $captainProfile,
            ] : null,
        ];
    }

    private function priceForKm(?float $km): ?float
    {
        if ($km === null) {
            return null;
        }

        if ($km <= 3) {
            return 100;
        }

        return 100 + (ceil(($km - 3) / 0.5) * 10);
    }
}
