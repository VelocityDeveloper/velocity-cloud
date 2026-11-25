<?php

namespace App\Jobs;

use App\Models\CheckoutWebhook;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Kirim satu event VDC Pay ke webhook klien. Header VDC-Signature: t=<unix>,v1=<hex HMAC-SHA256("<t>.<body>", rahasia)>.
 * Tanda tangan dibuat ulang tiap percobaan (waktu baru), jadi klien boleh menolak t yang lebih tua dari 5 menit.
 * Gagal (non-2xx/timeout) diulang dengan jeda bertahap ±1 hari; sesudah itu status "gagal" dan bisa dikirim ulang dari Admin › Pembayaran.
 * Unik per event: selama rantai percobaan masih berjalan, dispatch lain (penyapu/admin) diabaikan.
 */
class KirimWebhookCheckout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    /** Lebih lama dari seluruh rantai percobaan (±22 jam) supaya kunci tidak lepas saat job masih menunggu. */
    public int $uniqueFor = 172800;

    public function __construct(public CheckoutWebhook $webhook) {}

    public function uniqueId(): string
    {
        return (string) $this->webhook->id;
    }

    /**
     * Detik sebelum percobaan ke-2, ke-3, …: 1 mnt, 5 mnt, 15 mnt, 1 jam, 3 jam, 6 jam, 12 jam.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800, 21600, 43200];
    }

    public function handle(): void
    {
        $w = $this->webhook;
        if ($w->status === 'terkirim') {
            return; // kiriman ulang manual atas event yang sudah sampai
        }
        $klien = $w->sesi->klien;
        $body = json_encode($w->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $t = now()->timestamp;
        $w->increment('percobaan');

        try {
            $res = Http::withBody($body, 'application/json')->acceptJson()->timeout(15)->withoutRedirecting()->withHeaders([
                'User-Agent' => 'VDC-Pay/1',
                'VDC-Event-Id' => $w->event_id,
                'VDC-Signature' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $klien->rahasia()),
            ])->post($klien->url_webhook);
        } catch (Throwable $e) {
            $w->update(['kode_respons' => null, 'galat' => Str::limit($e->getMessage(), 500, '')]);
            throw $e;
        }

        if (! $res->successful()) {
            $w->update(['kode_respons' => $res->status(), 'galat' => Str::limit($res->body(), 500, '') ?: 'HTTP '.$res->status()]);
            throw new RuntimeException('Webhook '.$w->event_id.' dibalas HTTP '.$res->status());
        }
        $w->update(['status' => 'terkirim', 'kode_respons' => $res->status(), 'galat' => null, 'terkirim_pada' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        // bersyarat: jangan menimpa event yang sudah sampai lewat job lain
        CheckoutWebhook::whereKey($this->webhook->id)->where('status', '!=', 'terkirim')->update(['status' => 'gagal']);
    }
}
