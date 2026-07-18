<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmService
{
    private const NEIGHBORHOOD_RADIUS_KM = 3.5;
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CUSTOMER_MESSAGES = [
        'ride_created'  => ['title' => 'Course créée',    'body' => 'Votre demande a été envoyée aux chauffeurs.'],
        'ride_accepted' => ['title' => 'Course acceptée', 'body' => 'Un chauffeur arrive vers vous.'],
        'driver_arrived'=> ['title' => 'Chauffeur arrivé','body' => 'Votre chauffeur est au point de départ.'],
        'ride_started'  => ['title' => 'Course démarrée', 'body' => 'Bonne route avec Masar.'],
        'ride_completed'=> ['title' => 'Course terminée', 'body' => 'Merci d\'avoir voyagé avec Masar.'],
        'ride_cancelled'=> ['title' => 'Course annulée',  'body' => 'La course a été annulée.'],
    ];

    private const DRIVER_MESSAGES = [
        'ride_cancelled'=> ['title' => 'Course annulée', 'body' => 'La course a été annulée par le client.'],
    ];

    public function sendNewRideToAvailableCaptains(Ride $ride): void
    {
        $lat = (float) $ride->pickup_lat;
        $lng = (float) $ride->pickup_lng;

        $ride->loadMissing('client');

        $tokens = User::query()
            ->join('captain_profiles', 'captain_profiles.user_id', '=', 'users.id')
            ->where('users.role', 'captain')
            ->where('users.is_active', true)
            ->whereNotNull('users.fcm_token')
            ->where('captain_profiles.status', 'available')
            ->whereNotNull('captain_profiles.current_lat')
            ->whereNotNull('captain_profiles.current_lng')
            ->select('users.fcm_token')
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(captain_profiles.current_lat)) * cos(radians(captain_profiles.current_lng) - radians(?)) + sin(radians(?)) * sin(radians(captain_profiles.current_lat)))) as distance_km',
                [$lat, $lng, $lat]
            )
            ->having('distance_km', '<=', self::NEIGHBORHOOD_RADIUS_KM)
            ->get()
            ->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values();

        $data = [
            'type'             => 'new_ride',
            'ride_id'          => (string) $ride->id,
            'pickup'           => (string) $ride->pickup_address,
            'destination'      => (string) $ride->dropoff_address,
            'customer_phone'   => (string) ($ride->client?->phone ?? ''),
            'price'            => (string) ($ride->estimated_price ?? ''),
            'timestamp'        => (string) now()->toIso8601String(),
        ];

        foreach ($tokens as $token) {
            $this->sendNewRide($token, $data);
        }
    }

    public function sendRideUpdateToCustomer(?Ride $ride, string $type): void
    {
        if (!$ride) return;

        $ride->loadMissing(['client', 'broker']);
        $tokens = collect([
            $ride->client?->fcm_token,
            $ride->broker?->fcm_token,
        ])->filter()->unique()->values();

        $message = self::CUSTOMER_MESSAGES[$type] ?? ['title' => 'Masar', 'body' => 'Mise à jour de votre course.'];

        foreach ($tokens as $token) {
            $this->send($token, $message, [
                'type'    => $type,
                'ride_id' => (string) $ride->id,
                'status'  => (string) $ride->status,
            ]);
        }
    }

    public function sendWelcomeNotification(User $user): void
    {
        if (!$user->fcm_token) return;

        $this->send($user->fcm_token, [
            'title' => 'Welcome · Bienvenue · مرحبا',
            'body'  => 'Masar — تنقّل براحة، اكسب بثقة',
        ], [
            'type' => 'welcome',
        ]);
    }

    /**
     * Send a custom notification to a list of user IDs (admin broadcast).
     *
     * @param  int[]  $userIds   Empty array = send to all users of given role.
     * @param  string $role      'captain' | 'client' | 'all'
     */
    public function sendCustomToUsers(
        array  $userIds,
        string $title,
        string $body,
        string $role = 'all'
    ): int {
        $query = User::query()->whereNotNull('fcm_token');

        if (!empty($userIds)) {
            $query->whereIn('id', $userIds);
        } elseif ($role !== 'all') {
            $normalised = $role === 'driver' ? 'captain' : $role;
            $query->where('role', $normalised);
        }

        $tokens = $query->pluck('fcm_token')->filter()->unique()->values();

        foreach ($tokens as $token) {
            $this->send($token, ['title' => $title, 'body' => $body], [
                'type' => 'admin_notification',
            ]);
        }

        return $tokens->count();
    }

    public function sendRideUpdateToDriver(?Ride $ride, string $type): void
    {
        if (!$ride) return;

        $ride->loadMissing('captain');
        if (!$ride->captain?->fcm_token) return;

        $message = self::DRIVER_MESSAGES[$type] ?? ['title' => 'Masar Driver', 'body' => 'Mise à jour de votre course.'];

        $this->send($ride->captain->fcm_token, $message, [
            'type'    => $type,
            'ride_id' => (string) $ride->id,
            'status'  => (string) $ride->status,
        ]);
    }

    /**
     * Data-only high-priority FCM message for new rides.
     *
     * Sending NO `notification` key means the FCM SDK never auto-displays a
     * system notification.  Instead the Flutter background isolate receives the
     * data silently and shows a full-screen local notification via
     * flutter_local_notifications – matching Uber/Bolt behaviour.
     */
    public function sendNewRide(string $token, array $data): void
    {
        $projectId = (string) config('services.firebase.project_id');
        if ($projectId === '') {
            logger()->warning('FCM project ID missing; new-ride notification skipped.');
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) return;

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post($endpoint, [
                'message' => [
                    'token'   => $token,
                    // ── No 'notification' key → data-only, background handler fires ──
                    'data'    => array_map('strval', $data),
                    'android' => [
                        'priority' => 'high',   // FCM_HIGH → delivers to killed app
                    ],
                    // iOS: background push so the isolate can wake up and show local notif
                    'apns' => [
                        'headers' => ['apns-push-type' => 'background', 'apns-priority' => '5'],
                        'payload' => ['aps' => ['content-available' => 1]],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            logger()->warning('FCM v1 new-ride (data-only) failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }
    }

    public function send(string $token, array $notification, array $data = []): void
    {
        $projectId = (string) config('services.firebase.project_id');
        if ($projectId === '') {
            logger()->warning('FCM project ID missing; notification skipped.');
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return;
        }

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post($endpoint, [
                'message' => [
                    'token'        => $token,
                    'notification' => [
                        'title' => $notification['title'] ?? 'Masar',
                        'body'  => $notification['body'] ?? '',
                    ],
                    'data'    => array_map('strval', $data),
                    'android' => [
                        'priority'     => 'high',
                        'notification' => [
                            'sound'      => 'default',
                            'channel_id' => 'masar_rides',
                        ],
                    ],
                    'apns' => [
                        'headers' => ['apns-priority' => '10'],
                        'payload' => ['aps' => ['sound' => 'default']],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            logger()->warning('FCM v1 notification failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // OAuth2 via service-account JWT  (no external packages required)
    // -----------------------------------------------------------------------

    private function getAccessToken(): ?string
    {
        return Cache::remember('fcm_v1_access_token', 2700, function () {
            $credentials = $this->loadCredentials();
            if ($credentials === null) return null;

            $jwt = $this->buildJwt($credentials);

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if (!$response->successful()) {
                logger()->warning('FCM OAuth token fetch failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json('access_token');
        });
    }

    private function loadCredentials(): ?array
    {
        $path = (string) config('services.firebase.credentials_path');

        if (!file_exists($path)) {
            logger()->warning('Firebase credentials file not found', ['path' => $path]);
            return null;
        }

        $credentials = json_decode(file_get_contents($path), true);

        if (!isset($credentials['client_email'], $credentials['private_key'])) {
            logger()->warning('Firebase credentials file is invalid or missing required fields.');
            return null;
        }

        return $credentials;
    }

    private function buildJwt(array $credentials): string
    {
        $now = time();

        $header = $this->base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64url((string) json_encode([
            'iss'   => $credentials['client_email'],
            'scope' => self::FCM_SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = "{$header}.{$claims}";
        openssl_sign($signingInput, $signature, $credentials['private_key'], 'sha256WithRSAEncryption');

        return "{$signingInput}." . $this->base64url($signature);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
