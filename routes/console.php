<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tagihan:proses')->dailyAt('07:00');
Schedule::command('analitik:impor')->everyMinute()->withoutOverlapping();
// Pengingat jatuh tempo email (25 Nov): hanya berjalan setelah PENGINGAT_EMAIL_AKTIF=true; pemberitahuan dashboard tidak butuh jadwal
Schedule::command('pengingat:email')->dailyAt(config('pengingat.email.jam'))->when(fn () => config('pengingat.email.aktif'));
