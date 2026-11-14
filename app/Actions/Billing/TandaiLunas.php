<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Jobs\PodAction;
use App\Jobs\ProvisionPod;
use App\Jobs\ProvisionVps;
use App\Models\Invoice;
use App\Models\SaldoMutasi;
use App\Models\User;
use App\Models\Service;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class TandaiLunas
{
    /**
     * Lunasi tagihan lalu perbarui layanan:
     * - tagihan pertama: layanan disiapkan (app → job provisioning pod, VPS/managed → disiapkan admin manual)
     * - perpanjangan: jatuh tempo maju, layanan yang ditangguhkan diaktifkan lagi.
     */
    public function handle(Invoice $invoice, string $metode, ?string $referensi = null): void
    {
        if ($invoice->status !== InvoiceStatus::BelumDibayar) {
            return;
        }

        $provision = DB::transaction(function () use ($invoice, $metode, $referensi) {
            $invoice->update([
                'status' => InvoiceStatus::Lunas,
                'dibayar_pada' => now(),
                'metode' => $metode,
                'referensi' => $referensi,
            ]);

            $this->kodeUnikKeSaldo($invoice);

            $service = $invoice->service()->lockForUpdate()->first();

            if ($invoice->token_juta) {
                $this->tambahToken($service, $invoice->token_juta);

                return null;
            }

            $service->jatuh_tempo = $invoice->periode_selesai;

            if ($service->status === ServiceStatus::MenungguPembayaran) {
                $service->status = ServiceStatus::Disiapkan;
                $service->save();

                return $service->isApp() ? ProvisionPod::class : ($service->plan->vpsOtomatis() ? ProvisionVps::class : null);
            }

            if ($service->status === ServiceStatus::Ditangguhkan) {
                $service->status = ServiceStatus::Aktif;
                if ($service->bisaKontrol()) {
                    DB::afterCommit(fn () => PodAction::dispatch($service, 'start')); // pod/VM dihentikan saat ditangguhkan
                }
            }
            $service->save();

            return null;
        });

        if ($provision) {
            $provision::dispatch($invoice->service);
        }
    }

    /** Kode unik yang ikut dibayar menjadi saldo pelanggan (tidak bisa ditarik). */
    private function kodeUnikKeSaldo(Invoice $invoice): void
    {
        if ($invoice->kode_unik < 1) {
            return;
        }
        $user = User::whereKey($invoice->user_id)->lockForUpdate()->first();
        $user->increment('saldo', $invoice->kode_unik);
        SaldoMutasi::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'jumlah' => $invoice->kode_unik,
            'saldo_akhir' => $user->saldo,
            'keterangan' => 'Kode unik '.$invoice->nomor,
        ]);
    }

    /** Beli pertama: buat API key + aktifkan. Top-up: saldo bertambah (layanan ditangguhkan admin tidak diaktifkan otomatis). */
    private function tambahToken(Service $service, int $juta): void
    {
        $token = $service->tokenAi;
        if (! $token) {
            $kunci = 'vc-'.Str::random(48);
            $token = $service->tokenAi()->create(['kunci_hash' => hash('sha256', $kunci), 'saldo' => 0]);
            $service->detail = [...($service->detail ?? []), 'kredensial' => Crypt::encryptString(json_encode(['api_key' => $kunci]))];
        }
        $token->increment('saldo', $juta * 1_000_000);

        if ($service->status === ServiceStatus::MenungguPembayaran) {
            $service->status = ServiceStatus::Aktif;
        }
        $service->save();
    }
}
