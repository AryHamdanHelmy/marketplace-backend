<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('orders:auto-complete')->dailyAt('02:00');
schedule::command('payments:expire')->hourly();
Schedule::command('shipping:poll')->hourly();

// Token kedaluwarsa tidak hilang sendiri dari tabelnya. Tanpa ini,
// personal_access_tokens terus tumbuh meski tokennya sudah tidak berlaku.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
