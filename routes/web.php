<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Internal\AgenDiskusiController;
use App\Http\Controllers\KatalogController;
use App\Http\Controllers\LayananController;
use App\Http\Controllers\TagihanController;
use App\Http\Controllers\TiketController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Server bot lokal mencatat masalah grup & status fix (token bersama, tanpa sesi/CSRF).
Route::post('internal/agen/diskusi', AgenDiskusiController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])
    ->middleware('throttle:60,1')->name('internal.agen.diskusi');

Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
    Route::get('customer/dashboard', DashboardController::class)->name('customer.dashboard');

    Route::get('katalog/{kategori?}', [KatalogController::class, 'index'])->whereIn('kategori', array_keys(KatalogController::KATEGORI_URL))->name('katalog.index');
    Route::get('katalog/{plan:slug}', [KatalogController::class, 'show'])->name('katalog.show');
    Route::post('katalog/{plan:slug}', [KatalogController::class, 'store'])->middleware('throttle:10,1')->name('katalog.store');

    Route::get('layanan', [LayananController::class, 'index'])->name('layanan.index');
    Route::get('layanan/{service}', [LayananController::class, 'show'])->name('layanan.show');
    Route::post('layanan/{service}/topup', [LayananController::class, 'topup'])->middleware('throttle:10,1')->name('layanan.topup');
    Route::post('layanan/{service}/aksi', [LayananController::class, 'aksi'])->middleware('throttle:20,1')->name('layanan.aksi');
    Route::post('layanan/{service}/perangkat/{permintaan}', [LayananController::class, 'setujuiPerangkat'])->middleware('throttle:20,1')->name('layanan.perangkat');

    Route::get('chat/{uuid?}', [ChatController::class, 'index'])->whereUuid('uuid')->name('chat');
    Route::post('chat/alat', [ChatController::class, 'alat'])->middleware('throttle:30,1');
    Route::post('chat/{uuid}/kirim', [ChatController::class, 'kirim'])->whereUuid('uuid')->middleware('throttle:30,1');
    Route::get('chat/{uuid}/status', [ChatController::class, 'status'])->whereUuid('uuid');
    Route::post('chat/{uuid}/izin', [ChatController::class, 'izin'])->whereUuid('uuid')->middleware('throttle:60,1');
    Route::post('chat/{uuid}/otomatis', [ChatController::class, 'otomatis'])->whereUuid('uuid')->middleware('throttle:60,1');
    Route::post('chat/{uuid}/berhenti', [ChatController::class, 'berhenti'])->whereUuid('uuid')->middleware('throttle:60,1');
    Route::delete('chat/{uuid}', [ChatController::class, 'hapus'])->whereUuid('uuid');

    Route::get('tiket', [TiketController::class, 'index'])->name('tiket.index');
    Route::post('tiket', [TiketController::class, 'store'])->middleware('throttle:10,1')->name('tiket.store');
    Route::get('tiket/{tiket}', [TiketController::class, 'show'])->name('tiket.show');
    Route::post('tiket/{tiket}/balas', [TiketController::class, 'balas'])->middleware('throttle:30,1')->name('tiket.balas');
    Route::post('tiket/{tiket}/tutup', [TiketController::class, 'tutup'])->name('tiket.tutup');
    Route::get('tiket/{tiket}/lampiran/{lampiran}', [TiketController::class, 'lampiran'])->name('tiket.lampiran');

    Route::get('tagihan', [TagihanController::class, 'index'])->name('tagihan.index');
    Route::get('tagihan/{invoice}', [TagihanController::class, 'show'])->name('tagihan.show');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('tagihan', [Admin\TagihanController::class, 'index'])->name('tagihan.index');
        Route::get('tiket', [Admin\TiketController::class, 'index'])->name('tiket.index');
        Route::post('tagihan/{invoice}/lunas', [Admin\TagihanController::class, 'lunas'])->name('tagihan.lunas');
        Route::get('layanan', [Admin\LayananController::class, 'index'])->name('layanan.index');
        Route::put('layanan/{service}', [Admin\LayananController::class, 'update'])->name('layanan.update');
        Route::post('layanan/{service}/ulangi', [Admin\LayananController::class, 'ulangi'])->name('layanan.ulangi');
        Route::redirect('paket', '/admin/paket/vps');
        Route::get('paket/{kategori}', [Admin\PaketController::class, 'index'])->name('paket.index');
        Route::post('paket/ai', [Admin\PaketController::class, 'storeAi'])->name('paket.store-ai');
        Route::put('paket/{plan}', [Admin\PaketController::class, 'update'])->name('paket.update');
        Route::put('paket/{plan}/sumber', [Admin\PaketController::class, 'sumber'])->name('paket.sumber');
        Route::post('paket/{plan}/uji-sumber', [Admin\PaketController::class, 'ujiSumber'])->middleware('throttle:10,1')->name('paket.uji-sumber');
        Route::get('node', [Admin\NodeController::class, 'index'])->name('node.index');
        Route::post('node', [Admin\NodeController::class, 'store'])->name('node.store');
        Route::put('node/{node}', [Admin\NodeController::class, 'update'])->name('node.update');
        Route::post('node/{node}/uji', [Admin\NodeController::class, 'uji'])->name('node.uji');
        Route::get('pengguna', [Admin\PenggunaController::class, 'index'])->name('pengguna.index');
        Route::get('pembayaran', [Admin\PembayaranController::class, 'index'])->name('pembayaran.index');
        Route::post('pembayaran', [Admin\PembayaranController::class, 'store'])->name('pembayaran.store');
        Route::post('pembayaran/{metode}', [Admin\PembayaranController::class, 'update'])->name('pembayaran.update');
        Route::delete('pembayaran/{metode}', [Admin\PembayaranController::class, 'destroy'])->name('pembayaran.destroy');
        Route::get('analitik', [Admin\AnalitikController::class, 'index'])->name('analitik.index');
        Route::get('seo', [Admin\SeoController::class, 'index'])->name('seo.index');
        Route::get('agen', [Admin\AgenController::class, 'index'])->name('agen.index');
        Route::post('agen/{catatan}/putuskan', [Admin\AgenController::class, 'putuskan'])->name('agen.putuskan');
    });
});

require __DIR__.'/settings.php';
