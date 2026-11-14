<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie penanda (bukan sesi, tanpa data) di domain induk agar landing statis
 * velocitydeveloper.ai.id bisa menampilkan tombol "Dashboard" alih-alih "Masuk".
 */
class TandaMasuk
{
    public const NAMA = 'vc_masuk';

    public const DOMAIN = '.velocitydeveloper.ai.id';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $masuk = $request->user() !== null;
        $ada = $request->cookies->has(self::NAMA);

        if ($masuk && ! $ada) {
            $response->headers->setCookie(Cookie::make(self::NAMA, '1', 60 * 24 * 30, '/', self::DOMAIN, true, false, false, 'lax'));
        } elseif (! $masuk && $ada) {
            $response->headers->setCookie(Cookie::forget(self::NAMA, '/', self::DOMAIN));
        }

        return $response;
    }
}
