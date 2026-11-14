<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Str;

class PodSpec
{
    /**
     * Susun spesifikasi pod untuk agent dari config('cloud.pods'), mengganti {host}, {sandi}, {sandi_db}, {rahasia}, {email}.
     * Mengembalikan [spec, kredensial, siapkan] — kredensial disimpan terenkripsi di layanan; siapkan = permintaan HTTP
     * pertama ke app (buat owner/instal) yang dijalankan ProvisionPod, tidak dikirim ke agent.
     */
    public static function untuk(Service $service): array
    {
        $pod = $service->plan->pod() ?? throw new \RuntimeException('Spesifikasi pod tidak tersedia untuk '.$service->plan->slug);

        $nilai = [
            '{host}' => $service->subdomain.'.'.config('cloud.domain_app'),
            '{sandi}' => self::sandi(),
            '{sandi_db}' => Str::password(24, symbols: false),
            '{rahasia}' => base64_encode(random_bytes(32)), // tidak disimpan/ditampilkan; format = openssl rand -base64 32
            '{email}' => $service->user->email,
        ];
        $ganti = fn ($v) => is_string($v) ? strtr($v, $nilai) : $v;
        $gantiDalam = function ($v) use (&$gantiDalam, $ganti) {
            return is_array($v) ? array_map($gantiDalam, $v) : $ganti($v);
        };

        $spec = [
            'name' => $service->podName(),
            'host' => $nilai['{host}'],
            'port' => $pod['port'],
            'tcp' => $pod['tcp'] ?? false,
            'limit' => $pod['limit'],
            'containers' => array_map(fn ($c) => [
                'name' => $c['name'],
                'image' => $c['image'],
                'env' => array_map($ganti, $c['env']),
                'args' => array_map($ganti, $c['args'] ?? []),
                'volume' => $c['volume'] ?? null,
            ], $pod['containers']),
        ];

        $dipakai = json_encode($pod);

        return [$spec, array_filter([
            'pengguna' => isset($pod['pengguna']) ? $ganti($pod['pengguna']) : null,
            'sandi' => str_contains($dipakai, '{sandi}') ? $nilai['{sandi}'] : null,
            'sandi_db' => str_contains($dipakai, '{sandi_db}') ? $nilai['{sandi_db}'] : null,
        ]), isset($pod['siapkan']) ? $gantiDalam($pod['siapkan']) : null];
    }

    /** Sandi 24 karakter yang pasti memuat huruf besar, huruf kecil, dan angka (syarat sandi owner n8n). */
    public static function sandi(): string
    {
        do {
            $s = Str::password(24, symbols: false);
        } while (! preg_match('/[A-Z]/', $s) || ! preg_match('/[a-z]/', $s) || ! preg_match('/\d/', $s));

        return $s;
    }
}
