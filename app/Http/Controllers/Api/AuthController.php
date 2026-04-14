<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptainProfile;
use App\Models\BrokerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Connexion (tous rôles)
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string',
            'role'     => 'required|in:admin,captain,client,broker',
        ]);

        $user = User::where('phone', $request->phone)
            ->where('role', $request->role)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['Identifiants incorrects.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Compte désactivé. Contactez l\'administrateur.'], 403);
        }

        // Mettre à jour le token FCM si fourni
        if ($request->fcm_token) {
            $user->update(['fcm_token' => $request->fcm_token]);
        }

        $token = $user->createToken('etaxis-' . $user->role)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ]);
    }

    /**
     * Inscription client (auto)
     */
    public function registerClient(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'phone'    => 'required|string|unique:users,phone',
            'email'    => 'nullable|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => $request->email,
            'password' => $request->password,
            'role'     => 'client',
        ]);

        $token = $user->createToken('etaxis-client')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ], 201);
    }

    /**
     * Inscription captain (admin valide ensuite)
     */
    public function registerCaptain(Request $request): JsonResponse
    {
        $request->validate([
            'name'           => 'required|string|max:100',
            'phone'          => 'required|string|unique:users,phone',
            'email'          => 'nullable|email|unique:users,email',
            'password'       => 'required|string|min:6|confirmed',
            'license_number' => 'required|string|unique:captain_profiles,license_number',
            'vehicle_brand'  => 'required|string',
            'vehicle_model'  => 'required|string',
            'vehicle_color'  => 'required|string',
            'vehicle_plate'  => 'required|string|unique:captain_profiles,vehicle_plate',
            'vehicle_year'   => 'required|integer|min:2000|max:' . date('Y'),
        ]);

        $user = User::create([
            'name'      => $request->name,
            'phone'     => $request->phone,
            'email'     => $request->email,
            'password'  => $request->password,
            'role'      => 'captain',
            'is_active' => false, // Attente validation admin
        ]);

        CaptainProfile::create([
            'user_id'        => $user->id,
            'license_number' => $request->license_number,
            'vehicle_brand'  => $request->vehicle_brand,
            'vehicle_model'  => $request->vehicle_model,
            'vehicle_color'  => $request->vehicle_color,
            'vehicle_plate'  => $request->vehicle_plate,
            'vehicle_year'   => $request->vehicle_year,
        ]);

        return response()->json([
            'message' => 'Demande envoyée. En attente de validation par l\'administrateur.',
            'user'    => $this->formatUser($user),
        ], 201);
    }

    /**
     * Inscription broker (admin valide ensuite)
     */
    public function registerBroker(Request $request): JsonResponse
    {
        $request->validate([
            'name'         => 'required|string|max:100',
            'phone'        => 'required|string|unique:users,phone',
            'email'        => 'nullable|email|unique:users,email',
            'password'     => 'required|string|min:6|confirmed',
            'company_name' => 'nullable|string|max:200',
            'address'      => 'nullable|string',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'phone'     => $request->phone,
            'email'     => $request->email,
            'password'  => $request->password,
            'role'      => 'broker',
            'is_active' => false,
        ]);

        BrokerProfile::create([
            'user_id'      => $user->id,
            'company_name' => $request->company_name,
            'address'      => $request->address,
        ]);

        return response()->json([
            'message' => 'Demande envoyée. En attente de validation.',
            'user'    => $this->formatUser($user),
        ], 201);
    }

    /**
     * Profil connecté
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->formatUser($request->user()));
    }

    /**
     * Déconnexion
     */
    public function logout(Request $request): JsonResponse
    {
        // Mettre le captain hors ligne
        if ($request->user()->isCaptain()) {
            $request->user()->captainProfile?->setOffline();
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    /**
     * Changer mot de passe
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mot de passe actuel incorrect.'],
            ]);
        }

        $request->user()->update(['password' => $request->password]);

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }

    private function formatUser(\App\Models\User $user): array
    {
        $data = [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'phone'                 => $user->phone,
            'email'                 => $user->email,
            'role'                  => $user->role,      // 'client' ou 'captain' uniquement
            'is_active'             => $user->is_active,
            'avatar_url'            => $user->avatar_url,
            // Fonctionnalité broker (pour les clients uniquement)
            'is_broker_enabled'     => $user->is_broker_enabled ?? false,
            'broker_credit_balance' => $user->broker_credit_balance ?? 0,
        ];

        if ($user->isCaptain()) {
            $profile = $user->captainProfile;
            $data['captain_profile'] = $profile ? [
                'points'                  => $profile->points,
                'balance'                 => $profile->balance,
                'is_online'               => $profile->is_online,
                'status'                  => $profile->status,
                'vehicle_brand'           => $profile->vehicle_brand,
                'vehicle_model'           => $profile->vehicle_model,
                'vehicle_color'           => $profile->vehicle_color,
                'vehicle_plate'           => $profile->vehicle_plate,
                'has_active_subscription' => $profile->hasActiveSubscription(),
            ] : null;
        }

        return $data;
    }
}
