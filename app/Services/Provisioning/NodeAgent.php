<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke agent node (Bun + Elysia) yang menjalankan pod Podman.
 * Kontrak API agent:
 *   POST   /pods                  buat & jalankan pod   → { name, status, host_port }
 *   GET    /pods/{name}           status pod            → { name, status }
 *   POST   /pods/{name}/{aksi}    start | stop | restart
 *   GET    /pods/{name}/log       log terakhir tiap container
 *   DELETE /pods/{name}           hapus pod + volume
 *   GET    /pods/{name}/perangkat        daftar pairing OpenClaw   → { pending[], paired[] }
 *   POST   /pods/{name}/perangkat/{id}   setujui permintaan pairing
 *   POST   /pods/{name}/wp           WP-CLI di container web { args[] } → { ok, out, err }
 *   POST   /pods/{name}/berkas       tulis berkas di wp-content { path, isi, tambah } → { path, ukuran }
 * Implementasi: /opt/velocity-pod-agent/agent.ts (PC lokal, Podman rootless user velocity-pod).
 */
class NodeAgent
{
    public const AKSI = ['start', 'stop', 'restart'];

    public function __construct(private Node $node) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->node->agent_url, '/'))
            ->withToken($this->node->token)
            ->acceptJson()
            ->timeout(120)
            ->throw();
    }

    /** Bisa lama: agent menarik image dulu (n8n ±500 MB). */
    public function buat(array $spec): array
    {
        return $this->http()->timeout(900)->post('/pods', $spec)->json();
    }

    /** { name, status, containers[{name,state}], pemakaian[{container,cpu,memori}] } */
    public function status(string $nama): array
    {
        return $this->http()->timeout(10)->get('/pods/'.rawurlencode($nama))->json();
    }

    /** { name, log: {container: teks} } */
    public function log(string $nama, int $baris = 200): array
    {
        return $this->http()->timeout(20)->get('/pods/'.rawurlencode($nama).'/log', ['baris' => $baris])->json();
    }

    public function aksi(string $nama, string $aksi): void
    {
        abort_unless(in_array($aksi, self::AKSI, true), 422);
        $this->http()->post('/pods/'.rawurlencode($nama).'/'.$aksi);
    }

    /** Pairing browser Control UI OpenClaw (lewat CLI di container, bisa ±10 dtk). */
    public function perangkat(string $nama): array
    {
        return $this->http()->timeout(100)->get('/pods/'.rawurlencode($nama).'/perangkat')->json();
    }

    public function setujuiPerangkat(string $nama, string $id): array
    {
        return $this->http()->timeout(100)->post('/pods/'.rawurlencode($nama).'/perangkat/'.rawurlencode($id))->json();
    }

    /** WP-CLI di container web pod WordPress (Chat AI) → { ok, out, err }. */
    public function wp(string $nama, array $args): array
    {
        return $this->http()->timeout(130)->post('/pods/'.rawurlencode($nama).'/wp', ['args' => array_values($args)])->json();
    }

    /** Tulis berkas di wp-content pod WordPress (Chat AI: tema FSE & isi halaman). */
    public function berkas(string $nama, string $path, string $isi, bool $tambah = false): array
    {
        return $this->http()->timeout(60)->post('/pods/'.rawurlencode($nama).'/berkas', compact('path', 'isi', 'tambah'))->json();
    }

    public function hapus(string $nama): void
    {
        $this->http()->delete('/pods/'.rawurlencode($nama));
    }
}
