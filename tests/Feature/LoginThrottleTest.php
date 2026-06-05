<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Audit A-2 — web auth iki qatlı throttle (composite + email-only).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    RateLimiter::clear('login_email:victim@example.com');
    $this->victim = User::factory()->create([
        'email'     => 'victim@example.com',
        'password'  => bcrypt('correct-horse'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
});

it('blocks the 6th attempt from the same IP+email (composite layer)', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email'    => 'victim@example.com',
            'password' => 'wrong-' . $i,
        ])->assertSessionHasErrors('email');
    }

    // 6-cı cəhd lockout-a düşməlidir (composite key 5 üçün hit).
    $this->post('/login', [
        'email'    => 'victim@example.com',
        'password' => 'wrong-6',
    ])->assertSessionHasErrors('email');

    // Doğru parol olsa belə hələ də blokludur (composite key).
    $this->post('/login', [
        'email'    => 'victim@example.com',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors('email');
});

it('blocks the same email when attacker rotates IPs (email-only layer)', function () {
    // Hər cəhd fərqli IP-dən gəlsin → composite key heç vaxt 5-ə çatmır.
    // Lakin email-only layer 10-da kilidlənməlidir.
    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post('/login', [
                'email'    => 'victim@example.com',
                'password' => 'wrong-' . $i,
            ])
            ->assertSessionHasErrors('email');
    }

    // 11-ci IP-dən gəlir, doğru parol — email-only layer hələ blokda saxlayır.
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post('/login', [
            'email'    => 'victim@example.com',
            'password' => 'correct-horse',
        ]);

    $response->assertSessionHasErrors('email');
    $errors = session('errors')->get('email');
    expect($errors[0])->toContain('Çox cəhd');
});

it('successful login clears both throttle layers', function () {
    // 4 yanlış cəhd (5-in altında).
    for ($i = 0; $i < 4; $i++) {
        $this->post('/login', [
            'email'    => 'victim@example.com',
            'password' => 'wrong',
        ]);
    }

    // Düzgün login uğurlu olur.
    $this->post('/login', [
        'email'    => 'victim@example.com',
        'password' => 'correct-horse',
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue();
    expect(RateLimiter::attempts('login_email:victim@example.com'))->toBe(0);
});
