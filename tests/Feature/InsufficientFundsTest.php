<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Exceptions\InsufficientFundsException;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Audit C-6 — InsufficientFundsException domain exception.
| Yaranma yeri: LedgerService::redeem / refund balans çatmadıqda.
| Render: bootstrap/app.php JSON sorğuları üçün 422-yə bağlayır.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);
    config()->set('loyalty.redemption.max_percent_of_sale_bp', 5000);
    config()->set('loyalty.redemption.min_sale_amount_cents', 100);

    $this->ledger   = app(LedgerService::class);
    $this->merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
});

it('throws InsufficientFundsException with structured available/required from redeem', function () {
    // Bucket-də cəmi 100 qəpik var, müştəri 500 redeem etməyə çalışır.
    Bucket::create([
        'user_id'        => $this->customer->id,
        'merchant_id'    => $this->merchant->id,
        'balance'        => 100,
        'earned_total'   => 100,
        'redeemed_total' => 0,
        'expired_total'  => 0,
    ]);

    try {
        $this->ledger->redeem($this->customer, $this->merchant, new BonusValue(500));
        $this->fail('InsufficientFundsException atılmalı idi.');
    } catch (InsufficientFundsException $e) {
        expect($e->available->amount)->toBe(100);
        expect($e->required->amount)->toBe(500);
        expect($e->getMessage())->toContain('Kifayət qədər bonus yoxdur');
        expect($e->getMessage())->toContain('redeem');
    }
});

// Global exception renderer-i sınamaq üçün inline route. POS controller redeem-i
// öncədən clamp etdiyinə görə real endpoint bu yola düşmür; renderer
// müdafiə qatıdır (gələcək kod yolları, məs. admin manuel redeem üçün).
it('renders InsufficientFundsException as 422 JSON via global handler', function () {
    Route::middleware('api')->post('/_test/insufficient', function () {
        throw new InsufficientFundsException(
            available: new BonusValue(50),
            required:  new BonusValue(500),
            context:   'unit-test',
        );
    });

    $this->postJson('/_test/insufficient')
        ->assertStatus(422)
        ->assertJson([
            'status'          => 'insufficient_funds',
            'available_cents' => 50,
            'required_cents'  => 500,
        ]);
});
