<?php

use App\Http\Controllers\Api\CheckoutSesiController;
use App\Http\Controllers\Api\CrmLayananController;
use App\Http\Controllers\Api\StatusKatalogController;
use App\Http\Middleware\KlienCheckout;
use Illuminate\Support\Facades\Route;

// Landing (velocitydatacenter.com) membaca status stok paket untuk tombol "Stok habis".
// Tanpa throttle: cloudflared meneruskan semua pengunjung dari 127.0.0.1 (trustProxies belum dipasang), jadi batas per IP
// akan berlaku untuk semua orang sekaligus. Beban ditahan cache 60 detik di controller.
Route::get('katalog/status', StatusKatalogController::class)->name('api.katalog.status');

// VDC Pay: web klien (Bearer API key dari Admin › Pembayaran) membuat sesi bayar & mengecek status. Batas per klien, bukan per IP.
Route::middleware([KlienCheckout::class, 'throttle:checkout'])->prefix('checkout/sessions')->group(function () {
    Route::post('/', [CheckoutSesiController::class, 'store'])->name('api.checkout.store');
    Route::get('{id}', [CheckoutSesiController::class, 'show'])->name('api.checkout.show');
});

// CRM new-vdnet: layanan Velocity Cloud milik customer (per email). Kunci CRM_MASUK_KEY, hanya jalur lokal.
Route::get('crm/layanan', CrmLayananController::class)->middleware('throttle:120,1')->name('api.crm.layanan');
