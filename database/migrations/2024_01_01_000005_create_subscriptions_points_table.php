<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abonnements des captains à la compagnie
        Schema::create('captain_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount_paid', 8, 2);
            $table->string('reference')->unique();
            $table->enum('period', ['weekly', 'monthly'])->default('monthly');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->boolean('is_active')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Règles de points (configurables par l'admin)
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('points_per_ride')->default(1);   // Points par course
            $table->decimal('min_ride_price', 8, 2)->default(0); // Prix minimum pour gagner des points
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Historique des points captain
        Schema::create('captain_points_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('points');
            $table->enum('type', ['earned', 'redeemed', 'adjusted'])->default('earned');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captain_points_history');
        Schema::dropIfExists('point_rules');
        Schema::dropIfExists('captain_subscriptions');
    }
};
