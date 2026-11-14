<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use Illuminate\Support\Facades\Http;

/**
 * Klien API legacy DirectAdmin (CMD_API_*) untuk node jenis directadmin, login key lewat Basic Auth.
 * Login key sanke "velocity-cloud" hanya boleh perintah akun user & hanya dari IP kantor.
 */
class DirectAdmin
{
    public function __construct(private Node $node) {}

    /** Daftar nama paket user milik pemilik key; gagal = exception berisi pesan DA. */
    public function paket(): array
    {
        $r = Http::baseUrl(rtrim($this->node->agent_url, '/'))
            ->withBasicAuth($this->node->da_user, $this->node->token)
            ->timeout(15)
            ->get('/CMD_API_PACKAGES_USER')
            ->throw();
        parse_str($r->body(), $data);
        if (($data['error'] ?? '0') !== '0' || ! str_contains($r->header('Content-Type'), 'text/plain')) {
            throw new \RuntimeException($data['text'] ?? $data['details'] ?? 'Balasan DirectAdmin tidak dikenal (login key salah?)');
        }

        return $data['list'] ?? [];
    }
}
