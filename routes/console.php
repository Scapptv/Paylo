<?php

use App\Modules\Admin\Console\Commands\ExpireBucketsCommand;
use App\Modules\Admin\Console\Commands\SettlementReconcileCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled loyalty operations
|--------------------------------------------------------------------------
| Bütün gündəlik, fon işləri burada qeydiyyatdan keçir. Hər command öz
| sinifində no-op skeleton olaraq başlayır — schedule-a qoşulub ki,
| prod infrastructure-i ilkin gündən sınansın.
*/

// Bucket expiration — hər gecə 03:00, server timezone.
Schedule::command(ExpireBucketsCommand::class)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Settlement reconciliation — hər gecə 02:00, dünənki günü reconcile edir.
Schedule::command(SettlementReconcileCommand::class, ['--for=yesterday'])
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
