<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptainProfile;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Envoi OTP (compat mobile app)
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'purpose' => 'required|in:login,register',
            'channel' => 'nullable|string',
            'role' => 'nullable|in:client,captain,driver',
            'name' => 'nullable|string|max:100',
            'email' => 'nullable|email',
            'fcm_token' => 'nullable|string',
        ]);

        $phone = $this->normalizePhone($request->phone);
        $role = $this->normalizeRole($request->input('role', 'client'));
        logger()->info('OTP send requested', [
            'raw_phone' => $request->phone,
            'normalized_phone' => $phone,
            'purpose' => $request->purpose,
            'role' => $role,
            'channel' => $request->channel ?? 'whatsapp',
        ]);

        if ($request->purpose === 'login') {
            $exists = User::where('phone', $phone)->where('role', $role)->exists();
            logger()->info('OTP login user lookup', [
                'phone' => $phone,
                'role' => $role,
                'exists' => $exists,
            ]);
            if (!$exists) {
                $label = $role === 'captain' ? 'chauffeur' : 'client';
                return response()->json(['message' => "Aucun compte {$label} trouvé pour ce numéro."], 404);
            }
        } else {
            $exists = User::where('phone', $phone)->exists();
            logger()->info('OTP register user lookup', [
                'phone' => $phone,
                'exists' => $exists,
            ]);
            if ($exists) {
                return response()->json(['message' => 'Ce numéro est déjà utilisé.'], 422);
            }
        }

        $otp = (string) random_int(1000, 9999);
        Cache::put($this->otpCacheKey($phone, $request->purpose, $role), $otp, now()->addMinutes(5));

        $this->sendOtpViaUltraMsg($phone, $otp);

        logger()->info('OTP generated', [
            'phone' => $phone,
            'purpose' => $request->purpose,
            'role' => $role,
            'channel' => $request->channel ?? 'whatsapp',
        ]);

        return response()->json([
            'message' => 'Code OTP envoyé.',
            'expires_in' => 300,
        ]);
    }

    /**
     * Vérification OTP (compat mobile app)
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string|min:4|max:8',
            'purpose' => 'required|in:login,register',
            'role' => 'nullable|in:client,captain,driver',
            'name' => 'nullable|string|max:100',
            'email' => 'nullable|email',
            'fcm_token' => 'nullable|string',
        ]);

        $phone = $this->normalizePhone($request->phone);
        $role = $this->normalizeRole($request->input('role', 'client'));
        logger()->info('OTP verify requested', [
            'raw_phone' => $request->phone,
            'normalized_phone' => $phone,
            'purpose' => $request->purpose,
            'role' => $role,
            'code_length' => strlen((string) $request->code),
        ]);
        $cached = Cache::get($this->otpCacheKey($phone, $request->purpose, $role));
        logger()->info('OTP verify cache lookup', [
            'phone' => $phone,
            'purpose' => $request->purpose,
            'role' => $role,
            'has_cached_code' => (bool) $cached,
        ]);
        if (!$cached || (string) $cached !== (string) $request->code) {
            throw ValidationException::withMessages([
                'code' => ['Code OTP invalide ou expiré.'],
            ]);
        }
        Cache::forget($this->otpCacheKey($phone, $request->purpose, $role));

        if ($request->purpose === 'register') {
            if ($role === 'captain') {
                return response()->json([
                    'message' => 'Code OTP vérifié.',
                ]);
            }

            $user = User::create([
                'name' => $request->name ?: 'Client',
                'phone' => $phone,
                'email' => $request->email,
                'password' => Str::random(16),
                'role' => 'client',
                'is_active' => true,
                'fcm_token' => $request->fcm_token,
            ]);

            // Send welcome notification to new client
            app(FcmService::class)->sendWelcomeNotification($user);
        } else {
            $user = User::where('phone', $phone)
                ->where('role', $role)
                ->first();

            if (!$user) {
                $label = $role === 'captain' ? 'chauffeur' : 'client';
                return response()->json(['message' => "Compte {$label} introuvable."], 404);
            }
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Compte désactivé. Contactez l\'administrateur.'], 403);
        }

        $updates = ['last_login_at' => now()];
        if ($request->fcm_token) {
            $updates['fcm_token'] = $request->fcm_token;
        }
        $user->update($updates);

        $token = $user->createToken('etaxis-' . $user->role)->plainTextToken;

        if ($request->fcm_token) {
            app(FcmService::class)->sendWelcomeNotification($user);
        }

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ]);
    }

    /**
     * Connexion (tous rôles)
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string',
            'role'     => 'required|in:admin,captain,client',
            'fcm_token' => 'nullable|string',
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

        $updates = ['last_login_at' => now()];
        if ($request->fcm_token) {
            $updates['fcm_token'] = $request->fcm_token;
        }
        $user->update($updates);

        if ($request->fcm_token) {
            app(FcmService::class)->sendWelcomeNotification($user);
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
            'fcm_token' => 'nullable|string',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => $request->email,
            'password' => $request->password,
            'role'     => 'client',
            'fcm_token' => $request->fcm_token,
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
            'fcm_token'      => 'nullable|string',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'phone'     => $request->phone,
            'email'     => $request->email,
            'password'  => $request->password,
            'role'      => 'captain',
            'is_active' => false, // Attente validation admin
            'fcm_token' => $request->fcm_token,
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

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $request->user()->update(['fcm_token' => $request->fcm_token]);

        return response()->json(['message' => 'Token FCM mis a jour.']);
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

    private function otpCacheKey(string $phone, string $purpose, string $role = 'client'): string
    {
        return 'otp:' . $purpose . ':' . $this->normalizeRole($role) . ':' . $this->normalizePhone($phone);
    }

    private function normalizeRole(string $role): string
    {
        return $role === 'driver' ? 'captain' : $role;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', trim($phone));
    }

    private function sendOtpViaUltraMsg(string $phone, string $otp): void
    {
        $instanceId = (string) config('services.ultramsg.instance_id');
        $token = (string) config('services.ultramsg.token');

        if ($instanceId === '' || $token === '') {
            logger()->error('UltraMsg config missing', [
                'has_instance_id' => $instanceId !== '',
                'has_token' => $token !== '',
            ]);
            throw ValidationException::withMessages([
                'whatsapp' => ['Configuration UltraMsg manquante (ULTRAMSG_INSTANCE_ID / ULTRAMSG_TOKEN).'],
            ]);
        }

        $to = preg_replace('/\D+/', '', $phone);
        $body = "Votre code Masar est : {$otp}. Il expire dans 5 minutes.";

        $url = "https://api.ultramsg.com/{$instanceId}/messages/chat";
        logger()->info('UltraMsg OTP send start', [
            'phone' => $phone,
            'to' => $to,
            'instance_id' => $instanceId,
        ]);
        $response = Http::asForm()->timeout(15)->post($url, [
            'token' => $token,
            'to' => $to,
            'body' => $body,
        ]);
        logger()->info('UltraMsg OTP send response', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->body(),
            'phone' => $phone,
        ]);

        if (!$response->successful()) {
            logger()->error('UltraMsg OTP send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'phone' => $phone,
            ]);

            throw ValidationException::withMessages([
                'whatsapp' => ['Impossible d\'envoyer le code OTP sur WhatsApp.'],
            ]);
        }
    }
}
