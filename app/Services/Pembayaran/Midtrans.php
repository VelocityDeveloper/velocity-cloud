<?php

namespace App\Services\Pembayaran;

use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Pengaturan;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Midtrans Snap (14 Nov): pelanggan diarahkan ke halaman bayar Midtrans (VA, QRIS, e-wallet, kartu),
 * notifikasi HTTP → MidtransController::notifikasi → TandaiLunas (provisioning sama dengan lunas manual).
 * Kunci & mode diatur di Admin › Pembayaran (tabel pengaturan, server key terenkripsi).
 */
class Midtrans
{
    public const JAM_BERLAKU = 23;

    public function __construct(private TandaiLunas $tandai) {}

    /** Tombol bayar tampil; notifikasi tetap diproses walau dimatikan (transaksi yang sudah dibuat bisa lunas belakangan). */
    public static function aktif(): bool
    {
        return Pengaturan::ambil('midtrans.aktif', false) && self::kunci() !== '';
    }

    public static function kunci(): string
    {
        $k = Pengaturan::ambil('midtrans.server_key');

        return $k ? Crypt::decryptString($k) : '';
    }

    public static function produksi(): bool
    {
        return (bool) Pengaturan::ambil('midtrans.produksi', false);
    }

    /** Kunci sandbox berawalan "SB-Mid-server-", produksi "Mid-server-": cegah salah pasang mode. */
    public static function kunciCocokMode(string $kunci, bool $produksi): bool
    {
        return str_starts_with($kunci, $produksi ? 'Mid-server-' : 'SB-Mid-server-');
    }

    /** URL halaman bayar Snap; transaksi lama dipakai ulang selama belum kedaluwarsa dan nominalnya sama. */
    public function urlBayar(Invoice $invoice): string
    {
        $lama = $invoice->midtrans;
        if ($lama && $lama['total'] === $invoice->total() && now()->lt($lama['sampai'])) {
            return $lama['url'];
        }

        // order_id unik per percobaan (Midtrans menolak order_id yang pernah dipakai); nomor tagihan = bagian sebelum "-" terakhir
        $order = $invoice->nomor.'-'.now()->format('ymdHis');
        $res = $this->http()->post($this->dasar('app').'/snap/v1/transactions', [
            'transaction_details' => ['order_id' => $order, 'gross_amount' => $invoice->total()],
            'item_details' => [['id' => (string) $invoice->id, 'price' => $invoice->total(), 'quantity' => 1,
                'name' => Str::limit($invoice->nomor.' '.$invoice->service->label, 50, '')]],
            'customer_details' => ['first_name' => Str::limit($invoice->user->name, 50, ''), 'email' => $invoice->user->email],
            'callbacks' => ['finish' => rtrim(config('app.url'), '/').route('tagihan.show', $invoice, false)],
            'expiry' => ['unit' => 'hours', 'duration' => self::JAM_BERLAKU],
        ])->throw()->json();

        $invoice->forceFill(['midtrans' => ['order' => $order, 'url' => $res['redirect_url'], 'total' => $invoice->total(),
            'sampai' => now()->addHours(self::JAM_BERLAKU)->toIso8601String()]])->save();

        return $res['redirect_url'];
    }

    /** Status transaksi langsung dari API Midtrans (isi notifikasi tidak dipercaya begitu saja). */
    public function status(string $order): array
    {
        return $this->http()->get($this->dasar('api').'/v2/'.rawurlencode($order).'/status')->throw()->json();
    }

    /**
     * Ambil status order dari API lalu lunasi tagihannya bila dana masuk dan nominal sama.
     * Dipanggil notifikasi HTTP dan saat pelanggan membuka tagihan (cadangan bila notifikasi tidak sampai). Idempoten.
     */
    public function proses(string $order): void
    {
        $s = $this->status($order);
        $invoice = Invoice::firstWhere('nomor', Str::beforeLast($order, '-'));
        $info = ['order' => $order, 'status' => $s['transaction_status'] ?? null, 'jumlah' => $s['gross_amount'] ?? null];

        if (! $invoice || ! self::lunas($s)) {
            Log::info('midtrans', $info); // pending/expire/cancel atau notifikasi uji dari dashboard Midtrans

            return;
        }
        if ($invoice->status === InvoiceStatus::Lunas && $invoice->metode === 'midtrans') {
            return; // sudah diproses (notifikasi ulang / halaman dibuka lagi)
        }
        if ((int) round((float) ($s['gross_amount'] ?? 0)) !== $invoice->total() || $invoice->status !== InvoiceStatus::BelumDibayar) {
            // dibayar dobel (sudah lunas manual) atau nominal beda: dana masuk tapi perlu dicek admin (refund/manual)
            Log::warning('midtrans perlu dicek admin', $info + ['tagihan' => $invoice->nomor, 'status_tagihan' => $invoice->status->value, 'total' => $invoice->total()]);

            return;
        }

        $this->tandai->handle($invoice, 'midtrans', Str::limit(($s['payment_type'] ?? '').' '.($s['transaction_id'] ?? $order), 100, ''));
        Log::info('midtrans lunas', $info + ['tagihan' => $invoice->nomor]);
    }

    public static function tandaTanganSah(array $n): bool
    {
        $kunci = self::kunci();

        return $kunci !== '' && is_string($n['signature_key'] ?? null)
            && hash_equals(hash('sha512', ($n['order_id'] ?? '').($n['status_code'] ?? '').($n['gross_amount'] ?? '').$kunci), $n['signature_key']);
    }

    /** settlement = dana masuk; capture (kartu) hanya bila lolos fraud check. */
    public static function lunas(array $s): bool
    {
        return ($s['transaction_status'] ?? null) === 'settlement'
            || (($s['transaction_status'] ?? null) === 'capture' && ($s['fraud_status'] ?? 'accept') === 'accept');
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth(self::kunci(), '')->acceptJson()->asJson()->timeout(20);
    }

    private function dasar(string $jenis): string
    {
        return 'https://'.$jenis.(self::produksi() ? '' : '.sandbox').'.midtrans.com';
    }
}
