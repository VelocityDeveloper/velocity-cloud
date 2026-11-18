<?php

use App\Http\Controllers\Api\StatusKatalogController;
use Illuminate\Support\Facades\Route;

// Landing (velocitydatacenter.com) membaca status stok paket untuk tombol "Stok habis".
Route::get('katalog/status', StatusKatalogController::class)->middleware('throttle:120,1')->name('api.katalog.status');
