<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analitik\Laporan;
use App\Services\Seo\LaporanSeo;
use App\Services\Seo\PetaKataKunci;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeoController extends Controller
{
    public function index(Request $request, LaporanSeo $laporan): Response
    {
        $filter = $request->validate([
            'periode' => ['nullable', 'in:'.implode(',', array_keys(LaporanSeo::PERIODE))],
            'segarkan' => ['nullable', 'boolean'],
        ]);
        $periode = $filter['periode'] ?? '28h';
        $seo = $laporan->semua($periode, (bool) ($filter['segarkan'] ?? false));
        if ($seo['status'] === 'ok') {
            // Pengunjung landing (analitik sendiri) per hari, tanggal sama dengan tren GSC, untuk grafik gabungan
            [$dari, $sampai] = [CarbonImmutable::parse($seo['dari']), CarbonImmutable::parse($seo['sampai'])->addDay()];
            $seo['pengunjung'] = array_column((new Laporan)->tren($dari, $sampai, false), 'pengguna');
        }

        return Inertia::render('admin/Seo', [
            'periode' => $periode,
            'pilihanPeriode' => LaporanSeo::PERIODE,
            'situs' => config('cloud.gsc.situs'),
            'laporan' => $seo,
        ]);
    }

    public function peta(Request $request, PetaKataKunci $peta): Response
    {
        return Inertia::render('admin/SeoPeta', [
            'situs' => config('cloud.gsc.situs'),
            'peta' => $peta->semua($request->boolean('segarkan')),
        ]);
    }

    /** Tambah/hapus kata kunci negatif: kata kunci yang memuat semua katanya hilang dari peta & tidak dipakai Kang SEO. */
    public function negatif(Request $request, PetaKataKunci $peta): RedirectResponse
    {
        $v = $request->validate(['kata' => ['required', 'string', 'max:100'], 'hapus' => ['nullable', 'boolean']]);
        $peta->ubahNegatif($v['kata'], ! $request->boolean('hapus'));

        return back();
    }
}
