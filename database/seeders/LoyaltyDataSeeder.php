<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Modules\Pos\Services\EarnCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Realistic ledger + transactions yaradır:
 *   - hər customer × bir neçə merchant cütü üçün satışlar
 *   - earn → bəzi satışlarda redeem
 *   - bir neçə refund
 *
 * Bütün hərəkətlər LedgerService vasitəsilə yazılır — beləliklə bucket-lər
 * və ledger-in synchronization-ı qarantili.
 */
class LoyaltyDataSeeder extends Seeder
{
    public function run(LedgerService $ledger, EarnCalculator $earn): void
    {
        $customers = User::where('role', UserRole::Customer)->get();
        $merchants = Merchant::where('status', 'active')->get();
        $cashiers  = User::whereIn('role', [UserRole::Cashier, UserRole::PosTerminal])->get();

        if ($customers->isEmpty() || $merchants->isEmpty()) {
            $this->command->warn('  ⚠ Customers/merchants tapılmadı, LoyaltyData seed boşaldıldı');
            return;
        }

        $totalTransactions = 0;

        foreach ($customers as $customer) {
            // hər customer 2-5 fərqli merchant-da satış edir
            $visitedMerchants = $merchants->random(min($merchants->count(), random_int(2, 5)));

            foreach ($visitedMerchants as $merchant) {
                $cashier = $cashiers->where('merchant_id', $merchant->id)->first() ?? $cashiers->first();
                $txCount = random_int(2, 8);

                for ($i = 0; $i < $txCount; $i++) {
                    $saleAzn   = match (true) {
                        $merchant->category === 'fuel'       => random_int(20, 120),
                        $merchant->category === 'grocery'    => random_int(5, 80),
                        $merchant->category === 'restaurant' => random_int(10, 60),
                        $merchant->category === 'pharmacy'   => random_int(3, 40),
                        default                              => random_int(5, 50),
                    };
                    $saleAmount = BonusValue::fromAzn($saleAzn + (random_int(0, 99) / 100));
                    $earnAmount = $earn->calculate($merchant, $saleAmount);

                    // 25% ehtimalla əvvəlki bucket-dən bonus istifadə
                    $bucket = $customer->buckets()->where('merchant_id', $merchant->id)->first();
                    $useBonus    = $bucket && $bucket->balance > 500 && random_int(1, 4) === 1;
                    $redeemAmount = $useBonus
                        ? new BonusValue(min($bucket->balance, intdiv($saleAmount->amount, 4)))
                        : new BonusValue(0);

                    $occurredAt = now()->subDays(random_int(0, 60))->subHours(random_int(0, 23));
                    $receiptNo  = 'r' . Str::upper(Str::random(8));

                    $tx = Transaction::create([
                        'receipt_no'      => $receiptNo,
                        'merchant_id'     => $merchant->id,
                        'branch_id'       => $merchant->branches()->first()?->id,
                        'cashier_id'      => $cashier?->id,
                        'user_id'         => $customer->id,
                        'sale_amount'     => $saleAmount->amount,
                        'earned_amount'   => $earnAmount->amount,
                        'redeemed_amount' => $redeemAmount->amount,
                        'status'          => 'completed',
                        'occurred_at'     => $occurredAt,
                    ]);

                    if (! $redeemAmount->isZero()) {
                        $ledger->redeem(
                            customer: $customer,
                            merchant: $merchant,
                            amount: $redeemAmount,
                            receiptNo: $receiptNo,
                            branchId: $tx->branch_id,
                            cashierId: $cashier?->id,
                            meta: ['transaction_id' => $tx->id, 'seeded' => true],
                        );
                    }

                    $ledger->earn(
                        customer: $customer,
                        merchant: $merchant,
                        amount: $earnAmount,
                        receiptNo: $receiptNo,
                        branchId: $tx->branch_id,
                        cashierId: $cashier?->id,
                        meta: ['transaction_id' => $tx->id, 'seeded' => true],
                    );

                    $totalTransactions++;
                }
            }
        }

        $this->command->info("  ✓ {$totalTransactions} transactions + ledger entries seeded");
    }
}
