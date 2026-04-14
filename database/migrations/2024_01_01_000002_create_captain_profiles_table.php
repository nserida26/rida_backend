<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captain_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('license_number')->unique();
            $table->string('vehicle_brand');
            $table->string('vehicle_model');
            $table->string('vehicle_color');
            $table->string('vehicle_plate')->unique();
            $table->integer('vehicle_year');
            $table->integer('points')->default(0);        // Points gagnés par courses
            $table->decimal('balance', 10, 2)->default(0); // Solde en espèces cumulé
            $table->boolean('is_online')->default(false);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->enum('status', ['available', 'busy', 'offline'])->default('offline');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captain_profiles');
    }
};
