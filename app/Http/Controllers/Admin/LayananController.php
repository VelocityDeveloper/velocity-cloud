<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Tampil;
use App\Jobs\ProvisionPod;
use App\Jobs\ProvisionVps;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LayananController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'semua');

        return Inertia::render('admin/Layanan', [
            'status' => $status,
            'daftar_status' => collect(ServiceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'layanan' => Service::with('plan', 'user', 'node')
                ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
                ->latest()->limit(200)->get()
                ->map(fn (Service $s) => [
                    ...Tampil::layanan($s),
                    'pelanggan' => $s->user->name,
                    'email' => $s->user->email,
                    'node' => $s->node?->nama,
                    'akses' => $s->detail['akses'] ?? null,
                    'custom_domain' => $s->custom_domain, // domain akun hosting
                    'pesan_gagal' => $s->pesan_gagal,
                ]),
        ]);
    }

    /** VPS/managed (dan hosting bila otomatis gagal) disiapkan manual: admin mengisi info akses lalu mengaktifkan. */
    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ServiceStatus::class)],
            'akses' => ['nullable', 'string', 'max:2000'],
        ]);
        $lama = $service->status;
        $service->update([
            'status' => $data['status'],
            'detail' => [...($service->detail ?? []), 'akses' => $data['akses'] ?? null],
        ]);
        // Tangguhkan/aktifkan manual ikut menghentikan/menyalakan resource, sama seperti tagihan otomatis.
        $aksi = match ([$lama, $service->status]) {
            [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan] => 'stop',
            [ServiceStatus::Ditangguhkan, ServiceStatus::Aktif] => 'start',
            default => null,
        };
        if ($aksi && $service->ikutDitangguhkan()) {
            \App\Jobs\PodAction::dispatch($service, $aksi);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Layanan diperbarui.']);

        return back();
    }

    public function ulangi(Service $service): RedirectResponse
    {
        abort_unless($service->status === ServiceStatus::Gagal && ($service->isApp() || $service->isHosting() || $service->isDomain() || $service->plan->vpsOtomatis()), 422);
        $service->update(['status' => ServiceStatus::Disiapkan, 'pesan_gagal' => null]);
        match (true) {
            $service->isApp() => ProvisionPod::dispatch($service),
            $service->isHosting() => \App\Jobs\ProvisionHosting::dispatch($service),
            $service->isDomain() => \App\Jobs\KirimDomainCrm::dispatch($service), // CRM idempoten: order sama, registrar dicoba ulang
            default => ProvisionVps::dispatch($service),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provisioning diulang.']);

        return back();
    }
}
