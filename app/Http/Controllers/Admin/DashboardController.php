<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Tampil;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Bisnis\Ringkasan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** /dashboard khusus admin; pelanggan dialihkan ke /customer/dashboard. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->is_admin) {
            return to_route('customer.dashboard', $request->query());
        }

        $ringkasan = Ringkasan::data();
        $ringkasan['layanan_per_status'] = collect(ServiceStatus::cases())
            ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'n' => (int) ($ringkasan['layanan_per_status'][$s->value] ?? 0)]);

        return Inertia::render('admin/Dashboard', [
            'ringkasan' => $ringkasan,
            'perlu_tindakan' => Service::with('plan', 'user')
                ->whereIn('status', [ServiceStatus::Disiapkan, ServiceStatus::Gagal])
                ->oldest()->limit(20)->get()
                ->map(fn (Service $s) => [...Tampil::layanan($s), 'pelanggan' => $s->user->name]),
            'tagihan' => Invoice::with('service', 'user')->where('status', InvoiceStatus::BelumDibayar)
                ->orderBy('jatuh_tempo')->limit(10)->get()
                ->map(fn (Invoice $i) => [...Tampil::tagihan($i), 'pelanggan' => $i->user->name]),
        ]);
    }
}
