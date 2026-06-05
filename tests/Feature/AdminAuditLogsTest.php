<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\AuditLog;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Admin roadmap Phase 3.1 — DB-əsaslı audit jurnalı + AuditLogger dual-write.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
});

it('Phase 3.1: dual-writes an audit row on an admin action', function () {
    $target = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->post('/admin/users/' . $target->id . '/toggle-active')
        ->assertRedirect();

    $log = AuditLog::query()->where('event', 'admin.user.deactivated')->first();
    expect($log)->not->toBeNull();
    expect($log->actor_id)->toBe($this->admin->id);
    expect($log->context['user_id'])->toBe($target->id);
});

it('Phase 3.1: renders the audit log list', function () {
    AuditLog::create(['event' => 'test.alpha', 'context' => ['x' => 1]]);
    AuditLog::create(['event' => 'test.beta', 'context' => ['y' => 2]]);

    $this->actingAs($this->admin)->get('/admin/audit-logs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuditLogs')
            ->has('logs.data', 2)
            ->has('events', 2)
        );
});

it('Phase 3.1: filters by event', function () {
    AuditLog::create(['event' => 'test.alpha', 'context' => []]);
    AuditLog::create(['event' => 'test.beta', 'context' => []]);
    AuditLog::create(['event' => 'test.alpha', 'context' => []]);

    $this->actingAs($this->admin)->get('/admin/audit-logs?event=test.alpha')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
});

it('Phase 3.1: blocks non-admin', function () {
    $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $this->actingAs($customer)->get('/admin/audit-logs')->assertStatus(403);
});

it('Phase 3.1: audit logs are immutable (update/delete forbidden)', function () {
    $log = AuditLog::create(['event' => 'test.immutable', 'context' => []]);

    expect(fn () => $log->update(['event' => 'changed']))->toThrow(\RuntimeException::class);
    expect(fn () => $log->fresh()->delete())->toThrow(\RuntimeException::class);
});
