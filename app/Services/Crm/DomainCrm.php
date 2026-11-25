<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Klien API publik CRM new-vdnet untuk domain: harga + ketersediaan, dan pembelian yang sudah lunas. */
class DomainCrm
{
    /**
     * Harga total per periode + ketersediaan dari registrar. null = ekstensi tidak dijual.
     *
     * @return array{domain: string, tld: string, tersedia: ?bool, pesan: ?string, tercatat: bool, harga: array}|null
     */
    public function harga(string $domain): ?array
    {
        $res = $this->http(30)->get('domain-harga', ['domain' => $domain]);
        if ($res->status() === 404) {
            return null;
        }

        return $this->json($res);
    }

    /** Semua TLD yang dijual di Harga Domain CRM: [{tld, registrar, harga: [{tahun, register, transfer}]}]. */
    public function daftarTld(): array
    {
        return $this->json($this->http(30)->get('domain-tld'));
    }

    /** Harga total satu periode; null = jenis/periode tidak dijual. */
    public static function hargaPeriode(array $info, string $jenis, int $tahun): ?int
    {
        $baris = collect($info['harga'])->firstWhere('tahun', $tahun);
        $nilai = $baris[$jenis === 'Transfer' ? 'transfer' : 'register'] ?? null;

        return $nilai === null ? null : (int) ceil($nilai);
    }

    /**
     * Kirim pembelian lunas; CRM membuat order + invoice lunas lalu mendaftarkan ke registrar (bisa beberapa menit).
     * 4xx = data ditolak CRM (tidak berguna diulang otomatis) → RuntimeException dengan pesan CRM.
     */
    public function order(array $data): array
    {
        return $this->json($this->http(330)->post('domain-order', ['sumber' => config('cloud.crm.sumber'), ...$data]));
    }

    /** Data domain di CRM (id, status, expiry_date, next_due_date) milik customer CRM; null = tidak ada. */
    public function domainCustomer(int $customerId, string $domain): ?array
    {
        return collect($this->json($this->customer(30)->get("customers/{$customerId}/domains"))['data'] ?? [])->firstWhere('domain', $domain);
    }

    /** Nameserver terkini langsung dari registrar. */
    public function nameserver(int $customerId, int $domainId): array
    {
        return $this->json($this->customer(90)->get("customers/{$customerId}/domains/{$domainId}/nameservers"))['data']['nameservers'] ?? [];
    }

    /** @return array{ok: bool, message: string} */
    public function ubahNameserver(int $customerId, int $domainId, array $ns): array
    {
        return $this->json($this->customer(90)->put("customers/{$customerId}/domains/{$domainId}/nameservers", ['ns' => array_values($ns)]));
    }

    private function customer(int $timeout): PendingRequest
    {
        $key = config('cloud.crm.customer_key');
        if (blank($key)) {
            throw new RuntimeException('CRM_CUSTOMER_KEY belum diatur.');
        }

        return Http::baseUrl(rtrim(config('cloud.crm.customer_url'), '/'))->withHeaders(['vdnet-key' => $key])->acceptJson()->timeout($timeout)->connectTimeout(10);
    }

    private function http(int $timeout): PendingRequest
    {
        $key = config('cloud.crm.key');
        if (blank($key)) {
            throw new RuntimeException('CRM_API_KEY belum diatur.');
        }

        return Http::baseUrl(rtrim(config('cloud.crm.url'), '/'))->withToken($key)->acceptJson()->timeout($timeout)->connectTimeout(10);
    }

    private function json(Response $res): array
    {
        if ($res->failed()) {
            $pesan = $res->json('message') ?: 'HTTP '.$res->status();
            if ($errors = $res->json('errors')) {
                $pesan .= ': '.collect($errors)->flatten()->implode(' ');
            }
            throw new RuntimeException('CRM: '.mb_substr($pesan, 0, 500), $res->status());
        }

        return $res->json();
    }
}
