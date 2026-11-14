<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Pembayaran\Midtrans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MidtransController extends Controller
{
    /** Tombol "Bayar otomatis": arahkan pelanggan ke halaman bayar Snap. */
    public function bayar(Request $request, Invoice $invoice, Midtrans $midtrans): Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);
        abort_unless(Midtrans::aktif() && $invoice->status === InvoiceStatus::BelumDibayar, 409, 'Tagihan ini tidak bisa dibayar online.');

        try {
            return Inertia::location($midtrans->urlBayar($invoice->load('user', 'service')));
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Pembayaran online sedang bermasalah. Coba lagi sebentar, atau bayar manual.']);

            return back();
        }
    }

    /** Notifikasi HTTP Midtrans (tanpa login/CSRF): tanda tangan dicek, status diambil ulang dari API (Midtrans::proses). */
    public function notifikasi(Request $request, Midtrans $midtrans): JsonResponse
    {
        abort_unless(Midtrans::tandaTanganSah($request->all()), 403);
        $midtrans->proses($request->input('order_id'));

        return response()->json(['ok' => true]);
    }
}
