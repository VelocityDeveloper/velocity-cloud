<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Klien Search Analytics GSC lewat service account (JWT RS256, tanpa paket Google). */
class SearchConsole
{
    private const CAKUPAN = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function terhubung(): bool
    {
        return is_readable(config('cloud.gsc.kredensial'));
    }

    /** @return list<array{keys: list<string>, clicks: float, impressions: float, ctr: float, position: float}> */
    public function kueri(string $dari, string $sampai, array $dimensi, int $batas = 1000): array
    {
        $situs = rawurlencode(config('cloud.gsc.situs'));

        return Http::withToken($this->token())->timeout(60)
            ->post("https://searchconsole.googleapis.com/webmasters/v3/sites/$situs/searchAnalytics/query", [
                'startDate' => $dari, 'endDate' => $sampai, 'dimensions' => $dimensi,
                'rowLimit' => $batas, 'dataState' => 'all', // termasuk data segar (belum final)
            ])->throw()->json('rows', []);
    }

    private function token(): string
    {
        return Cache::remember('gsc:token', 3000, function () {
            $kred = json_decode(file_get_contents(config('cloud.gsc.kredensial')), true, flags: JSON_THROW_ON_ERROR);
            $aud = $kred['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $t = time();
            $isi = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $kred['client_email'], 'scope' => self::CAKUPAN, 'aud' => $aud, 'iat' => $t, 'exp' => $t + 3600,
            ]));
            if (! openssl_sign($isi, $tanda, $kred['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Gagal menandatangani JWT GSC.');
            }

            return Http::asForm()->timeout(30)->post($aud, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $isi.'.'.$b64($tanda),
            ])->throw()->json('access_token');
        });
    }
}
