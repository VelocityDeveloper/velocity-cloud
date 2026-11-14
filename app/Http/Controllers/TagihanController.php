<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Http\Presenters\Tampil;
use App\Models\Invoice;
use App\Models\MetodeBayar;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagihanController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('tagihan/Index', [
            'tagihan' => $request->user()->invoices()->with('service')->latest()->get()->map(Tampil::tagihan(...)),
            'saldo' => $request->user()->saldo,
        ]);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);

        return Inertia::render('tagihan/Show', [
            'tagihan' => Tampil::tagihan($invoice->load('service')),
            'layanan_id' => $invoice->service_id,
            'metode_bayar' => $invoice->status === InvoiceStatus::BelumDibayar ? MetodeBayar::aktif()->get()->map->tampil() : [],
        ]);
    }
}
