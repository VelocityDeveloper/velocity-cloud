<?php

use App\Services\Pembayaran\Checkout;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// VDC Pay: sesi bayar yang lewat batas ditutup (webhook checkout.kedaluwarsa), status Midtrans dicek bila notifikasi hilang
Artisan::command('checkout:tutup', function (Checkout $checkout) {
    $this->info($checkout->tutupKedaluwarsa().' sesi ditutup.');
    $this->info($checkout->sapuEventTertinggal().' event antri diperiksa ulang.');
})->purpose('Tutup sesi VDC Pay yang kedaluwarsa & kirim ulang event yang tertinggal');
Schedule::command('checkout:tutup')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('tagihan:proses')->dailyAt('07:00');
Schedule::command('domain:landing')->dailyAt('06:30'); // harga domain CRM ikut kurs
Schedule::command('metrik:ambil')->everyMinute()->withoutOverlapping();
Schedule::command('tiket:ambil-email')->everyMinute()->withoutOverlapping();
Schedule::command('analitik:impor')->everyMinute()->withoutOverlapping();
// Pengingat jatuh tempo email (25 Nov): hanya berjalan setelah PENGINGAT_EMAIL_AKTIF=true; pemberitahuan dashboard tidak butuh jadwal
Schedule::command('pengingat:email')->dailyAt(config('pengingat.email.jam'))->when(fn () => config('pengingat.email.aktif'));
