<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->decimal('captain_commission', 8, 2)->default(0)->after('duration_minutes');
            $table->timestamp('commission_debited_at')->nullable()->after('captain_commission');
            $table->timestamp('commission_refunded_at')->nullable()->after('commission_debited_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn([
                'captain_commission',
                'commission_debited_at',
                'commission_refunded_at',
            ]);
        });
    }
};
