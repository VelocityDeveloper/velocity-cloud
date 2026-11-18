<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Status stok paket untuk landing (dibaca JS di browser): {slug: {habis, bisa_dipesan}}.
 * Publik & tanpa sesi; di-cache 60 detik karena tiap tampilan landing memanggilnya.
 */
class StatusKatalogController extends Controller
{
    public const CACHE_DETIK = 60;

    public function __invoke(): JsonResponse
    {
        $data = Cache::remember('katalog-status', self::CACHE_DETIK, fn () => Plan::query()->orderBy('slug')->get()
            ->mapWithKeys(fn (Plan $p) => [$p->slug => [
                'habis' => $p->sisaStok() === 0,
                'bisa_dipesan' => $p->bisaDipesan(),
            ]])->all());

        return response()->json((object) $data)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age='.self::CACHE_DETIK);
    }
}
