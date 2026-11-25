<?php

namespace App\Http\Controllers;

use App\Enums\CheckoutStatus;
use App\Models\CheckoutSesi;
use App\Services\Pembayaran\Checkout;
use App\Services\Pembayaran\Midtrans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/** Halaman bayar VDC Pay (tanpa login): pembeli di web klien diarahkan ke sini, lalu ke Snap Midtrans. */
class CheckoutController extends Controller
{
    public function show(CheckoutSesi $sesi, Checkout $checkout): Response
    {
        $this->sinkron($sesi, $checkout);

        return Inertia::render('checkout/Show', [
            'sesi' => [
                'id' => $sesi->uuid,
                'klien' => $sesi->klien->nama,
                'deskripsi' => $sesi->deskripsi,
                'jumlah' => $sesi->jumlah,
                'email' => $sesi->email,
                'status' => $sesi->status->value,
                'status_label' => $sesi->status->label(),
                'berlaku_sampai' => $sesi->berlaku_sampai->toIso8601String(),
                'url_sukses' => $sesi->status === CheckoutStatus::Lunas ? $sesi->url_sukses : null,
                'url_batal' => $sesi->url_batal,
            ],
            'bisa_bayar' => $this->bisaBayar($sesi),
        ]);
    }

    /** Tombol "Bayar sekarang": buat/ambil transaksi Snap lalu arahkan ke halaman Midtrans. */
    public function bayar(CheckoutSesi $sesi, Checkout $checkout): HttpResponse
    {
        abort_unless($this->bisaBayar($sesi), 409, 'Sesi pembayaran ini sudah tidak bisa dibayar.');

        try {
            return Inertia::location($checkout->urlBayar($sesi->load('klien')));
        } catch (Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Pembayaran online sedang bermasalah. Coba lagi sebentar.']);

            return back();
        }
    }

    /** Kembali dari Snap (callbacks.finish): sinkron status, lunas → kembali ke web klien, selain itu tampilkan status. */
    public function selesai(Request $request, CheckoutSesi $sesi, Checkout $checkout): RedirectResponse
    {
        $this->sinkron($sesi, $checkout);

        return $sesi->status === CheckoutStatus::Lunas
            ? redirect()->away($this->tambahId($sesi->url_sukses, $sesi->uuid))
            : redirect()->route('checkout.show', $sesi);
    }

    private function bisaBayar(CheckoutSesi $sesi): bool
    {
        return $sesi->klien->aktif && Midtrans::aktif() && $sesi->status->terbuka() && ! $sesi->lewatBatas();
    }

    /** Cadangan notifikasi Midtrans yang tidak sampai. */
    private function sinkron(CheckoutSesi $sesi, Checkout $checkout): void
    {
        if ($sesi->status === CheckoutStatus::Menunggu && isset($sesi->midtrans['order']) && Midtrans::kunci() !== '') {
            try {
                $checkout->proses($sesi->midtrans['order']);
                $sesi->refresh();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function tambahId(string $url, string $id): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'checkout_id='.urlencode($id);
    }
}
