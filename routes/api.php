<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaptainController;
use App\Http\Controllers\Api\RideController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ===== AUTH PUBLIC =====
Route::prefix('auth')->group(function () {
    Route::post('/login',             [AuthController::class, 'login']);
    Route::post('/otp/send',          [AuthController::class, 'sendOtp']);
    Route::post('/otp/verify',        [AuthController::class, 'verifyOtp']);
    Route::post('/register/client',   [AuthController::class, 'registerClient']);
    Route::post('/register/captain',  [AuthController::class, 'registerCaptain']);
    Route::post('/register/driver',   [AuthController::class, 'registerCaptain']); // alias mobile app
});

// ===== ROUTES AUTHENTIFIÉES =====
Route::middleware('auth:sanctum')->group(function () {

    // Auth commun
    Route::prefix('auth')->group(function () {
        Route::get('/me',              [AuthController::class, 'me']);
        Route::post('/logout',         [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/fcm-token',      [AuthController::class, 'updateFcmToken']);
    });

    // ===== COURSES =====
    Route::prefix('rides')->group(function () {
        Route::get('/',                [RideController::class, 'myRides']);
        Route::post('/',               [RideController::class, 'store']);
        Route::get('/available',       [RideController::class, 'available']);   // Captain
        Route::get('/{ride}',          [RideController::class, 'show']);
        Route::post('/{ride}/accept',  [RideController::class, 'accept']);      // Captain
        Route::post('/{ride}/arrived', [RideController::class, 'markArrived']); // Captain
        Route::post('/{ride}/start',   [RideController::class, 'start']);       // Captain
        Route::post('/{ride}/complete', [RideController::class, 'complete']);    // Captain
        Route::post('/{ride}/cancel',  [RideController::class, 'cancel']);
        Route::post('/{ride}/rate',    [RideController::class, 'rate']);        // Client
    });

    // ===== CAPTAIN =====
    Route::get('/drivers/nearby', [CaptainController::class, 'nearbyCaptains']);

    Route::prefix('captain')->group(function () {
        Route::get('/dashboard',           [CaptainController::class, 'dashboard']);
        Route::post('/location',           [CaptainController::class, 'updateLocation']);
        Route::post('/status',             [CaptainController::class, 'setStatus']);
        Route::get('/points',              [CaptainController::class, 'points']);
        Route::get('/subscriptions',       [CaptainController::class, 'subscriptions']);
        Route::get('/available-captains',  [CaptainController::class, 'availableCaptains']); // Admin + Broker
    });

    // Compatibilité mobile (driver = captain)
    Route::prefix('driver')->group(function () {
        Route::get('/dashboard',           [CaptainController::class, 'dashboard']);
        Route::post('/location',           [CaptainController::class, 'updateLocation']);
        Route::post('/status',             [CaptainController::class, 'setStatus']);
        Route::get('/points',              [CaptainController::class, 'points']);
        Route::get('/subscriptions',       [CaptainController::class, 'subscriptions']);
        Route::get('/nearby',              [CaptainController::class, 'nearbyCaptains']);
    });

    // ===== ADMIN =====
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/dashboard',                [AdminController::class, 'dashboard']);

        // Utilisateurs
        Route::get('/users',                    [AdminController::class, 'users']);
        Route::post('/users/{user}/approve',    [AdminController::class, 'approveUser']);
        Route::post('/users/{user}/suspend',    [AdminController::class, 'suspendUser']);

        // Abonnements captains
        Route::post('/subscriptions',           [AdminController::class, 'storeSubscription']);
        Route::get('/subscriptions',            [AdminController::class, 'subscriptions']);

        // Fonctionnalité course pour tiers (clients)
        Route::post('/users/{user}/enable-third-party', [AdminController::class, 'enableBroker']);
        Route::post('/users/{user}/recharge',   [AdminController::class, 'rechargeBroker']);

        // Notifications
        Route::post('/notifications/send',      [AdminController::class, 'sendNotification']);

        // Courses
        Route::get('/rides',                    [AdminController::class, 'allRides']);
        Route::post('/rides',                   [AdminController::class, 'createRide']);
 
        // Ajustement points
        Route::post('/captain/adjust-points',   [AdminController::class, 'adjustPoints']);
        Route::post('/driver/adjust-points',    [AdminController::class, 'adjustPoints']); // alias mobile app
    });
});
