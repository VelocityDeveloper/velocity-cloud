<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API /api/crm/*: Bearer CRM_MASUK_KEY dan hanya jalur lokal (CRM di PC yang sama); lewat Cloudflare = 404 walau kunci benar. */
class KunciCrm
{
    public function handle(Request $request, Closure $next): Response
    {
        $kunci = (string) config('cloud.crm.masuk_key');
        abort_if($request->hasHeader('CF-Connecting-IP') || $kunci === '' || ! hash_equals($kunci, (string) $request->bearerToken()), 404);

        return $next($request);
    }
}
