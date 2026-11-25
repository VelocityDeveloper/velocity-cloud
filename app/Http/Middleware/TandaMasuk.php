<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie penanda (bukan sesi, tanpa data) di domain induk agar landing statis
 * (velocitydatacenter.com) bisa menampilkan tombol "Dashboard" alih-alih "Masuk".
 * Domain induk diambil dari host permintaan (app.X -> .X), jadi ikut saat domain dashboard pindah (18 Nov 2026).
 */
class TandaMasuk
{
    public const NAMA = 'vc_masuk';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $masuk = $request->user() !== null;
        $ada = $request->cookies->has(self::NAMA);
        $domain = '.'.Str::after($request->getHost(), '.');

        if ($masuk && ! $ada) {
            $response->headers->setCookie(Cookie::make(self::NAMA, '1', 60 * 24 * 30, '/', $domain, true, false, false, 'lax'));
        } elseif (! $masuk && $ada) {
            $response->headers->setCookie(Cookie::forget(self::NAMA, '/', $domain));
        }

        return $response;
    }
}
