<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Loyalty scheduled commands — qeydiyyatdan keçib və işlədir olmalıdır.
|--------------------------------------------------------------------------
*/

it('registers both loyalty commands in the artisan registry', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('loyalty:expire-buckets');
    expect($commands)->toContain('loyalty:settlement-reconcile');
});

it('runs loyalty:expire-buckets without errors as a no-op skeleton', function () {
    $exitCode = Artisan::call('loyalty:expire-buckets', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Expire-buckets');
});

it('runs loyalty:settlement-reconcile without errors as a no-op skeleton', function () {
    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'yesterday']);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Settlement reconcile');
});

it('schedules both loyalty commands at daily cadence', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $commands = collect($schedule->events())
        ->map(fn ($e) => $e->command ?? '')
        ->implode(' | ');

    expect($commands)->toContain('loyalty:expire-buckets');
    expect($commands)->toContain('loyalty:settlement-reconcile');
});
