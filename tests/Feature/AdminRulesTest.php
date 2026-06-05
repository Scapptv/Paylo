<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\AuditLog;
use App\Core\Models\LoyaltyRule;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4.2 — Admin Rules controller (index / update / reset / validasiya / authz).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Cache::flush();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
});

it('Phase 4.2: renders grouped editable rules', function () {
    $this->actingAs($this->admin)->get('/admin/rules')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Rules')
            ->has('rules')
            ->where('rules.0.group', 'Earn rates')
        );
});

it('Phase 4.2: updates a rule — persists with actor + audits', function () {
    $this->from('/admin/rules')
        ->actingAs($this->admin)
        ->post('/admin/rules', ['key' => 'earn_rates_bp.grocery', 'value' => 350])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('loyalty_rules', [
        'key' => 'earn_rates_bp.grocery', 'value' => 350, 'updated_by' => $this->admin->id,
    ]);

    // Audit aktor + köhnə/yeni dəyər qeyd olunur.
    $log = AuditLog::query()->where('event', 'admin.loyalty_rule.updated')->first();
    expect($log)->not->toBeNull();
    expect($log->actor_id)->toBe($this->admin->id);
    expect($log->context['old'])->toBe(200);
    expect($log->context['new'])->toBe(350);
});

it('Phase 4.2: override becomes effective via resolver (per-request boot)', function () {
    // Feature test-də provider boot bir dəfə işlədiyi üçün applyOverrides-i
    // birbaşa çağırırıq — real php-fpm/serve-də hər request boot edir.
    LoyaltyRule::create(['key' => 'earn_rates_bp.grocery', 'value' => 350, 'updated_by' => $this->admin->id]);
    app(\App\Core\Services\LoyaltyRuleResolver::class)->applyOverrides();

    expect((int) config('loyalty.earn_rates_bp.grocery'))->toBe(350);
});

it('Phase 4.2: rejects an out-of-range value (earn rate > 10000 bp)', function () {
    $this->from('/admin/rules')
        ->actingAs($this->admin)
        ->post('/admin/rules', ['key' => 'earn_rates_bp.grocery', 'value' => 99999])
        ->assertSessionHasErrors('value');

    $this->assertDatabaseMissing('loyalty_rules', ['key' => 'earn_rates_bp.grocery']);
});

it('Phase 4.2: rejects a negative value (EarnCalculator fail-fast qoruması)', function () {
    $this->from('/admin/rules')
        ->actingAs($this->admin)
        ->post('/admin/rules', ['key' => 'tier_multipliers_bp.premium', 'value' => -10])
        ->assertSessionHasErrors('value');

    $this->assertDatabaseMissing('loyalty_rules', ['key' => 'tier_multipliers_bp.premium']);
});

it('Phase 4.2: rejects an unknown rule key', function () {
    $this->from('/admin/rules')
        ->actingAs($this->admin)
        ->post('/admin/rules', ['key' => 'hacker.injected_key', 'value' => 5])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->assertDatabaseMissing('loyalty_rules', ['key' => 'hacker.injected_key']);
});

it('Phase 4.2: resets a rule to file default', function () {
    LoyaltyRule::create(['key' => 'earn_rate_default_bp', 'value' => 300, 'updated_by' => $this->admin->id]);

    $this->from('/admin/rules')
        ->actingAs($this->admin)
        ->post('/admin/rules/reset', ['key' => 'earn_rate_default_bp'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('loyalty_rules', ['key' => 'earn_rate_default_bp']);
    expect(AuditLog::query()->where('event', 'admin.loyalty_rule.reset')->exists())->toBeTrue();
});

it('Phase 4.2: blocks non-admin from viewing and editing', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $owner = User::factory()->create(['role' => UserRole::MerchantOwner, 'merchant_id' => $merchant->id, 'is_active' => true]);

    $this->actingAs($owner)->get('/admin/rules')->assertStatus(403);
    $this->actingAs($owner)->post('/admin/rules', ['key' => 'earn_rates_bp.grocery', 'value' => 100])->assertStatus(403);
});
