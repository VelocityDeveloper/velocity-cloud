<?php

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Crm\DomainCrm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Domain lunas → CRM new-vdnet: customer + order + invoice lunas, lalu register/transfer ke registrar (saldo registrar terpotong).
 * CRM idempoten lewat nomor tagihan: diulang (retry/Ulangi admin) tidak membuat order kedua, registrar hanya dicoba ulang bila gagal.
 */
class KirimDomainCrm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 360; // registrar bisa beberapa panggilan API @60 dtk

    public function __construct(public Service $service) {}

    public function handle(DomainCrm $crm): void
    {
        $service = $this->service->fresh();
        if ($service->status !== ServiceStatus::Disiapkan) {
            return;
        }
        $d = $service->detail;
        $tagihan = $service->invoices()->where('status', InvoiceStatus::Lunas)->oldest('id')->firstOrFail();

        try {
            $hasil = $crm->order([
                'referensi' => $tagihan->nomor,
                'domain' => $d['domain'],
                'type' => $d['jenis'],
                'periode' => $d['periode'],
                'epp_code' => isset($d['epp']) ? Crypt::decryptString($d['epp']) : null,
                'harga' => $tagihan->jumlah, // tanpa kode unik (kode unik jadi saldo)
                'kontak' => $d['kontak'],
            ]);
        } catch (RuntimeException $e) {
            // ditolak CRM (data/domain) → langsung Gagal, diulang pun sama; koneksi/5xx/429 → dicoba lagi
            if ($e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 429) {
                $this->fail($e);

                return;
            }
            throw $e;
        }

        $crmInfo = ['order' => $hasil['order'], 'invoice' => $hasil['invoice'], 'customer_id' => $hasil['customer_id'] ?? null, 'status' => $hasil['domain']['status'] ?? null];
        // TLD tanpa registrar di CRM: tidak didaftarkan otomatis, tim mendaftarkan manual lalu admin set Aktif
        if (($hasil['registrasi'] ?? null) === null) {
            $service->update(['detail' => [...$d, 'crm' => $crmInfo, 'akses' => "Order {$hasil['order']} diterima. Domain {$d['domain']} sedang didaftarkan manual oleh tim kami (maks. 1x24 jam kerja)."]]);

            return;
        }
        if (! ($hasil['registrasi']['ok'] ?? false)) {
            $service->update([
                'status' => ServiceStatus::Gagal,
                'pesan_gagal' => mb_substr("Order CRM {$hasil['order']} sudah dibuat, tapi registrar menolak: ".($hasil['registrasi']['message'] ?? $hasil['domain']['registrar_pesan'] ?? '-')
                    .'. Perbaiki lalu Retry di CRM, atau klik Ulangi di sini.', 0, 1000),
                'detail' => [...$d, 'crm' => $crmInfo],
            ]);

            return;
        }

        $sampai = $hasil['domain']['expiry_date'] ?? null;
        $service->update([
            'status' => ServiceStatus::Aktif,
            'pesan_gagal' => null,
            'detail' => [...$d, 'crm' => $crmInfo, 'akses' => $d['jenis'] === 'Transfer'
                ? "Permintaan transfer {$d['domain']} sudah dikirim ke registrar. Transfer biasanya selesai 5-7 hari; setujui email konfirmasi transfer bila ada."
                : "Domain {$d['domain']} sudah terdaftar".($sampai ? ' sampai '.substr($sampai, 0, 10) : '').'.'
                    ."\nTagihan perpanjangan dikirim ke email {$d['kontak']['email']} sebelum masa aktif habis."
                    ."\nNameserver bisa dilihat & diubah lewat Chat AI, atau buka tiket support."],
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->service->fresh()->update(['status' => ServiceStatus::Gagal, 'pesan_gagal' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
