<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Crm\TagihanCrm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Tagihan lunas Midtrans → billing + bank midtrans_vd di CRM. Hasil disimpan di invoices.crm_* (badge di Admin › Tagihan).
 * Aman diulang: CRM idempoten lewat nomor tagihan, tagihan yang sudah punya crm_bank_id dilewati.
 */
class KirimTagihanCrm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 90;

    public function __construct(public Invoice $invoice) {}

    public function handle(TagihanCrm $crm): void
    {
        $invoice = $this->invoice->fresh(['service.plan', 'user']);
        if ($invoice->crm_bank_id || ! TagihanCrm::perluDikirim($invoice)) {
            return;
        }

        try {
            $hasil = $crm->kirim($invoice);
        } catch (RuntimeException $e) {
            // ditolak CRM (422 dsb.) → catat, diulang pun sama; koneksi/5xx/429 → dicoba lagi
            if ($e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 429) {
                $this->fail($e); // → failed() mencatat crm_galat

                return;
            }
            throw $e;
        }

        $invoice->forceFill([
            'crm_billing_id' => $hasil['billing_id'],
            'crm_bank_id' => $hasil['bank_id'],
            'crm_terkirim_pada' => now(),
            'crm_galat' => null,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        $this->gagal($e);
    }

    private function gagal(Throwable $e): void
    {
        $this->invoice->forceFill(['crm_galat' => mb_substr($e->getMessage(), 0, 500)])->save();
    }
}
