<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // === ADMIN ===
        User::create([
            'name'     => 'Sistem Admin',
            'email'    => 'admin@paylo.az',
            'password' => Hash::make('password'),
            'role'     => UserRole::Admin,
        ]);

        // === MERCHANT OWNERS & STAFF (Bravo Market üçün tam komanda) ===
        $bravo = Merchant::where('code', 'm_412')->first();
        $araz  = Merchant::where('code', 'm_209')->first();
        $socar = Merchant::where('code', 'm_088')->first();

        User::create([
            'name'        => 'Elçin Məmmədov',
            'email'       => 'owner@bravo.az',
            'password'    => Hash::make('password'),
            'role'        => UserRole::MerchantOwner,
            'merchant_id' => $bravo->id,
        ]);

        User::create([
            'name'        => 'Cəfər Hüseynov',
            'email'       => 'owner@araz.az',
            'password'    => Hash::make('password'),
            'role'        => UserRole::MerchantOwner,
            'merchant_id' => $araz->id,
        ]);

        // === CASHIERS ===
        User::create([
            'name'        => 'Aysu Quliyeva',
            'email'       => 'cashier@bravo.az',
            'password'    => Hash::make('password'),
            'role'        => UserRole::Cashier,
            'merchant_id' => $bravo->id,
        ]);

        User::create([
            'name'        => 'Rəşad Əliyev',
            'email'       => 'cashier2@bravo.az',
            'password'    => Hash::make('password'),
            'role'        => UserRole::Cashier,
            'merchant_id' => $bravo->id,
        ]);

        User::create([
            'name'        => 'Nigar Babayeva',
            'email'       => 'cashier@socar.az',
            'password'    => Hash::make('password'),
            'role'        => UserRole::Cashier,
            'merchant_id' => $socar->id,
        ]);

        // === POS TERMINAL (terminal-itself-as-user) ===
        User::create([
            'name'        => 'POS-001 Yasamal',
            'email'       => 'pos1@bravo.az',
            'password'    => Hash::make('password'),
            'role'        => UserRole::PosTerminal,
            'merchant_id' => $bravo->id,
        ]);

        // === CUSTOMERS ===
        $customers = [
            ['Aysel Hüseynova', 'aysel@gmail.com', '+994501234567'],
            ['Tural Quliyev',   'tural@gmail.com', '+994551234567'],
            ['Lalə Məmmədova',  'lale@gmail.com',  '+994701234567'],
            ['Vüqar Əhmədli',   'vugar@gmail.com', '+994551234568'],
            ['Səbinə Cəfərli',  'sebine@gmail.com','+994501234568'],
            ['Murad Bayramov',  'murad@gmail.com', '+994551234569'],
            ['Günel Salimova',  'gunel@gmail.com', '+994701234568'],
            ['Pərvin Hacıyev',  'pervin@gmail.com','+994501234569'],
        ];

        foreach ($customers as [$name, $email, $phone]) {
            User::create([
                'name'        => $name,
                'email'       => $email,
                'phone'       => $phone,
                'password'    => Hash::make('password'),
                'role'        => UserRole::Customer,
                'customer_qr' => 'qr_' . Str::lower(Str::random(12)),
            ]);
        }

        $this->command->info('  ✓ Users seeded:');
        $this->command->info('    · admin@paylo.az / password');
        $this->command->info('    · owner@bravo.az / password');
        $this->command->info('    · cashier@bravo.az / password');
        $this->command->info('    · aysel@gmail.com / password');
    }
}
