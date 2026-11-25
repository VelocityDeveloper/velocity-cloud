<?php

namespace App\Actions\Order;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Pengaturan;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DomainCrm;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Pesanan domain (register/transfer) = layanan "menunggu pembayaran" + tagihan; dipakai halaman Pesan Domain dan Chat AI. */
class PesanDomain
{
    public const POLA = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** Kolom kontak WHOIS wajib (perusahaan opsional). */
    public const KONTAK_WAJIB = ['nama', 'email', 'hp', 'alamat', 'kota', 'provinsi', 'kode_pos'];

    public function __construct(private DomainCrm $crm) {}

    /** "https://www.Toko.com/x" → "toko.com" */
    public static function normalisasi(string $domain): string
    {
        return strtolower(trim(preg_replace(['#^https?://#i', '#^www\.#i', '#/.*$#'], '', trim($domain))));
    }

    /** Harga CRM, null bila ekstensi tidak dijual di CRM atau dimatikan di Admin › Paket › Domain. RuntimeException = CRM bermasalah. */
    public function info(string $domain): ?array
    {
        $info = $this->crm->harga($domain);

        return $info && in_array($info['tld'], Pengaturan::tldDomain(), true) ? $info : null;
    }

    /** Kontak pemilik domain terakhir pelanggan; belum pernah pesan = dari akun. */
    public static function kontakTerakhir(User $user): array
    {
        $lama = Service::where('user_id', $user->id)->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::Domain))->latest('id')->value('detail');

        return $lama['kontak'] ?? ['nama' => $user->name, 'email' => $user->email, 'hp' => $user->whatsapp];
    }

    /**
     * Harga & ketersediaan dicek ulang ke CRM di sini (bukan dari isian pelanggan). Penolakan = ValidationException field "domain".
     * Pesanan sama yang belum dibayar dipakai ulang (tagihannya dikembalikan).
     */
    public function handle(User $user, string $domain, string $jenis, int $periode, ?string $epp, array $kontak): Invoice
    {
        try {
            $info = $this->info($domain);
        } catch (RuntimeException $e) {
            report($e);
            throw ValidationException::withMessages(['domain' => 'Cek domain sedang bermasalah, coba lagi beberapa saat.']);
        }
        $harga = $info ? DomainCrm::hargaPeriode($info, $jenis, $periode) : null;
        $tolak = match (true) {
            ! $info => 'Ekstensi domain ini belum kami jual.',
            $info['tercatat'] => 'Domain ini sudah tercatat sebagai layanan Velocity. Hubungi support bila ini domain Anda.',
            $jenis === 'Register' && $info['tersedia'] !== true => 'Domain ini sudah terdaftar. Pilih Transfer bila domain ini milik Anda.',
            $jenis === 'Transfer' && $info['tersedia'] === true => 'Domain ini belum terdaftar, pilih Daftar baru.',
            $jenis === 'Transfer' && blank($epp) => 'Kode EPP wajib diisi untuk transfer.',
            $harga === null => "Periode {$periode} tahun tidak tersedia untuk domain ini.",
            default => null,
        };
        if ($tolak) {
            throw ValidationException::withMessages(['domain' => $tolak]);
        }

        $menunggu = Service::where('user_id', $user->id)->where('status', ServiceStatus::MenungguPembayaran)->where('detail->domain', $domain)->first();
        if ($menunggu && ($tagihan = $menunggu->invoices()->where('status', InvoiceStatus::BelumDibayar)->latest('id')->first())) {
            return $tagihan;
        }

        return DB::transaction(function () use ($user, $domain, $jenis, $periode, $epp, $kontak, $harga) {
            $plan = Plan::firstOrCreate(['slug' => 'domain'], ['kategori' => PlanKategori::Domain, 'nama' => 'Domain', 'harga' => 0, 'aktif' => false]);
            $service = Service::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'label' => $domain,
                'status' => ServiceStatus::MenungguPembayaran,
                'harga' => $harga,
                'detail' => array_filter([
                    'domain' => $domain,
                    'jenis' => $jenis,
                    'periode' => $periode,
                    'kontak' => $kontak,
                    'epp' => $jenis === 'Transfer' ? Crypt::encryptString($epp) : null,
                ]),
            ]);

            return Invoice::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'nomor' => Invoice::nomorBaru(),
                'jumlah' => $harga,
                'status' => InvoiceStatus::BelumDibayar,
                'periode_mulai' => today(),
                'periode_selesai' => today()->addYears($periode),
                'jatuh_tempo' => today()->addDays(config('cloud.tagihan.jatuh_tempo_hari')),
            ]);
        });
    }
}
