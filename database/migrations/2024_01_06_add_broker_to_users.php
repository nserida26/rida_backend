<?php
// Migration : ajouter le support broker dans la table users
// (remplace l'ancienne table broker_profiles séparée)
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Le broker n'est PLUS un rôle — c'est une option activable pour un client
            $table->boolean('is_broker_enabled')->default(false)->after('is_active');
            $table->decimal('broker_credit_balance', 10, 2)->default(0)->after('is_broker_enabled');
            $table->decimal('broker_total_recharged', 10, 2)->default(0)->after('broker_credit_balance');
            $table->decimal('broker_total_spent', 10, 2)->default(0)->after('broker_total_recharged');
        });

        // Les courses lancées via la fonctionnalité broker gardent une trace
        Schema::table('rides', function (Blueprint $table) {
            // broker_id devient l'ID du client qui lance pour un tiers
            // (déjà présent — on ajoute juste les infos du passager)
            $table->string('broker_client_name')->nullable()->after('broker_id');
            $table->string('broker_client_phone')->nullable()->after('broker_client_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_broker_enabled', 'broker_credit_balance', 'broker_total_recharged', 'broker_total_spent']);
        });
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['broker_client_name', 'broker_client_phone']);
        });
    }
};
