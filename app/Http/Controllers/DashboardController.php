<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Presenters\Tampil;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('customer/Dashboard', [
            'layanan' => $user->services()->with('plan')->whereNot('status', ServiceStatus::Dibatalkan)->latest()->get()->map(Tampil::layanan(...)),
            'tagihan' => $user->invoices()->with('service')->where('status', InvoiceStatus::BelumDibayar)->orderBy('jatuh_tempo')->get()->map(Tampil::tagihan(...)),
            'saldo' => $user->saldo,
            // pemakaian token AI per layanan: persen = terpakai / (sisa + terpakai)
            'token_ai' => $user->services()->with('tokenAi')->whereHas('tokenAi')->where('status', ServiceStatus::Aktif)->get()
                ->map(function ($s) {
                    $sisa = max(0, $s->tokenAi->saldo);
                    $kuota = $sisa + $s->tokenAi->terpakai;

                    return ['id' => $s->id, 'label' => $s->label, 'terpakai' => $s->tokenAi->terpakai, 'kuota' => $kuota,
                        'persen' => $kuota > 0 ? round($s->tokenAi->terpakai / $kuota * 100, 1) : 0];
                }),
        ]);
    }
}
