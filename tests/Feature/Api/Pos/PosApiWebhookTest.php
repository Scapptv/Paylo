<?php

declare(strict_types=1);

use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Modules\Api\Models\WebhookDelivery;
use App\Modules\Api\Models\WebhookEndpoint;
use App\Modules\Api\Services\WebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

    $this->merchant = Merchant::factory()->create([
        'status'   => 'active',
        'category' => 'grocery',
        'tier'     => 'standard',
    ]);

    $this->endpoint = WebhookEndpoint::create([
        'merchant_id' => $this->merchant->id,
        'name'        => 'posnet-test',
        'url'         => 'https://posnet.test/loyalty-events',
        'hmac_secret' => str_repeat('a', 64),
        'events'      => ['admin_reverse', 'bucket_expire'],
        'active'      => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| WebhookSender — direct unit-style coverage
|--------------------------------------------------------------------------
*/

it('emits webhook with HMAC headers and stores a delivered record on 2xx', function () {
    Http::fake([
        'posnet.test/*' => Http::response(['ok' => true], 200),
    ]);

    $sender = app(WebhookSender::class);
    $count = $sender->emit($this->merchant->id, 'admin_reverse', [
        'transaction_id' => 147,
        'receipt_no'     => 'R-1',
    ]);

    expect($count)->toBe(1);

    $delivery = WebhookDelivery::first();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED);
    expect($delivery->delivered_at)->not->toBeNull();
    expect($delivery->attempt_count)->toBe(1);

    // Inspect the actual HTTP request payload.
    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://posnet.test/loyalty-events') {
            return false;
        }
        $headers = $request->headers();
        // Signature shape
        if (! isset($headers['X-Paylo-Signature'][0])
            || ! str_starts_with($headers['X-Paylo-Signature'][0], 'sha256=')) {
            return false;
        }
        // ULID event id
        if (! isset($headers['X-Paylo-Event-Id'][0])
            || strlen($headers['X-Paylo-Event-Id'][0]) !== 26) {
            return false;
        }
        if ($headers['X-Paylo-Event'][0] !== 'admin_reverse') {
            return false;
        }
        return true;
    });
});

it('does not emit to inactive endpoints', function () {
    Http::fake();
    $this->endpoint->update(['active' => false]);

    $sender = app(WebhookSender::class);
    $count = $sender->emit($this->merchant->id, 'admin_reverse', ['tx' => 1]);

    expect($count)->toBe(0);
    Http::assertNothingSent();
});

it('does not emit events the endpoint did not subscribe to', function () {
    Http::fake();
    $this->endpoint->update(['events' => ['bucket_expire']]);

    $sender = app(WebhookSender::class);
    $count = $sender->emit($this->merchant->id, 'admin_reverse', ['tx' => 1]);

    expect($count)->toBe(0);
    Http::assertNothingSent();
});

it('does not emit cross-merchant events', function () {
    Http::fake();
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);

    $sender = app(WebhookSender::class);
    $count = $sender->emit($otherMerchant->id, 'admin_reverse', ['tx' => 1]);

    expect($count)->toBe(0);
    Http::assertNothingSent();
});

it('marks delivery as failed when receiver returns 5xx', function () {
    Http::fake([
        'posnet.test/*' => Http::response(['error' => 'boom'], 500),
    ]);

    $sender = app(WebhookSender::class);
    $sender->emit($this->merchant->id, 'admin_reverse', ['tx' => 1]);

    $delivery = WebhookDelivery::first();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED);
    expect($delivery->last_response_status)->toBe(500);
});

it('marks delivery as failed when network throws', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('DNS failed');
    });

    $sender = app(WebhookSender::class);
    $sender->emit($this->merchant->id, 'admin_reverse', ['tx' => 1]);

    $delivery = WebhookDelivery::first();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED);
    expect($delivery->last_response_body)->toContain('EXCEPTION');
});

it('signature is reproducible from the canonical formula', function () {
    Http::fake([
        'posnet.test/*' => Http::response(['ok' => true], 200),
    ]);

    $sender = app(WebhookSender::class);
    $sender->emit($this->merchant->id, 'admin_reverse', ['tx' => 1]);

    Http::assertSent(function ($request) {
        $headers = $request->headers();
        $ts = $headers['X-Paylo-Timestamp'][0];
        $sig = $headers['X-Paylo-Signature'][0];
        $body = $request->body();

        $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $this->endpoint->hmac_secret);
        return hash_equals($expected, $sig);
    });
});

/*
|--------------------------------------------------------------------------
| ReverseFlowService integration — admin reverse emits, API reverse does NOT
|--------------------------------------------------------------------------
*/

it('admin reverse fires admin_reverse webhook', function () {
    Http::fake(['posnet.test/*' => Http::response(['ok' => true], 200)]);

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $tx = Transaction::create([
        'receipt_no'      => 'r-rev-admin',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $customer->id,
        'user_id'         => $customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => TransactionStatus::Completed,
        'occurred_at'     => now(),
    ]);
    \App\Core\Models\Bucket::create([
        'user_id' => $customer->id,
        'merchant_id' => $this->merchant->id,
        'balance' => 100, 'earned_total' => 100, 'redeemed_total' => 0,
    ]);

    $reverseFlow = app(\App\Core\Services\ReverseFlowService::class);
    $reverseFlow->execute($tx, 1, 'RET-1', 'admin reversal', 'admin.transaction.reverse');

    expect(WebhookDelivery::where('event_type', 'admin_reverse')->count())->toBe(1);
});

it('API POS reverse does NOT fire webhook (POSNET already knows)', function () {
    Http::fake();

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $posUser = User::factory()->create([
        'role' => UserRole::PosTerminal, 'merchant_id' => $this->merchant->id,
    ]);
    $tx = Transaction::create([
        'receipt_no'      => 'r-rev-api',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $posUser->id,
        'user_id'         => $customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => TransactionStatus::Completed,
        'occurred_at'     => now(),
    ]);
    \App\Core\Models\Bucket::create([
        'user_id' => $customer->id,
        'merchant_id' => $this->merchant->id,
        'balance' => 100, 'earned_total' => 100, 'redeemed_total' => 0,
    ]);

    $reverseFlow = app(\App\Core\Services\ReverseFlowService::class);
    $reverseFlow->execute($tx, $posUser->id, 'RET-1', null, 'api.pos.sale.reverse');

    expect(WebhookDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Endpoint security: HMAC secret must NOT be exposed via /api or list output
|--------------------------------------------------------------------------
*/

it('list-webhooks output does NOT contain the hmac_secret', function () {
    $this->artisan('pos:list-webhooks', ['--merchant' => $this->merchant->code])
        ->doesntExpectOutputToContain($this->endpoint->hmac_secret)
        ->assertSuccessful();
});
