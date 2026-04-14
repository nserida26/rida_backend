<?php

namespace Database\Seeders;

use App\Models\CaptainProfile;
use App\Models\BrokerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin principal
        User::create([
            'name'      => 'Administrateur',
            'email'     => 'admin@etaxis.mr',
            'phone'     => '+22200000000',
            'password'  => bcrypt('Admin@1234'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        // Captain de test
        $captain = User::create([
            'name'      => 'Mamadou Diallo',
            'email'     => 'captain@etaxis.mr',
            'phone'     => '+22200000001',
            'password'  => bcrypt('Captain@1234'),
            'role'      => 'captain',
            'is_active' => true,
        ]);
        CaptainProfile::create([
            'user_id'        => $captain->id,
            'license_number' => 'LIC-001',
            'vehicle_brand'  => 'Toyota',
            'vehicle_model'  => 'Corolla',
            'vehicle_color'  => 'Blanc',
            'vehicle_plate'  => 'MR-1234-A',
            'vehicle_year'   => 2020,
            'points'         => 0,
            'status'         => 'offline',
        ]);

        // Client de test
        User::create([
            'name'      => 'Fatima Mint Ahmed',
            'email'     => 'client@etaxis.mr',
            'phone'     => '+22200000002',
            'password'  => bcrypt('Client@1234'),
            'role'      => 'client',
            'is_active' => true,
        ]);

        // Broker de test
        $broker = User::create([
            'name'      => 'Hotel Sahara',
            'email'     => 'broker@etaxis.mr',
            'phone'     => '+22200000003',
            'password'  => bcrypt('Broker@1234'),
            'role'      => 'broker',
            'is_active' => true,
        ]);
        BrokerProfile::create([
            'user_id'         => $broker->id,
            'company_name'    => 'Hotel Sahara Nouakchott',
            'address'         => 'Rue Mamadou Konaté, Nouakchott',
            'credit_balance'  => 5000.00,
            'total_recharged' => 5000.00,
            'is_approved'     => true,
        ]);

        $this->command->info('✅ Données de base créées avec succès.');
        $this->command->info('   Admin:   admin@etaxis.mr / Admin@1234');
        $this->command->info('   Captain: +22200000001 / Captain@1234');
        $this->command->info('   Client:  +22200000002 / Client@1234');
        $this->command->info('   Broker:  +22200000003 / Broker@1234');
    }
}
