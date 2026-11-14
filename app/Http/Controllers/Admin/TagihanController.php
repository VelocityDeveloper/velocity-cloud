<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Tampil;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagihanController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', InvoiceStatus::BelumDibayar->value);

        return Inertia::render('admin/Tagihan', [
            'status' => $status,
            'tagihan' => Invoice::with('service', 'user')
                ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
                ->latest()->limit(200)->get()
                ->map(fn (Invoice $i) => [...Tampil::tagihan($i), 'pelanggan' => $i->user->name, 'email' => $i->user->email]),
        ]);
    }

    public function lunas(Request $request, Invoice $invoice, TandaiLunas $tandai): RedirectResponse
    {
        $data = $request->validate([
            'metode' => ['required', Rule::in(['transfer', 'qris', 'tunai', 'lainnya'])],
            'referensi' => ['nullable', 'string', 'max:100'],
        ]);
        $tandai->handle($invoice, $data['metode'], $data['referensi'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $invoice->nomor.' ditandai lunas.']);

        return back();
    }
}
