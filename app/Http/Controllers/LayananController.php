<?php

namespace App\Http\Controllers;

use App\Actions\Order\BuatPesanan;
use App\Enums\ServiceStatus;
use App\Http\Presenters\Tampil;
use App\Jobs\PodAction;
use App\Models\Service;
use App\Services\Provisioning\Incus;
use App\Services\Provisioning\NodeAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LayananController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('layanan/Index', [
            'layanan' => $request->user()->services()->with('plan')->latest()->get()->map(Tampil::layanan(...)),
        ]);
    }

    public function show(Request $request, Service $service): Response
    {
        $this->milik($request, $service);

        $pod = fn (callable $f) => function () use ($service, $f) {
            if (! $service->bisaKontrol()) {
                return null;
            }
            try {
                return $f($service->node->jenis === 'incus' ? new Incus($service->node) : new NodeAgent($service->node));
            } catch (Throwable $e) {
                report($e);

                return ['galat' => 'Status tidak bisa dibaca saat ini. Coba lagi sebentar.'];
            }
        };

        return Inertia::render('layanan/Show', [
            'layanan' => Tampil::layanan($service->load('plan'), lengkap: true),
            'tagihan' => $service->invoices()->with('service')->latest()->get()->map(Tampil::tagihan(...)),
            // Dimuat setelah halaman tampil (agent bisa lambat); log hanya saat diminta.
            'pod' => Inertia::defer($pod(fn (NodeAgent|Incus $a) => $a instanceof Incus ? $a->ringkas($service->podName()) : $a->status($service->podName()))),
            // Log & pairing hanya untuk pod app.
            'log' => Inertia::optional($pod(fn ($a) => $a instanceof Incus ? [] : $a->log($service->podName(), 200)['log'] ?? [])),
            'perangkat' => Inertia::optional($pod(fn ($a) => $a instanceof Incus ? null : self::ringkasPerangkat($a->perangkat($service->podName())))),
            'token' => $service->isAi() ? self::token($service) : null,
        ]);
    }

    /** Beli token tambahan untuk layanan token AI yang sudah aktif. */
    public function topup(Request $request, Service $service, BuatPesanan $buat): RedirectResponse
    {
        $this->milik($request, $service);
        abort_unless($service->isAi() && $service->status === ServiceStatus::Aktif, 422, 'Top-up hanya untuk layanan token AI yang aktif.');
        abort_unless($service->plan->bisaDipesan(), 422, 'Paket ini sedang tidak dijual.');
        $juta = (int) $request->validate(['token_juta' => ['required', 'integer', 'min:1', 'max:'.config('cloud.token_ai.beli_maks_juta')]])['token_juta'];

        $invoice = $buat->tagihanToken($service, $juta);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tagihan top-up dibuat. Saldo bertambah setelah lunas.']);

        return to_route('tagihan.show', $invoice);
    }

    private static function token(Service $service): array
    {
        $t = $service->tokenAi;

        return [
            'endpoint' => config('cloud.token_ai.endpoint'),
            'model' => $service->plan->spek['model'] ?? null,
            'saldo' => $t?->saldo ?? 0,
            'terpakai' => $t?->terpakai ?? 0,
            'pemakaian' => $t ? $t->pemakaian()->where('status', 200)->latest('id')->limit(20)->get(['model', 'prompt', 'completion', 'total', 'created_at'])
                ->map(fn ($p) => [...$p->only('model', 'prompt', 'completion', 'total'), 'created_at' => $p->created_at->format('Y-m-d H:i')]) : [],
        ];
    }

    public function aksi(Request $request, Service $service): RedirectResponse
    {
        $this->milik($request, $service);
        $aksi = $request->validate(['aksi' => ['required', Rule::in(NodeAgent::AKSI)]])['aksi'];
        abort_unless($service->bisaKontrol() && $service->status === ServiceStatus::Aktif, 422, 'Aksi hanya untuk layanan yang aktif.');

        PodAction::dispatch($service, $aksi);
        Inertia::flash('toast', ['type' => 'success', 'message' => ['start' => 'App dinyalakan.', 'stop' => 'App dimatikan.', 'restart' => 'App dimulai ulang.'][$aksi].' Status diperbarui dalam beberapa detik.']);

        return back();
    }

    /** Setujui browser yang meminta pairing ke Control UI OpenClaw (hanya pemegang token gateway yang bisa membuat permintaan). */
    public function setujuiPerangkat(Request $request, Service $service, string $permintaan): RedirectResponse
    {
        $this->milik($request, $service);
        abort_unless($service->isApp() && $service->status === ServiceStatus::Aktif && $service->node && ($service->plan->pod()['pairing'] ?? false), 422, 'Pairing hanya untuk app yang aktif.');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,100}$/', $permintaan) === 1, 422);

        try {
            (new NodeAgent($service->node))->setujuiPerangkat($service->podName(), $permintaan);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Perangkat disetujui. Halaman Control UI akan tersambung sendiri.']);
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Gagal menyetujui perangkat. Muat ulang daftar lalu coba lagi.']);
        }

        return back();
    }

    /** Ambil field yang perlu ditampilkan saja dari `openclaw devices list --json`. */
    private static function ringkasPerangkat(array $data): array
    {
        $ringkas = fn (array $d) => [
            'id' => $d['requestId'] ?? $d['deviceId'] ?? null,
            'nama' => 'Browser'.(isset($d['platform']) ? ' · '.$d['platform'] : ''),
            'ip' => $d['remoteIp'] ?? null,
            'waktu' => isset($d['ts']) ? date('c', intdiv((int) $d['ts'], 1000)) : null,
        ];
        return [
            'menunggu' => array_map($ringkas, $data['pending'] ?? []),
            'disetujui' => array_map($ringkas, $data['paired'] ?? []),
        ];
    }

    private function milik(Request $request, Service $service): void
    {
        abort_unless($service->user_id === $request->user()->id, 404);
    }
}
