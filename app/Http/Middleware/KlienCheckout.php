<?php

namespace App\Http\Middleware;

use App\Models\CheckoutKlien;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** API VDC Pay: Authorization: Bearer vdcpay_… → klien aktif disimpan di atribut request "klien". */
class KlienCheckout
{
    public const BATAS_SALAH = 20; // per menit

    public function handle(Request $request, Closure $next): Response
    {
        // Kunci salah dibatasi per IP asli (lewat cloudflared semua trafik dari 127.0.0.1) sebelum menyentuh DB.
        $salah = 'checkout-401:'.($request->header('CF-Connecting-IP') ?? $request->ip());
        if (RateLimiter::tooManyAttempts($salah, self::BATAS_SALAH)) {
            return response()->json(['galat' => 'Terlalu banyak percobaan API key salah.'], 429);
        }
        $klien = CheckoutKlien::dariKunci((string) $request->bearerToken());
        if (! $klien) {
            RateLimiter::hit($salah, 60);

            return response()->json(['galat' => 'API key tidak valid.'], 401);
        }
        $request->attributes->set('klien', $klien);

        return $next($request);
    }
}
