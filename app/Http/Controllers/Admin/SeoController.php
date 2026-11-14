<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Seo\LaporanSeo;
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

        return Inertia::render('admin/Seo', [
            'periode' => $periode,
            'pilihanPeriode' => LaporanSeo::PERIODE,
            'situs' => config('cloud.gsc.situs'),
            'laporan' => $laporan->semua($periode, (bool) ($filter['segarkan'] ?? false)),
        ]);
    }
}
