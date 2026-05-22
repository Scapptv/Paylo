<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Models\Branch;
use App\Core\Models\Merchant;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $merchants = [
            ['code' => 'm_412', 'name' => 'Bravo Market', 'legal_name' => 'Bravo Supermarket MMC', 'tin' => '1700412091', 'mcc' => 5411, 'category' => 'grocery',    'tier' => 'premium',    'status' => 'active', 'region' => 'Bakı'],
            ['code' => 'm_209', 'name' => 'Araz Express',  'legal_name' => 'Araz Supermarket MMC', 'tin' => '1700209534', 'mcc' => 5411, 'category' => 'grocery',    'tier' => 'standard',   'status' => 'active', 'region' => 'Bakı'],
            ['code' => 'm_088', 'name' => 'Socar Station', 'legal_name' => 'SOCAR LSI MMC',         'tin' => '9900088201', 'mcc' => 5541, 'category' => 'fuel',       'tier' => 'enterprise', 'status' => 'active', 'region' => 'Network-wide'],
            ['code' => 'm_321', 'name' => 'Aptek Plus',    'legal_name' => 'Aptek Plus Şəbəkəsi',  'tin' => '1700321078', 'mcc' => 5912, 'category' => 'pharmacy',   'tier' => 'standard',   'status' => 'active', 'region' => 'Bakı'],
            ['code' => 'm_055', 'name' => 'Pasha Cafe',    'legal_name' => 'Pasha Restoranları',    'tin' => '1700055018', 'mcc' => 5812, 'category' => 'restaurant', 'tier' => 'standard',   'status' => 'active', 'region' => 'Bakı'],
            ['code' => 'm_077', 'name' => 'BP Connect',    'legal_name' => 'BP Azerbaijan',         'tin' => '9900077001', 'mcc' => 5541, 'category' => 'fuel',       'tier' => 'premium',    'status' => 'active', 'region' => 'Network-wide'],
            ['code' => 'm_412alt', 'name' => 'Tabaqa Restaurant', 'legal_name' => 'Tabaqa MMC',     'tin' => '1700412112', 'mcc' => 5812, 'category' => 'restaurant', 'tier' => 'standard',   'status' => 'active', 'region' => 'Bakı'],
            ['code' => 'm_120', 'name' => 'Yerli Bazar',   'legal_name' => 'Yerli Aqro Tədarük',   'tin' => '1700120842', 'mcc' => 5499, 'category' => 'grocery',    'tier' => 'standard',   'status' => 'pending', 'region' => 'Gəncə'],
        ];

        $branchPrefixes = [
            'm_412' => ['Yasamal', 'Nizami', 'Səbail', '28 May'],
            'm_209' => ['Nərimanov', 'Xətai', 'Sahil'],
            'm_088' => ['Sumqayıt-1', 'Bakı-Şəmkir', 'Port Baku', 'Caspian Plaza'],
            'm_321' => ['Yasamal', 'Nizami'],
            'm_055' => ['Park Bulvar', 'AF Mall'],
            'm_077' => ['Bakı-Sumqayıt', 'Port Baku', 'Bakı-Quba'],
            'm_412alt' => ['Yasamal'],
            'm_120' => ['Gəncə-1', 'Gəncə-2'],
        ];

        foreach ($merchants as $data) {
            $merchant = Merchant::create([
                ...$data,
                'settlement_iban'  => 'AZ' . random_int(10, 99) . 'NABZ' . random_int(1000, 9999) . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                'settlement_cycle' => $data['tier'] === 'enterprise' ? 'T+1' : ($data['tier'] === 'premium' ? 'T+3' : 'T+5'),
                'onboarded_at'     => now()->subDays(random_int(30, 720)),
            ]);

            foreach ($branchPrefixes[$data['code']] ?? [] as $i => $name) {
                Branch::create([
                    'merchant_id'     => $merchant->id,
                    'code'            => 'b' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'name'            => explode(' ', $data['name'])[0] . ' ' . $name,
                    'address'         => $name . ', ' . $data['region'],
                    'pos_terminal_id' => 'pos_' . (8000 + $i * 17 + strlen($data['code'])),
                ]);
            }
        }

        $this->command->info('  ✓ ' . count($merchants) . ' merchants + branches created');
    }
}
