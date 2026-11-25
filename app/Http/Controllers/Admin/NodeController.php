<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\Metrik\Riwayat;
use App\Services\Provisioning\DirectAdmin;
use App\Services\Provisioning\Incus;
use App\Services\Provisioning\NodeAgent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Node', [
            'node' => Node::withCount(['services' => fn ($q) => $q->whereIn('status', [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan])])
                ->orderBy('nama')->get(['id', 'nama', 'jenis', 'agent_url', 'vps_host', 'da_user', 'kapasitas_pod', 'ram_pod_mb', 'aktif']),
            'jenis' => Node::JENIS,
        ]);
    }

    /** Detail mesin node: sampel per menit dari metrik:ambil (baru node pod). Rentang grafik lewat partial reload. */
    public function show(Request $request, Node $node): Response
    {
        return Inertia::render('admin/NodeShow', [
            'node' => [...$node->only('id', 'nama', 'jenis', 'agent_url', 'kapasitas_pod', 'ram_pod_mb', 'aktif'),
                'jenis_nama' => Node::JENIS[$node->jenis] ?? $node->jenis,
                'layanan' => $node->services()->whereIn('status', [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan])->count()],
            'terkini' => $node->metrik()->latest('waktu')->first(['waktu', 'cpu', 'inti', 'ram', 'ram_total', 'disk', 'disk_total', 'load', 'uptime']),
            'riwayat' => fn () => Riwayat::titik($node->metrik()->getQuery(), (string) $request->query('rentang', '24j'),
                ['cpu', 'ram', 'ram_total', 'disk', 'disk_total', 'load'], ['rx', 'tx']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Node::create($this->valid($request, true));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Node ditambahkan.']);

        return back();
    }

    public function update(Request $request, Node $node): RedirectResponse
    {
        $data = $this->valid($request, false);
        if (blank($data['token'] ?? null)) {
            unset($data['token']); // kosong = token lama tetap
        }
        $node->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Node diperbarui.']);

        return back();
    }

    /** Uji kredensial node: pod = token agent diterima, DirectAdmin = login key bisa membaca daftar paket. */
    public function uji(Node $node): RedirectResponse
    {
        try {
            if ($node->jenis === 'incus') {
                $info = (new Incus($node))->info();
                throw_unless(($info['auth'] ?? '') === 'trusted', \RuntimeException::class, 'sertifikat app belum dipercaya node (incus config trust add-certificate).');
                $pesan = 'Terhubung ke Incus '.($info['environment']['server_version'] ?? '').', project '.config('cloud.vps.project').'.';
            } elseif ($node->jenis === 'directadmin') {
                $paket = (new DirectAdmin($node))->paket();
                $pesan = 'Terhubung ke DirectAdmin, '.count($paket).' paket tersedia.';
            } else {
                try {
                    (new NodeAgent($node))->status('svc-0');
                } catch (RequestException $e) {
                    if ($e->response->status() !== 404) { // 404 = token diterima, pod uji memang tidak ada
                        throw $e;
                    }
                }
                $pesan = 'Terhubung ke agent.';
            }
            Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);
        } catch (\Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Gagal: '.mb_substr($e->getMessage(), 0, 200)]);
        }

        return back();
    }

    private function valid(Request $request, bool $baru): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:60'],
            'jenis' => ['required', Rule::in(array_keys(Node::JENIS))],
            'da_user' => ['nullable', 'required_if:jenis,directadmin', 'regex:/^[a-z][a-z0-9_]{1,59}$/'],
            'agent_url' => ['required', 'url:http,https', 'max:255'],
            // incus: host SSH/port tambahan VPS di node ini (node ber-IP publik); kosong = relay global VPS_RELAY_HOST.
            'vps_host' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i'],
            // incus: sertifikat server PEM dari skrip pasang-node.sh (dipakai memverifikasi node, bukan rahasia).
            'token' => [$baru ? 'required' : 'nullable', 'string', 'min:32', $request->input('jenis') === 'incus' ? 'max:5000' : 'max:255', ...($request->input('jenis') === 'incus' ? ['starts_with:-----BEGIN CERTIFICATE-----'] : [])],
            'kapasitas_pod' => ['required', 'integer', 'min:1', 'max:1000'],
            'ram_pod_mb' => ['nullable', 'integer', 'min:256', 'max:1048576'], // khusus node pod
            'aktif' => ['required', 'boolean'],
        ]);
    }
}
