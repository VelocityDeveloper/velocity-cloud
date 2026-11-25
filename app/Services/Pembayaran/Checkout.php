<?php

namespace App\Services\Pembayaran;

use App\Enums\CheckoutStatus;
use App\Jobs\KirimWebhookCheckout;
use App\Models\CheckoutSesi;
use App\Models\CheckoutWebhook;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * VDC Pay (24 Nov): sesi bayar milik web lain dibayar lewat Midtrans VDC.
 * Order Midtrans berformat CS-<kode klien>-<id sesi>-<waktu> sehingga notifikasi di URL yang sama
 * (MidtransController::notifikasi) bisa dipisah dari tagihan VDC (INV-…). Tiap perubahan status final
 * dicatat sebagai event (checkout_webhook) lalu dikirim ke klien lewat antrean, bertanda tangan HMAC.
 */
class Checkout
{
    public const AWALAN = 'CS-';

    public function __construct(private Midtrans $midtrans) {}

    public static function milik(string $order): bool
    {
        return str_starts_with($order, self::AWALAN);
    }

    /** URL halaman bayar Snap; transaksi lama dipakai ulang selama belum kedaluwarsa. */
    public function urlBayar(CheckoutSesi $sesi): string
    {
        $lama = $sesi->midtrans;
        if ($lama && now()->lt($lama['sampai'])) {
            return $lama['url'];
        }

        $order = self::AWALAN.$sesi->klien->kode.'-'.$sesi->id.'-'.now()->format('ymdHis');
        $res = $this->midtrans->snap([
            'transaction_details' => ['order_id' => $order, 'gross_amount' => $sesi->jumlah],
            'item_details' => [['id' => Str::limit($sesi->ref, 50, ''), 'price' => $sesi->jumlah, 'quantity' => 1,
                'name' => Str::limit($sesi->deskripsi, 50, '')]],
            'customer_details' => array_filter(['first_name' => $sesi->nama ? Str::limit($sesi->nama, 50, '') : null, 'email' => $sesi->email]),
            'callbacks' => ['finish' => rtrim(config('app.url'), '/').route('checkout.selesai', $sesi, false)],
            'expiry' => ['unit' => 'hours', 'duration' => Midtrans::JAM_BERLAKU],
        ]);

        // Bersyarat: notifikasi lunas bisa masuk di antara pengecekan controller dan baris ini; jangan mundurkan status.
        $diubah = CheckoutSesi::whereKey($sesi->id)->whereIn('status', [CheckoutStatus::Dibuat, CheckoutStatus::Menunggu])->update([
            'midtrans' => json_encode(['order' => $order, 'url' => $res['redirect_url'], 'sampai' => now()->addHours(Midtrans::JAM_BERLAKU)->toIso8601String()]),
            'status' => CheckoutStatus::Menunggu->value,
            'updated_at' => now(),
        ]);
        $sesi->refresh();

        return $diubah ? $res['redirect_url'] : route('checkout.show', $sesi);
    }

    /**
     * Ambil status order dari API Midtrans (isi notifikasi tidak dipercaya) lalu perbarui sesinya. Idempoten:
     * dipanggil notifikasi, saat pelanggan kembali dari Snap, saat klien menanyakan status, dan oleh penjadwal.
     *
     * @return bool false bila transaksi belum ada di Midtrans (Snap dibuka tapi metode bayar tidak pernah dipilih)
     */
    public function proses(string $order): bool
    {
        $sesi = $this->sesiDariOrder($order);
        if (! $sesi) {
            Log::info('checkout: order tidak dikenal', ['order' => $order]);

            return true;
        }

        try {
            $s = $this->midtrans->status($order);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return false;
            }
            throw $e;
        }
        if (($s['status_code'] ?? null) === '404') { // sebagian balasan Midtrans HTTP 200 dengan status_code 404 di isi
            return false;
        }
        $baru = self::petakan($s);
        $info = ['order' => $order, 'sesi' => $sesi->uuid, 'status_midtrans' => $s['transaction_status'] ?? null];

        // Refund sebagian tidak membatalkan pembelian; cukup diberitahukan ke admin.
        if (($s['transaction_status'] ?? null) === 'partial_refund') {
            Log::warning('checkout: refund sebagian, status tetap', $info + ['refund' => $s['refund_amount'] ?? null]);

            return true;
        }

        if ($baru === CheckoutStatus::Lunas && (int) round((float) ($s['gross_amount'] ?? 0)) !== $sesi->jumlah) {
            Log::warning('checkout: nominal beda, perlu dicek admin', $info + ['jumlah' => $s['gross_amount'] ?? null, 'harus' => $sesi->jumlah]);

            return true;
        }
        // Refund hanya berlaku untuk transaksi yang melunasi sesi; refund bayar dobel tidak mencabut pembelian.
        if ($baru === CheckoutStatus::Refund && $sesi->order_lunas !== $order) {
            Log::warning('checkout: refund transaksi yang bukan pelunas sesi diabaikan', $info + ['order_lunas' => $sesi->order_lunas]);

            return true;
        }
        // Sesi sudah lunas lewat transaksi lain: dana kedua perlu dikembalikan admin.
        if ($baru === CheckoutStatus::Lunas && $sesi->order_lunas && $sesi->order_lunas !== $order) {
            Log::warning('checkout: bayar dobel, kembalikan dana transaksi ini', $info + ['order_lunas' => $sesi->order_lunas]);

            return true;
        }
        // Status gagal milik transaksi lama (pelanggan sudah membuat transaksi baru) tidak boleh menutup sesi.
        if (! in_array($baru, [CheckoutStatus::Lunas, CheckoutStatus::Refund], true) && ($sesi->midtrans['order'] ?? null) !== $order) {
            Log::info('checkout: status transaksi lama diabaikan', $info);

            return true;
        }

        $this->ubahStatus($sesi, $baru, $baru === CheckoutStatus::Lunas ? [
            'order_lunas' => $order,
            'referensi' => Str::limit(($s['payment_type'] ?? '').' '.($s['transaction_id'] ?? $order), 100, ''),
            'dibayar_pada' => now(),
        ] : [], $info);

        return true;
    }

    /** Penjadwal: tutup sesi yang tidak pernah dibayar, dan cek ulang yang transaksinya sudah lewat waktu (notifikasi hilang). */
    public function tutupKedaluwarsa(): int
    {
        $n = 0;
        CheckoutSesi::with('klien')->whereIn('status', [CheckoutStatus::Dibuat, CheckoutStatus::Menunggu])
            ->where('berlaku_sampai', '<', now())->get()
            ->each(function (CheckoutSesi $sesi) use (&$n) {
                if (! $sesi->lewatBatas()) {
                    return; // transaksi Midtrans masih berjalan
                }
                if (isset($sesi->midtrans['order'])) {
                    try {
                        // status final diambil dari Midtrans, bukan ditebak; hanya transaksi yang tidak pernah ada yang ditutup di sini
                        if ($this->proses($sesi->midtrans['order'])) {
                            if (! $sesi->refresh()->status->terbuka()) {
                                $n++;

                                return;
                            }
                            // Transaksi ada tapi tak kunjung final sesudah masa berlakunya (nominal beda / challenge):
                            // tutup supaya tidak ditanyakan ke Midtrans tiap 5 menit selamanya.
                            Log::warning('checkout: transaksi tidak final sesudah masa berlaku, sesi ditutup; cek admin', ['sesi' => $sesi->uuid, 'order' => $sesi->midtrans['order']]);
                        }
                    } catch (\Throwable $e) {
                        report($e); // Midtrans sedang gangguan: coba lagi di jadwal berikutnya

                        return;
                    }
                }
                $this->ubahStatus($sesi, CheckoutStatus::Kedaluwarsa, [], ['sesi' => $sesi->uuid, 'sebab' => 'lewat batas']);
                $n++;
            });

        return $n;
    }

    /**
     * Penjadwal: kirim ulang event "antri" yang job-nya hilang (mis. proses mati di antara commit dan dispatch).
     * Job unik per event, jadi event yang job-nya masih di antrean/sedang diulang tidak tergandakan.
     */
    public function sapuEventTertinggal(): int
    {
        $tertinggal = CheckoutWebhook::where('status', 'antri')->where('created_at', '<', now()->subMinutes(10))->get();
        $tertinggal->each(fn (CheckoutWebhook $w) => KirimWebhookCheckout::dispatch($w));

        return $tertinggal->count();
    }

    /** @param array<string, mixed> $s balasan API status Midtrans */
    public static function petakan(array $s): CheckoutStatus
    {
        return match ($s['transaction_status'] ?? null) {
            'settlement' => CheckoutStatus::Lunas,
            'capture' => match ($s['fraud_status'] ?? 'accept') {
                'accept' => CheckoutStatus::Lunas,
                'deny' => CheckoutStatus::Ditolak,
                default => CheckoutStatus::Menunggu, // challenge: menunggu keputusan di dashboard Midtrans
            },
            'expire' => CheckoutStatus::Kedaluwarsa,
            'cancel' => CheckoutStatus::Batal,
            'deny', 'failure' => CheckoutStatus::Ditolak,
            'refund' => CheckoutStatus::Refund, // partial_refund ditangani di proses()
            default => CheckoutStatus::Menunggu, // pending, authorize
        };
    }

    /** Perubahan yang sah; status lain diabaikan (mis. "expire" datang sesudah lunas). */
    public static function bolehPindah(CheckoutStatus $dari, CheckoutStatus $ke): bool
    {
        if ($dari === $ke) {
            return false;
        }

        return match ($dari) {
            CheckoutStatus::Dibuat, CheckoutStatus::Menunggu => true,
            // transaksi lama ternyata dibayar sesudah sesi ditutup: dana sudah masuk, tetap lunas
            CheckoutStatus::Kedaluwarsa, CheckoutStatus::Batal, CheckoutStatus::Ditolak => $ke === CheckoutStatus::Lunas,
            CheckoutStatus::Lunas => $ke === CheckoutStatus::Refund,
            CheckoutStatus::Refund => false,
        };
    }

    /**
     * @param  array<string, mixed>  $isian  kolom tambahan saat pindah status
     * @param  array<string, mixed>  $info  konteks log
     */
    private function ubahStatus(CheckoutSesi $sesi, CheckoutStatus $ke, array $isian, array $info): void
    {
        $event = DB::transaction(function () use ($sesi, $ke, $isian) {
            $sesi = CheckoutSesi::lockForUpdate()->find($sesi->id); // notifikasi ganda bisa datang bersamaan
            if (! self::bolehPindah($sesi->status, $ke)) {
                if ($ke === CheckoutStatus::Lunas && $sesi->order_lunas !== ($isian['order_lunas'] ?? null)) {
                    Log::warning('checkout: bayar dobel bersamaan, kembalikan dana transaksi ini', ['sesi' => $sesi->uuid,
                        'order' => $isian['order_lunas'] ?? null, 'order_lunas' => $sesi->order_lunas]);
                }

                return null;
            }
            $sesi->forceFill(['status' => $ke] + $isian)->save();

            return $ke->event() ? $this->catatEvent($sesi, $ke->event()) : null;
        });

        if ($event) {
            Log::info('checkout: '.$ke->value, $info);
            KirimWebhookCheckout::dispatch($event)->afterCommit();
        }
    }

    private function catatEvent(CheckoutSesi $sesi, string $jenis): ?CheckoutWebhook
    {
        if ($sesi->webhook()->where('jenis', $jenis)->exists()) {
            return null;
        }
        $id = 'evt_'.Str::lower(Str::random(32));

        return $sesi->webhook()->create([
            'event_id' => $id,
            'jenis' => $jenis,
            'payload' => ['id' => $id, 'jenis' => $jenis, 'dibuat' => now()->toIso8601String(), 'data' => $sesi->untukKlien()],
        ]);
    }

    private function sesiDariOrder(string $order): ?CheckoutSesi
    {
        // CS-<kode>-<id sesi>-<ymdHis>
        if (! preg_match('/^CS-([A-Z0-9]{2,6})-(\d+)-\d{12}$/', $order, $m)) {
            return null;
        }
        $sesi = CheckoutSesi::with('klien')->find((int) $m[2]);

        return $sesi && $sesi->klien->kode === $m[1] ? $sesi : null;
    }
}
