<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaksaHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        // Hanya jika Cloudflare menyatakan http; tanpa header (akses lokal/tes) tidak dialihkan, jadi tidak bisa loop.
        if ($request->header('X-Forwarded-Proto') === 'http') {
            return redirect()->to('https://'.$request->getHost().$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
