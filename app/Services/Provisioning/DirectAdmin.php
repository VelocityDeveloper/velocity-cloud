<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        return $this->baca($this->http(15)->get('/CMD_API_PACKAGES_USER'))['list'] ?? [];
    }

    /** Konfigurasi user DA (ip, ns1, ns2, ...); tanpa argumen = pemilik key. */
    public function config(?string $user = null): array
    {
        return $this->baca($this->http(15)->get('/CMD_API_SHOW_USER_CONFIG', ['user' => $user ?? $this->node->da_user]));
    }

    public function userAda(string $user): bool
    {
        return ($this->baca($this->http(15)->get('/CMD_API_USER_EXISTS', ['user' => $user]))['exists'] ?? '0') === '1';
    }

    /** Buat akun user di IP bersama pemilik key, tanpa email notifikasi DA. */
    public function buatAkun(string $user, string $sandi, string $email, string $domain, string $paket, string $ip): void
    {
        $this->baca($this->http(90)->asForm()->post('/CMD_API_ACCOUNT_USER', [
            'action' => 'create', 'add' => 'Submit', 'username' => $user, 'email' => $email,
            'passwd' => $sandi, 'passwd2' => $sandi, 'domain' => $domain, 'package' => $paket, 'ip' => $ip, 'notify' => 'no',
        ]));
    }

    /** Suspend/unsuspend akun. dosuspend/dounsuspend idempoten (suspend=… adalah toggle); hasil dicek dari user config. */
    public function suspend(string $user, bool $suspend): void
    {
        $this->baca($this->http(90)->asForm()->post('/CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS', $suspend ? 'dosuspend' : 'dounsuspend' => 'yes', 'select0' => $user,
        ]));
        if (($this->config($user)['suspended'] ?? '') !== ($suspend ? 'yes' : 'no')) {
            throw new \RuntimeException("Status suspend akun DirectAdmin {$user} tidak berubah.");
        }
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim($this->node->agent_url, '/'))
            ->withBasicAuth($this->node->da_user, $this->node->token)
            ->timeout($timeout);
    }

    /** Balasan urlencoded; error≠0 atau bukan text/plain (halaman login = key salah) → exception berisi pesan DA. */
    private function baca(Response $r): array
    {
        $r->throw();
        parse_str($r->body(), $data);
        if (($data['error'] ?? '0') !== '0' || ! str_contains((string) $r->header('Content-Type'), 'text/plain')) {
            $pesan = trim(($data['text'] ?? '').' '.strip_tags($data['details'] ?? ''));
            throw new \RuntimeException($pesan !== '' ? $pesan : 'Balasan DirectAdmin tidak dikenal (login key salah?)');
        }

        return $data;
    }
}
