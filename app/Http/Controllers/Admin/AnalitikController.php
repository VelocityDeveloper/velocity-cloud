<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analitik\Laporan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalitikController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->validate([
            'periode' => ['nullable', 'in:'.implode(',', array_keys(Laporan::PERIODE))],
            'internal' => ['nullable', 'boolean'],
        ]);
        $periode = $filter['periode'] ?? '7h';
        $internal = (bool) ($filter['internal'] ?? false);

        return Inertia::render('admin/Analitik', [
            'filter' => ['periode' => $periode, 'internal' => $internal],
            'pilihanPeriode' => Laporan::PERIODE,
            'laporan' => (new Laporan(tanpaInternal: ! $internal))->semua($periode),
        ]);
    }
}
