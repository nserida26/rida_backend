<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // ETX-2024-00001

            // Acteurs
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('captain_id')->nullable()->constrained('users');
            $table->foreignId('broker_id')->nullable()->constrained('users'); // Si lancé par broker

            // Départ
            $table->string('pickup_address');
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);

            // Destination
            $table->string('dropoff_address');
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);

            // Statut
            $table->enum('status', [
                'pending',      // En attente d'un captain
                'accepted',     // Captain accepté
                'arrived',      // Captain arrivé au point de départ
                'in_progress',  // Course en cours
                'completed',    // Terminée
                'cancelled',    // Annulée
            ])->default('pending');

            // Tarification
            $table->decimal('estimated_price', 8, 2)->nullable();
            $table->decimal('final_price', 8, 2)->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->integer('duration_minutes')->nullable();

            // Paiement
            $table->enum('payment_method', ['cash', 'broker_credit'])->default('cash');
            $table->boolean('is_paid')->default(false);

            // Points attribués au captain
            $table->integer('points_earned')->default(0);

            // Timestamps métier
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            // Note client
            $table->integer('rating')->nullable(); // 1-5
            $table->text('comment')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
