<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien REST API Incus di node VPS. Autentikasi sertifikat klien (config cloud.vps.client_*, dipercaya node
 * terbatas ke project velocity); server diverifikasi dengan sertifikatnya sendiri yang disimpan di nodes.token.
 */
class Incus
{
    public function __construct(private Node $node) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->node->agent_url, '/'))
            ->withOptions(['verify' => $this->sertifikatNode(), 'cert' => config('cloud.vps.client_cert'), 'ssl_key' => config('cloud.vps.client_key')])
            ->withQueryParameters(['project' => config('cloud.vps.project')])
            ->acceptJson()->timeout(30)->throw();
    }

    private function sertifikatNode(): string
    {
        $f = storage_path('app/private/incus/node-'.$this->node->id.'.crt');
        if (! is_file($f) || file_get_contents($f) !== $this->node->token) {
            is_dir(dirname($f)) || mkdir(dirname($f), 0700, true);
            file_put_contents($f, $this->node->token, LOCK_EX);
        }

        return $f;
    }

    /** GET /1.0: auth harus "trusted". */
    public function info(): array
    {
        return $this->http()->get('/1.0')->json('metadata');
    }

    public function ada(string $nama): bool
    {
        try {
            $this->http()->get('/1.0/instances/'.rawurlencode($nama));

            return true;
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return false;
            }
            throw $e;
        }
    }

    public function buat(array $instance): void
    {
        $this->tunggu($this->http()->post('/1.0/instances', $instance), 840);
    }

    public function state(string $nama): array
    {
        return $this->http()->get('/1.0/instances/'.rawurlencode($nama).'/state')->json('metadata');
    }

    /** start | stop | restart. Stop/restart sopan dulu (ACPI), lalu paksa bila VM tidak merespons. */
    public function aksi(string $nama, string $aksi): void
    {
        $kirim = fn (bool $paksa) => $this->tunggu($this->http()->put('/1.0/instances/'.rawurlencode($nama).'/state', [
            'action' => $aksi, 'timeout' => 30, 'force' => $paksa,
        ]), 90);
        try {
            $kirim(false);
        } catch (RuntimeException $e) {
            if ($aksi === 'start') {
                throw $e;
            }
            $kirim(true);
        }
    }

    /** Bentuk sama dengan status pod agent agar panel Kontrol di dashboard dipakai bersama. */
    public function ringkas(string $nama): array
    {
        $s = $this->state($nama);
        $mem = $s['memory'] ?? [];
        $gib = fn ($b) => round($b / 1073741824, 1).' GiB';

        return [
            'status' => $s['status'] ?? '-', // Running | Stopped | ...
            'pemakaian' => ($s['status'] ?? '') === 'Running' && ($mem['usage'] ?? 0) > 0
                ? [['container' => 'vps', 'cpu' => '-', 'memori' => $gib($mem['usage']).' / '.$gib($mem['total'] ?? 0)]]
                : [],
        ];
    }

    /** Perintah sh sebagai root di VM (Chat AI; butuh incus-agent, ada di image cloud). → { kode, stdout, stderr } */
    public function exec(string $nama, string $perintah, int $detik = 60): array
    {
        $op = $this->http()->post('/1.0/instances/'.rawurlencode($nama).'/exec', [
            'command' => ['timeout', (string) $detik, 'sh', '-c', $perintah],
            'environment' => ['HOME' => '/root', 'LANG' => 'C.UTF-8', 'DEBIAN_FRONTEND' => 'noninteractive', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'],
            'record-output' => true, 'wait-for-websocket' => false, 'interactive' => false,
        ])->json('operation');
        $m = $this->http()->timeout($detik + 30)->get($op.'/wait', ['timeout' => $detik + 15])->json('metadata');
        if (($m['status'] ?? '') !== 'Success') {
            throw new RuntimeException('Incus: '.($m['err'] ?: ($m['status'] ?? 'exec gagal')));
        }
        $baca = function (string $k) use ($m) {
            $path = $m['metadata']['output'][$k] ?? null;
            if (! $path) {
                return '';
            }
            $isi = $this->http()->get($path)->body();
            rescue(fn () => $this->http()->delete($path), report: false); // log exec tak menumpuk di instance

            return $isi;
        };

        return ['kode' => $m['metadata']['return'] ?? null, 'stdout' => $baca('1'), 'stderr' => $baca('2')];
    }

    private function tunggu(Response $r, int $detik): void
    {
        $op = $r->json('operation');
        if (! $op) {
            return;
        }
        $m = $this->http()->timeout($detik + 10)->get($op.'/wait', ['timeout' => $detik])->json('metadata');
        if (($m['status'] ?? '') !== 'Success') {
            throw new RuntimeException('Incus: '.($m['err'] ?: ($m['status'] ?? 'operasi gagal')));
        }
    }
}
