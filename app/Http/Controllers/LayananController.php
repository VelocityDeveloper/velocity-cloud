<?php

namespace App\Http\Controllers;

use App\Actions\Order\BuatPesanan;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Http\Presenters\Tampil;
use App\Jobs\PodAction;
use App\Models\Service;
use App\Models\ServiceMetrik;
use App\Services\Provisioning\Incus;
use App\Services\Metrik\Riwayat;
use App\Services\Provisioning\NodeAgent;
use App\Services\TokenAi\LaporanToken;
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
            // Domain yang dibeli pelanggan di VDC, untuk pilihan cepat custom domain.
            'domain_saya' => $service->isAi() || $service->isDomain() ? [] : Service::where('user_id', $service->user_id)
                ->where('status', ServiceStatus::Aktif)->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::Domain))
                ->get()->pluck('detail.domain')->filter()->unique()->values(),
            // Grafik historis hanya untuk VPS Incus; rentang dipilih di halaman (partial reload).
            'riwayat' => Inertia::optional(fn () => self::riwayat($service, (string) $request->query('rentang', '1j'))),
        ]);
    }

    private static function riwayat(Service $service, string $rentang): array
    {
        return Riwayat::titik(ServiceMetrik::where('service_id', $service->id), $rentang, ['cpu', 'ram', 'ram_total', 'proses'], ['rx', 'tx']);
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
        $dari = now()->subDays(29)->startOfDay();

        return [
            'endpoint' => config('cloud.token_ai.endpoint'),
            'model' => $service->plan->spek['model'] ?? null,
            // Harga paket saat ini (top-up memakai ini, bukan harga layanan saat dipesan); harga = output, null = input/cache seharga output
            'harga' => $service->plan->harga,
            'harga_promo' => $service->plan->hargaPromo(), // harga top-up per 1 jt bila promo berlaku
            'harga_input' => $service->plan->spek['harga_input'] ?? null,
            'harga_cache' => $service->plan->spek['harga_cache'] ?? null,
            'saldo' => $t?->saldo ?? 0,
            'terpakai' => $t?->terpakai ?? 0,
            // Grafik harian 30 hari vs 30 hari sebelumnya, pola sama dengan analitik admin.
            'tren' => $t ? (new LaporanToken)->tren($dari, 30, $t->id) : [],
            'tren_lalu' => $t ? (new LaporanToken)->tren($dari->copy()->subDays(30), 30, $t->id) : [],
            // Biaya = potong saldo × harga output paket saat ini (saldo = token setara output).
            'pemakaian' => $t ? $t->pemakaian()->where('status', 200)->latest('id')->limit(20)->get(['model', 'prompt', 'cache_hit', 'completion', 'total', 'potong', 'created_at'])
                ->map(fn ($p) => [
                    ...$p->only('model', 'prompt', 'cache_hit', 'completion'),
                    'potong' => $potong = $p->potong ?? $p->total,
                    'biaya' => round($potong * ($service->plan->harga ?? 0) / 1_000_000, 2),
                    'created_at' => $p->created_at->format('Y-m-d H:i'),
                ]) : [],
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

    /** Pasang/lepas custom domain. App & VPS di belakang relay: menunggu DNS ke relay lalu diaktifkan lewat periksaDomain; lainnya hanya dicatat. */
    public function domain(Request $request, Service $service): RedirectResponse
    {
        $this->milik($request, $service);
        abort_if($service->isAi(), 422, 'Layanan token AI tidak memakai custom domain.');
        $domain = strtolower(trim((string) $request->input('domain')));
        $request->merge(['domain' => $domain]);

        if ($domain !== '') {
            $request->validate(['domain' => Service::aturanDomain($service->id)], Service::PESAN_DOMAIN);
        }

        if ($service->isApp() && $service->node && $service->status === ServiceStatus::Aktif) {
            try {
                (new NodeAgent($service->node))->domain($service->podName(), $domain === '' ? [] : [$domain]);
            } catch (Throwable $e) {
                report($e);
                Inertia::flash('toast', ['type' => 'error', 'message' => 'Gagal menyimpan domain. Coba lagi sebentar.']);

                return back();
            }
        }

        $service->update(['custom_domain' => $domain ?: null, 'custom_domain_aktif_at' => null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $domain === '' ? 'Custom domain dilepas.' : ($service->domainLewatRelay() ? 'Domain disimpan. Arahkan A record, lalu klik Periksa DNS.' : 'Domain dicatat. Tim kami akan menyambungkannya.')]);

        return back();
    }

    /** Cek A record domain sudah menunjuk ke relay; bila ya, relay akan menerbitkan SSL dalam ±1 menit. */
    public function periksaDomain(Request $request, Service $service): RedirectResponse
    {
        $this->milik($request, $service);
        abort_unless($service->domainLewatRelay() && $service->custom_domain, 422);

        $ip = array_column(@dns_get_record($service->custom_domain, DNS_A) ?: [], 'ip');
        $cocok = $ip === [config('cloud.relay_ip')];
        $service->update(['custom_domain_aktif_at' => $cocok ? ($service->custom_domain_aktif_at ?? now()) : null]);
        Inertia::flash('toast', $cocok
            ? ['type' => 'success', 'message' => 'DNS sudah benar. SSL diterbitkan otomatis dalam ±1–2 menit.']
            : ['type' => 'error', 'message' => 'A record belum menunjuk ke '.config('cloud.relay_ip').($ip ? ' (terbaca: '.implode(', ', $ip).')' : '').'. Perubahan DNS bisa butuh beberapa menit.']);

        return back();
    }

    /** Domain app aktif → relay mem-proxy ke router PC (vc-relay-sync). Hanya app: domain VPS punya daftar sendiri. */
    public function relayDaftar(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->tokenRelay($request);

        return response()->json(Service::whereNotNull('custom_domain_aktif_at')->whereNotNull('custom_domain')
            ->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::App))->pluck('custom_domain')->values());
    }

    /** Domain VPS/managed aktif → relay mem-proxy ke IP VM port 80 (vc-relay-vps-sync): [{domain, ip}]. */
    public function relayVps(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->tokenRelay($request);

        return response()->json(Service::with('plan')->where('status', ServiceStatus::Aktif)
            ->whereNotNull('custom_domain_aktif_at')->whereNotNull('custom_domain')
            ->whereHas('plan', fn ($q) => $q->whereIn('kategori', [PlanKategori::Vps, PlanKategori::Managed]))
            ->get()->filter->domainLewatRelay()
            ->map(fn (Service $s) => ['domain' => $s->custom_domain, 'ip' => $s->detail['ip']])->values());
    }

    private function tokenRelay(Request $request): void
    {
        $token = (string) config('cloud.relay_token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);
    }

    private function milik(Request $request, Service $service): void
    {
        abort_unless($service->user_id === $request->user()->id, 404);
    }
}
