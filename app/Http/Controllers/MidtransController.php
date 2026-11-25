<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Pembayaran\Checkout;
use App\Services\Pembayaran\Midtrans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
    public function notifikasi(Request $request, Midtrans $midtrans, Checkout $checkout): JsonResponse
    {
        $order = (string) $request->input('order_id');
        // shortcut: notifikasi ditandatangani server key DEFAULT yang tidak tersimpan (app pakai kunci "vdc"),
        // jadi tanda tangan tak cocok tetap diproses: status selalu diambil dari API Midtrans, isi notifikasi tak dipercaya.
        // Kunci DEFAULT tersimpan → kembalikan abort_unless(tandaTanganSah, 403).
        if (! Midtrans::tandaTanganSah($request->all()) && ! Checkout::milik($order)
            && ! Invoice::where('nomor', Str::beforeLast($order, '-'))->exists()) {
            return response()->json(['ok' => true]); // order bukan milik kita (mis. notifikasi uji dashboard): abaikan tanpa memanggil API
        }
        // Satu Notification URL per akun Midtrans: order VDC Pay (CS-…) dipisah dari tagihan VDC (INV-…)
        Checkout::milik($order) ? $checkout->proses($order) : $midtrans->proses($order);

        return response()->json(['ok' => true]);
    }
}
