<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dijalankan oleh proses `schedule:work` di dalam container (lihat
// docker/supervisord.conf). Sebelum proses itu ada, tidak satu pun dari
// ketiganya pernah benar-benar dieksekusi di produksi.
//
// withoutOverlapping: payments:expire menyapu ribuan baris dan bisa lewat
// dari satu jam kalau backlog-nya besar. Tanpa kunci ini, jalannya yang
// kedua akan menabrak yang pertama.
Schedule::command('orders:auto-complete')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('payments:expire')->hourly()->withoutOverlapping();
Schedule::command('shipping:poll')->hourly()->withoutOverlapping();

// Token kedaluwarsa tidak hilang sendiri dari tabelnya. Tanpa ini,
// personal_access_tokens terus tumbuh meski tokennya sudah tidak berlaku.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
