<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('credit_balance', 10, 2)->default(0); // Crédit prépayé
            $table->decimal('total_recharged', 10, 2)->default(0);
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->boolean('is_approved')->default(false); // Admin doit approuver
            $table->timestamps();
        });

        // Recharges de crédit broker
        Schema::create('broker_recharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_profile_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reference')->unique(); // Référence de la recharge
            $table->enum('method', ['cash', 'transfer', 'other'])->default('cash');
            $table->text('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users'); // Admin qui valide
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_recharges');
        Schema::dropIfExists('broker_profiles');
    }
};
