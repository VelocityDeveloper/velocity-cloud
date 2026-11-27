<?php

namespace App\Services\Crm;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tagihan lunas Midtrans → CRM new-vdnet (POST vdc-tagihan): billing + baris bank midtrans_vd.
 * CRM idempoten lewat (sumber, nomor tagihan): kirim ulang mengembalikan data yang sama (200).
 * Domain tidak ikut (dicatat CRM lewat domain-order); metode lain (crm/transfer/saldo/gratis) sudah tercatat di CRM atau bukan uang Midtrans.
 */
class TagihanCrm
{
    /** Tagihan yang semestinya ada di CRM (terkirim atau belum). */
    public static function lingkup(): Builder
    {
        return Invoice::query()->where('status', InvoiceStatus::Lunas)->where('metode', 'midtrans')
            ->whereHas('service.plan', fn ($q) => $q->where('kategori', '!=', PlanKategori::Domain));
    }

    public static function perluDikirim(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Lunas && $invoice->metode === 'midtrans'
            && self::mode($invoice) === 'production' && ! $invoice->service->isDomain();
    }

    /**
     * Mode Midtrans saat tagihan dibayar. Tagihan baru menyimpan midtrans.mode saat transaksi Snap dibuat;
     * tagihan lama (sebelum penanda ada) = produksi bila dibayar setelah VDC pindah ke Midtrans produksi.
     */
    public static function mode(Invoice $invoice): string
    {
        if ($mode = $invoice->midtrans['mode'] ?? null) {
            return $mode;
        }

        return $invoice->dibayar_pada?->gte(config('cloud.crm.midtrans_produksi_sejak')) ? 'production' : 'sandbox';
    }

    /** Pengiriman otomatis setelah lunas; dimatikan sampai endpoint CRM live (CRM_KIRIM_TAGIHAN). */
    public static function otomatis(): bool
    {
        return (bool) config('cloud.crm.kirim_tagihan') && filled(config('cloud.crm.key'));
    }

    /**
     * @return array{billing_id: int, bank_id: int, customer_id: ?int, baru: bool}
     *
     * @throws RuntimeException kode = status HTTP (4xx = data ditolak, tidak berguna diulang)
     */
    public function kirim(Invoice $invoice): array
    {
        $key = config('cloud.crm.key');
        if (blank($key)) {
            throw new RuntimeException('CRM_API_KEY belum diatur.');
        }
        $res = Http::baseUrl(rtrim(config('cloud.crm.url'), '/'))->withToken($key)->acceptJson()
            ->timeout(60)->connectTimeout(10)->post('vdc-tagihan', self::data($invoice));
        if ($res->failed()) {
            $pesan = $res->json('message') ?: 'HTTP '.$res->status();
            if ($errors = $res->json('errors')) {
                $pesan .= ': '.collect($errors)->flatten()->implode(' ');
            }
            throw new RuntimeException('CRM: '.mb_substr($pesan, 0, 450), $res->status());
        }
        if (! $res->json('billing_id') || ! $res->json('bank_id')) {
            throw new RuntimeException('CRM: respons tanpa billing_id/bank_id.', 502);
        }

        return $res->json();
    }

    public static function data(Invoice $invoice): array
    {
        $service = $invoice->service;
        $plan = $service->plan;
        $user = $invoice->user;

        return [
            'referensi' => $invoice->nomor,
            'sumber' => config('cloud.crm.sumber'),
            'dibayar_pada' => $invoice->dibayar_pada->format('Y-m-d H:i:s'),
            'jumlah' => $invoice->jumlah,
            'kode_unik' => (int) $invoice->kode_unik,
            'total' => $invoice->total(), // yang dibayar lewat Midtrans (kode unik jadi saldo pelanggan)
            'midtrans' => $invoice->referensi, // "<payment_type> <transaction_id>"
            'midtrans_mode' => self::mode($invoice),
            'kategori' => $plan->kategori->value,
            'jenis' => self::jenis($invoice),
            'paket' => mb_substr($plan->nama.($invoice->token_juta ? ' '.number_format($invoice->token_juta, 0, ',', '.').' juta token' : ''), 0, 150),
            'periode_mulai' => $invoice->periode_mulai?->toDateString(),
            'periode_selesai' => $invoice->periode_selesai?->toDateString(),
            'nama_web' => self::namaWeb($invoice),
            'customer' => ['nama' => $user->name, 'email' => $user->email, 'hp' => $user->whatsapp],
        ];
    }

    /** Perpanjangan = layanan sudah pernah lunas sebelum tagihan ini. Top-up token AI = pembelian baru. */
    private static function jenis(Invoice $invoice): string
    {
        if ($invoice->token_juta) {
            return 'baru';
        }

        return Invoice::where('service_id', $invoice->service_id)->where('id', '<', $invoice->id)
            ->where('status', InvoiceStatus::Lunas)->exists() ? 'perpanjangan' : 'baru';
    }

    /**
     * Nama web billing CRM: custom domain → subdomain Velocity → vdc-<kategori>-<id layanan>.
     * Bukan label: CRM memakai ulang webhost bernama sama, label (mis. "DeepSeek V4.1 Flash") bisa menggabungkan pelanggan berbeda.
     */
    private static function namaWeb(Invoice $invoice): string
    {
        $s = $invoice->service;

        return $s->custom_domain ?: ($s->subdomain ? $s->subdomain.'.'.config('cloud.domain_app') : 'vdc-'.$s->plan->kategori->value.'-'.$s->id);
    }
}
