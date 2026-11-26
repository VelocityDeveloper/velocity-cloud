<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analitik\Laporan;
use App\Services\Seo\LaporanSeo;
use App\Services\Seo\KeywordPlanner;
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
            // GSC menyimpan data ±16 bulan
            'dari' => ['nullable', 'required_with:sampai', 'date_format:Y-m-d', 'after_or_equal:'.now()->subMonths(16)->toDateString()],
            'sampai' => ['nullable', 'required_with:dari', 'date_format:Y-m-d', 'after_or_equal:dari', 'before_or_equal:today'],
        ]);
        $periode = $filter['periode'] ?? '28h';
        $seo = $laporan->semua($periode, (bool) ($filter['segarkan'] ?? false), $filter['dari'] ?? null, $filter['sampai'] ?? null);
        if ($seo['status'] === 'ok') {
            // Pengunjung landing (analitik sendiri) per hari, tanggal sama dengan tren GSC, untuk grafik gabungan
            [$dari, $sampai] = [CarbonImmutable::parse($seo['dari']), CarbonImmutable::parse($seo['sampai'])->addDay()];
            $seo['pengunjung'] = array_column((new Laporan)->tren($dari, $sampai, false), 'pengguna');
        }

        return Inertia::render('admin/Seo', [
            'periode' => isset($filter['dari']) ? 'kustom' : $periode,
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
    /** Admin › SEO › Keyword Planner: ide kata kunci + volume Google Ads; kata yang sudah ada di Keyword Mapping ditandai. */
    public function keyword(Request $request, KeywordPlanner $kp, PetaKataKunci $peta): Response
    {
        $v = $request->validate([
            'kata' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:500'],
            'lokasi' => ['nullable', 'in:'.implode(',', array_keys(KeywordPlanner::LOKASI))],
            'bahasa' => ['nullable', 'in:'.implode(',', array_keys(KeywordPlanner::BAHASA))],
        ]);
        $kata = collect(preg_split('/[\n,]+/', (string) ($v['kata'] ?? '')))->map(fn ($k) => mb_strtolower(trim($k)))->filter()->unique()->take(20)->values()->all();
        $hasil = null;
        $galat = null;
        if ($kata || filled($v['url'] ?? null)) {
            try {
                $dipetakan = collect($peta->riset())->map(fn ($r) => PetaKataKunci::normal($r['kata']))->flip();
                $hasil = collect($kp->ide($kata, $v['url'] ?? null, $v['lokasi'] ?? '2360', $v['bahasa'] ?? '1025'))
                    ->map(fn ($r) => [...$r, 'dipetakan' => $dipetakan->has(PetaKataKunci::normal($r['kata']))])->all();
            } catch (\RuntimeException $e) {
                report($e);
                $galat = $e->getMessage();
            }
        }

        return Inertia::render('admin/SeoKeyword', [
            'isian' => ['kata' => implode(', ', $kata), 'url' => $v['url'] ?? '', 'lokasi' => $v['lokasi'] ?? '2360', 'bahasa' => $v['bahasa'] ?? '1025'],
            'pilihanLokasi' => KeywordPlanner::LOKASI,
            'pilihanBahasa' => KeywordPlanner::BAHASA,
            // URL starter = domain properti GSC (sc-domain:velocitydatacenter.com) + path halaman
            'starter' => collect(KeywordPlanner::STARTER)->map(fn ($s) => [
                'nama' => $s['nama'], 'kata' => implode(', ', $s['kata']),
                'url' => 'https://'.str_replace('sc-domain:', '', config('cloud.gsc.situs')).$s['path'],
            ])->values(),
            'siap' => $kp->siap(),
            'hasil' => $hasil,
            'galat' => $galat,
        ]);
    }

    public function negatif(Request $request, PetaKataKunci $peta): RedirectResponse
    {
        $v = $request->validate(['kata' => ['required', 'string', 'max:100'], 'hapus' => ['nullable', 'boolean']]);
        $peta->ubahNegatif($v['kata'], ! $request->boolean('hapus'));

        return back();
    }
}
