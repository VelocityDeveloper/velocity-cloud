<?php

use App\Http\Controllers\Api\StatusKatalogController;
use Illuminate\Support\Facades\Route;

// Landing (velocitydatacenter.com) membaca status stok paket untuk tombol "Stok habis".
// Tanpa throttle: cloudflared meneruskan semua pengunjung dari 127.0.0.1 (trustProxies belum dipasang), jadi batas per IP
// akan berlaku untuk semua orang sekaligus. Beban ditahan cache 60 detik di controller.
Route::get('katalog/status', StatusKatalogController::class)->name('api.katalog.status');
