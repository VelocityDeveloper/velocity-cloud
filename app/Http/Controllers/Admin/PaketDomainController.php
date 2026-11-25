<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\DomainLanding;
use App\Http\Controllers\Controller;
use App\Models\Pengaturan;
use App\Services\Crm\DomainCrm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Admin › Paket › Domain: TLD dari Harga Domain CRM yang dinyalakan untuk dijual di Pesan Domain (CRM sendiri tidak berubah). */
class PaketDomainController extends Controller
{
    public function index(DomainCrm $crm): Response
    {
        $aktif = Pengaturan::tldDomain();
        try {
            $tld = collect($crm->daftarTld())->map(fn ($t) => [...$t, 'aktif' => in_array($t['tld'], $aktif, true)]);
            $galat = null;
        } catch (RuntimeException $e) {
            report($e);
            $tld = collect();
            $galat = 'Daftar TLD dari CRM gagal dimuat: '.$e->getMessage();
        }

        return Inertia::render('admin/PaketDomain', [
            'tld' => $tld->values(),
            // aktif di sini tapi sudah tidak dijual di CRM: tetap tertolak saat pesan (harga CRM 404)
            'tidak_di_crm' => array_values(array_diff($aktif, $tld->pluck('tld')->all())),
            'galat' => $galat,
        ]);
    }

    /** Simpan TLD yang dijual. Domain yang sudah dibeli tidak terpengaruh. */
    public function update(Request $request, DomainCrm $crm): RedirectResponse
    {
        $data = $request->validate(['tld' => ['present', 'array'], 'tld.*' => ['string', 'max:63', 'regex:/^\.[a-z0-9.-]+$/']]);
        Pengaturan::simpan('domain.tld_aktif', array_values(array_unique($data['tld'])));
        try {
            DomainLanding::tulis($crm->daftarTld()); // tabel harga di velocitydatacenter.com/domain/ ikut
        } catch (RuntimeException $e) {
            report($e); // pilihan tetap tersimpan; landing menyusul lewat jadwal harian
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => count($data['tld']).' ekstensi domain dijual.']);

        return back();
    }
}
