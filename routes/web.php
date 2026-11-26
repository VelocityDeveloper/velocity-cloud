<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Internal\AgenDiskusiController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\KatalogController;
use App\Http\Controllers\LayananController;
use App\Http\Controllers\MidtransController;
use App\Http\Controllers\TagihanController;
use App\Http\Controllers\TiketController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Server bot lokal mencatat masalah grup & status fix (token bersama, tanpa sesi/CSRF).
Route::post('internal/agen/diskusi', AgenDiskusiController::class)
    ->withoutMiddleware([PreventRequestForgery::class])
    ->middleware('throttle:60,1')->name('internal.agen.diskusi');

Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
    Route::get('customer/dashboard', DashboardController::class)->name('customer.dashboard');
    Route::inertia('panduan', 'panduan/Index')->name('panduan');
    Route::post('notifikasi/baca-semua', [NotifikasiController::class, 'bacaSemua'])->name('notifikasi.baca-semua');
    Route::post('notifikasi/{id}/baca', [NotifikasiController::class, 'baca'])->whereUuid('id')->name('notifikasi.baca');

    Route::get('katalog/{kategori?}', [KatalogController::class, 'index'])->whereIn('kategori', array_keys(KatalogController::KATEGORI_URL))->name('katalog.index');
    Route::get('katalog/{plan:slug}', [KatalogController::class, 'show'])->name('katalog.show');
    Route::post('katalog/{plan:slug}', [KatalogController::class, 'store'])->middleware('throttle:10,1')->name('katalog.store');

    Route::get('domain', [DomainController::class, 'index'])->name('domain.index');
    Route::get('domain/cek', [DomainController::class, 'cek'])->middleware('throttle:30,1')->name('domain.cek');
    Route::post('domain', [DomainController::class, 'store'])->middleware('throttle:10,1')->name('domain.store');

    Route::get('layanan', [LayananController::class, 'index'])->name('layanan.index');
    Route::get('layanan/{service}', [LayananController::class, 'show'])->name('layanan.show');
    Route::post('layanan/{service}/topup', [LayananController::class, 'topup'])->middleware('throttle:10,1')->name('layanan.topup');
    Route::put('layanan/{service}/domain', [LayananController::class, 'domain'])->middleware('throttle:10,1')->name('layanan.domain');
    Route::post('layanan/{service}/domain/periksa', [LayananController::class, 'periksaDomain'])->middleware('throttle:10,1')->name('layanan.domain.periksa');
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
    Route::post('tagihan/{invoice}/midtrans', [MidtransController::class, 'bayar'])->middleware('throttle:10,1')->name('tagihan.midtrans');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('tagihan', [Admin\TagihanController::class, 'index'])->name('tagihan.index');
        Route::get('tiket', [Admin\TiketController::class, 'index'])->name('tiket.index');
        Route::get('email', [Admin\EmailController::class, 'index'])->name('email.index');
        Route::post('tagihan/{invoice}/lunas', [Admin\TagihanController::class, 'lunas'])->name('tagihan.lunas');
        Route::get('layanan', [Admin\LayananController::class, 'index'])->name('layanan.index');
        Route::put('layanan/{service}', [Admin\LayananController::class, 'update'])->name('layanan.update');
        Route::post('layanan/{service}/ulangi', [Admin\LayananController::class, 'ulangi'])->name('layanan.ulangi');
        Route::redirect('paket', '/admin/paket/vps');
        Route::get('paket/domain', [Admin\PaketDomainController::class, 'index'])->name('paket-domain.index'); // sebelum paket/{kategori}
        Route::put('paket/domain', [Admin\PaketDomainController::class, 'update'])->name('paket-domain.update');
        Route::get('paket/{kategori}', [Admin\PaketController::class, 'index'])->name('paket.index');
        Route::post('paket/ai', [Admin\PaketController::class, 'storeAi'])->name('paket.store-ai');
        Route::put('paket/{plan}', [Admin\PaketController::class, 'update'])->name('paket.update');
        Route::delete('paket/{plan}', [Admin\PaketController::class, 'destroy'])->name('paket.destroy');
        Route::put('paket/{plan}/sumber', [Admin\PaketController::class, 'sumber'])->name('paket.sumber');
        Route::post('paket/{plan}/uji-sumber', [Admin\PaketController::class, 'ujiSumber'])->middleware('throttle:10,1')->name('paket.uji-sumber');
        Route::get('setting-vps', [Admin\SettingVpsController::class, 'index'])->name('setting-vps.index');
        Route::put('setting-vps', [Admin\SettingVpsController::class, 'update'])->name('setting-vps.update');
        Route::get('node', [Admin\NodeController::class, 'index'])->name('node.index');
        Route::post('node', [Admin\NodeController::class, 'store'])->name('node.store');
        Route::get('node/{node}', [Admin\NodeController::class, 'show'])->name('node.show');
        Route::put('node/{node}', [Admin\NodeController::class, 'update'])->name('node.update');
        Route::post('node/{node}/uji', [Admin\NodeController::class, 'uji'])->name('node.uji');
        Route::get('pengguna', [Admin\PenggunaController::class, 'index'])->name('pengguna.index');
        Route::get('pengguna/{user}', [Admin\PenggunaController::class, 'show'])->name('pengguna.show');
        Route::get('pembayaran', [Admin\PembayaranController::class, 'index'])->name('pembayaran.index');
        Route::post('pembayaran', [Admin\PembayaranController::class, 'store'])->name('pembayaran.store');
        Route::post('pembayaran/midtrans', [Admin\PembayaranController::class, 'midtrans'])->name('pembayaran.midtrans');
        Route::post('pembayaran/{metode}', [Admin\PembayaranController::class, 'update'])->name('pembayaran.update');
        Route::delete('pembayaran/{metode}', [Admin\PembayaranController::class, 'destroy'])->name('pembayaran.destroy');
        Route::post('checkout-klien', [Admin\CheckoutKlienController::class, 'store'])->name('checkout-klien.store');
        Route::put('checkout-klien/{klien}', [Admin\CheckoutKlienController::class, 'update'])->name('checkout-klien.update');
        Route::post('checkout-klien/{klien}/kunci', [Admin\CheckoutKlienController::class, 'kunci'])->name('checkout-klien.kunci');
        Route::post('checkout-webhook/{webhook}/ulang', [Admin\CheckoutKlienController::class, 'kirimUlang'])->name('checkout-webhook.ulang');
        Route::get('notifikasi', [Admin\NotifikasiController::class, 'index'])->name('notifikasi.index');
        Route::post('notifikasi', [Admin\NotifikasiController::class, 'store'])->middleware('throttle:10,1')->name('notifikasi.store');
        Route::delete('notifikasi/{kiriman}', [Admin\NotifikasiController::class, 'destroy'])->whereUuid('kiriman')->name('notifikasi.destroy');
        Route::get('promo', [Admin\PromoController::class, 'index'])->name('promo.index');
        Route::post('promo', [Admin\PromoController::class, 'store'])->name('promo.store');
        Route::put('promo/{promo}', [Admin\PromoController::class, 'update'])->name('promo.update');
        Route::delete('promo/{promo}', [Admin\PromoController::class, 'destroy'])->name('promo.destroy');
        Route::get('analitik', [Admin\AnalitikController::class, 'index'])->name('analitik.index');
        Route::get('analitik/stream', [Admin\AnalitikController::class, 'stream'])->name('analitik.stream');
        Route::get('seo', [Admin\SeoController::class, 'index'])->name('seo.index');
        Route::get('keyword-mapping', [Admin\SeoController::class, 'peta'])->name('seo.peta');
        Route::get('keyword-planner', [Admin\SeoController::class, 'keyword'])->middleware('throttle:30,1')->name('seo.keyword');
        Route::post('keyword-mapping/negatif', [Admin\SeoController::class, 'negatif'])->name('seo.negatif');
        Route::get('agen', [Admin\AgenController::class, 'index'])->name('agen.index');
        Route::post('agen/arahan', [Admin\AgenController::class, 'simpanArahan'])->name('agen.arahan');
        Route::put('agen/target', [Admin\AgenController::class, 'simpanTarget'])->name('agen.target');
        Route::post('agen/{catatan}/putuskan', [Admin\AgenController::class, 'putuskan'])->name('agen.putuskan');
        Route::post('agen/{catatan}/selesai', [Admin\AgenController::class, 'selesai'])->name('agen.selesai');
        Route::post('agen/pelajaran/{pelajaran}/status', [Admin\AgenController::class, 'ubahPelajaran'])->name('agen.pelajaran.status');
    });
});

// Relay custom domain (VPS Sumopod) menarik daftar domain app yang DNS-nya sudah menunjuk ke relay; token bearer.
Route::get('relay/domain', [LayananController::class, 'relayDaftar'])->middleware('throttle:60,1')->name('relay.domain');
Route::get('relay/domain-vps', [LayananController::class, 'relayVps'])->middleware('throttle:60,1')->name('relay.domain-vps');

// Lampiran chat tanpa login: diunduh WP-CLI/curl di layanan pelanggan lewat URL bertanda tangan (relatif, app di belakang tunnel)
Route::get('chat-lampiran/{uuid}/{berkas}', [ChatController::class, 'lampiran'])->whereUuid('uuid')
    ->where('berkas', '[A-Za-z0-9]{40}\\.[a-z0-9]{2,4}')->middleware(['signed:relative', 'throttle:120,1'])->name('chat.lampiran');

// Notifikasi pembayaran Midtrans (Payment Notification URL di dashboard Midtrans); keaslian dicek tanda tangan + API status
Route::post('midtrans/notifikasi', [MidtransController::class, 'notifikasi'])->middleware('throttle:120,1')
    ->withoutMiddleware(PreventRequestForgery::class)->name('midtrans.notifikasi');

// VDC Pay: halaman bayar publik untuk pembeli dari web klien (id sesi acak cs_…, tanpa login).
// Batas per sesi + IP asli (throttle:N,1 biasa = per IP = 127.0.0.1 cloudflared, berlaku untuk semua pembeli sekaligus).
Route::middleware('throttle:checkout-halaman')->group(function () {
    Route::get('bayar/{sesi}', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('bayar/{sesi}', [CheckoutController::class, 'bayar'])->name('checkout.bayar');
    Route::get('bayar/{sesi}/selesai', [CheckoutController::class, 'selesai'])->name('checkout.selesai');
});

require __DIR__.'/settings.php';
